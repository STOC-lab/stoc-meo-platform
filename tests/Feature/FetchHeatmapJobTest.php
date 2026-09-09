<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Enums\HeatmapGridSize;
use App\Enums\HeatmapRunStatus;
use App\Jobs\FetchHeatmapJob;
use App\Models\HeatmapPoint;
use App\Models\HeatmapRun;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Services\Heatmap\GridGenerator;
use App\Services\Ranking\RankProviderRouter;
use App\Services\UsageTracker;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchHeatmapJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, int|bool|null>  $features
     */
    protected function organizationOnPlan(array $features = []): Organization
    {
        return Organization::factory()
            ->onPlan(Plan::factory()->withFeatures($features + [
                Feature::RankingEnabled->value => true,
                Feature::Heatmap5x5MonthlyLimit->value => 10,
                Feature::Heatmap7x7MonthlyLimit->value => 4,
            ])->create())
            ->create();
    }

    protected function runFor(Organization $organization, HeatmapGridSize $size = HeatmapGridSize::Grid5x5, array $locationAttributes = []): HeatmapRun
    {
        $location = Location::factory()->create([
            'organization_id' => $organization->id,
            'gbp_location_id' => 'locations/1234567890',
            'latitude' => 35.6580,
            'longitude' => 139.7016,
            ...$locationAttributes,
        ]);

        $keyword = Keyword::factory()->forLocation($location)->create();

        return HeatmapRun::factory()->forKeyword($keyword)->gridSize($size)->create();
    }

    protected function fakeSerp(?int $rank): void
    {
        config(['services.dataforseo.login' => 'login', 'services.dataforseo.password' => 'secret']);

        Http::fake(['*' => Http::response([
            'status_code' => 20000,
            'tasks' => [[
                'status_code' => 20000,
                'result' => [[
                    'check_url' => 'https://www.google.com/maps?q=x',
                    'items' => $rank === null ? [] : [[
                        'rank_absolute' => $rank,
                        'place_id' => '1234567890',
                    ]],
                ]],
            ]],
        ])]);
    }

    protected function runJob(HeatmapRun $run): void
    {
        (new FetchHeatmapJob($run))->handle(
            app(RankProviderRouter::class),
            app(GridGenerator::class),
            app(UsageTracker::class),
            app(Tenancy::class),
        );
    }

    public function test_a_five_by_five_run_records_twenty_five_points(): void
    {
        $this->fakeSerp(4);
        $run = $this->runFor($this->organizationOnPlan());

        $this->runJob($run);

        $this->assertSame(25, HeatmapPoint::acrossTenants()->where('heatmap_run_id', $run->id)->count());
        $this->assertSame(HeatmapRunStatus::Completed, $run->refresh()->status);
        $this->assertNotNull($run->completed_at);
    }

    public function test_a_seven_by_seven_run_records_forty_nine_points(): void
    {
        $this->fakeSerp(4);
        $run = $this->runFor($this->organizationOnPlan(), HeatmapGridSize::Grid7x7);

        $this->runJob($run);

        $this->assertSame(49, HeatmapPoint::acrossTenants()->where('heatmap_run_id', $run->id)->count());
    }

    public function test_every_point_carries_its_place_in_the_grid_and_the_rank_seen_there(): void
    {
        $this->fakeSerp(7);
        $run = $this->runFor($this->organizationOnPlan());

        $this->runJob($run);

        $points = HeatmapPoint::acrossTenants()->where('heatmap_run_id', $run->id)->get();

        $this->assertSame([7], $points->pluck('rank')->unique()->all());
        $this->assertCount(25, $points->map(fn ($point) => "{$point->row}:{$point->col}")->unique());
        $this->assertSame($run->organization_id, $points->first()->organization_id);
    }

    public function test_a_point_where_the_store_front_does_not_appear_is_recorded_as_unranked(): void
    {
        $this->fakeSerp(null);
        $run = $this->runFor($this->organizationOnPlan());

        $this->runJob($run);

        $this->assertSame(
            25,
            HeatmapPoint::acrossTenants()->where('heatmap_run_id', $run->id)->whereNull('rank')->count(),
        );
    }

    public function test_the_run_charges_the_monthly_allowance_for_its_grid_once(): void
    {
        $this->fakeSerp(4);
        $organization = $this->organizationOnPlan();
        $run = $this->runFor($organization, HeatmapGridSize::Grid7x7);

        $this->runJob($run);

        $usage = app(UsageTracker::class);

        $this->assertSame(1, $usage->used(Feature::Heatmap7x7MonthlyLimit, $organization));
        $this->assertSame(0, $usage->used(Feature::Heatmap5x5MonthlyLimit, $organization));
    }

    public function test_a_retry_redraws_the_grid_without_charging_the_allowance_again(): void
    {
        $this->fakeSerp(4);
        $organization = $this->organizationOnPlan();
        $run = $this->runFor($organization);

        $this->runJob($run);

        // The retry finds the run already claimed, so it neither charges the
        // allowance a second time nor leaves the earlier points behind.
        $run->refresh()->forceFill(['status' => HeatmapRunStatus::Running])->save();
        $this->runJob($run->refresh());

        $this->assertSame(1, app(UsageTracker::class)->used(Feature::Heatmap5x5MonthlyLimit, $organization));
        $this->assertSame(25, HeatmapPoint::acrossTenants()->where('heatmap_run_id', $run->id)->count());
    }

    public function test_a_run_that_has_already_finished_is_left_alone(): void
    {
        $this->fakeSerp(4);
        $organization = $this->organizationOnPlan();
        $run = $this->runFor($organization);
        $run->markCompleted();

        $this->runJob($run->refresh());

        $this->assertSame(0, HeatmapPoint::acrossTenants()->count());
        $this->assertSame(0, app(UsageTracker::class)->used(Feature::Heatmap5x5MonthlyLimit, $organization));
    }

    public function test_the_run_fails_when_the_monthly_allowance_is_spent(): void
    {
        $this->fakeSerp(4);
        $organization = $this->organizationOnPlan([Feature::Heatmap5x5MonthlyLimit->value => 1]);
        app(UsageTracker::class)->record(Feature::Heatmap5x5MonthlyLimit, 1, $organization);

        $run = $this->runFor($organization);

        $this->runJob($run);

        $this->assertSame(HeatmapRunStatus::Failed, $run->refresh()->status);
        $this->assertStringContainsString('上限', $run->failure_reason);
        $this->assertSame(0, HeatmapPoint::acrossTenants()->count());
    }

    public function test_the_run_fails_when_the_store_front_has_no_coordinates(): void
    {
        $this->fakeSerp(4);
        $run = $this->runFor($this->organizationOnPlan(), HeatmapGridSize::Grid5x5, [
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->runJob($run);

        $this->assertSame(HeatmapRunStatus::Failed, $run->refresh()->status);
        $this->assertStringContainsString('緯度', $run->failure_reason);
        $this->assertSame(0, HeatmapPoint::acrossTenants()->count());
    }

    public function test_the_grid_points_are_searched_from_their_own_coordinates(): void
    {
        $this->fakeSerp(4);
        $run = $this->runFor($this->organizationOnPlan());

        $this->runJob($run);

        $coordinates = [];

        Http::assertSent(function ($request) use (&$coordinates) {
            $task = $request->data()[0];

            $this->assertFalse($task['calculate_rectangles']);
            $this->assertArrayNotHasKey('location_name', $task);

            $coordinates[] = $task['location_coordinate'];

            return true;
        });

        $this->assertCount(25, array_unique($coordinates));
    }

    public function test_the_job_runs_on_the_heatmap_queue_and_retries_with_a_backoff(): void
    {
        $job = new FetchHeatmapJob($this->runFor($this->organizationOnPlan()));

        $this->assertSame('heatmap', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());
    }

    public function test_the_job_leaves_the_tenant_as_it_found_it(): void
    {
        $this->fakeSerp(4);
        $run = $this->runFor($this->organizationOnPlan());

        $this->runJob($run);

        $this->assertFalse(app(Tenancy::class)->check());
    }

    public function test_an_exhausted_run_is_closed_off_rather_than_left_in_progress(): void
    {
        $run = $this->runFor($this->organizationOnPlan());
        $run->claim();

        (new FetchHeatmapJob($run))->failed(new \RuntimeException('provider unavailable'));

        $this->assertSame(HeatmapRunStatus::Failed, $run->refresh()->status);
        $this->assertSame('provider unavailable', $run->failure_reason);
    }
}
