<?php

namespace Tests\Feature;

use App\Enums\AiReplyStatus;
use App\Enums\Feature;
use App\Jobs\AiReplyGenerationJob;
use App\Jobs\AutoReplyReviewsJob;
use App\Jobs\PublishReviewReplyJob;
use App\Jobs\SyncReviewsJob;
use App\Models\GbpAccount;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Review;
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
class AutoReplyReviewsTest extends TestCase
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
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
        ]);

        $this->organization = $this->organizationOnPlan([
            Feature::ReviewAiReplyEnabled->value => true,
            Feature::ReviewAiReplyMonthlyLimit->value => 30,
            Feature::ReviewAutoReplyEnabled->value => true,
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

    protected function runSweep(?Location $location = null): void
    {
        (new AutoReplyReviewsJob($location ?? $this->location))->handle(
            app(FeatureResolver::class),
            app(UsageTracker::class),
            app(Tenancy::class),
        );
    }

    protected function fakeModelAndGoogle(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model' => 'claude-haiku-4-5',
                'content' => [['type' => 'text', 'text' => 'ご来店ありがとうございました。']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 40],
            ]),
            'mybusiness.googleapis.com/*' => Http::response(['comment' => 'ご来店ありがとうございました。']),
        ]);
    }

    public function test_the_sweep_queues_a_draft_for_every_unanswered_review(): void
    {
        Queue::fake();

        Review::factory()->count(3)->forLocation($this->location)->create();
        Review::factory()->forLocation($this->location)->answered()->create();

        $this->runSweep();

        Queue::assertPushed(AiReplyGenerationJob::class, 3);
    }

    public function test_a_review_that_already_has_a_draft_is_not_queued_again(): void
    {
        Queue::fake();

        Review::factory()->forLocation($this->location)->create([
            'ai_reply_status' => AiReplyStatus::AwaitingApproval,
        ]);

        $this->runSweep();

        Queue::assertNothingPushed();
    }

    public function test_a_plan_without_automatic_replies_is_left_alone(): void
    {
        Queue::fake();

        $organization = $this->organizationOnPlan([
            Feature::ReviewAiReplyEnabled->value => true,
            Feature::ReviewAiReplyMonthlyLimit->value => 30,
            Feature::ReviewAutoReplyEnabled->value => false,
        ]);
        $location = Location::factory()->create(['organization_id' => $organization->id]);
        Review::factory()->forLocation($location)->create();

        $this->runSweep($location);

        Queue::assertNothingPushed();
    }

    public function test_a_plan_without_ai_replies_at_all_is_left_alone(): void
    {
        Queue::fake();

        $organization = $this->organizationOnPlan([
            Feature::ReviewAiReplyEnabled->value => false,
            Feature::ReviewAutoReplyEnabled->value => true,
        ]);
        $location = Location::factory()->create(['organization_id' => $organization->id]);
        Review::factory()->forLocation($location)->create();

        $this->runSweep($location);

        Queue::assertNothingPushed();
    }

    public function test_the_sweep_queues_no_more_than_the_allowance_allows(): void
    {
        Queue::fake();

        // Thirty a month, twenty-eight already spent: only two more may go.
        app(UsageTracker::class)->record(Feature::ReviewAiReplyMonthlyLimit, 28, $this->organization);

        Review::factory()->count(10)->forLocation($this->location)->create();

        $this->runSweep();

        Queue::assertPushed(AiReplyGenerationJob::class, 2);
    }

    public function test_a_spent_allowance_queues_nothing(): void
    {
        Queue::fake();

        app(UsageTracker::class)->record(Feature::ReviewAiReplyMonthlyLimit, 30, $this->organization);
        Review::factory()->count(5)->forLocation($this->location)->create();

        $this->runSweep();

        Queue::assertNothingPushed();
    }

    public function test_a_suspended_organization_is_left_alone(): void
    {
        Queue::fake();

        $this->organization->update(['status' => Organization::STATUS_SUSPENDED]);
        Review::factory()->forLocation($this->location)->create();

        $this->runSweep();

        Queue::assertNothingPushed();
    }

    public function test_the_review_sync_hands_the_store_front_to_the_sweep(): void
    {
        Queue::fake();

        GbpAccount::factory()->forLocation($this->location)->create(['gbp_account_name' => 'accounts/999']);

        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['reviews' => []])]);

        (new SyncReviewsJob($this->location))->handle(
            app(GBPClientFactory::class),
            app(Tenancy::class),
        );

        Queue::assertPushed(
            AutoReplyReviewsJob::class,
            fn (AutoReplyReviewsJob $job) => $job->location->is($this->location),
        );
    }

    public function test_a_review_that_arrives_overnight_is_answered_end_to_end(): void
    {
        GbpAccount::factory()->forLocation($this->location)->create(['gbp_account_name' => 'accounts/999']);
        $this->fakeModelAndGoogle();

        $review = Review::factory()->forLocation($this->location)->rated(5)->create([
            'google_review_id' => 'review-1',
        ]);

        // The sweep hands it to generation, which on this plan approves it and
        // hands it to publishing; the queue runs inline in tests.
        $this->runSweep();

        $review->refresh();

        $this->assertSame(AiReplyStatus::Published, $review->ai_reply_status);
        $this->assertSame('ご来店ありがとうございました。', $review->reply);
        $this->assertTrue($review->isAnswered());
        $this->assertSame(1, app(UsageTracker::class)->used(Feature::ReviewAiReplyMonthlyLimit, $this->organization));
    }

    public function test_the_generation_job_publishes_without_waiting_for_a_person_on_this_plan(): void
    {
        Queue::fake();
        $this->fakeModelAndGoogle();

        $review = Review::factory()->forLocation($this->location)->create();

        (new AiReplyGenerationJob($review))->handle(
            app(AIProviderFactory::class),
            app(ReviewReplyPrompt::class),
            app(FeatureResolver::class),
            app(UsageTracker::class),
            app(Tenancy::class),
        );

        $this->assertSame(AiReplyStatus::Approved, $review->fresh()->ai_reply_status);
        Queue::assertPushed(PublishReviewReplyJob::class, 1);
    }

    public function test_the_job_runs_on_the_ai_queue(): void
    {
        $this->assertSame('ai', (new AutoReplyReviewsJob($this->location))->queue);
    }

    public function test_the_job_leaves_the_tenant_as_it_found_it(): void
    {
        Queue::fake();

        $this->runSweep();

        $this->assertFalse(app(Tenancy::class)->check());
    }

    public function test_reviews_of_another_store_front_are_not_swept(): void
    {
        Queue::fake();

        $other = Location::factory()->create(['organization_id' => $this->organization->id]);
        Review::factory()->forLocation($other)->create();

        $this->runSweep();

        Queue::assertNothingPushed();
    }
}
