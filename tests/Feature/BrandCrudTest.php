<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandCrudTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    protected function member(string $role): User
    {
        $user = User::factory()->create();
        $this->organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    protected function url(string $path = ''): string
    {
        return "/api/v1/organizations/{$this->organization->id}/brands".$path;
    }

    public function test_a_member_lists_the_brands_of_the_organization(): void
    {
        $brand = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'あかね珈琲']);
        Location::factory()->count(2)->create([
            'organization_id' => $this->organization->id,
            'brand_id' => $brand->id,
        ]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'brands')
            ->assertJsonPath('brands.0.name', 'あかね珈琲')
            ->assertJsonPath('brands.0.locations_count', 2);
    }

    public function test_brands_of_another_organization_are_not_listed(): void
    {
        Brand::factory()->create(['organization_id' => $this->organization->id]);
        Brand::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'brands');
    }

    public function test_a_member_reads_a_single_brand(): void
    {
        $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url("/{$brand->id}"))
            ->assertOk()
            ->assertJsonPath('brand.id', $brand->id);
    }

    public function test_an_admin_creates_a_brand(): void
    {
        $this->actingAs($this->member('admin'))
            ->postJson($this->url(), ['name' => 'あかね珈琲'])
            ->assertCreated()
            ->assertJsonPath('brand.name', 'あかね珈琲')
            ->assertJsonPath('brand.locations_count', 0);

        $this->assertDatabaseHas('brands', [
            'organization_id' => $this->organization->id,
            'name' => 'あかね珈琲',
        ]);
    }

    public function test_the_brand_name_is_required(): void
    {
        $this->actingAs($this->member('admin'))
            ->postJson($this->url(), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_a_brand_name_cannot_repeat_within_the_organization(): void
    {
        Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'あかね珈琲']);

        $this->actingAs($this->member('admin'))
            ->postJson($this->url(), ['name' => 'あかね珈琲'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_another_organization_may_use_the_same_brand_name(): void
    {
        Brand::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'name' => 'あかね珈琲',
        ]);

        $this->actingAs($this->member('admin'))
            ->postJson($this->url(), ['name' => 'あかね珈琲'])
            ->assertCreated();
    }

    public function test_an_editor_cannot_create_a_brand(): void
    {
        $this->actingAs($this->member('editor'))
            ->postJson($this->url(), ['name' => 'あかね珈琲'])
            ->assertForbidden();

        $this->assertDatabaseCount('brands', 0);
    }

    public function test_an_admin_renames_a_brand(): void
    {
        $brand = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => '旧名']);

        $this->actingAs($this->member('admin'))
            ->patchJson($this->url("/{$brand->id}"), ['name' => '新名'])
            ->assertOk()
            ->assertJsonPath('brand.name', '新名');

        $this->assertSame('新名', $brand->refresh()->name);
    }

    public function test_renaming_a_brand_to_its_own_name_is_allowed(): void
    {
        $brand = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'あかね珈琲']);

        $this->actingAs($this->member('admin'))
            ->patchJson($this->url("/{$brand->id}"), ['name' => 'あかね珈琲'])
            ->assertOk();
    }

    public function test_an_admin_deletes_a_brand_and_its_locations_survive(): void
    {
        $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
        $location = Location::factory()->create([
            'organization_id' => $this->organization->id,
            'brand_id' => $brand->id,
        ]);

        $this->actingAs($this->member('admin'))
            ->deleteJson($this->url("/{$brand->id}"))
            ->assertNoContent();

        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
        $this->assertDatabaseHas('locations', ['id' => $location->id, 'brand_id' => null]);
    }

    public function test_an_editor_cannot_delete_a_brand(): void
    {
        $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->member('editor'))
            ->deleteJson($this->url("/{$brand->id}"))
            ->assertForbidden();

        $this->assertDatabaseHas('brands', ['id' => $brand->id]);
    }

    public function test_a_brand_of_another_organization_is_not_found(): void
    {
        $other = Brand::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($this->member('admin'))
            ->getJson($this->url("/{$other->id}"))
            ->assertNotFound();
    }

    public function test_a_user_outside_the_organization_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson($this->url())
            ->assertForbidden();
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }
}
