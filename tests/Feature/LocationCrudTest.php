<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationCrudTest extends TestCase
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
        return "/api/v1/organizations/{$this->organization->id}/locations".$path;
    }

    protected function location(array $attributes = []): Location
    {
        return Location::factory()->create($attributes + ['organization_id' => $this->organization->id]);
    }

    public function test_a_member_lists_the_store_fronts_of_the_organization(): void
    {
        $brand = Brand::factory()->create(['organization_id' => $this->organization->id, 'name' => 'あかね珈琲']);
        $this->location(['name' => '渋谷店', 'brand_id' => $brand->id]);
        Location::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.name', '渋谷店')
            ->assertJsonPath('locations.0.brand.name', 'あかね珈琲')
            ->assertJsonPath('locations.0.linked_to_gbp', true);
    }

    public function test_the_list_can_be_narrowed_to_one_brand(): void
    {
        $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);
        $this->location(['name' => '渋谷店', 'brand_id' => $brand->id]);
        $this->location(['name' => '新宿店', 'brand_id' => null]);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url()."?brand_id={$brand->id}")
            ->assertOk()
            ->assertJsonCount(1, 'locations')
            ->assertJsonPath('locations.0.name', '渋谷店');
    }

    public function test_a_member_reads_a_single_store_front(): void
    {
        $location = $this->location(['name' => '渋谷店']);

        $this->actingAs($this->member('viewer'))
            ->getJson($this->url("/{$location->id}"))
            ->assertOk()
            ->assertJsonPath('location.name', '渋谷店');
    }

    public function test_an_org_admin_creates_a_store_front(): void
    {
        $brand = Brand::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->member('org_admin'))
            ->postJson($this->url(), [
                'name' => '渋谷店',
                'brand_id' => $brand->id,
                'gbp_location_id' => 'locations/1234567890',
                'website_url' => 'https://example.com',
                'phone' => '03-1234-5678',
                'address' => '東京都渋谷区1-2-3',
            ])
            ->assertCreated()
            ->assertJsonPath('location.name', '渋谷店')
            ->assertJsonPath('location.brand.id', $brand->id)
            ->assertJsonPath('location.linked_to_gbp', true);

        $this->assertDatabaseHas('locations', [
            'organization_id' => $this->organization->id,
            'name' => '渋谷店',
            'gbp_location_id' => 'locations/1234567890',
        ]);
    }

    public function test_a_store_front_may_be_created_without_a_brand_or_a_profile(): void
    {
        $this->actingAs($this->member('org_admin'))
            ->postJson($this->url(), ['name' => '渋谷店'])
            ->assertCreated()
            ->assertJsonPath('location.brand', null)
            ->assertJsonPath('location.linked_to_gbp', false);
    }

    public function test_the_store_front_name_is_required(): void
    {
        $this->actingAs($this->member('org_admin'))
            ->postJson($this->url(), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_a_brand_from_another_organization_is_rejected(): void
    {
        $foreign = Brand::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($this->member('org_admin'))
            ->postJson($this->url(), ['name' => '渋谷店', 'brand_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('brand_id');
    }

    public function test_a_business_profile_cannot_be_linked_to_two_store_fronts(): void
    {
        $this->location(['gbp_location_id' => 'locations/1234567890']);

        $this->actingAs($this->member('org_admin'))
            ->postJson($this->url(), ['name' => '渋谷店', 'gbp_location_id' => 'locations/1234567890'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gbp_location_id');
    }

    public function test_a_location_admin_cannot_create_a_store_front(): void
    {
        $this->actingAs($this->member('location_admin'))
            ->postJson($this->url(), ['name' => '渋谷店'])
            ->assertForbidden();

        $this->assertDatabaseCount('locations', 0);
    }

    public function test_a_location_admin_updates_a_store_front(): void
    {
        $location = $this->location(['name' => '旧店名']);

        $this->actingAs($this->member('location_admin'))
            ->patchJson($this->url("/{$location->id}"), ['name' => '渋谷店', 'phone' => '03-0000-0000'])
            ->assertOk()
            ->assertJsonPath('location.name', '渋谷店');

        $location->refresh();

        $this->assertSame('渋谷店', $location->name);
        $this->assertSame('03-0000-0000', $location->phone);
    }

    public function test_an_update_leaves_out_fields_alone(): void
    {
        $location = $this->location(['name' => '渋谷店', 'address' => '東京都渋谷区1-2-3']);

        $this->actingAs($this->member('location_admin'))
            ->patchJson($this->url("/{$location->id}"), ['phone' => '03-0000-0000'])
            ->assertOk();

        $this->assertSame('東京都渋谷区1-2-3', $location->refresh()->address);
    }

    public function test_keeping_its_own_business_profile_on_update_is_allowed(): void
    {
        $location = $this->location(['gbp_location_id' => 'locations/1234567890']);

        $this->actingAs($this->member('location_admin'))
            ->patchJson($this->url("/{$location->id}"), ['gbp_location_id' => 'locations/1234567890'])
            ->assertOk();
    }

    public function test_a_staff_member_cannot_update_a_store_front(): void
    {
        $location = $this->location(['name' => '渋谷店']);

        $this->actingAs($this->member('staff'))
            ->patchJson($this->url("/{$location->id}"), ['name' => '新宿店'])
            ->assertForbidden();

        $this->assertSame('渋谷店', $location->refresh()->name);
    }

    public function test_a_staff_member_may_still_read_the_store_fronts(): void
    {
        $this->location(['name' => '渋谷店']);

        $this->actingAs($this->member('staff'))
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('locations.0.name', '渋谷店');
    }

    public function test_an_org_admin_deletes_a_store_front(): void
    {
        $location = $this->location();

        $this->actingAs($this->member('org_admin'))
            ->deleteJson($this->url("/{$location->id}"))
            ->assertNoContent();

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    public function test_a_location_admin_cannot_delete_a_store_front(): void
    {
        $location = $this->location();

        $this->actingAs($this->member('location_admin'))
            ->deleteJson($this->url("/{$location->id}"))
            ->assertForbidden();

        $this->assertDatabaseHas('locations', ['id' => $location->id]);
    }

    public function test_a_store_front_of_another_organization_is_not_found(): void
    {
        $foreign = Location::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($this->member('org_admin'))
            ->getJson($this->url("/{$foreign->id}"))
            ->assertNotFound();
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
    }
}
