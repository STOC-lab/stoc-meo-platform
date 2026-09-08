<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Location;
use App\Models\Organization;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_queries_are_scoped_to_the_active_organization(): void
    {
        [$a, $b] = [Organization::factory()->create(), Organization::factory()->create()];

        Brand::factory()->for($a)->create();
        Brand::factory()->for($b)->count(2)->create();

        $tenancy = app(Tenancy::class);

        $tenancy->set($a);
        $this->assertSame(1, Brand::count());

        $tenancy->set($b);
        $this->assertSame(2, Brand::count());
    }

    public function test_organization_id_is_backfilled_on_create(): void
    {
        $organization = Organization::factory()->create();

        app(Tenancy::class)->set($organization);

        $location = Location::create(['name' => '渋谷店']);

        $this->assertSame($organization->id, $location->organization_id);
    }

    public function test_tenancy_can_be_bypassed(): void
    {
        $a = Organization::factory()->create();
        Brand::factory()->for($a)->create();
        Brand::factory()->for(Organization::factory()->create())->create();

        $tenancy = app(Tenancy::class);
        $tenancy->set($a);

        $this->assertSame(1, Brand::count());
        $this->assertSame(2, Brand::acrossTenants()->count());
        $this->assertSame(2, $tenancy->withoutTenancy(fn () => Brand::count()));
    }

    public function test_the_active_organization_is_restored_after_scoping_to_another(): void
    {
        [$a, $b] = [Organization::factory()->create(), Organization::factory()->create()];

        $tenancy = app(Tenancy::class);
        $tenancy->set($a);

        $inner = $tenancy->forOrganization($b, fn () => $tenancy->id());

        $this->assertSame($b->id, $inner);
        $this->assertSame($a->id, $tenancy->id());
    }

    public function test_no_active_organization_leaves_queries_unscoped(): void
    {
        Brand::factory()->count(2)->create();

        $this->assertFalse(app(Tenancy::class)->check());
        $this->assertSame(2, Brand::count());
    }
}
