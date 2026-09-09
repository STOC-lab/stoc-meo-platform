<?php

namespace Tests\Feature;

use App\Enums\GbpTokenStatus;
use App\Jobs\SyncGBPPerformanceJob;
use App\Models\GbpAccount;
use App\Models\GbpPerformanceMetric;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use App\Services\GBP\GBPClientFactory;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every Google call in this file is faked; nothing here reaches the network.
 */
class GbpPerformanceTest extends TestCase
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
            'gbp.performance.metrics' => ['CALL_CLICKS', 'WEBSITE_CLICKS'],
            'gbp.performance.lookback_days' => 3,
            'gbp.performance.lag_days' => 1,
        ]);

        $this->travelTo(CarbonImmutable::create(2026, 9, 9, 4, 0));

        $this->organization = Organization::factory()->create();
        $this->location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'gbp_location_id' => 'locations/1234567890',
        ]);
    }

    protected function connect(): GbpAccount
    {
        return GbpAccount::factory()
            ->forLocation($this->location)
            ->create(['gbp_account_name' => 'accounts/999']);
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    /**
     * Google's shape: a series per metric, with the day as year/month/day and
     * zero days simply left out.
     *
     * @param  array<string, array<string, int>>  $series
     */
    protected function fakePerformance(array $series): void
    {
        $groups = [];

        foreach ($series as $metric => $values) {
            $dated = [];

            foreach ($values as $date => $value) {
                [$year, $month, $day] = array_map('intval', explode('-', $date));

                $dated[] = ['date' => ['year' => $year, 'month' => $month, 'day' => $day], 'value' => $value];
            }

            $groups[] = ['dailyMetricTimeSeries' => [[
                'dailyMetric' => $metric,
                'timeSeries' => ['datedValues' => $dated],
            ]]];
        }

        Http::fake([
            'businessprofileperformance.googleapis.com/*' => Http::response([
                'multiDailyMetricTimeSeries' => $groups,
            ]),
        ]);
    }

    protected function runSync(): void
    {
        (new SyncGBPPerformanceJob($this->location))->handle(
            app(GBPClientFactory::class),
            app(Tenancy::class),
        );
    }

    public function test_the_sync_stores_a_days_value_for_each_metric(): void
    {
        $this->connect();

        $this->fakePerformance([
            'CALL_CLICKS' => ['2026-09-06' => 3, '2026-09-07' => 5, '2026-09-08' => 8],
            'WEBSITE_CLICKS' => ['2026-09-06' => 10, '2026-09-07' => 12, '2026-09-08' => 14],
        ]);

        $this->runSync();

        $this->assertSame(6, GbpPerformanceMetric::acrossTenants()->count());

        $this->assertDatabaseHas('gbp_performance_metrics', [
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'metric' => 'CALL_CLICKS',
            'date' => '2026-09-08',
            'value' => 8,
        ]);
    }

    public function test_the_window_ends_far_enough_back_for_google_to_have_closed_the_day(): void
    {
        $this->connect();
        $this->fakePerformance(['CALL_CLICKS' => []]);

        $this->runSync();

        Http::assertSent(function ($request) {
            $url = urldecode($request->url());

            // Three days back from yesterday: 6th to 8th September.
            $this->assertStringContainsString('dailyRange.start_date.day=6', $url);
            $this->assertStringContainsString('dailyRange.end_date.day=8', $url);
            $this->assertStringContainsString('dailyRange.end_date.month=9', $url);

            return true;
        });
    }

    public function test_a_day_google_leaves_out_is_stored_as_zero(): void
    {
        $this->connect();

        // Google omits a day whose value is zero rather than sending one.
        $this->fakePerformance(['CALL_CLICKS' => ['2026-09-07' => 5]]);

        $this->runSync();

        $this->assertDatabaseHas('gbp_performance_metrics', [
            'metric' => 'CALL_CLICKS',
            'date' => '2026-09-06',
            'value' => 0,
        ]);
        $this->assertDatabaseHas('gbp_performance_metrics', [
            'metric' => 'CALL_CLICKS',
            'date' => '2026-09-07',
            'value' => 5,
        ]);
    }

    public function test_a_metric_google_did_not_answer_for_is_left_alone(): void
    {
        $this->connect();

        $this->fakePerformance(['CALL_CLICKS' => ['2026-09-07' => 5]]);

        $this->runSync();

        // WEBSITE_CLICKS was asked for but not answered, so it is absent
        // rather than written as a run of zeroes Google never confirmed.
        $this->assertSame(
            0,
            GbpPerformanceMetric::acrossTenants()->where('metric', 'WEBSITE_CLICKS')->count(),
        );
    }

    public function test_a_resynced_day_is_overwritten_rather_than_added_to(): void
    {
        $this->connect();

        Http::fakeSequence('businessprofileperformance.googleapis.com/*')
            ->push($this->body(['CALL_CLICKS' => ['2026-09-07' => 5]]))
            ->push($this->body(['CALL_CLICKS' => ['2026-09-07' => 9]]));

        $this->runSync();
        $this->runSync();

        $rows = GbpPerformanceMetric::acrossTenants()
            ->where('metric', 'CALL_CLICKS')
            ->where('date', '2026-09-07')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(9, $rows->first()->value);
    }

    /**
     * @param  array<string, array<string, int>>  $series
     * @return array<string, mixed>
     */
    protected function body(array $series): array
    {
        $groups = [];

        foreach ($series as $metric => $values) {
            $dated = [];

            foreach ($values as $date => $value) {
                [$year, $month, $day] = array_map('intval', explode('-', $date));

                $dated[] = ['date' => ['year' => $year, 'month' => $month, 'day' => $day], 'value' => $value];
            }

            $groups[] = ['dailyMetricTimeSeries' => [[
                'dailyMetric' => $metric,
                'timeSeries' => ['datedValues' => $dated],
            ]]];
        }

        return ['multiDailyMetricTimeSeries' => $groups];
    }

    public function test_a_store_front_that_is_not_connected_is_passed_over(): void
    {
        Http::fake();

        $this->runSync();

        Http::assertNothingSent();
    }

    public function test_the_job_runs_on_the_gbp_queue_and_retries_with_a_backoff(): void
    {
        $job = new SyncGBPPerformanceJob($this->location);

        $this->assertSame('gbp', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());
    }

    public function test_the_job_leaves_the_tenant_as_it_found_it(): void
    {
        $this->connect();
        $this->fakePerformance(['CALL_CLICKS' => []]);

        $this->runSync();

        $this->assertFalse(app(Tenancy::class)->check());
    }

    public function test_the_command_queues_a_sync_for_every_connected_store_front(): void
    {
        Queue::fake();

        $this->connect();

        $this->artisan('gbp:sync-performance')->assertSuccessful();

        Queue::assertPushed(SyncGBPPerformanceJob::class, 1);
    }

    public function test_the_sweep_is_scheduled_for_monday_at_four_tokyo_time(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'gbp:sync-performance'));

        $this->assertCount(1, $events);
        $this->assertSame('0 4 * * 1', $events->first()->expression);
        $this->assertSame('Asia/Tokyo', $events->first()->timezone);
    }

    public function test_the_token_refresh_is_scheduled_daily_tokyo_time(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'gbp:refresh-tokens'));

        $this->assertCount(1, $events);
        $this->assertSame('0 1 * * *', $events->first()->expression);
        $this->assertSame('Asia/Tokyo', $events->first()->timezone);
    }

    public function test_the_refresh_sweep_renews_a_live_connection(): void
    {
        $account = $this->connect();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.renewed', 'expires_in' => 3600]),
        ]);

        $this->artisan('gbp:refresh-tokens')->assertSuccessful();

        $this->assertSame('ya29.renewed', $account->fresh()->accessToken());
    }

    public function test_the_refresh_sweep_leaves_a_broken_connection_for_a_person(): void
    {
        $account = GbpAccount::factory()->forLocation($this->location)->expired()->create();

        Http::fake();

        $this->artisan('gbp:refresh-tokens')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(GbpTokenStatus::Expired, $account->fresh()->token_status);
    }

    public function test_a_member_reads_the_figures_back_as_a_series_with_totals(): void
    {
        GbpPerformanceMetric::factory()->forLocation($this->location)
            ->on('2026-09-06', 'CALL_CLICKS', 3)->create();
        GbpPerformanceMetric::factory()->forLocation($this->location)
            ->on('2026-09-07', 'CALL_CLICKS', 5)->create();
        GbpPerformanceMetric::factory()->forLocation($this->location)
            ->on('2026-09-07', 'WEBSITE_CLICKS', 11)->create();

        $this->actingAs($this->member('viewer'))
            ->getJson("/api/v1/locations/{$this->location->id}/gbp/performance?start=2026-09-01&end=2026-09-08")
            ->assertOk()
            ->assertJsonPath('metrics.CALL_CLICKS.2026-09-06', 3)
            ->assertJsonPath('metrics.CALL_CLICKS.2026-09-07', 5)
            ->assertJsonPath('totals.CALL_CLICKS', 8)
            ->assertJsonPath('totals.WEBSITE_CLICKS', 11)
            ->assertJsonPath('range.start', '2026-09-01');
    }

    public function test_days_outside_the_range_are_left_out(): void
    {
        GbpPerformanceMetric::factory()->forLocation($this->location)
            ->on('2026-08-01', 'CALL_CLICKS', 99)->create();
        GbpPerformanceMetric::factory()->forLocation($this->location)
            ->on('2026-09-07', 'CALL_CLICKS', 5)->create();

        $this->actingAs($this->member('viewer'))
            ->getJson("/api/v1/locations/{$this->location->id}/gbp/performance?start=2026-09-01&end=2026-09-08")
            ->assertOk()
            ->assertJsonPath('totals.CALL_CLICKS', 5);
    }

    public function test_figures_of_another_organization_are_not_readable(): void
    {
        $foreign = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson("/api/v1/locations/{$foreign->id}/gbp/performance")
            ->assertNotFound();
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson("/api/v1/locations/{$this->location->id}/gbp/performance")
            ->assertUnauthorized();
    }
}
