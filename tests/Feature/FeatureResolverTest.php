<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\Organization;
use App\Models\Plan;
use App\Services\FeatureResolver;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureResolverTest extends TestCase
{
    use RefreshDatabase;

    protected FeatureResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(FeatureResolver::class);
    }

    protected function organizationWith(array $features): Organization
    {
        $plan = Plan::factory()->withFeatures($features)->create();

        return Organization::factory()->onPlan($plan)->create();
    }

    public function test_it_reads_limits_from_the_plan(): void
    {
        $organization = $this->organizationWith([
            Feature::RankingKeywordLimit->value => 3,
        ]);

        $this->assertSame(3, $this->resolver->limit(Feature::RankingKeywordLimit, $organization));
        $this->assertTrue($this->resolver->allows(Feature::RankingKeywordLimit, $organization));
    }

    public function test_a_null_limit_means_unlimited(): void
    {
        $organization = $this->organizationWith([
            Feature::RankingKeywordLimit->value => null,
        ]);

        $this->assertNull($this->resolver->limit(Feature::RankingKeywordLimit, $organization));
        $this->assertTrue($this->resolver->isUnlimited(Feature::RankingKeywordLimit, $organization));
        $this->assertTrue($this->resolver->allows(Feature::RankingKeywordLimit, $organization));
    }

    public function test_a_feature_the_plan_omits_is_denied_and_reads_as_zero(): void
    {
        $organization = $this->organizationWith([
            Feature::RankingKeywordLimit->value => 3,
        ]);

        $this->assertFalse($this->resolver->has(Feature::MultiLocationEnabled, $organization));
        $this->assertFalse($this->resolver->allows(Feature::MultiLocationEnabled, $organization));
        $this->assertSame(0, $this->resolver->limit(Feature::MultiLocationEnabled, $organization));
        $this->assertFalse($this->resolver->isUnlimited(Feature::MultiLocationEnabled, $organization));
    }

    public function test_boolean_features_are_read_as_flags(): void
    {
        $organization = $this->organizationWith([
            Feature::PdfReportEnabled->value => true,
            Feature::MultiLocationEnabled->value => false,
        ]);

        $this->assertTrue($this->resolver->value(Feature::PdfReportEnabled, $organization));
        $this->assertFalse($this->resolver->value(Feature::MultiLocationEnabled, $organization));
        $this->assertTrue($this->resolver->allows(Feature::PdfReportEnabled, $organization));
        $this->assertFalse($this->resolver->allows(Feature::MultiLocationEnabled, $organization));

        // A flag the plan sets to false is still "known" to the plan.
        $this->assertTrue($this->resolver->has(Feature::MultiLocationEnabled, $organization));
    }

    public function test_a_zero_limit_denies_the_feature(): void
    {
        $organization = $this->organizationWith([
            Feature::GbpPostMonthlyLimit->value => 0,
        ]);

        $this->assertFalse($this->resolver->allows(Feature::GbpPostMonthlyLimit, $organization));
    }

    public function test_an_organization_without_a_plan_has_no_features(): void
    {
        $organization = Organization::factory()->create();

        $this->assertTrue($this->resolver->features($organization)->isEmpty());
        $this->assertFalse($this->resolver->allows(Feature::PdfReportEnabled, $organization));
    }

    public function test_it_falls_back_to_the_active_tenant(): void
    {
        $organization = $this->organizationWith([
            Feature::RankingKeywordLimit->value => 20,
        ]);

        app(Tenancy::class)->set($organization);

        $this->assertSame(20, $this->resolver->limit(Feature::RankingKeywordLimit));
    }

    public function test_flushing_the_cache_picks_up_plan_changes(): void
    {
        $organization = $this->organizationWith([
            Feature::RankingKeywordLimit->value => 20,
        ]);

        $this->assertSame(20, $this->resolver->limit(Feature::RankingKeywordLimit, $organization));

        $organization->plan->features()->where('key', Feature::RankingKeywordLimit->value)->update(['value' => '50']);

        // Still cached against the old plan definition.
        $this->assertSame(20, $this->resolver->limit(Feature::RankingKeywordLimit, $organization));

        $this->resolver->flush($organization->plan);

        $this->assertSame(50, $this->resolver->limit(Feature::RankingKeywordLimit, $organization->fresh()));
    }
}
