<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Services\FeatureResolver;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_creates_the_meo_and_instagram_catalogue(): void
    {
        $this->seed(PlanSeeder::class);

        $this->assertSame(
            ['meo_free', 'meo_light', 'meo_standard', 'meo_premium', 'ig_light', 'ig_standard', 'ig_premium'],
            Plan::orderBy('sort_order')->pluck('code')->all(),
        );

        $this->assertSame(4, Plan::product(Plan::PRODUCT_MEO)->count());
        $this->assertSame(3, Plan::product(Plan::PRODUCT_INSTAGRAM)->count());
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(PlanSeeder::class);
        $features = PlanFeature::count();

        $this->seed(PlanSeeder::class);

        $this->assertSame(7, Plan::count());
        $this->assertSame($features, PlanFeature::count());
    }

    public function test_the_free_plan_is_free_and_the_premium_plan_is_unlimited(): void
    {
        $this->seed(PlanSeeder::class);

        $free = Plan::where('code', 'meo_free')->firstOrFail();
        $premium = Plan::where('code', 'meo_premium')->firstOrFail();
        $resolver = app(FeatureResolver::class);

        $this->assertTrue($free->isFree());
        $this->assertSame(0, $free->trial_days);
        $this->assertSame(1, $resolver->limit(Feature::LocationsMax, $this->organizationOn($free)));

        $this->assertFalse($premium->isFree());
        $this->assertNull($resolver->limit(Feature::LocationsMax, $this->organizationOn($premium)));
        $this->assertTrue($resolver->allows(Feature::ApiAccess, $this->organizationOn($premium)));
    }

    public function test_every_seeded_feature_key_is_known_to_the_feature_enum(): void
    {
        $this->seed(PlanSeeder::class);

        $unknown = PlanFeature::pluck('key')
            ->unique()
            ->reject(fn (string $key) => Feature::tryFrom($key) !== null);

        $this->assertEmpty($unknown, 'Unknown feature keys: '.$unknown->implode(', '));
    }

    public function test_feature_values_are_stored_with_the_type_the_enum_declares(): void
    {
        $this->seed(PlanSeeder::class);

        PlanFeature::each(function (PlanFeature $feature) {
            $this->assertSame(
                $feature->feature()->type(),
                $feature->type,
                "Feature [{$feature->key}] is stored as {$feature->type->value}.",
            );
        });
    }

    protected function organizationOn(Plan $plan): Organization
    {
        return Organization::factory()->onPlan($plan)->create();
    }
}
