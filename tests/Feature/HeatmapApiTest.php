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
use App\Models\User;
use App\Services\UsageTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HeatmapApiTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected Keyword $keyword;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->organization = $this->organizationOnPlan([
            Feature::RankingEnabled->value => true,
            Feature::Heatmap5x5MonthlyLimit->value => 4,
            Feature::Heatmap7x7MonthlyLimit->value => 2,
        ]);

        $this->location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'latitude' => 35.6580,
            'longitude' => 139.7016,
        ]);

        $this->keyword = Keyword::factory()->forLocation($this->location)->create(['keyword' => '渋谷 カフェ']);
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

    protected function url(string $path = '', ?Location $location = null): string
    {
        $location ??= $this->location;

        return "/api/v1/locations/{$location->id}/heatmaps".$path;
    }

    public function test_a_store_manager_queues_a_heatmap(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['keyword_id' => $this->keyword->id, 'grid_size' => '5x5'])
            ->assertAccepted()
            ->assertJsonPath('heatmap.grid_size', '5x5')
            ->assertJsonPath('heatmap.point_count', 25)
            ->assertJsonPath('heatmap.status', 'pending')
            ->assertJsonPath('heatmap.keyword', '渋谷 カフェ')
            ->assertJsonPath('allowances.5x5.limit', 4);

        $this->assertDatabaseHas('heatmap_runs', [
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'keyword_id' => $this->keyword->id,
            'grid_size' => '5x5',
            'status' => 'pending',
        ]);

        Queue::assertPushed(FetchHeatmapJob::class, 1);
    }

    public function test_the_grid_size_must_be_one_the_design_defines(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['keyword_id' => $this->keyword->id, 'grid_size' => '9x9'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('grid_size');

        Queue::assertNothingPushed();
    }

    public function test_the_keyword_must_belong_to_the_store_front(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);
        $foreign = Keyword::factory()->forLocation($other)->create();

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['keyword_id' => $foreign->id, 'grid_size' => '5x5'])
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_a_store_front_without_coordinates_cannot_be_mapped(): void
    {
        $this->location->update(['latitude' => null, 'longitude' => null]);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['keyword_id' => $this->keyword->id, 'grid_size' => '5x5'])
            ->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_a_grid_the_plan_does_not_include_is_refused_with_an_upgrade_prompt(): void
    {
        $organization = $this->organizationOnPlan([
            Feature::Heatmap5x5MonthlyLimit->value => 4,
            Feature::Heatmap7x7MonthlyLimit->value => 0,
        ]);
        $location = Location::factory()->create([
            'organization_id' => $organization->id,
            'latitude' => 35.6,
            'longitude' => 139.7,
        ]);
        $keyword = Keyword::factory()->forLocation($location)->create();

        $this->actingAs($this->member('location_admin', $organization))
            ->postJson($this->url('', $location), ['keyword_id' => $keyword->id, 'grid_size' => '7x7'])
            ->assertForbidden()
            ->assertJsonPath('feature', 'heatmap.7x7.monthly_limit')
            ->assertJsonPath('upgrade.required', true);

        Queue::assertNothingPushed();
    }

    public function test_the_smaller_grid_stays_available_when_the_larger_one_is_not(): void
    {
        $organization = $this->organizationOnPlan([
            Feature::Heatmap5x5MonthlyLimit->value => 4,
            Feature::Heatmap7x7MonthlyLimit->value => 0,
        ]);
        $location = Location::factory()->create([
            'organization_id' => $organization->id,
            'latitude' => 35.6,
            'longitude' => 139.7,
        ]);
        $keyword = Keyword::factory()->forLocation($location)->create();

        $this->actingAs($this->member('location_admin', $organization))
            ->postJson($this->url('', $location), ['keyword_id' => $keyword->id, 'grid_size' => '5x5'])
            ->assertAccepted();
    }

    public function test_the_monthly_allowance_refuses_a_further_run(): void
    {
        app(UsageTracker::class)->record(Feature::Heatmap5x5MonthlyLimit, 4, $this->organization);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['keyword_id' => $this->keyword->id, 'grid_size' => '5x5'])
            ->assertForbidden()
            ->assertJsonPath('feature', 'heatmap.5x5.monthly_limit')
            ->assertJsonPath('limit', 4)
            ->assertJsonPath('used', 4);

        $this->assertDatabaseCount('heatmap_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_asking_for_a_run_does_not_itself_spend_the_allowance(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['keyword_id' => $this->keyword->id, 'grid_size' => '5x5'])
            ->assertAccepted();

        // The run charges the allowance when a worker picks it up, so a
        // request that never reaches one costs the organization nothing.
        $this->assertSame(0, app(UsageTracker::class)->used(Feature::Heatmap5x5MonthlyLimit, $this->organization));
    }

    public function test_a_staff_member_cannot_ask_for_a_heatmap(): void
    {
        $this->actingAs($this->member('staff'))
            ->postJson($this->url(), ['keyword_id' => $this->keyword->id, 'grid_size' => '5x5'])
            ->assertForbidden();

        $this->assertDatabaseCount('heatmap_runs', 0);
    }

    public function test_a_member_lists_the_runs_of_a_store_front_newest_first(): void
    {
        $older = HeatmapRun::factory()->forKeyword($this->keyword)->completed()->create();
        $newer = HeatmapRun::factory()->forKeyword($this->keyword)->gridSize(HeatmapGridSize::Grid7x7)->create();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'heatmaps')
            ->assertJsonPath('heatmaps.0.id', $newer->id)
            ->assertJsonPath('heatmaps.0.grid_size', '7x7')
            ->assertJsonPath('heatmaps.1.id', $older->id)
            ->assertJsonPath('heatmaps.1.status', 'completed')
            ->assertJsonPath('allowances.7x7.remaining', 2);
    }

    public function test_runs_of_another_store_front_are_not_listed(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);
        $foreign = Keyword::factory()->forLocation($other)->create();

        HeatmapRun::factory()->forKeyword($this->keyword)->create();
        HeatmapRun::factory()->forKeyword($foreign)->create();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'heatmaps');
    }

    public function test_the_detail_carries_the_grid_the_map_is_drawn_from(): void
    {
        $run = HeatmapRun::factory()->forKeyword($this->keyword)->completed()->create();

        HeatmapPoint::factory()->forRun($run)->at(0, 0)->create(['rank' => 3]);
        HeatmapPoint::factory()->forRun($run)->at(2, 2)->create(['rank' => 11]);
        HeatmapPoint::factory()->forRun($run)->at(4, 4)->unranked()->create();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url("/{$run->id}"))
            ->assertOk()
            ->assertJsonPath('heatmap.id', $run->id)
            ->assertJsonPath('heatmap.grid_size', '5x5')
            ->assertJsonCount(3, 'heatmap.points')
            ->assertJsonPath('heatmap.points.0.rank', 3)
            ->assertJsonPath('heatmap.grid.0.0', 3)
            ->assertJsonPath('heatmap.grid.2.2', 11)
            ->assertJsonPath('heatmap.grid.4.4', null)
            ->assertJsonPath('heatmap.grid.1.1', null)
            ->assertJsonPath('heatmap.centre.lat', 35.658);
    }

    public function test_the_grid_is_the_size_the_run_was_made_at(): void
    {
        $run = HeatmapRun::factory()->forKeyword($this->keyword)
            ->gridSize(HeatmapGridSize::Grid7x7)
            ->completed()
            ->create();

        $response = $this->actingAs($this->member('viewer'))
            ->getJson($this->url("/{$run->id}"))
            ->assertOk();

        $this->assertCount(7, $response->json('heatmap.grid'));
        $this->assertCount(7, $response->json('heatmap.grid.0'));
    }

    public function test_a_failed_run_says_why(): void
    {
        $run = HeatmapRun::factory()->forKeyword($this->keyword)
            ->failed('ご利用中のプランの今月の上限に達しました。')
            ->create();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url("/{$run->id}"))
            ->assertOk()
            ->assertJsonPath('heatmap.status', HeatmapRunStatus::Failed->value)
            ->assertJsonPath('heatmap.status_label', '失敗')
            ->assertJsonPath('heatmap.failure_reason', 'ご利用中のプランの今月の上限に達しました。');
    }

    public function test_a_run_of_another_store_front_is_not_found(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);
        $run = HeatmapRun::factory()->forKeyword(
            Keyword::factory()->forLocation($other)->create()
        )->create();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url("/{$run->id}"))
            ->assertNotFound();
    }

    public function test_a_store_front_of_another_organization_is_not_reachable(): void
    {
        $foreign = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('', $foreign))
            ->assertNotFound();
    }

    public function test_past_runs_stay_readable_when_the_plan_no_longer_includes_the_grid(): void
    {
        $organization = $this->organizationOnPlan([Feature::Heatmap5x5MonthlyLimit->value => 0]);
        $location = Location::factory()->create(['organization_id' => $organization->id]);
        $run = HeatmapRun::factory()->forKeyword(
            Keyword::factory()->forLocation($location)->create()
        )->completed()->create();

        $this->actingAs($this->member('viewer', $organization))
            ->getJson($this->url("/{$run->id}", $location))
            ->assertOk()
            ->assertJsonPath('heatmap.id', $run->id);
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }
}
