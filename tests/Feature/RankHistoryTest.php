<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Http\Controllers\Api\V1\KeywordController;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\RankingResult;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected Keyword $keyword;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::create(2026, 9, 9, 12, 0));

        $this->organization = Organization::factory()
            ->onPlan(Plan::factory()->withFeatures([
                Feature::RankingEnabled->value => true,
                Feature::RankingKeywordLimit->value => 10,
            ])->create())
            ->create();

        $this->location = Location::factory()->create(['organization_id' => $this->organization->id]);
        $this->keyword = Keyword::factory()->forLocation($this->location)->create(['keyword' => '渋谷 カフェ']);
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    protected function url(string $query = '', ?Keyword $keyword = null, ?Location $location = null): string
    {
        $location ??= $this->location;
        $keyword ??= $this->keyword;

        return "/api/v1/locations/{$location->id}/keywords/{$keyword->id}/history".$query;
    }

    protected function record(string $checkedAt, ?int $rank): RankingResult
    {
        return RankingResult::factory()->forKeyword($this->keyword)->create([
            'rank' => $rank,
            'checked_at' => $checkedAt,
        ]);
    }

    public function test_a_member_reads_the_history_oldest_first(): void
    {
        $this->record('2026-09-07 02:00:00', 5);
        $this->record('2026-09-08 02:00:00', 3);
        $this->record('2026-09-09 02:00:00', 4);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('keyword.keyword', '渋谷 カフェ')
            ->assertJsonPath('days', 30)
            ->assertJsonCount(3, 'history')
            ->assertJsonPath('history.0.date', '2026-09-07')
            ->assertJsonPath('history.0.rank', 5)
            ->assertJsonPath('history.2.date', '2026-09-09')
            ->assertJsonPath('history.2.rank', 4);
    }

    public function test_a_check_that_found_nothing_is_reported_as_null_rather_than_dropped(): void
    {
        $this->record('2026-09-08 02:00:00', null);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'history')
            ->assertJsonPath('history.0.rank', null);
    }

    public function test_a_second_check_on_the_same_day_replaces_the_first(): void
    {
        $this->record('2026-09-08 02:00:00', 9);
        // A manual check later the same day.
        $this->record('2026-09-08 15:00:00', 4);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'history')
            ->assertJsonPath('history.0.rank', 4);
    }

    public function test_the_window_defaults_to_thirty_days(): void
    {
        $this->record('2026-09-08 02:00:00', 3);
        $this->record('2026-07-01 02:00:00', 20);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'history')
            ->assertJsonPath('history.0.date', '2026-09-08');
    }

    public function test_the_window_can_be_widened_up_to_the_cap(): void
    {
        $this->record('2026-07-15 02:00:00', 12);
        $this->record('2026-09-08 02:00:00', 3);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('?days=90'))
            ->assertOk()
            ->assertJsonPath('days', 90)
            ->assertJsonCount(2, 'history');
    }

    public function test_a_window_beyond_the_cap_is_refused(): void
    {
        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('?days=365'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('days');

        $this->assertSame(90, KeywordController::MAX_HISTORY_DAYS);
    }

    public function test_the_history_of_another_store_fronts_keyword_is_not_reachable(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);
        $foreign = Keyword::factory()->forLocation($other)->create();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('', $foreign))
            ->assertNotFound();
    }

    public function test_a_store_front_of_another_organization_is_not_reachable(): void
    {
        $foreignLocation = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);
        $foreignKeyword = Keyword::factory()->forLocation($foreignLocation)->create();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('', $foreignKeyword, $foreignLocation))
            ->assertNotFound();
    }

    public function test_a_plan_without_ranking_cannot_reach_the_history(): void
    {
        $organization = Organization::factory()
            ->onPlan(Plan::factory()->withFeatures([Feature::RankingEnabled->value => false])->create())
            ->create();
        $location = Location::factory()->create(['organization_id' => $organization->id]);
        $keyword = Keyword::factory()->forLocation($location)->create();

        $user = User::factory()->create();
        $organization->users()->attach($user, ['role' => 'org_admin']);

        $this->actingAs($user)
            ->getJson("/api/v1/locations/{$location->id}/keywords/{$keyword->id}/history")
            ->assertForbidden()
            ->assertJsonPath('feature', 'ranking.enabled');
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }
}
