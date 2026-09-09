<?php

namespace Tests\Feature;

use App\Enums\CampaignChannel;
use App\Enums\CampaignPostStatus;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Jobs\GenerateCampaignContentJob;
use App\Jobs\InstagramPublishJob;
use App\Jobs\PublishCampaignPostJob;
use App\Models\ContentCampaign;
use App\Models\ContentCampaignPost;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\Prompts\CampaignContentPrompt;
use App\Support\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every model call in this file is faked; nothing here reaches Anthropic.
 */
class ContentCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'ai.claude.api_key' => 'sk-ant-test',
            'ai.claude.models' => ['fast' => 'claude-haiku-4-5', 'strong' => 'claude-sonnet-4-6'],
        ]);

        $this->organization = Organization::factory()
            ->onPlan(Plan::factory()->withFeatures([])->create())
            ->create();

        $this->location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => 'locations/1234567890',
        ]);
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    protected function url(string $path = '', ?Location $location = null): string
    {
        return '/api/v1/locations/'.($location ?? $this->location)->id.'/campaigns'.$path;
    }

    protected function fakeModel(string $text): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-haiku-4-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 60],
        ])]);
    }

    protected function runGeneration(ContentCampaignPost $post): void
    {
        (new GenerateCampaignContentJob($post))->handle(
            app(AIProviderFactory::class),
            app(CampaignContentPrompt::class),
            app(Tenancy::class),
        );
    }

    public function test_a_store_manager_creates_a_campaign_with_a_post_per_channel(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), [
                'name' => '秋の新メニュー',
                'theme' => '新しく始めた秋限定のメニューを紹介する',
                'campaign_type' => 'manual',
                'channels' => ['instagram', 'gbp'],
            ])
            ->assertCreated()
            ->assertJsonPath('campaign.name', '秋の新メニュー')
            ->assertJsonPath('campaign.status', 'draft')
            ->assertJsonCount(2, 'campaign.posts');

        $campaign = ContentCampaign::acrossTenants()->firstOrFail();

        $this->assertSame($this->organization->id, $campaign->organization_id);
        $this->assertSame(2, $campaign->posts()->count());
        $this->assertNotNull($campaign->posts()->first()->idempotency_key);
    }

    public function test_a_scheduled_campaign_has_to_say_when(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), [
                'name' => '予約投稿',
                'campaign_type' => 'scheduled',
                'channels' => ['gbp'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');
    }

    public function test_at_least_one_channel_is_required(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), [
                'name' => 'どこにも出さない',
                'campaign_type' => 'manual',
                'channels' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('channels');
    }

    public function test_a_staff_member_cannot_create_a_campaign(): void
    {
        $this->actingAs($this->member('staff'))
            ->postJson($this->url(), [
                'name' => 'テスト',
                'campaign_type' => 'manual',
                'channels' => ['gbp'],
            ])
            ->assertForbidden();
    }

    public function test_generation_writes_a_caption_per_channel_and_waits_for_a_person(): void
    {
        $this->fakeModel("秋のメニューを始めました。\n#カフェ #秋メニュー #渋谷");

        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();
        $post = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Instagram)->create();

        $this->runGeneration($post);

        $post->refresh();

        $this->assertSame(CampaignPostStatus::AwaitingApproval, $post->status);
        $this->assertStringContainsString('秋のメニュー', $post->ai_content);
        $this->assertSame(['#カフェ', '#秋メニュー', '#渋谷'], $post->ai_hashtags);
        $this->assertNotNull($post->ai_prompt);
    }

    public function test_hashtags_are_only_pulled_out_for_the_channel_that_uses_them(): void
    {
        $this->fakeModel('本日も営業しています。 #カフェ');

        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();
        $post = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Gbp)->create();

        $this->runGeneration($post);

        $this->assertSame([], $post->fresh()->ai_hashtags);
    }

    public function test_the_prompt_matches_the_channel_it_is_writing_for(): void
    {
        $this->fakeModel('本文');

        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();
        $post = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Gbp)->create();

        $this->runGeneration($post);

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('Googleビジネスプロフィール', $request->data()['system']);
            $this->assertStringContainsString('ハッシュタグは使わない', $request->data()['system']);

            return true;
        });
    }

    public function test_generating_twice_does_not_run_the_model_twice_for_one_post(): void
    {
        $this->fakeModel('本文');

        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();
        $post = ContentCampaignPost::factory()->forCampaign($campaign)->create();

        $this->runGeneration($post);
        $this->runGeneration($post->fresh());

        Http::assertSentCount(1);
    }

    public function test_a_model_that_declines_fails_only_that_channels_post(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-haiku-4-5',
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'other'],
        ])]);

        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();
        $instagram = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Instagram)->create();
        $gbp = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Gbp)->create();

        $this->runGeneration($instagram);

        $this->assertSame(CampaignPostStatus::Failed, $instagram->fresh()->status);
        // The other channel is untouched.
        $this->assertSame(CampaignPostStatus::Pending, $gbp->fresh()->status);
    }

    public function test_the_generate_endpoint_queues_every_unwritten_post(): void
    {
        Queue::fake();

        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();
        ContentCampaignPost::factory()->forCampaign($campaign)->channel(CampaignChannel::Instagram)->create();
        ContentCampaignPost::factory()->forCampaign($campaign)->channel(CampaignChannel::Gbp)->published()->create();

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url("/{$campaign->id}/generate"))
            ->assertAccepted()
            ->assertJsonPath('queued', 1)
            ->assertJsonPath('campaign.status', 'active');

        Queue::assertPushed(GenerateCampaignContentJob::class, 1);
    }

    public function test_a_cancelled_campaign_cannot_be_generated(): void
    {
        Queue::fake();

        $campaign = ContentCampaign::factory()->forLocation($this->location)->cancelled()->create();
        ContentCampaignPost::factory()->forCampaign($campaign)->create();

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url("/{$campaign->id}/generate"))
            ->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_approving_a_post_hands_it_to_its_own_channel_job(): void
    {
        Queue::fake();

        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();
        $instagram = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Instagram)->awaitingApproval()->create();
        $gbp = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Gbp)->awaitingApproval()->create();

        $member = $this->member('location_admin');

        $this->actingAs($member)
            ->postJson($this->url("/{$campaign->id}/posts/{$instagram->id}/approve"))
            ->assertAccepted()
            ->assertJsonPath('post.status', 'approved');

        $this->actingAs($member)
            ->postJson($this->url("/{$campaign->id}/posts/{$gbp->id}/approve"))
            ->assertAccepted();

        Queue::assertPushed(InstagramPublishJob::class, 1);
        Queue::assertPushed(PublishCampaignPostJob::class, 1);

        $this->assertSame($member->id, $instagram->fresh()->approved_by_user_id);
    }

    public function test_a_post_that_is_not_awaiting_approval_cannot_be_approved(): void
    {
        Queue::fake();

        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();
        $post = ContentCampaignPost::factory()->forCampaign($campaign)->create();

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url("/{$campaign->id}/posts/{$post->id}/approve"))
            ->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_a_post_of_another_campaign_is_not_approvable_through_this_one(): void
    {
        Queue::fake();

        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();
        $other = ContentCampaign::factory()->forLocation($this->location)->create();
        $post = ContentCampaignPost::factory()->forCampaign($other)->awaitingApproval()->create();

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url("/{$campaign->id}/posts/{$post->id}/approve"))
            ->assertNotFound();
    }

    public function test_a_viewer_cannot_approve(): void
    {
        Queue::fake();

        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();
        $post = ContentCampaignPost::factory()->forCampaign($campaign)->awaitingApproval()->create();

        $this->actingAs($this->member('viewer'))
            ->postJson($this->url("/{$campaign->id}/posts/{$post->id}/approve"))
            ->assertForbidden();
    }

    public function test_cancelling_a_campaign_stops_its_unpublished_posts(): void
    {
        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();
        $pending = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Instagram)->create();
        $published = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Gbp)->published()->create();

        $this->actingAs($this->member('location_admin'))
            ->deleteJson($this->url("/{$campaign->id}"))
            ->assertNoContent();

        $this->assertSame(CampaignStatus::Cancelled, $campaign->fresh()->status);
        $this->assertSame(CampaignPostStatus::Cancelled, $pending->fresh()->status);
        // What is already out in the world stays as it is.
        $this->assertSame(CampaignPostStatus::Published, $published->fresh()->status);
    }

    public function test_a_campaign_closes_itself_once_every_post_has_settled(): void
    {
        $campaign = ContentCampaign::factory()->forLocation($this->location)->active()->create();
        $first = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Instagram)->approved()->create();
        $second = ContentCampaignPost::factory()->forCampaign($campaign)
            ->channel(CampaignChannel::Gbp)->approved()->create();

        $first->markPublished('ig-1');
        $this->assertSame(CampaignStatus::Active, $campaign->fresh()->status);

        $second->markFailed('接続エラー');
        $this->assertSame(CampaignStatus::Completed, $campaign->fresh()->status);
    }

    public function test_a_campaign_posts_to_a_channel_only_once(): void
    {
        $campaign = ContentCampaign::factory()->forLocation($this->location)->create();

        $campaign->addChannels([CampaignChannel::Instagram, CampaignChannel::Gbp]);
        $campaign->addChannels([CampaignChannel::Instagram, CampaignChannel::Wordpress]);

        $this->assertSame(3, $campaign->posts()->count());
    }

    public function test_a_member_lists_the_campaigns(): void
    {
        ContentCampaign::factory()->forLocation($this->location)->count(2)->create();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'campaigns');
    }

    public function test_campaigns_of_another_organization_are_not_reachable(): void
    {
        $foreign = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('', $foreign))
            ->assertNotFound();
    }

    public function test_the_weekly_sweep_is_scheduled_for_tuesday_morning_tokyo_time(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'instagram:publish-weekly'));

        $this->assertCount(1, $events);
        $this->assertSame('0 10 * * 2', $events->first()->expression);
        $this->assertSame('Asia/Tokyo', $events->first()->timezone);
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }

    public function test_campaign_types_and_channels_carry_their_own_limits(): void
    {
        $this->assertSame(2200, CampaignChannel::Instagram->contentLimit());
        $this->assertSame(1500, CampaignChannel::Gbp->contentLimit());
        $this->assertTrue(CampaignChannel::Instagram->usesHashtags());
        $this->assertFalse(CampaignChannel::Gbp->usesHashtags());
        $this->assertTrue(CampaignType::Recurring instanceof CampaignType);
    }
}
