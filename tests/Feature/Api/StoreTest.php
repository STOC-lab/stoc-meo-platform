<?php

namespace Tests\Feature\Api;

use App\Enums\Feature;
use App\Models\Brand;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The store front CRUD surface. A "store" in the specification is this
 * application's `Location`: the row every keyword, ranking, review and score
 * already hangs off.
 *
 * `LocationCrudTest` covers which role may reach which verb; this covers
 * tenant isolation, the filters, the plan's ceiling, and the fields.
 */
class StoreTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    protected function member(string $role = 'org_admin'): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    protected function url(string $path = ''): string
    {
        return "/api/v1/organizations/{$this->organization->id}/locations".$path;
    }

    /**
     * Put the organization on a plan running more than one shop, with the
     * given ceiling. Null is unlimited.
     */
    protected function onPlanAllowing(?int $stores): void
    {
        $plan = Plan::factory()
            ->withFeatures([
                Feature::MultiLocationEnabled->value => true,
                Feature::LocationLimit->value => $stores,
            ])
            ->create();

        $this->organization->forceFill(['plan_id' => $plan->getKey()])->save();
    }

    protected function store(array $attributes = []): Location
    {
        return Location::factory()->create($attributes + ['organization_id' => $this->organization->id]);
    }

    public function test_a_guest_is_refused_every_verb(): void
    {
        $store = $this->store();

        $this->getJson($this->url())->assertUnauthorized();
        $this->postJson($this->url(), ['name' => '渋谷店'])->assertUnauthorized();
        $this->getJson($this->url("/{$store->id}"))->assertUnauthorized();
        $this->patchJson($this->url("/{$store->id}"), ['name' => 'x'])->assertUnauthorized();
        $this->deleteJson($this->url("/{$store->id}"))->assertUnauthorized();
    }

    public function test_the_list_holds_only_this_organizations_store_fronts(): void
    {
        $this->store(['name' => '渋谷店']);
        Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'name' => 'よその店',
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.name', '渋谷店')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_list_pages_at_fifteen(): void
    {
        $this->onPlanAllowing(null);

        for ($i = 1; $i <= 17; $i++) {
            $this->store(['name' => '店舗'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(15, 'locations')
            ->assertJsonPath('meta.total', 17)
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url().'?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'locations');
    }

    public function test_the_list_can_be_narrowed_to_one_brand(): void
    {
        $this->onPlanAllowing(null);

        $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
        $this->store(['name' => '渋谷店', 'brand_id' => $brand->id]);
        $this->store(['name' => '姫路店', 'brand_id' => null]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url()."?brand_id={$brand->id}")
            ->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.name', '渋谷店')
            ->assertJsonPath('locations.0.brand.id', $brand->id);
    }

    public function test_a_brand_filter_cannot_reach_across_organizations(): void
    {
        $foreignBrand = Brand::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->store(['name' => '渋谷店']);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url()."?brand_id={$foreignBrand->id}")
            ->assertOk()
            ->assertJsonCount(0, 'locations');
    }

    public function test_the_list_can_be_narrowed_to_the_ones_being_run(): void
    {
        $this->onPlanAllowing(null);

        $this->store(['name' => '営業中', 'is_active' => true]);
        $this->store(['name' => '休止中', 'is_active' => false]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url().'?is_active=1')
            ->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.name', '営業中');

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url().'?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.name', '休止中');
    }

    public function test_a_store_front_is_created_with_its_address_parts_and_a_slug(): void
    {
        $this->actingAs($this->member())
            ->postJson($this->url(), [
                'name' => 'Akane Shibuya',
                'phone' => '03-1234-5678',
                'postal_code' => '150-0001',
                'prefecture' => '東京都',
                'city' => '渋谷区',
                'address' => '神宮前1-2-3',
                'google_place_id' => 'ChIJ_abc123',
                'google_maps_url' => 'https://maps.google.com/?cid=1',
            ])
            ->assertCreated()
            ->assertJsonPath('location.slug', 'akane-shibuya')
            ->assertJsonPath('location.postal_code', '150-0001')
            ->assertJsonPath('location.prefecture', '東京都')
            ->assertJsonPath('location.city', '渋谷区')
            ->assertJsonPath('location.google_place_id', 'ChIJ_abc123')
            ->assertJsonPath('location.is_active', true);
    }

    public function test_a_japanese_name_falls_back_to_a_readable_slug(): void
    {
        $this->onPlanAllowing(null);

        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => '渋谷店'])
            ->assertCreated()
            ->assertJsonPath('location.slug', 'store');

        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => '姫路店'])
            ->assertCreated()
            ->assertJsonPath('location.slug', 'store-2');
    }

    public function test_a_nameless_store_front_is_refused(): void
    {
        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_a_malformed_postal_code_is_refused(): void
    {
        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => '渋谷店', 'postal_code' => '15-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('postal_code');
    }

    public function test_a_brand_from_another_organization_cannot_be_attached(): void
    {
        $foreignBrand = Brand::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => '渋谷店', 'brand_id' => $foreignBrand->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('brand_id');
    }

    public function test_a_store_front_beyond_the_plans_allowance_is_refused(): void
    {
        $this->onPlanAllowing(2);
        $this->store(['name' => 'One']);
        $this->store(['name' => 'Two']);

        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => 'Three'])
            ->assertForbidden()
            ->assertJsonPath('feature', 'location.limit');

        $this->assertSame(
            2,
            Location::acrossTenants()->where('organization_id', $this->organization->id)->count(),
        );
    }

    public function test_deleting_a_store_front_gives_the_slot_straight_back(): void
    {
        $this->onPlanAllowing(1);
        $store = $this->store(['name' => 'One']);

        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => 'Two'])
            ->assertForbidden();

        $this->actingAs($this->member())->deleteJson($this->url("/{$store->id}"))->assertNoContent();

        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => 'Two'])
            ->assertCreated();
    }

    public function test_a_store_front_is_read_back_with_its_brand(): void
    {
        $brand = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Akane']);
        $store = $this->store(['brand_id' => $brand->id]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url("/{$store->id}"))
            ->assertOk()
            ->assertJsonPath('location.id', $store->id)
            ->assertJsonPath('location.brand.name', 'Akane')
            ->assertJsonPath('location.brand.slug', 'akane');
    }

    public function test_a_store_front_can_be_paused_without_losing_anything(): void
    {
        $store = $this->store();
        Keyword::factory()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $store->id,
        ]);

        $this->actingAs($this->member())
            ->patchJson($this->url("/{$store->id}"), ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('location.is_active', false);

        $this->assertSame(1, $store->keywords()->count());
        $this->assertSame(0, Location::query()->active()->count());
    }

    public function test_deleting_a_store_front_is_soft_and_keeps_its_history(): void
    {
        $store = $this->store();
        $keyword = Keyword::factory()->create([
            'organization_id' => $this->organization->id,
            'location_id' => $store->id,
        ]);

        $this->actingAs($this->member())
            ->deleteJson($this->url("/{$store->id}"))
            ->assertNoContent();

        $this->assertSoftDeleted('locations', ['id' => $store->id]);
        $this->assertDatabaseHas('keywords', ['id' => $keyword->id]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(0, 'locations');
    }

    public function test_another_organizations_store_front_is_not_there(): void
    {
        $foreign = Location::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $member = $this->member();

        $this->actingAs($member)->getJson($this->url("/{$foreign->id}"))->assertNotFound();
        $this->actingAs($member)->patchJson($this->url("/{$foreign->id}"), ['name' => 'x'])->assertNotFound();
        $this->actingAs($member)->deleteJson($this->url("/{$foreign->id}"))->assertNotFound();

        $this->assertDatabaseHas('locations', ['id' => $foreign->id, 'deleted_at' => null]);
    }
}
