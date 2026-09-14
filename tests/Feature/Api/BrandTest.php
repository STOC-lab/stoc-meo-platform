<?php

namespace Tests\Feature\Api;

use App\Enums\Feature;
use App\Models\Brand;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The brand CRUD surface as the API exposes it: tenant isolation, the plan's
 * ceiling on how many a chain may run, and the shape of what comes back.
 *
 * `BrandCrudTest` covers the roles that may reach each verb; this covers what
 * the rows themselves have to obey.
 */
class BrandTest extends TestCase
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
        return "/api/v1/organizations/{$this->organization->id}/brands".$path;
    }

    /**
     * Put the organization on a plan that allows exactly this many brands.
     * Null is unlimited.
     */
    protected function onPlanAllowing(?int $brands): void
    {
        $plan = Plan::factory()
            ->withFeatures([Feature::BrandLimit->value => $brands])
            ->create();

        $this->organization->forceFill(['plan_id' => $plan->getKey()])->save();
    }

    protected function brand(array $attributes = []): Brand
    {
        return Brand::factory()->create($attributes + ['organization_id' => $this->organization->id]);
    }

    public function test_a_guest_is_refused_every_verb(): void
    {
        $brand = $this->brand();

        $this->getJson($this->url())->assertUnauthorized();
        $this->postJson($this->url(), ['name' => 'あかね珈琲'])->assertUnauthorized();
        $this->getJson($this->url("/{$brand->id}"))->assertUnauthorized();
        $this->patchJson($this->url("/{$brand->id}"), ['name' => '別名'])->assertUnauthorized();
        $this->deleteJson($this->url("/{$brand->id}"))->assertUnauthorized();
    }

    public function test_the_list_holds_only_this_organizations_brands(): void
    {
        $this->brand(['name' => 'あかね珈琲']);
        Brand::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'name' => 'よその珈琲',
        ]);

        $response = $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'brands')
            ->assertJsonPath('brands.0.name', 'あかね珈琲');

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(15, $response->json('meta.per_page'));
    }

    public function test_the_list_pages_at_fifteen(): void
    {
        $this->onPlanAllowing(null);

        for ($i = 1; $i <= 17; $i++) {
            $this->brand(['name' => 'ブランド'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(15, 'brands')
            ->assertJsonPath('meta.total', 17)
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url().'?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'brands');
    }

    public function test_a_brand_is_created_with_a_slug_of_its_own(): void
    {
        $this->onPlanAllowing(null);

        $this->actingAs($this->member())
            ->postJson($this->url(), [
                'name' => 'Akane Coffee',
                'description' => '姫路の自家焙煎コーヒー店。',
                'website_url' => 'https://akane.example.com',
                'logo_url' => 'https://akane.example.com/logo.png',
            ])
            ->assertCreated()
            ->assertJsonPath('brand.slug', 'akane-coffee')
            ->assertJsonPath('brand.description', '姫路の自家焙煎コーヒー店。')
            ->assertJsonPath('brand.website_url', 'https://akane.example.com')
            ->assertJsonPath('brand.locations_count', 0);
    }

    public function test_a_japanese_name_falls_back_to_a_readable_slug(): void
    {
        // Str::slug() drops what it cannot transliterate, so a name written
        // only in Japanese slugs to nothing. The fallback has to stay unique.
        $this->onPlanAllowing(null);

        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => 'あかね珈琲'])
            ->assertCreated()
            ->assertJsonPath('brand.slug', 'brand');

        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => 'さくら珈琲'])
            ->assertCreated()
            ->assertJsonPath('brand.slug', 'brand-2');
    }

    public function test_two_organizations_may_hold_the_same_slug(): void
    {
        $this->onPlanAllowing(null);
        $mine = $this->brand(['name' => 'Sakura']);

        $other = Organization::factory()->create();
        $theirs = Brand::factory()->create(['organization_id' => $other->id, 'name' => 'Sakura']);

        // A tenant must not learn that another exists by failing to take a name.
        $this->assertSame('sakura', $mine->fresh()->slug);
        $this->assertSame('sakura', $theirs->fresh()->slug);
    }

    public function test_a_nameless_brand_is_refused(): void
    {
        $this->onPlanAllowing(null);

        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_a_logo_that_is_not_a_url_is_refused(): void
    {
        $this->onPlanAllowing(null);

        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => 'Akane', 'logo_url' => 'not-a-url'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo_url');
    }

    public function test_a_brand_beyond_the_plans_allowance_is_refused(): void
    {
        $this->onPlanAllowing(2);
        $this->brand(['name' => 'One']);
        $this->brand(['name' => 'Two']);

        // A plan refusal is a 403 with the upgrade envelope, which is how
        // every other entitlement in this application answers.
        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => 'Three'])
            ->assertForbidden()
            ->assertJsonPath('feature', 'brand.limit');

        $this->assertSame(2, Brand::acrossTenants()->where('organization_id', $this->organization->id)->count());
    }

    public function test_deleting_a_brand_gives_the_slot_straight_back(): void
    {
        $this->onPlanAllowing(1);
        $brand = $this->brand(['name' => 'One']);

        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => 'Two'])
            ->assertForbidden();

        $this->actingAs($this->member())->deleteJson($this->url("/{$brand->id}"))->assertNoContent();

        // Not a monthly counter: the slot is free the same second.
        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => 'Two'])
            ->assertCreated();
    }

    public function test_a_plan_that_says_nothing_about_brands_is_not_given_a_ceiling(): void
    {
        // FeatureResolver answers 0 both for "the plan sets zero" and for "the
        // plan has never heard of this". An organization on an older plan must
        // not be locked out of its own product by the second.
        $plan = Plan::factory()->withFeatures([])->create();
        $this->organization->forceFill(['plan_id' => $plan->getKey()])->save();

        $this->actingAs($this->member())
            ->postJson($this->url(), ['name' => 'Akane'])
            ->assertCreated();
    }

    public function test_a_brand_is_read_back_with_its_store_front_count(): void
    {
        $brand = $this->brand(['name' => 'Akane']);
        Location::factory()->count(2)->create([
            'organization_id' => $this->organization->id,
            'brand_id' => $brand->id,
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url("/{$brand->id}"))
            ->assertOk()
            ->assertJsonPath('brand.id', $brand->id)
            ->assertJsonPath('brand.locations_count', 2);
    }

    public function test_a_brand_is_updated_and_its_slug_follows_the_name(): void
    {
        $brand = $this->brand(['name' => 'Akane']);

        $this->actingAs($this->member())
            ->patchJson($this->url("/{$brand->id}"), ['name' => 'Sakura'])
            ->assertOk()
            ->assertJsonPath('brand.name', 'Sakura')
            ->assertJsonPath('brand.slug', 'sakura');
    }

    public function test_an_empty_brand_is_soft_deleted(): void
    {
        $brand = $this->brand();

        $this->actingAs($this->member())
            ->deleteJson($this->url("/{$brand->id}"))
            ->assertNoContent();

        $this->assertSoftDeleted('brands', ['id' => $brand->id]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(0, 'brands');
    }

    public function test_a_brand_with_store_fronts_is_refused_deletion(): void
    {
        $brand = $this->brand();
        Location::factory()->create([
            'organization_id' => $this->organization->id,
            'brand_id' => $brand->id,
        ]);

        $this->actingAs($this->member())
            ->deleteJson($this->url("/{$brand->id}"))
            ->assertUnprocessable();

        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'deleted_at' => null]);
    }

    public function test_another_organizations_brand_is_not_there(): void
    {
        $foreign = Brand::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $member = $this->member();

        $this->actingAs($member)->getJson($this->url("/{$foreign->id}"))->assertNotFound();
        $this->actingAs($member)->patchJson($this->url("/{$foreign->id}"), ['name' => 'x'])->assertNotFound();
        $this->actingAs($member)->deleteJson($this->url("/{$foreign->id}"))->assertNotFound();

        $this->assertDatabaseHas('brands', ['id' => $foreign->id, 'deleted_at' => null]);
    }
}
