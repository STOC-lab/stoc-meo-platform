<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\RankingResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordCrudTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organizationOnPlan([
            Feature::RankingEnabled->value => true,
            Feature::RankingKeywordLimit->value => 3,
        ]);

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

        return "/api/v1/locations/{$location->id}/keywords".$path;
    }

    public function test_a_member_lists_the_keywords_of_a_store_front(): void
    {
        Keyword::factory()->forLocation($this->location)->create(['keyword' => '渋谷 カフェ']);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'keywords')
            ->assertJsonPath('keywords.0.keyword', '渋谷 カフェ')
            ->assertJsonPath('keywords.0.is_active', true)
            ->assertJsonPath('keywords.0.latest_result', null)
            ->assertJsonPath('allowance.limit', 3)
            ->assertJsonPath('allowance.used', 1)
            ->assertJsonPath('allowance.remaining', 2);
    }

    public function test_the_list_carries_the_most_recent_rank(): void
    {
        $keyword = Keyword::factory()->forLocation($this->location)->create();

        RankingResult::factory()->forKeyword($keyword)->create([
            'rank' => 9,
            'checked_at' => now()->subDay(),
        ]);
        RankingResult::factory()->forKeyword($keyword)->create([
            'rank' => 4,
            'checked_at' => now(),
            'provider' => 'dataforseo',
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('keywords.0.latest_result.rank', 4)
            ->assertJsonPath('keywords.0.latest_result.ranked', true)
            ->assertJsonPath('keywords.0.latest_result.provider', 'dataforseo');
    }

    public function test_keywords_of_another_store_front_are_not_listed(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);
        Keyword::factory()->forLocation($this->location)->create();
        Keyword::factory()->forLocation($other)->create();

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'keywords');
    }

    public function test_a_store_front_of_another_organization_is_not_reachable(): void
    {
        $foreign = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);
        Keyword::factory()->forLocation($foreign)->create(['keyword' => '他社 キーワード']);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url('', $foreign))
            ->assertNotFound();
    }

    public function test_a_store_manager_adds_a_keyword(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['keyword' => '渋谷 カフェ'])
            ->assertCreated()
            ->assertJsonPath('keyword.keyword', '渋谷 カフェ')
            ->assertJsonPath('keyword.is_active', true)
            ->assertJsonPath('allowance.remaining', 2);

        $this->assertDatabaseHas('keywords', [
            'organization_id' => $this->organization->id,
            'location_id' => $this->location->id,
            'keyword' => '渋谷 カフェ',
            'is_active' => true,
        ]);
    }

    public function test_the_keyword_is_required(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('keyword');
    }

    public function test_the_same_keyword_cannot_be_tracked_twice_for_one_store_front(): void
    {
        Keyword::factory()->forLocation($this->location)->create(['keyword' => '渋谷 カフェ']);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['keyword' => '渋谷 カフェ'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('keyword');
    }

    public function test_another_store_front_may_track_the_same_keyword(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);
        Keyword::factory()->forLocation($other)->create(['keyword' => '渋谷 カフェ']);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['keyword' => '渋谷 カフェ'])
            ->assertCreated();
    }

    public function test_a_staff_member_cannot_add_a_keyword(): void
    {
        $this->actingAs($this->member('staff'))
            ->postJson($this->url(), ['keyword' => '渋谷 カフェ'])
            ->assertForbidden();

        $this->assertDatabaseCount('keywords', 0);
    }

    public function test_the_plan_limit_refuses_a_further_keyword_with_an_upgrade_prompt(): void
    {
        Keyword::factory()->count(3)->forLocation($this->location)->create();

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['keyword' => '渋谷 カフェ'])
            ->assertForbidden()
            ->assertJsonPath('feature', 'ranking.keyword_limit')
            ->assertJsonPath('limit', 3)
            ->assertJsonPath('used', 3)
            ->assertJsonPath('upgrade.required', true);

        $this->assertDatabaseCount('keywords', 3);
    }

    public function test_a_paused_keyword_still_counts_against_the_limit(): void
    {
        Keyword::factory()->count(3)->paused()->forLocation($this->location)->create();

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['keyword' => '渋谷 カフェ'])
            ->assertForbidden();
    }

    public function test_the_limit_is_counted_per_store_front(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);
        Keyword::factory()->count(3)->forLocation($other)->create();

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['keyword' => '渋谷 カフェ'])
            ->assertCreated();
    }

    public function test_a_plan_without_ranking_cannot_reach_the_keywords_at_all(): void
    {
        $organization = $this->organizationOnPlan([Feature::RankingEnabled->value => false]);
        $location = Location::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($this->member('org_admin', $organization))
            ->getJson($this->url('', $location))
            ->assertForbidden()
            ->assertJsonPath('feature', 'ranking.enabled');
    }

    public function test_a_store_manager_renames_a_keyword(): void
    {
        $keyword = Keyword::factory()->forLocation($this->location)->create(['keyword' => '旧 キーワード']);

        $this->actingAs($this->member('location_admin'))
            ->patchJson($this->url("/{$keyword->id}"), ['keyword' => '新 キーワード'])
            ->assertOk()
            ->assertJsonPath('keyword.keyword', '新 キーワード');

        $this->assertSame('新 キーワード', $keyword->refresh()->keyword);
    }

    public function test_tracking_can_be_paused_without_deleting_the_keyword(): void
    {
        $keyword = Keyword::factory()->forLocation($this->location)->create();

        $this->actingAs($this->member('location_admin'))
            ->patchJson($this->url("/{$keyword->id}"), ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('keyword.is_active', false);

        $this->assertFalse($keyword->refresh()->is_active);
    }

    public function test_a_viewer_cannot_change_a_keyword(): void
    {
        $keyword = Keyword::factory()->forLocation($this->location)->create(['keyword' => '渋谷 カフェ']);

        $this->actingAs($this->member('viewer'))
            ->patchJson($this->url("/{$keyword->id}"), ['keyword' => '新宿 カフェ'])
            ->assertForbidden();

        $this->assertSame('渋谷 カフェ', $keyword->refresh()->keyword);
    }

    public function test_deleting_a_keyword_takes_its_history_and_frees_the_slot(): void
    {
        $keyword = Keyword::factory()->forLocation($this->location)->create();
        RankingResult::factory()->forKeyword($keyword)->create();

        $this->actingAs($this->member('location_admin'))
            ->deleteJson($this->url("/{$keyword->id}"))
            ->assertNoContent();

        $this->assertDatabaseMissing('keywords', ['id' => $keyword->id]);
        $this->assertDatabaseMissing('ranking_results', ['keyword_id' => $keyword->id]);
    }

    public function test_a_keyword_of_another_store_front_is_not_found(): void
    {
        $other = Location::factory()->create(['organization_id' => $this->organization->id]);
        $keyword = Keyword::factory()->forLocation($other)->create();

        $this->actingAs($this->member('location_admin'))
            ->deleteJson($this->url("/{$keyword->id}"))
            ->assertNotFound();

        $this->assertDatabaseHas('keywords', ['id' => $keyword->id]);
    }

    public function test_a_keyword_cannot_be_added_to_another_organizations_store_front(): void
    {
        $foreign = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url('', $foreign), ['keyword' => '渋谷 カフェ'])
            ->assertNotFound();

        $this->assertDatabaseCount('keywords', 0);
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }
}
