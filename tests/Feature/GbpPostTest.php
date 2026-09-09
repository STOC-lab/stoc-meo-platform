<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Enums\GbpPostStatus;
use App\Enums\GbpTokenStatus;
use App\Jobs\PublishGbpPostJob;
use App\Models\GbpAccount;
use App\Models\GbpPost;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\GBP\GBPClientFactory;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every Google call in this file is faked; nothing here reaches the network.
 */
class GbpPostTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
        ]);

        $this->organization = $this->organizationOnPlan([Feature::GbpPostMonthlyLimit->value => 4]);

        $this->location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => 'locations/1234567890',
        ]);
    }

    /**
     * @param  array<string, int|bool|null>  $features
     */
    protected function organizationOnPlan(array $features): Organization
    {
        return Organization::factory()
            ->onPlan(Plan::factory()->withFeatures($features)->create())
            ->create();
    }

    protected function member(string $role, ?Organization $organization = null): User
    {
        $user = User::factory()->create();
        ($organization ?? $this->organization)->users()->attach($user, ['role' => $role]);

        return $user;
    }

    protected function connect(array $state = []): GbpAccount
    {
        return GbpAccount::factory()
            ->forLocation($this->location)
            ->create($state + ['gbp_account_name' => 'accounts/999']);
    }

    protected function url(?Location $location = null): string
    {
        return '/api/v1/locations/'.($location ?? $this->location)->id.'/gbp-posts';
    }

    protected function runJob(GbpPost $post): void
    {
        (new PublishGbpPostJob($post))->handle(
            app(GBPClientFactory::class),
            app(UsageTracker::class),
            app(Tenancy::class),
        );
    }

    public function test_a_store_manager_queues_a_post(): void
    {
        Queue::fake();

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['content' => '本日は10時から営業しています。'])
            ->assertAccepted()
            ->assertJsonPath('post.status', 'draft')
            ->assertJsonPath('post.content', '本日は10時から営業しています。')
            ->assertJsonPath('allowance.limit', 4);

        $this->assertDatabaseHas('gbp_posts', [
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'status' => 'draft',
        ]);

        Queue::assertPushed(PublishGbpPostJob::class, 1);
    }

    public function test_the_content_is_required_and_capped(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['content' => str_repeat('あ', 1501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');
    }

    public function test_a_call_to_action_other_than_call_needs_somewhere_to_send_the_person(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['content' => '予約受付中', 'cta_type' => 'BOOK'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cta_url');

        Queue::fake();

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['content' => 'お電話ください', 'cta_type' => 'CALL'])
            ->assertAccepted();
    }

    public function test_an_unknown_call_to_action_is_refused(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['content' => '本日営業', 'cta_type' => 'DANCE'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cta_type');
    }

    public function test_a_staff_member_cannot_publish(): void
    {
        $this->actingAs($this->member('staff'))
            ->postJson($this->url(), ['content' => '本日営業'])
            ->assertForbidden();

        $this->assertDatabaseCount('gbp_posts', 0);
    }

    public function test_a_plan_without_posting_cannot_reach_it(): void
    {
        $organization = $this->organizationOnPlan([Feature::GbpPostMonthlyLimit->value => 0]);
        $location = Location::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($this->member('org_admin', $organization))
            ->postJson($this->url($location), ['content' => '本日営業'])
            ->assertForbidden()
            ->assertJsonPath('feature', 'gbp.post.monthly_limit');
    }

    public function test_the_monthly_allowance_refuses_a_further_post(): void
    {
        app(UsageTracker::class)->record(Feature::GbpPostMonthlyLimit, 4, $this->organization);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['content' => '本日営業'])
            ->assertForbidden()
            ->assertJsonPath('feature', 'gbp.post.monthly_limit')
            ->assertJsonPath('limit', 4)
            ->assertJsonPath('used', 4);

        $this->assertDatabaseCount('gbp_posts', 0);
    }

    public function test_queueing_a_post_does_not_itself_spend_the_allowance(): void
    {
        Queue::fake();

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['content' => '本日営業'])
            ->assertAccepted();

        $this->assertSame(0, app(UsageTracker::class)->used(Feature::GbpPostMonthlyLimit, $this->organization));
    }

    public function test_the_job_publishes_the_post_and_records_what_google_called_it(): void
    {
        $this->connect();

        Http::fake(['mybusiness.googleapis.com/*' => Http::response([
            'name' => 'accounts/999/locations/1234567890/localPosts/555',
        ])]);

        $post = GbpPost::factory()->forLocation($this->location)->create(['content' => '本日営業']);

        $this->runJob($post);

        $post->refresh();

        $this->assertSame(GbpPostStatus::Published, $post->status);
        $this->assertSame('accounts/999/locations/1234567890/localPosts/555', $post->gbp_post_id);
        $this->assertNotNull($post->published_at);

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('/accounts/999/locations/1234567890/localPosts', $request->url());
            $this->assertSame('本日営業', $request->data()['summary']);

            return true;
        });
    }

    public function test_publishing_charges_the_monthly_allowance_once(): void
    {
        $this->connect();
        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['name' => 'localPosts/1'])]);

        $post = GbpPost::factory()->forLocation($this->location)->create();

        $this->runJob($post);

        $this->assertSame(1, app(UsageTracker::class)->used(Feature::GbpPostMonthlyLimit, $this->organization));
    }

    public function test_a_retry_does_not_publish_the_same_post_twice(): void
    {
        $this->connect();
        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['name' => 'localPosts/1'])]);

        $post = GbpPost::factory()->forLocation($this->location)->create();

        $this->runJob($post);
        $this->runJob($post->fresh());

        Http::assertSentCount(1);
        $this->assertSame(1, app(UsageTracker::class)->used(Feature::GbpPostMonthlyLimit, $this->organization));
    }

    public function test_the_job_fails_the_post_when_the_allowance_is_spent(): void
    {
        $this->connect();
        Http::fake();

        app(UsageTracker::class)->record(Feature::GbpPostMonthlyLimit, 4, $this->organization);

        $post = GbpPost::factory()->forLocation($this->location)->create();

        $this->runJob($post);

        $this->assertSame(GbpPostStatus::Failed, $post->fresh()->status);
        $this->assertStringContainsString('上限', $post->fresh()->failure_reason);
        Http::assertNothingSent();
    }

    public function test_the_job_fails_the_post_when_the_connection_is_gone(): void
    {
        $this->connect(['token_status' => GbpTokenStatus::Revoked]);
        Http::fake();

        $post = GbpPost::factory()->forLocation($this->location)->create();

        $this->runJob($post);

        $this->assertSame(GbpPostStatus::Failed, $post->fresh()->status);
        $this->assertStringContainsString('再接続', $post->fresh()->failure_reason);
    }

    public function test_the_job_runs_on_the_gbp_queue_and_retries_with_a_backoff(): void
    {
        $job = new PublishGbpPostJob(GbpPost::factory()->forLocation($this->location)->create());

        $this->assertSame('gbp', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());
    }

    public function test_an_exhausted_post_is_closed_off_rather_than_left_mid_publish(): void
    {
        $post = GbpPost::factory()->forLocation($this->location)->create();
        $post->claim();

        (new PublishGbpPostJob($post))->failed(new \RuntimeException('Googleが応答しませんでした。'));

        $this->assertSame(GbpPostStatus::Failed, $post->fresh()->status);
        $this->assertSame('Googleが応答しませんでした。', $post->fresh()->failure_reason);
    }

    public function test_a_member_lists_the_posts_newest_first(): void
    {
        $older = GbpPost::factory()->forLocation($this->location)->published()->create();
        $newer = GbpPost::factory()->forLocation($this->location)->failed()->create();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'posts')
            ->assertJsonPath('posts.0.id', $newer->id)
            ->assertJsonPath('posts.0.status', 'failed')
            ->assertJsonPath('posts.1.id', $older->id)
            ->assertJsonPath('posts.1.status', 'published');
    }

    public function test_posts_of_another_store_front_are_not_listed(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);

        GbpPost::factory()->forLocation($this->location)->create();
        GbpPost::factory()->forLocation($other)->create();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'posts');
    }

    public function test_a_store_front_of_another_organization_is_not_reachable(): void
    {
        $foreign = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url($foreign))
            ->assertNotFound();
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }
}
