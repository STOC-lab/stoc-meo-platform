<?php

namespace Tests\Feature;

use App\Enums\CampaignChannel;
use App\Enums\CampaignPostStatus;
use App\Enums\CampaignType;
use App\Enums\Feature;
use App\Enums\InstagramTokenStatus;
use App\Jobs\InstagramPublishJob;
use App\Jobs\PublishCampaignPostJob;
use App\Models\ContentCampaign;
use App\Models\ContentCampaignPost;
use App\Models\GbpAccount;
use App\Models\InstagramAccount;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Services\GBP\GBPClientFactory;
use App\Services\Instagram\Exceptions\InstagramException;
use App\Services\Instagram\InstagramClient;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every Instagram and Google call in this file is faked; nothing reaches
 * either.
 */
class InstagramPublishTest extends TestCase
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

        $this->organization = $this->organizationOnPlan([
            Feature::InstagramEnabled->value => true,
            Feature::InstagramPostMonthlyLimit->value => 4,
            Feature::GbpPostMonthlyLimit->value => 4,
        ]);

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

    protected function connectInstagram(array $state = []): InstagramAccount
    {
        return InstagramAccount::factory()->forLocation($this->location)->create($state);
    }

    protected function campaignPost(array $postState = [], array $campaignState = []): ContentCampaignPost
    {
        $campaign = ContentCampaign::factory()->forLocation($this->location)->create($campaignState);

        return ContentCampaignPost::factory()
            ->forCampaign($campaign)
            ->channel(CampaignChannel::Instagram)
            ->approved('秋のメニューを始めました。')
            ->create($postState);
    }

    /**
     * Meta publishes in two calls: a container, then the publish.
     */
    protected function fakeInstagram(): void
    {
        Http::fakeSequence('graph.facebook.com/*')
            ->push(['id' => 'container-1'])
            ->push(['id' => 'media-1']);
    }

    protected function runJob(ContentCampaignPost $post): void
    {
        (new InstagramPublishJob($post))->handle(
            app(InstagramClient::class),
            app(UsageTracker::class),
            app(Tenancy::class),
        );
    }

    public function test_publishing_creates_a_container_and_then_publishes_it(): void
    {
        $this->connectInstagram(['ig_user_id' => '17841400000000000']);
        $this->fakeInstagram();

        $post = $this->campaignPost();

        $this->runJob($post);

        $post->refresh();

        $this->assertSame(CampaignPostStatus::Published, $post->status);
        $this->assertSame('media-1', $post->platform_post_id);
        $this->assertNotNull($post->published_at);

        Http::assertSentCount(2);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/media')) {
                return false;
            }

            if (str_contains($request->url(), '/media_publish')) {
                $this->assertSame('container-1', $request->data()['creation_id']);

                return true;
            }

            $this->assertSame('秋のメニューを始めました。', $request->data()['caption']);
            $this->assertNotEmpty($request->data()['image_url']);

            return true;
        });
    }

    public function test_the_token_is_stored_encrypted_and_never_serialised(): void
    {
        $account = $this->connectInstagram(['access_token_encrypted' => 'IGQplain']);

        $stored = (string) \DB::table('instagram_accounts')->where('id', $account->id)->value('access_token_encrypted');

        $this->assertStringNotContainsString('IGQplain', $stored);
        $this->assertSame('IGQplain', $account->fresh()->accessToken());
        $this->assertArrayNotHasKey('access_token_encrypted', $account->toArray());
    }

    public function test_publishing_charges_the_monthly_allowance_once(): void
    {
        $this->connectInstagram();
        $this->fakeInstagram();

        $post = $this->campaignPost();

        $this->runJob($post);

        $this->assertSame(1, app(UsageTracker::class)->used(Feature::InstagramPostMonthlyLimit, $this->organization));
    }

    public function test_a_retry_does_not_publish_the_same_post_twice(): void
    {
        $this->connectInstagram();
        $this->fakeInstagram();

        $post = $this->campaignPost();

        $this->runJob($post);
        $this->runJob($post->fresh());

        Http::assertSentCount(2);
        $this->assertSame(1, app(UsageTracker::class)->used(Feature::InstagramPostMonthlyLimit, $this->organization));
    }

    public function test_the_post_fails_when_the_allowance_is_spent(): void
    {
        $this->connectInstagram();
        Http::fake();
        app(UsageTracker::class)->record(Feature::InstagramPostMonthlyLimit, 4, $this->organization);

        $post = $this->campaignPost();

        $this->runJob($post);

        $this->assertSame(CampaignPostStatus::Failed, $post->fresh()->status);
        $this->assertStringContainsString('上限', $post->fresh()->last_error);
        Http::assertNothingSent();
    }

    public function test_a_post_without_an_image_cannot_be_published(): void
    {
        $this->connectInstagram();
        Http::fake();

        $post = $this->campaignPost([], ['source_image_path' => null]);

        $this->runJob($post);

        $this->assertSame(CampaignPostStatus::Failed, $post->fresh()->status);
        $this->assertStringContainsString('画像', $post->fresh()->last_error);
        Http::assertNothingSent();
    }

    public function test_an_unconnected_store_front_cannot_publish(): void
    {
        Http::fake();

        $post = $this->campaignPost();

        $this->runJob($post);

        $this->assertSame(CampaignPostStatus::Failed, $post->fresh()->status);
        $this->assertStringContainsString('接続', $post->fresh()->last_error);
    }

    public function test_a_token_meta_has_withdrawn_marks_the_connection_and_stops(): void
    {
        $account = $this->connectInstagram();

        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Session has expired', 'code' => 190, 'type' => 'OAuthException'],
        ], 400)]);

        $post = $this->campaignPost();

        $this->runJob($post);

        $this->assertSame(InstagramTokenStatus::Expired, $account->fresh()->token_status);
        $this->assertSame(CampaignPostStatus::Failed, $post->fresh()->status);
        $this->assertStringContainsString('再接続', $post->fresh()->last_error);
    }

    public function test_an_ordinary_instagram_failure_is_counted_and_retried(): void
    {
        $this->connectInstagram();

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Server error']], 500)]);

        $post = $this->campaignPost();

        try {
            $this->runJob($post);
            $this->fail('A server error should have been raised for the queue to retry.');
        } catch (InstagramException $e) {
            // expected: the queue retries it
        }

        $this->assertSame(1, $post->fresh()->retry_count);
        $this->assertSame(CampaignPostStatus::Publishing, $post->fresh()->status);
    }

    public function test_an_instagram_failure_leaves_the_business_profile_post_alone(): void
    {
        $this->connectInstagram();
        GbpAccount::factory()->forLocation($this->location)->create(['gbp_account_name' => 'accounts/999']);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom', 'code' => 190]], 400),
            'mybusiness.googleapis.com/*' => Http::response(['name' => 'localPosts/1']),
        ]);

        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();
        $instagram = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Instagram)->approved()->create();
        $gbp = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Gbp)->approved()->create();

        $this->runJob($instagram);

        (new PublishCampaignPostJob($gbp))->handle(
            app(GBPClientFactory::class),
            app(UsageTracker::class),
            app(Tenancy::class),
        );

        $this->assertSame(CampaignPostStatus::Failed, $instagram->fresh()->status);
        $this->assertSame(CampaignPostStatus::Published, $gbp->fresh()->status);
    }

    public function test_a_wordpress_post_says_it_is_not_supported_yet(): void
    {
        Http::fake();

        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();
        $post = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Wordpress)->approved()->create();

        (new PublishCampaignPostJob($post))->handle(
            app(GBPClientFactory::class),
            app(UsageTracker::class),
            app(Tenancy::class),
        );

        $this->assertSame(CampaignPostStatus::Failed, $post->fresh()->status);
        $this->assertStringContainsString('WordPress', $post->fresh()->last_error);
        Http::assertNothingSent();
    }

    public function test_the_jobs_run_on_the_social_queue_and_retry_with_a_backoff(): void
    {
        $post = $this->campaignPost();

        $this->assertSame('social', (new InstagramPublishJob($post))->queue);
        $this->assertSame(3, (new InstagramPublishJob($post))->tries);
        $this->assertSame([60, 300, 900], (new InstagramPublishJob($post))->backoff());
        $this->assertSame('social', (new PublishCampaignPostJob($post))->queue);
    }

    public function test_the_job_leaves_the_tenant_as_it_found_it(): void
    {
        $this->connectInstagram();
        $this->fakeInstagram();

        $this->runJob($this->campaignPost());

        $this->assertFalse(app(Tenancy::class)->check());
    }

    public function test_the_weekly_sweep_queues_approved_posts_of_recurring_campaigns(): void
    {
        Queue::fake();
        $this->connectInstagram();

        // Approved and recurring: swept.
        $this->campaignPost([], ['campaign_type' => CampaignType::Recurring]);

        // Recurring but still waiting on a person: not swept.
        $campaign = ContentCampaign::factory()->forLocation($this->location)
            ->recurring()->create();
        ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Instagram)->awaitingApproval()->create();

        // Approved but a one-off campaign: not swept.
        $this->campaignPost();

        $this->artisan('instagram:publish-weekly')->assertSuccessful();

        Queue::assertPushed(InstagramPublishJob::class, 1);
    }

    public function test_the_weekly_sweep_skips_a_plan_without_instagram(): void
    {
        Queue::fake();

        $organization = $this->organizationOnPlan([Feature::InstagramEnabled->value => false]);
        $location = Location::factory()->create(['organization_id' => $organization->id]);
        $campaign = ContentCampaign::factory()->forLocation($location)->recurring()->create();
        ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Instagram)->approved()->create();

        $this->artisan('instagram:publish-weekly')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
