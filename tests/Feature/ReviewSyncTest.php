<?php

namespace Tests\Feature;

use App\Enums\GbpTokenStatus;
use App\Jobs\SyncReviewsJob;
use App\Models\GbpAccount;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use App\Services\GBP\GBPClientFactory;
use App\Support\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every Google call in this file is faked; nothing here reaches the network.
 */
class ReviewSyncTest extends TestCase
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

        $this->organization = Organization::factory()->create();
        $this->location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => 'locations/1234567890',
        ]);
    }

    protected function connect(array $state = []): GbpAccount
    {
        return GbpAccount::factory()
            ->forLocation($this->location)
            ->create($state + ['gbp_account_name' => 'accounts/999']);
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    /**
     * @param  array<int, array<string, mixed>>  $reviews
     */
    protected function fakeReviews(array $reviews): void
    {
        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['reviews' => $reviews])]);
    }

    protected function runSync(): void
    {
        (new SyncReviewsJob($this->location))->handle(
            app(GBPClientFactory::class),
            app(Tenancy::class),
        );
    }

    public function test_the_sync_copies_a_review_down(): void
    {
        $this->connect();

        $this->fakeReviews([[
            'reviewId' => 'review-1',
            'reviewer' => ['displayName' => '山田 太郎', 'profilePhotoUrl' => 'https://example.com/p.jpg'],
            'starRating' => 'FOUR',
            'comment' => 'とても良いお店でした。',
            'createTime' => '2026-08-15T10:00:00Z',
        ]]);

        $this->runSync();

        $review = Review::acrossTenants()->firstOrFail();

        $this->assertSame($this->organization->id, $review->organization_id);
        $this->assertSame($this->location->id, $review->location_id);
        $this->assertSame('review-1', $review->google_review_id);
        $this->assertSame('山田 太郎', $review->author_name);
        $this->assertSame(4, $review->rating);
        $this->assertSame('とても良いお店でした。', $review->comment);
        $this->assertFalse($review->isAnswered());
        $this->assertSame('2026-08-15', $review->reviewed_at->toDateString());
    }

    public function test_google_star_names_become_numbers(): void
    {
        $this->connect();

        $this->fakeReviews([
            ['reviewId' => 'a', 'starRating' => 'ONE'],
            ['reviewId' => 'b', 'starRating' => 'FIVE'],
            ['reviewId' => 'c', 'starRating' => 'STAR_RATING_UNSPECIFIED'],
        ]);

        $this->runSync();

        $ratings = Review::acrossTenants()->orderBy('google_review_id')->pluck('rating', 'google_review_id');

        $this->assertSame(1, $ratings['a']);
        $this->assertSame(5, $ratings['b']);
        $this->assertNull($ratings['c']);
    }

    public function test_a_reply_left_on_google_arrives_with_the_review(): void
    {
        $this->connect();

        $this->fakeReviews([[
            'reviewId' => 'review-1',
            'starRating' => 'FIVE',
            'reviewReply' => ['comment' => 'ありがとうございます。', 'updateTime' => '2026-08-16T09:00:00Z'],
        ]]);

        $this->runSync();

        $review = Review::acrossTenants()->firstOrFail();

        $this->assertSame('ありがとうございます。', $review->reply);
        $this->assertTrue($review->isAnswered());
    }

    public function test_syncing_twice_updates_the_review_rather_than_duplicating_it(): void
    {
        $this->connect();

        // A sequence rather than two fakes: a second Http::fake() adds a stub
        // behind the first rather than replacing it, so the first would answer
        // both calls.
        Http::fakeSequence('mybusiness.googleapis.com/*')
            ->push(['reviews' => [['reviewId' => 'review-1', 'starRating' => 'THREE', 'comment' => '普通']]])
            ->push(['reviews' => [['reviewId' => 'review-1', 'starRating' => 'FIVE', 'comment' => '見直しました']]]);

        $this->runSync();
        $this->runSync();

        $this->assertSame(1, Review::acrossTenants()->count());

        $review = Review::acrossTenants()->firstOrFail();

        $this->assertSame(5, $review->rating);
        $this->assertSame('見直しました', $review->comment);
    }

    public function test_the_sync_records_when_it_last_ran(): void
    {
        $account = $this->connect();
        $this->fakeReviews([]);

        $this->runSync();

        $this->assertNotNull($account->fresh()->last_synced_at);
    }

    public function test_a_store_front_that_is_not_connected_is_passed_over(): void
    {
        Http::fake();

        $this->runSync();

        Http::assertNothingSent();
        $this->assertSame(0, Review::acrossTenants()->count());
    }

    public function test_a_broken_connection_is_passed_over_rather_than_retried(): void
    {
        Http::fake();
        $this->connect(['token_status' => GbpTokenStatus::Expired]);

        $this->runSync();

        Http::assertNothingSent();
    }

    public function test_the_job_runs_on_the_gbp_queue_and_retries_with_a_backoff(): void
    {
        $job = new SyncReviewsJob($this->location);

        $this->assertSame('gbp', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());
    }

    public function test_the_job_leaves_the_tenant_as_it_found_it(): void
    {
        $this->connect();
        $this->fakeReviews([]);

        $this->runSync();

        $this->assertFalse(app(Tenancy::class)->check());
    }

    public function test_the_command_queues_a_sync_for_every_connected_store_front(): void
    {
        Queue::fake();

        $this->connect();

        $other = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => 'locations/222',
        ]);
        GbpAccount::factory()->forLocation($other)->create();

        // Not connected, and a broken connection: neither is worth calling.
        Location::factory()->create(['organization_id' => $this->organization->id]);
        $broken = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => 'locations/333',
        ]);
        GbpAccount::factory()->forLocation($broken)->expired()->create();

        $this->artisan('gbp:sync-reviews')->assertSuccessful();

        Queue::assertPushed(SyncReviewsJob::class, 2);
    }

    public function test_the_command_skips_suspended_organizations(): void
    {
        Queue::fake();

        $this->organization->update(['status' => Organization::STATUS_SUSPENDED]);
        $this->connect();

        $this->artisan('gbp:sync-reviews')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_sync_is_scheduled_for_three_in_the_morning_tokyo_time(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'gbp:sync-reviews'));

        $this->assertCount(1, $events);
        $this->assertSame('0 3 * * *', $events->first()->expression);
        $this->assertSame('Asia/Tokyo', $events->first()->timezone);
    }

    public function test_a_member_lists_the_reviews_with_a_summary(): void
    {
        Review::factory()->forLocation($this->location)->rated(5)->answered()->create();
        Review::factory()->forLocation($this->location)->rated(4)->create();

        $this->actingAs($this->member('viewer'))
            ->getJson("/api/v1/locations/{$this->location->id}/reviews")
            ->assertOk()
            ->assertJsonCount(2, 'reviews')
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.unanswered', 1)
            ->assertJsonPath('summary.average_rating', 4.5);
    }

    public function test_replying_sends_the_answer_to_google_before_storing_it(): void
    {
        $this->connect();

        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['comment' => 'ありがとうございます。'])]);

        $review = Review::factory()->forLocation($this->location)->create([
            'google_review_id' => 'review-1',
        ]);

        $this->actingAs($this->member('staff'))
            ->postJson("/api/v1/locations/{$this->location->id}/reviews/{$review->id}/reply", [
                'reply' => 'ご来店ありがとうございました。',
            ])
            ->assertOk()
            ->assertJsonPath('review.answered', true);

        $this->assertSame('ご来店ありがとうございました。', $review->fresh()->reply);
        $this->assertNotNull($review->fresh()->replied_at);

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('/reviews/review-1/reply', $request->url());
            $this->assertSame('ご来店ありがとうございました。', $request->data()['comment']);

            return true;
        });
    }

    public function test_a_reply_that_google_refuses_is_not_stored_as_sent(): void
    {
        $this->connect();

        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['error' => ['message' => 'Backend error']], 500)]);

        $review = Review::factory()->forLocation($this->location)->create();

        $this->actingAs($this->member('staff'))
            ->postJson("/api/v1/locations/{$this->location->id}/reviews/{$review->id}/reply", [
                'reply' => '返信します',
            ])
            ->assertStatus(502);

        $this->assertNull($review->fresh()->reply);
        $this->assertFalse($review->fresh()->isAnswered());
    }

    public function test_replying_over_a_broken_connection_asks_for_a_reconnection(): void
    {
        $this->connect(['token_status' => GbpTokenStatus::Revoked]);

        $review = Review::factory()->forLocation($this->location)->create();

        $this->actingAs($this->member('staff'))
            ->postJson("/api/v1/locations/{$this->location->id}/reviews/{$review->id}/reply", [
                'reply' => '返信します',
            ])
            ->assertStatus(409)
            ->assertJsonPath('reconnect_required', true);

        $this->assertNull($review->fresh()->reply);
    }

    public function test_a_viewer_cannot_reply_to_a_review(): void
    {
        $this->connect();

        $review = Review::factory()->forLocation($this->location)->create();

        $this->actingAs($this->member('viewer'))
            ->postJson("/api/v1/locations/{$this->location->id}/reviews/{$review->id}/reply", [
                'reply' => '返信します',
            ])
            ->assertForbidden();
    }

    public function test_the_reply_is_required(): void
    {
        $this->connect();

        $review = Review::factory()->forLocation($this->location)->create();

        $this->actingAs($this->member('staff'))
            ->postJson("/api/v1/locations/{$this->location->id}/reviews/{$review->id}/reply", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reply');
    }

    public function test_a_review_of_another_store_front_is_not_found(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);
        $review = Review::factory()->forLocation($other)->create();

        $this->actingAs($this->member('staff'))
            ->postJson("/api/v1/locations/{$this->location->id}/reviews/{$review->id}/reply", [
                'reply' => '返信します',
            ])
            ->assertNotFound();
    }

    public function test_a_store_front_of_another_organization_is_not_reachable(): void
    {
        $foreign = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson("/api/v1/locations/{$foreign->id}/reviews")
            ->assertNotFound();
    }
}
