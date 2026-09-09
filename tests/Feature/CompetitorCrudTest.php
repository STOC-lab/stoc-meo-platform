<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\Competitor;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetitorCrudTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organizationOnPlan([Feature::CompetitorLimit->value => 3]);

        $this->location = Location::factory()->create([
            'organization_id' => $this->organization->id,
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

    protected function url(string $path = '', ?Location $location = null): string
    {
        $location ??= $this->location;

        return "/api/v1/locations/{$location->id}/competitors".$path;
    }

    public function test_a_member_lists_the_competitors_of_a_store_front(): void
    {
        Competitor::factory()->forLocation($this->location)->create(['name' => '向かいのカフェ']);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'competitors')
            ->assertJsonPath('competitors.0.name', '向かいのカフェ')
            ->assertJsonPath('allowance.limit', 3)
            ->assertJsonPath('allowance.used', 1)
            ->assertJsonPath('allowance.remaining', 2);
    }

    public function test_competitors_of_another_store_front_are_not_listed(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);

        Competitor::factory()->forLocation($this->location)->create();
        Competitor::factory()->forLocation($other)->create();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'competitors');
    }

    public function test_a_store_manager_adds_a_competitor(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['name' => '向かいのカフェ', 'gbp_place_id' => 'ChIJabc123'])
            ->assertCreated()
            ->assertJsonPath('competitor.name', '向かいのカフェ')
            ->assertJsonPath('competitor.gbp_place_id', 'ChIJabc123')
            ->assertJsonPath('allowance.remaining', 2);

        $this->assertDatabaseHas('competitors', [
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'name' => '向かいのカフェ',
        ]);
    }

    public function test_a_competitor_may_be_added_without_a_place_id(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['name' => '駅前の店'])
            ->assertCreated()
            ->assertJsonPath('competitor.gbp_place_id', null);
    }

    public function test_the_name_is_required(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_the_same_competitor_cannot_be_watched_twice_by_one_store_front(): void
    {
        Competitor::factory()->forLocation($this->location)->create(['gbp_place_id' => 'ChIJabc123']);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['name' => '向かいのカフェ', 'gbp_place_id' => 'ChIJabc123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gbp_place_id');
    }

    public function test_another_store_front_may_watch_the_same_competitor(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);
        Competitor::factory()->forLocation($other)->create(['gbp_place_id' => 'ChIJabc123']);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['name' => '向かいのカフェ', 'gbp_place_id' => 'ChIJabc123'])
            ->assertCreated();
    }

    public function test_the_plan_limit_refuses_a_further_competitor_with_an_upgrade_prompt(): void
    {
        Competitor::factory()->count(3)->forLocation($this->location)->create();

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['name' => '四軒目'])
            ->assertForbidden()
            ->assertJsonPath('feature', 'competitor.limit')
            ->assertJsonPath('limit', 3)
            ->assertJsonPath('used', 3)
            ->assertJsonPath('upgrade.required', true);

        $this->assertDatabaseCount('competitors', 3);
    }

    public function test_the_limit_is_counted_per_store_front(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);
        Competitor::factory()->count(3)->forLocation($other)->create();

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['name' => '向かいのカフェ'])
            ->assertCreated();
    }

    public function test_a_staff_member_cannot_add_a_competitor(): void
    {
        $this->actingAs($this->member('staff'))
            ->postJson($this->url(), ['name' => '向かいのカフェ'])
            ->assertForbidden();

        $this->assertDatabaseCount('competitors', 0);
    }

    public function test_removing_a_competitor_frees_the_slot(): void
    {
        $competitors = Competitor::factory()->count(3)->forLocation($this->location)->create();

        $this->actingAs($this->member('location_admin'))
            ->deleteJson($this->url("/{$competitors->first()->id}"))
            ->assertNoContent();

        $this->assertDatabaseMissing('competitors', ['id' => $competitors->first()->id]);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['name' => '入れ替えの店'])
            ->assertCreated();
    }

    public function test_a_viewer_cannot_remove_a_competitor(): void
    {
        $competitor = Competitor::factory()->forLocation($this->location)->create();

        $this->actingAs($this->member('viewer'))
            ->deleteJson($this->url("/{$competitor->id}"))
            ->assertForbidden();

        $this->assertDatabaseHas('competitors', ['id' => $competitor->id]);
    }

    public function test_a_competitor_of_another_store_front_is_not_found(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);
        $competitor = Competitor::factory()->forLocation($other)->create();

        $this->actingAs($this->member('location_admin'))
            ->deleteJson($this->url("/{$competitor->id}"))
            ->assertNotFound();

        $this->assertDatabaseHas('competitors', ['id' => $competitor->id]);
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

    public function test_a_plan_without_competitor_tracking_cannot_reach_the_list(): void
    {
        $organization = $this->organizationOnPlan([Feature::CompetitorLimit->value => 0]);
        $location = Location::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($this->member('org_admin', $organization))
            ->getJson($this->url('', $location))
            ->assertForbidden()
            ->assertJsonPath('feature', 'competitor.limit');
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }
}
