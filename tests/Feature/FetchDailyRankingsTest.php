<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Jobs\FetchDailyRankingsJob;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\RankingResult;
use App\Services\Ranking\RankProviderException;
use App\Services\Ranking\RankProviderRouter;
use App\Support\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class FetchDailyRankingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, int|bool|null>  $features
     */
    protected function organizationOnPlan(array $features = [], array $attributes = []): Organization
    {
        return Organization::factory()
            ->onPlan(Plan::factory()->withFeatures($features + [
                Feature::RankingEnabled->value => true,
                Feature::RankingDaily->value => true,
            ])->create())
            ->create($attributes);
    }

    protected function keywordOf(Organization $organization, array $attributes = []): Keyword
    {
        $location = Location::factory()->create(['organization_id' => $organization->id]);

        return Keyword::factory()->forLocation($location)->create($attributes);
    }

    protected function fakeSerp(?int $rank): void
    {
        Http::fake(['*' => Http::response([
            'status_code' => 20000,
            'tasks' => [[
                'status_code' => 20000,
                'result' => [[
                    'check_url' => 'https://www.google.com/maps?q=x',
                    'items' => $rank === null ? [] : [[
                        'rank_absolute' => $rank,
                        'place_id' => '1234567890',
                        'title' => 'どこかの店',
                    ]],
                ]],
            ]],
        ])]);
    }

    public function test_the_job_records_the_rank_it_was_given(): void
    {
        config(['services.dataforseo.login' => 'login', 'services.dataforseo.password' => 'secret']);
        $this->fakeSerp(6);

        $organization = $this->organizationOnPlan();
        $location = Location::factory()->create([
            'organization_id' => $organization->id,
            'gbp_location_id' => 'locations/1234567890',
        ]);
        $keyword = Keyword::factory()->forLocation($location)->create();

        (new FetchDailyRankingsJob($keyword))->handle(
            app(RankProviderRouter::class),
            app(Tenancy::class),
        );

        $this->assertDatabaseHas('ranking_results', [
            'organization_id' => $organization->id,
            'location_id' => $location->id,
            'keyword_id' => $keyword->id,
            'rank' => 6,
            'provider' => 'dataforseo',
        ]);
    }

    public function test_the_job_records_a_check_even_when_no_provider_can_answer(): void
    {
        config(['services.dataforseo.login' => null, 'services.dataforseo.password' => null]);

        $keyword = $this->keywordOf($this->organizationOnPlan());

        (new FetchDailyRankingsJob($keyword))->handle(
            app(RankProviderRouter::class),
            app(Tenancy::class),
        );

        $result = RankingResult::acrossTenants()->firstOrFail();

        $this->assertNull($result->rank);
        $this->assertSame('fallback', $result->provider);
        $this->assertSame($keyword->id, $result->keyword_id);
    }

    /**
     * A job carrying the attempt number the queue would have given it.
     */
    protected function jobOnAttempt(Keyword $keyword, int $attempt): FetchDailyRankingsJob
    {
        $job = new FetchDailyRankingsJob($keyword);

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);

        return $job->setJob($queueJob);
    }

    public function test_a_transient_provider_failure_is_retried_rather_than_recorded_as_a_gap(): void
    {
        config(['services.dataforseo.login' => 'login', 'services.dataforseo.password' => 'secret']);
        Http::fake(['*' => Http::response('', 401)]);

        $keyword = $this->keywordOf($this->organizationOnPlan());

        $this->expectException(RankProviderException::class);

        try {
            $this->jobOnAttempt($keyword, 1)->handle(
                app(RankProviderRouter::class),
                app(Tenancy::class),
            );
        } finally {
            // Nothing is written, so the retry is free to record the real
            // answer rather than landing beside a "not found" that was not one.
            $this->assertSame(0, RankingResult::acrossTenants()->count());
        }
    }

    public function test_the_last_attempt_records_the_fallback_so_the_day_keeps_one_row(): void
    {
        config(['services.dataforseo.login' => 'login', 'services.dataforseo.password' => 'secret']);
        Http::fake(['*' => Http::response('', 401)]);

        $keyword = $this->keywordOf($this->organizationOnPlan());

        $this->jobOnAttempt($keyword, 3)->handle(
            app(RankProviderRouter::class),
            app(Tenancy::class),
        );

        $result = RankingResult::acrossTenants()->firstOrFail();

        $this->assertNull($result->rank);
        $this->assertSame('fallback', $result->provider);
    }

    public function test_a_permanent_provider_failure_takes_the_fallback_without_spending_a_retry(): void
    {
        config(['services.dataforseo.login' => 'login', 'services.dataforseo.password' => 'secret']);
        Http::fake(['*' => Http::response('', 404)]);

        $keyword = $this->keywordOf($this->organizationOnPlan());

        $this->jobOnAttempt($keyword, 1)->handle(
            app(RankProviderRouter::class),
            app(Tenancy::class),
        );

        $this->assertSame('fallback', RankingResult::acrossTenants()->firstOrFail()->provider);
    }

    public function test_the_job_leaves_the_tenant_as_it_found_it(): void
    {
        config(['services.dataforseo.login' => null, 'services.dataforseo.password' => null]);

        $keyword = $this->keywordOf($this->organizationOnPlan());
        $tenancy = app(Tenancy::class);

        (new FetchDailyRankingsJob($keyword))->handle(app(RankProviderRouter::class), $tenancy);

        $this->assertFalse($tenancy->check());
    }

    public function test_the_job_runs_on_the_rankings_queue_and_retries_with_a_backoff(): void
    {
        $job = new FetchDailyRankingsJob($this->keywordOf($this->organizationOnPlan()));

        $this->assertSame('rankings', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());
    }

    public function test_the_command_queues_a_job_for_every_active_keyword(): void
    {
        Queue::fake();

        $organization = $this->organizationOnPlan();
        $tracked = $this->keywordOf($organization);
        $this->keywordOf($organization, ['is_active' => false]);

        $this->artisan('rankings:fetch-daily')->assertSuccessful();

        Queue::assertPushed(FetchDailyRankingsJob::class, 1);
        Queue::assertPushed(
            FetchDailyRankingsJob::class,
            fn (FetchDailyRankingsJob $job) => $job->keyword->is($tracked),
        );
    }

    public function test_the_command_skips_organizations_whose_plan_has_no_daily_ranking(): void
    {
        Queue::fake();

        $this->keywordOf($this->organizationOnPlan([Feature::RankingDaily->value => false]));

        $this->artisan('rankings:fetch-daily')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_command_skips_suspended_organizations(): void
    {
        Queue::fake();

        $this->keywordOf($this->organizationOnPlan([], ['status' => Organization::STATUS_SUSPENDED]));

        $this->artisan('rankings:fetch-daily')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_command_reaches_every_tenant(): void
    {
        Queue::fake();

        $this->keywordOf($this->organizationOnPlan());
        $this->keywordOf($this->organizationOnPlan());

        $this->artisan('rankings:fetch-daily')->assertSuccessful();

        Queue::assertPushed(FetchDailyRankingsJob::class, 2);
    }

    public function test_the_sweep_is_scheduled_for_two_in_the_morning_tokyo_time(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'rankings:fetch-daily'));

        $this->assertCount(1, $events, 'The daily sweep should be scheduled exactly once.');

        $event = $events->first();

        $this->assertSame('0 2 * * *', $event->expression);
        $this->assertSame('Asia/Tokyo', $event->timezone);
    }
}
