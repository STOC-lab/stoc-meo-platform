<?php

namespace Tests\Feature;

use App\Enums\AiReplyStatus;
use App\Enums\Feature;
use App\Enums\GbpTokenStatus;
use App\Jobs\AiReplyGenerationJob;
use App\Jobs\PublishReviewReplyJob;
use App\Models\GbpAccount;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Review;
use App\Models\User;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\Prompts\ReviewReplyPrompt;
use App\Services\FeatureResolver;
use App\Services\GBP\GBPClientFactory;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every model and Google call in this file is faked; nothing reaches either.
 */
class AiReviewReplyTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected Review $review;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'ai.claude.api_key' => 'sk-ant-test',
            'ai.claude.models' => ['fast' => 'claude-haiku-4-5', 'strong' => 'claude-sonnet-4-6'],
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
        ]);

        $this->organization = $this->organizationOnPlan([
            Feature::ReviewAiReplyEnabled->value => true,
            Feature::ReviewAiReplyMonthlyLimit->value => 5,
            Feature::ReviewAutoReplyEnabled->value => false,
        ]);

        $this->location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => 'locations/1234567890',
        ]);

        $this->review = Review::factory()->forLocation($this->location)->rated(2)->create([
            'comment' => '待ち時間が長かったです。',
            'google_review_id' => 'review-1',
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

    protected function connectGbp(array $state = []): GbpAccount
    {
        return GbpAccount::factory()
            ->forLocation($this->location)
            ->create($state + ['gbp_account_name' => 'accounts/999']);
    }

    protected function fakeModel(string $text = 'ご不便をおかけし申し訳ありません。改善に努めます。'): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-haiku-4-5',
                'content' => [['type' => 'text', 'text' => $text]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
            ]),
            'mybusiness.googleapis.com/*' => Http::response(['comment' => $text]),
        ]);
    }

    protected function runGeneration(?Review $review = null): void
    {
        (new AiReplyGenerationJob($review ?? $this->review))->handle(
            app(AIProviderFactory::class),
            app(ReviewReplyPrompt::class),
            app(FeatureResolver::class),
            app(UsageTracker::class),
            app(Tenancy::class),
        );
    }

    protected function runPublish(?Review $review = null): void
    {
        (new PublishReviewReplyJob($review ?? $this->review))->handle(
            app(GBPClientFactory::class),
            app(Tenancy::class),
        );
    }

    protected function url(string $path = ''): string
    {
        return "/api/v1/locations/{$this->location->id}/reviews/{$this->review->id}/ai-reply".$path;
    }

    public function test_a_draft_waits_for_a_person_rather_than_going_to_google(): void
    {
        $this->fakeModel();

        $this->runGeneration();

        $review = $this->review->fresh();

        $this->assertSame(AiReplyStatus::AwaitingApproval, $review->ai_reply_status);
        $this->assertSame('ご不便をおかけし申し訳ありません。改善に努めます。', $review->ai_reply);
        $this->assertSame('claude-haiku-4-5', $review->ai_reply_model);

        // The draft is not the store front's answer until someone approves it.
        $this->assertNull($review->reply);
        $this->assertFalse($review->isAnswered());

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'mybusiness.googleapis.com'));
    }

    public function test_the_prompt_carries_the_review_and_the_rules(): void
    {
        $this->fakeModel();

        $this->runGeneration();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.anthropic.com')) {
                return true;
            }

            $body = $request->data();

            $this->assertStringContainsString('待ち時間が長かったです。', $body['messages'][0]['content']);
            $this->assertStringContainsString('2 / 5', $body['messages'][0]['content']);
            $this->assertStringContainsString('事実を作らない', $body['system']);

            return true;
        });
    }

    public function test_generating_charges_the_monthly_allowance_once(): void
    {
        $this->fakeModel();

        $this->runGeneration();
        $this->runGeneration();

        $this->assertSame(1, app(UsageTracker::class)->used(Feature::ReviewAiReplyMonthlyLimit, $this->organization));
    }

    public function test_the_job_fails_the_draft_when_the_allowance_is_spent(): void
    {
        Http::fake();
        app(UsageTracker::class)->record(Feature::ReviewAiReplyMonthlyLimit, 5, $this->organization);

        $this->runGeneration();

        $this->assertSame(AiReplyStatus::Failed, $this->review->fresh()->ai_reply_status);
        $this->assertStringContainsString('上限', $this->review->fresh()->ai_reply_error);
        Http::assertNothingSent();
    }

    public function test_a_model_that_declines_ends_the_work_rather_than_retrying(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-haiku-4-5',
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'other'],
        ])]);

        $this->runGeneration();

        $review = $this->review->fresh();

        $this->assertSame(AiReplyStatus::Failed, $review->ai_reply_status);
        $this->assertStringContainsString('手動で返信', $review->ai_reply_error);
    }

    public function test_a_plan_with_automatic_replies_skips_the_person(): void
    {
        Queue::fake();
        $this->fakeModel();

        $organization = $this->organizationOnPlan([
            Feature::ReviewAiReplyEnabled->value => true,
            Feature::ReviewAiReplyMonthlyLimit->value => 5,
            Feature::ReviewAutoReplyEnabled->value => true,
        ]);
        $location = Location::factory()->create([
            'organization_id' => $organization->id,
            'gbp_location_id' => 'locations/2',
        ]);
        $review = Review::factory()->forLocation($location)->create();

        $this->runGeneration($review);

        $this->assertSame(AiReplyStatus::Approved, $review->fresh()->ai_reply_status);
        Queue::assertPushed(PublishReviewReplyJob::class, 1);
    }

    public function test_publishing_an_approved_draft_makes_it_the_real_reply(): void
    {
        $this->connectGbp();
        $this->fakeModel();

        $this->runGeneration();

        $this->review->fresh()->forceFill(['ai_reply_status' => AiReplyStatus::Approved])->save();

        $this->runPublish($this->review->fresh());

        $review = $this->review->fresh();

        $this->assertSame(AiReplyStatus::Published, $review->ai_reply_status);
        $this->assertSame($review->ai_reply, $review->reply);
        $this->assertTrue($review->isAnswered());
    }

    public function test_a_broken_google_connection_leaves_the_review_unanswered(): void
    {
        $this->connectGbp(['token_status' => GbpTokenStatus::Revoked]);
        Http::fake();

        $this->review->forceFill([
            'ai_reply' => '返信文',
            'ai_reply_status' => AiReplyStatus::Approved,
        ])->save();

        $this->runPublish($this->review->fresh());

        $review = $this->review->fresh();

        $this->assertSame(AiReplyStatus::Failed, $review->ai_reply_status);
        $this->assertNull($review->reply);
        $this->assertStringContainsString('再接続', $review->ai_reply_error);
    }

    public function test_a_draft_that_is_not_approved_is_never_published(): void
    {
        Http::fake();

        $this->review->forceFill([
            'ai_reply' => '返信文',
            'ai_reply_status' => AiReplyStatus::AwaitingApproval,
        ])->save();

        $this->runPublish($this->review->fresh());

        Http::assertNothingSent();
        $this->assertNull($this->review->fresh()->reply);
    }

    public function test_the_jobs_run_on_their_own_queues(): void
    {
        $this->assertSame('ai', (new AiReplyGenerationJob($this->review))->queue);
        $this->assertSame('gbp', (new PublishReviewReplyJob($this->review))->queue);
    }

    public function test_a_staff_member_asks_for_a_draft(): void
    {
        Queue::fake();

        $this->actingAs($this->member('staff'))
            ->postJson($this->url())
            ->assertAccepted()
            ->assertJsonPath('allowance.limit', 5);

        Queue::assertPushed(AiReplyGenerationJob::class, 1);
    }

    public function test_the_endpoint_refuses_when_the_allowance_is_spent(): void
    {
        Queue::fake();
        app(UsageTracker::class)->record(Feature::ReviewAiReplyMonthlyLimit, 5, $this->organization);

        $this->actingAs($this->member('staff'))
            ->postJson($this->url())
            ->assertForbidden()
            ->assertJsonPath('feature', 'review.ai_reply.monthly_limit');

        Queue::assertNothingPushed();
    }

    public function test_a_draft_already_being_written_is_not_asked_for_twice(): void
    {
        Queue::fake();
        $this->review->forceFill(['ai_reply_status' => AiReplyStatus::Generating])->save();

        $this->actingAs($this->member('staff'))
            ->postJson($this->url())
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_a_plan_without_ai_replies_cannot_reach_the_endpoint(): void
    {
        $organization = $this->organizationOnPlan([Feature::ReviewAiReplyEnabled->value => false]);
        $location = Location::factory()->create(['organization_id' => $organization->id]);
        $review = Review::factory()->forLocation($location)->create();

        $this->actingAs($this->member('org_admin', $organization))
            ->postJson("/api/v1/locations/{$location->id}/reviews/{$review->id}/ai-reply")
            ->assertForbidden()
            ->assertJsonPath('feature', 'review.ai_reply.enabled');
    }

    public function test_a_viewer_cannot_ask_for_a_draft(): void
    {
        $this->actingAs($this->member('viewer'))
            ->postJson($this->url())
            ->assertForbidden();
    }

    public function test_approving_a_draft_queues_it_for_google(): void
    {
        Queue::fake();

        $this->review->forceFill([
            'ai_reply' => '返信文',
            'ai_reply_status' => AiReplyStatus::AwaitingApproval,
        ])->save();

        $this->actingAs($this->member('staff'))
            ->postJson($this->url('/approve'))
            ->assertAccepted()
            ->assertJsonPath('review.ai_reply_status', 'approved');

        Queue::assertPushed(PublishReviewReplyJob::class, 1);
        $this->assertNotNull($this->review->fresh()->ai_reply_approved_at);
    }

    public function test_a_draft_that_is_not_awaiting_approval_cannot_be_approved(): void
    {
        Queue::fake();

        $this->actingAs($this->member('staff'))
            ->postJson($this->url('/approve'))
            ->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_the_list_shows_the_draft_beside_the_real_reply(): void
    {
        $this->review->forceFill([
            'ai_reply' => 'AIの下書き',
            'ai_reply_status' => AiReplyStatus::AwaitingApproval,
        ])->save();

        $this->actingAs($this->member('viewer'))
            ->getJson("/api/v1/locations/{$this->location->id}/reviews")
            ->assertOk()
            ->assertJsonPath('reviews.0.ai_reply', 'AIの下書き')
            ->assertJsonPath('reviews.0.ai_reply_awaiting_approval', true)
            ->assertJsonPath('reviews.0.answered', false);
    }
}
