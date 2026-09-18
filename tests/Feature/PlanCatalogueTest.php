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
        $this->assertSame([
            'meo_free', 'meo_light', 'meo_standard', 'meo_premium',
            'ig_light', 'ig_standard', 'ig_premium',
            'meo_premium_1y', 'meo_premium_2y', 'meo_premium_3y', 'meo_premium_5y', 'meo_premium_6m',
            'ig_line_1y', 'ig_line_2y', 'ig_line_3y', 'ig_line_5y',
        ], Plan::orderBy('sort_order')->pluck('code')->all());

        $this->assertSame(9, Plan::product(Plan::PRODUCT_MEO)->count());
        $this->assertSame(7, Plan::product(Plan::PRODUCT_INSTAGRAM)->count());
    }

    public function test_what_is_on_sale_is_the_free_tier_and_the_nine_contract_terms(): void
    {
        $this->assertSame([
            'meo_free',
            'meo_premium_1y', 'meo_premium_2y', 'meo_premium_3y', 'meo_premium_5y', 'meo_premium_6m',
            'ig_line_1y', 'ig_line_2y', 'ig_line_3y', 'ig_line_5y',
        ], Plan::active()->orderBy('sort_order')->pluck('code')->all());

        // Retiring a plan is marking it inactive, not deleting it: an
        // organization still on one needs its entitlements to keep resolving,
        // and BillingController refuses a checkout for an inactive plan.
        $this->assertSame(
            ['meo_light', 'meo_standard', 'meo_premium', 'ig_light', 'ig_standard', 'ig_premium'],
            Plan::where('is_active', false)->orderBy('sort_order')->pluck('code')->all(),
        );
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
            // The amount of one charge, not a monthly figure: ¥384,000 a year
            // on the one-year term, ¥210,000 once on the 6-month special.
            'meo_premium_1y' => 384000,
            'meo_premium_2y' => 324000,
            'meo_premium_3y' => 300000,
            'meo_premium_5y' => 252000,
            'meo_premium_6m' => 210000,
            'ig_line_1y' => 180000,
            'ig_line_2y' => 156000,
            'ig_line_3y' => 132000,
            'ig_line_5y' => 108000,
        ], Plan::orderBy('sort_order')->pluck('price', 'code')->all());
    }

    public function test_each_term_carries_its_commitment_and_its_number_of_charges(): void
    {
        $terms = Plan::whereIn('code', [
            'meo_premium_1y', 'meo_premium_2y', 'meo_premium_3y', 'meo_premium_5y', 'meo_premium_6m',
            'ig_line_1y', 'ig_line_2y', 'ig_line_3y', 'ig_line_5y',
        ])->orderBy('sort_order')->get();

        $this->assertSame([
            'meo_premium_1y' => ['year', 12, 1],
            'meo_premium_2y' => ['year', 24, 2],
            'meo_premium_3y' => ['year', 36, 3],
            'meo_premium_5y' => ['year', 60, 5],
            // Charged once, so nothing renews it and its term is carried by
            // billing_period_months alone.
            'meo_premium_6m' => ['one_time', 6, 1],
            'ig_line_1y' => ['year', 12, 1],
            'ig_line_2y' => ['year', 24, 2],
            'ig_line_3y' => ['year', 36, 3],
            'ig_line_5y' => ['year', 60, 5],
        ], $terms->mapWithKeys(fn (Plan $plan) => [
            $plan->code => [$plan->interval, $plan->billing_period_months, $plan->phases],
        ])->all());

        $this->assertSame(
            ['meo_premium_6m'],
            $terms->reject->isRecurring()->pluck('code')->values()->all(),
        );
    }

    public function test_the_monthly_equivalent_is_written_into_each_terms_description(): void
    {
        // The figure a shop owner compares terms on, and the one most easily
        // mis-divided out of a yearly price.
        $this->assertStringContainsString('月額換算 ¥27,000', (string) Plan::where('code', 'meo_premium_2y')->value('description'));
        $this->assertStringContainsString('月額換算 ¥35,000', (string) Plan::where('code', 'meo_premium_6m')->value('description'));
        $this->assertStringContainsString('月額換算 ¥9,000', (string) Plan::where('code', 'ig_line_5y')->value('description'));
    }

    public function test_every_meo_premium_term_unlocks_meo_premium_plus_aio(): void
    {
        $expected = $this->resolver->features($this->organizationOn('meo_premium'))->all() + [
            Feature::AioMonitoringEnabled->value => true,
            Feature::AioContentSuggestionEnabled->value => true,
            Feature::AioSchemaDiagnosisEnabled->value => true,
            Feature::AioReportEnabled->value => true,
        ];
        ksort($expected);

        foreach (['meo_premium_1y', 'meo_premium_2y', 'meo_premium_3y', 'meo_premium_5y', 'meo_premium_6m'] as $code) {
            $this->assertSame($expected, $this->resolver->features($this->organizationOn($code))->sortKeys()->all(), $code);
        }
    }

    public function test_aio_is_sold_on_a_term_and_not_on_a_monthly_tier(): void
    {
        // The organization still sitting on monthly MEO PREMIUM bought what
        // that row granted; a term is what carries AIO.
        foreach (['meo_free', 'meo_light', 'meo_standard', 'meo_premium', 'ig_premium', 'ig_line_5y'] as $code) {
            $organization = $this->organizationOn($code);

            foreach ([Feature::AioMonitoringEnabled, Feature::AioContentSuggestionEnabled, Feature::AioSchemaDiagnosisEnabled, Feature::AioReportEnabled] as $feature) {
                $this->assertFalse($this->resolver->allows($feature, $organization), "{$feature->value} on {$code}");
            }
        }
    }

    public function test_every_ig_line_term_unlocks_the_instagram_tier_with_the_posts_its_term_buys(): void
    {
        $expected = $this->resolver->features($this->organizationOn('ig_premium'))->sortKeys()->all();

        // The post allowance is the one entitlement a term changes.
        foreach (['ig_line_1y' => 4, 'ig_line_2y' => 12, 'ig_line_3y' => 20, 'ig_line_5y' => 30] as $code => $posts) {
            $this->assertSame(
                [...$expected, Feature::InstagramPostMonthlyLimit->value => $posts],
                $this->resolver->features($this->organizationOn($code))->sortKeys()->all(),
                $code,
            );
        }
    }

    public function test_an_ig_line_term_grants_no_meo_feature(): void
    {
        $organization = $this->organizationOn('ig_line_5y');

        foreach ([Feature::RankingEnabled, Feature::ReviewAiReplyEnabled, Feature::PdfReportEnabled, Feature::MultiLocationEnabled] as $feature) {
            $this->assertFalse($this->resolver->allows($feature, $organization), $feature->value);
        }

        $this->assertFalse($this->resolver->allows(Feature::RankingKeywordLimit, $organization));
    }

    public function test_the_seeder_leaves_the_stripe_price_ids_alone(): void
    {
        // The ids are written by stripe:create-products and live only in the
        // plans table, which is what makes a reseed safe after a price change.
        Plan::where('code', 'meo_premium_1y')->update(['stripe_price_id' => 'price_1Live001']);

        $this->seed(PlanSeeder::class);

        $this->assertSame('price_1Live001', Plan::where('code', 'meo_premium_1y')->value('stripe_price_id'));
    }

    public function test_no_plan_offers_a_trial(): void
    {
        $this->assertSame(0, (int) Plan::sum('trial_days'));
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $features = PlanFeature::count();

        $this->seed(PlanSeeder::class);

        $this->assertSame(16, Plan::count());
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
                // Instagram is an add-on, but the customer still runs a shop
                // and still needs somewhere to put it.
                Feature::LocationLimit->value => 1,
                Feature::BrandLimit->value => 1,
                Feature::MemberLimit->value => 3,
            ],
            'ig_standard' => [
                Feature::InstagramEnabled->value => true,
                Feature::InstagramPostMonthlyLimit->value => 12,
                Feature::LocationLimit->value => 1,
                Feature::BrandLimit->value => 1,
                Feature::MemberLimit->value => 3,
            ],
            'ig_premium' => [
                Feature::InstagramEnabled->value => true,
                Feature::InstagramPostMonthlyLimit->value => 30,
                Feature::InstagramAutoPublishEnabled->value => true,
                Feature::LocationLimit->value => 1,
                Feature::BrandLimit->value => 1,
                Feature::MemberLimit->value => 3,
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
