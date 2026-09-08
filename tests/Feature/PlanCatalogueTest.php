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

/**
 * Pins the catalogue to STOC MEO SYSTEM DESIGN v1.3 §27-29.
 */
class PlanCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected FeatureResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(FeatureResolver::class);
        $this->seed(PlanSeeder::class);
    }

    public function test_the_seeder_creates_the_meo_and_instagram_catalogue(): void
    {
        $this->assertSame(
            ['meo_free', 'meo_light', 'meo_standard', 'meo_premium', 'ig_light', 'ig_standard', 'ig_premium'],
            Plan::orderBy('sort_order')->pluck('code')->all(),
        );

        $this->assertSame(4, Plan::product(Plan::PRODUCT_MEO)->count());
        $this->assertSame(3, Plan::product(Plan::PRODUCT_INSTAGRAM)->count());
    }

    public function test_prices_match_the_design_document(): void
    {
        $this->assertSame([
            'meo_free' => 0,
            'meo_light' => 9000,
            'meo_standard' => 15000,
            'meo_premium' => 30000,
            'ig_light' => 5000,
            'ig_standard' => 9000,
            'ig_premium' => 15000,
        ], Plan::orderBy('sort_order')->pluck('price', 'code')->all());
    }

    public function test_no_plan_offers_a_trial(): void
    {
        $this->assertSame(0, (int) Plan::sum('trial_days'));
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $features = PlanFeature::count();

        $this->seed(PlanSeeder::class);

        $this->assertSame(7, Plan::count());
        $this->assertSame($features, PlanFeature::count());
    }

    /**
     * @return array<int, array{0: Feature, 1: array<int, int|bool>}>
     */
    protected function meoMatrix(): array
    {
        // FREE, LIGHT, STANDARD, PREMIUM
        return [
            [Feature::RankingEnabled, [true, true, true, true]],
            [Feature::RankingDaily, [false, true, true, true]],
            [Feature::RankingKeywordLimit, [3, 10, 20, 30]],
            [Feature::Heatmap5x5MonthlyLimit, [0, 1, 2, 4]],
            [Feature::Heatmap7x7MonthlyLimit, [0, 0, 1, 4]],
            [Feature::CompetitorLimit, [0, 3, 5, 10]],
            [Feature::ReviewAiReplyEnabled, [false, true, true, true]],
            [Feature::ReviewAiReplyMonthlyLimit, [0, 30, 100, 500]],
            [Feature::ReviewAutoReplyEnabled, [false, false, true, true]],
            [Feature::GbpPostMonthlyLimit, [0, 4, 12, 30]],
            [Feature::InstagramEnabled, [false, false, true, true]],
            [Feature::InstagramPostMonthlyLimit, [0, 0, 4, 12]],
            [Feature::InstagramAutoPublishEnabled, [false, false, false, true]],
            [Feature::BlogEnabled, [false, false, false, false]],
            [Feature::CitationEnabled, [false, false, true, true]],
            [Feature::CitationMonthlyLimit, [0, 0, 1, 4]],
            [Feature::AiDailyAnalysisEnabled, [false, false, false, true]],
            [Feature::AiWeeklyAnalysisEnabled, [false, false, true, true]],
            [Feature::AiImprovementProposalsEnabled, [false, false, true, true]],
            [Feature::PdfReportEnabled, [false, true, true, true]],
            [Feature::MultiLocationEnabled, [false, false, false, true]],
        ];
    }

    public function test_the_meo_matrix_is_seeded_for_every_tier(): void
    {
        $organizations = collect(['meo_free', 'meo_light', 'meo_standard', 'meo_premium'])
            ->map(fn (string $code) => [$code, $this->organizationOn($code)]);

        foreach ($this->meoMatrix() as [$feature, $expected]) {
            foreach ($organizations as $index => [$code, $organization]) {
                $this->assertSame(
                    $expected[$index],
                    $this->resolver->value($feature, $organization),
                    "{$feature->value} on {$code}",
                );
            }
        }
    }

    public function test_instagram_plans_carry_only_their_instagram_features(): void
    {
        $expected = [
            'ig_light' => [
                Feature::InstagramEnabled->value => true,
                Feature::InstagramPostMonthlyLimit->value => 4,
            ],
            'ig_standard' => [
                Feature::InstagramEnabled->value => true,
                Feature::InstagramPostMonthlyLimit->value => 12,
            ],
            'ig_premium' => [
                Feature::InstagramEnabled->value => true,
                Feature::InstagramPostMonthlyLimit->value => 30,
                Feature::InstagramAutoPublishEnabled->value => true,
            ],
        ];

        foreach ($expected as $code => $features) {
            ksort($features);

            $this->assertSame(
                $features,
                $this->resolver->features($this->organizationOn($code))->sortKeys()->all(),
                $code,
            );
        }
    }

    public function test_a_zero_allowance_denies_the_feature(): void
    {
        $free = $this->organizationOn('meo_free');
        $premium = $this->organizationOn('meo_premium');

        // FREE knows about heatmaps but is allowed none of them.
        $this->assertTrue($this->resolver->has(Feature::Heatmap5x5MonthlyLimit, $free));
        $this->assertFalse($this->resolver->allows(Feature::Heatmap5x5MonthlyLimit, $free));
        $this->assertTrue($this->resolver->allows(Feature::Heatmap5x5MonthlyLimit, $premium));

        // Ranking is on from the free tier, but only daily from LIGHT up.
        $this->assertTrue($this->resolver->allows(Feature::RankingEnabled, $free));
        $this->assertFalse($this->resolver->allows(Feature::RankingDaily, $free));
    }

    public function test_every_seeded_feature_key_is_known_to_the_feature_enum(): void
    {
        $unknown = PlanFeature::pluck('key')
            ->unique()
            ->reject(fn (string $key) => Feature::tryFrom($key) !== null);

        $this->assertEmpty($unknown, 'Unknown feature keys: '.$unknown->implode(', '));
    }

    public function test_feature_values_are_stored_with_the_type_the_enum_declares(): void
    {
        PlanFeature::each(function (PlanFeature $feature) {
            $this->assertSame(
                $feature->feature()->type(),
                $feature->type,
                "Feature [{$feature->key}] is stored as {$feature->type->value}.",
            );
        });
    }

    protected function organizationOn(string $code): Organization
    {
        return Organization::factory()->onPlan($code)->create();
    }
}
