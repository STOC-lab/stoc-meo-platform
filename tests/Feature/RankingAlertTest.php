<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\Feature;
use App\Jobs\CheckRankingAlertsJob;
use App\Jobs\FetchDailyRankingsJob;
use App\Models\Alert;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\RankingResult;
use App\Services\Alerts\RankDropDetector;
use App\Services\Ranking\RankProviderRouter;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RankingAlertTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Keyword $keyword;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()
            ->onPlan(Plan::factory()->withFeatures([
                Feature::RankingEnabled->value => true,
                Feature::RankingDaily->value => true,
            ])->create())
            ->create();

        $location = Location::factory()->create(['organization_id' => $this->organization->id]);

        $this->keyword = Keyword::factory()->forLocation($location)->create(['keyword' => '渋谷 カフェ']);
    }

    /**
     * Record a run of checks for the keyword, oldest first.
     *
     * @param  array<int, int|null>  $ranks
     */
    protected function recordRanks(array $ranks): void
    {
        foreach (array_values($ranks) as $index => $rank) {
            RankingResult::factory()->forKeyword($this->keyword)->create([
                'rank' => $rank,
                'checked_at' => now()->subDays(count($ranks) - $index),
            ]);
        }
    }

    protected function detect(): ?Alert
    {
        return app(Tenancy::class)->forOrganization(
            $this->organization,
            fn () => app(RankDropDetector::class)->detect($this->keyword),
        );
    }

    public function test_a_fall_of_five_places_raises_an_alert(): void
    {
        $this->recordRanks([3, 8]);

        $alert = $this->detect();

        $this->assertNotNull($alert);
        $this->assertSame(AlertType::RankDrop, $alert->type);
        $this->assertSame($this->keyword->id, $alert->keyword_id);
        $this->assertSame($this->keyword->location_id, $alert->location_id);
        $this->assertSame($this->organization->id, $alert->organization_id);
        $this->assertFalse($alert->is_read);
        $this->assertSame(3, $alert->payload['previous_rank']);
        $this->assertSame(8, $alert->payload['current_rank']);
        $this->assertSame(5, $alert->payload['drop']);
        $this->assertSame('渋谷 カフェ', $alert->payload['keyword']);
    }

    public function test_a_fall_of_four_places_is_left_alone(): void
    {
        $this->recordRanks([3, 7]);

        $this->assertNull($this->detect());
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_a_rise_is_not_an_alert(): void
    {
        $this->recordRanks([12, 2]);

        $this->assertNull($this->detect());
    }

    public function test_an_unchanged_rank_is_not_an_alert(): void
    {
        $this->recordRanks([4, 4]);

        $this->assertNull($this->detect());
    }

    public function test_falling_out_of_the_results_entirely_raises_an_alert(): void
    {
        $this->recordRanks([2, null]);

        $alert = $this->detect();

        $this->assertNotNull($alert);
        $this->assertSame(2, $alert->payload['previous_rank']);
        $this->assertNull($alert->payload['current_rank']);
    }

    public function test_appearing_for_the_first_time_is_not_a_drop(): void
    {
        $this->recordRanks([null, 18]);

        $this->assertNull($this->detect());
    }

    public function test_a_single_check_has_nothing_to_compare_against(): void
    {
        $this->recordRanks([3]);

        $this->assertNull($this->detect());
    }

    public function test_a_keyword_that_has_never_been_checked_is_not_an_alert(): void
    {
        $this->assertNull($this->detect());
    }

    public function test_only_the_two_most_recent_checks_are_compared(): void
    {
        // The fall from 2 to 14 is old news; yesterday to today is a rise.
        $this->recordRanks([2, 14, 3]);

        $this->assertNull($this->detect());
    }

    public function test_the_same_fall_is_not_reported_twice(): void
    {
        $this->recordRanks([3, 12]);

        $this->assertNotNull($this->detect());
        $this->assertNull($this->detect());
        $this->assertDatabaseCount('alerts', 1);
    }

    public function test_a_later_fall_is_reported_again(): void
    {
        $this->recordRanks([3, 12]);
        $this->detect();

        RankingResult::factory()->forKeyword($this->keyword)->create([
            'rank' => 30,
            'checked_at' => now()->addDay(),
        ]);

        $this->assertNotNull($this->detect());
        $this->assertDatabaseCount('alerts', 2);
    }

    public function test_the_daily_check_looks_for_a_drop_once_it_has_recorded_the_rank(): void
    {
        Queue::fake();

        config(['services.dataforseo.login' => null, 'services.dataforseo.password' => null]);

        (new FetchDailyRankingsJob($this->keyword))->handle(
            app(RankProviderRouter::class),
            app(Tenancy::class),
        );

        Queue::assertPushed(
            CheckRankingAlertsJob::class,
            fn (CheckRankingAlertsJob $job) => $job->keyword->is($this->keyword),
        );
    }

    public function test_the_alert_job_raises_the_alert_for_the_right_tenant(): void
    {
        $this->recordRanks([3, 11]);

        (new CheckRankingAlertsJob($this->keyword))->handle(
            app(RankDropDetector::class),
            app(Tenancy::class),
        );

        $this->assertDatabaseHas('alerts', [
            'organization_id' => $this->organization->id,
            'keyword_id' => $this->keyword->id,
            'type' => AlertType::RankDrop->value,
            'is_read' => false,
        ]);

        $this->assertFalse(app(Tenancy::class)->check());
    }

    public function test_an_alert_belongs_to_its_organization_alone(): void
    {
        $this->recordRanks([3, 11]);
        $this->detect();

        $other = Organization::factory()->create();

        $visible = app(Tenancy::class)->forOrganization($other, fn () => Alert::query()->count());

        $this->assertSame(0, $visible);
        $this->assertSame(1, Alert::acrossTenants()->count());
    }
}
