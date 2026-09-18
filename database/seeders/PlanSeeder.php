<?php

namespace Database\Seeders;

use App\Enums\Feature;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Services\FeatureResolver;
use Illuminate\Database\Seeder;

/**
 * The plan catalogue.
 *
 * What is sold today is MEO FREE, MEO PREMIUM on a one-, two-, three- or
 * five-year term plus a 6-month special, and IG LINE on the same four terms.
 * Every monthly tier of both products is kept as a row but marked inactive:
 * organizations still sitting on one need their entitlements to keep
 * resolving, and BillingController refuses a checkout for an inactive plan,
 * which is what retiring a plan means here. The monthly rows are also what the
 * term plans take their entitlements from, so they are the catalogue's memory
 * of what MEO PREMIUM and the Instagram tier grant.
 *
 * A contract term is not only a longer commitment at a lower rate. A MEO
 * PREMIUM term adds the AIO block, which no monthly tier carries, and an IG
 * LINE term's monthly Instagram allowance grows with its length.
 *
 * `price` is the amount of one charge in JPY, not a monthly figure: ¥384,000
 * billed yearly, ¥210,000 charged once. The month the customer is committed
 * for is `billing_period_months`, and `phases` is how many charges make that
 * up — the number of Subscription Schedule phases the multi-year terms need.
 * No plan offers a trial.
 *
 * stripe_price_id is deliberately absent from every definition below. The ids
 * live in the plans table and are written by `stripe:create-products`, so a
 * reseed after a price change cannot undo it. See `.ai/rules/stripe.md`.
 *
 * Re-running this seeder updates existing plans in place and drops features no
 * longer listed.
 */
class PlanSeeder extends Seeder
{
    /**
     * The MEO tiers, in the order FREE, LIGHT, STANDARD, PREMIUM.
     */
    protected const MEO_TIERS = [
        Plan::TIER_FREE,
        Plan::TIER_LIGHT,
        Plan::TIER_STANDARD,
        Plan::TIER_PREMIUM,
    ];

    /**
     * The terms MEO PREMIUM and IG LINE are sold on, keyed by the suffix their
     * plan codes carry.
     *
     * `meo` and `ig_line` are the amount of one charge: the yearly price on a
     * yearly term, the whole thing on the 6-month special, which is charged
     * once and so has no IG LINE counterpart. `phases` is the number of yearly
     * charges the commitment is made of, which is what a Subscription Schedule
     * is built from — a two-year term is two phases at ¥324,000, not one
     * charge of ¥648,000.
     *
     * `ig_posts` is the monthly Instagram allowance that term of IG LINE
     * carries. It is the one entitlement a term changes: a longer commitment
     * buys more posts a month, 4 on the one-year term up to 30 on the
     * five-year one.
     *
     * @var array<string, array<string, mixed>>
     */
    protected const TERMS = [
        '1y' => ['label' => '1年', 'months' => 12, 'phases' => 1, 'interval' => Plan::INTERVAL_YEAR, 'meo' => 384000, 'ig_line' => 180000, 'ig_posts' => 4, 'sort' => 10],
        '2y' => ['label' => '2年', 'months' => 24, 'phases' => 2, 'interval' => Plan::INTERVAL_YEAR, 'meo' => 324000, 'ig_line' => 156000, 'ig_posts' => 12, 'sort' => 20],
        '3y' => ['label' => '3年', 'months' => 36, 'phases' => 3, 'interval' => Plan::INTERVAL_YEAR, 'meo' => 300000, 'ig_line' => 132000, 'ig_posts' => 20, 'sort' => 30],
        '5y' => ['label' => '5年', 'months' => 60, 'phases' => 5, 'interval' => Plan::INTERVAL_YEAR, 'meo' => 252000, 'ig_line' => 108000, 'ig_posts' => 30, 'sort' => 40],
        '6m' => ['label' => '6ヶ月特例', 'months' => 6, 'phases' => 1, 'interval' => Plan::INTERVAL_ONE_TIME, 'meo' => 210000, 'ig_line' => null, 'ig_posts' => null, 'sort' => 50],
    ];

    /**
     * Seed the plan catalogue.
     */
    public function run(): void
    {
        foreach ($this->plans() as $definition) {
            $features = $definition['features'];
            unset($definition['features']);

            $plan = Plan::updateOrCreate(
                ['code' => $definition['code']],
                $definition,
            );

            $this->syncFeatures($plan, $features);
        }

        app(FeatureResolver::class)->flush();
    }

    /**
     * @param  array<string, int|bool|string|null>  $features
     */
    protected function syncFeatures(Plan $plan, array $features): void
    {
        $now = now();

        $rows = collect($features)->map(fn ($value, string $key) => [
            'plan_id' => $plan->getKey(),
            'key' => $key,
            'type' => FeatureResolver::typeFor($key)->value,
            'value' => $this->stringify($value),
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        PlanFeature::upsert($rows, ['plan_id', 'key'], ['type', 'value', 'updated_at']);

        $plan->features()->whereNotIn('key', array_keys($features))->delete();
    }

    /**
     * Limits keep null to mean "unlimited"; everything else is stored as text.
     */
    protected function stringify(int|bool|string|null $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function plans(): array
    {
        return [
            [
                'code' => 'meo_free',
                'name' => 'MEO FREE',
                'product' => Plan::PRODUCT_MEO,
                'tier' => Plan::TIER_FREE,
                'description' => '3キーワードの順位計測から始められる無料プラン。',
                'price' => 0,
                'trial_days' => 0,
                'sort_order' => 10,
                'is_active' => true,
                'features' => $this->meoFeatures(Plan::TIER_FREE),
            ],
            [
                'code' => 'meo_light',
                'name' => 'MEO LIGHT',
                'product' => Plan::PRODUCT_MEO,
                'tier' => Plan::TIER_LIGHT,
                'description' => '毎日の順位計測と AI 口コミ返信、PDF レポートに対応した基本プラン。',
                'price' => 9000,
                'trial_days' => 0,
                'sort_order' => 20,
                'is_active' => false,
                'features' => $this->meoFeatures(Plan::TIER_LIGHT),
            ],
            [
                'code' => 'meo_standard',
                'name' => 'MEO STANDARD',
                'product' => Plan::PRODUCT_MEO,
                'tier' => Plan::TIER_STANDARD,
                'description' => '口コミ自動返信、Instagram 連携、サイテーションと週次 AI 分析まで含む標準プラン。',
                'price' => 15000,
                'trial_days' => 0,
                'sort_order' => 30,
                'is_active' => false,
                'features' => $this->meoFeatures(Plan::TIER_STANDARD),
            ],
            [
                'code' => 'meo_premium',
                'name' => 'MEO PREMIUM',
                'product' => Plan::PRODUCT_MEO,
                'tier' => Plan::TIER_PREMIUM,
                'description' => '日次 AI 分析、Instagram 自動投稿、複数店舗管理に対応した上位プラン。',
                'price' => 30000,
                'trial_days' => 0,
                'sort_order' => 40,
                'is_active' => false,
                'features' => $this->meoFeatures(Plan::TIER_PREMIUM),
            ],
            [
                'code' => 'ig_light',
                'name' => 'IG LIGHT',
                'product' => Plan::PRODUCT_INSTAGRAM,
                'tier' => Plan::TIER_LIGHT,
                'description' => 'Instagram 投稿を月 4 件までご利用いただけるプラン。',
                'price' => 5000,
                'trial_days' => 0,
                'sort_order' => 50,
                'is_active' => false,
                'features' => [
                    Feature::InstagramEnabled->value => true,
                    Feature::InstagramPostMonthlyLimit->value => 4,
                    Feature::LocationLimit->value => 1,
                    Feature::BrandLimit->value => 1,
                    Feature::MemberLimit->value => 3,
                ],
            ],
            [
                'code' => 'ig_standard',
                'name' => 'IG STANDARD',
                'product' => Plan::PRODUCT_INSTAGRAM,
                'tier' => Plan::TIER_STANDARD,
                'description' => 'Instagram 投稿を月 12 件までご利用いただけるプラン。',
                'price' => 9000,
                'trial_days' => 0,
                'sort_order' => 60,
                'is_active' => false,
                'features' => [
                    Feature::InstagramEnabled->value => true,
                    Feature::InstagramPostMonthlyLimit->value => 12,
                    Feature::LocationLimit->value => 1,
                    Feature::BrandLimit->value => 1,
                    Feature::MemberLimit->value => 3,
                ],
            ],
            [
                'code' => 'ig_premium',
                'name' => 'IG PREMIUM',
                'product' => Plan::PRODUCT_INSTAGRAM,
                'tier' => Plan::TIER_PREMIUM,
                'description' => 'Instagram 投稿を月 30 件まで、自動投稿にも対応したプラン。',
                'price' => 15000,
                'trial_days' => 0,
                'sort_order' => 70,
                'is_active' => false,
                'features' => $this->instagramFeatures(),
            ],
            ...$this->termPlans(),
        ];
    }

    /**
     * The annual catalogue: MEO PREMIUM and IG LINE, each on the terms they
     * are sold on.
     *
     * Every MEO PREMIUM term unlocks what monthly MEO PREMIUM unlocks plus the
     * AIO block, which is sold only on a contract term; every IG LINE term
     * unlocks what the Instagram tier unlocks, with the monthly post allowance
     * the term buys. Both take their values from one place rather than
     * restating them, so the matrix cannot drift across nine rows.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function termPlans(): array
    {
        return collect(self::TERMS)
            ->flatMap(fn (array $term, string $suffix) => [
                [
                    'code' => 'meo_premium_'.$suffix,
                    'name' => 'MEO PREMIUM '.$term['label'],
                    'product' => Plan::PRODUCT_MEO,
                    'tier' => Plan::TIER_PREMIUM,
                    'description' => $this->termDescription('MEO PREMIUM', $term, $term['meo']),
                    'price' => $term['meo'],
                    'interval' => $term['interval'],
                    'billing_period_months' => $term['months'],
                    'phases' => $term['phases'],
                    'trial_days' => 0,
                    'sort_order' => $term['sort'] + 100,
                    'is_active' => true,
                    'features' => $this->meoPremiumTermFeatures(),
                ],
                ...$term['ig_line'] === null ? [] : [[
                    'code' => 'ig_line_'.$suffix,
                    'name' => 'IG LINE '.$term['label'],
                    'product' => Plan::PRODUCT_INSTAGRAM,
                    'tier' => Plan::TIER_PREMIUM,
                    'description' => $this->termDescription('IG LINE', $term, $term['ig_line']),
                    'price' => $term['ig_line'],
                    'interval' => $term['interval'],
                    'billing_period_months' => $term['months'],
                    'phases' => $term['phases'],
                    'trial_days' => 0,
                    'sort_order' => $term['sort'] + 200,
                    'is_active' => true,
                    'features' => $this->instagramFeatures($term['ig_posts']),
                ]],
            ])
            ->all();
    }

    /**
     * The monthly equivalent is what a shop owner compares terms on, so it is
     * said out loud rather than left to be divided out of the yearly figure.
     *
     * @param  array<string, mixed>  $term
     */
    protected function termDescription(string $product, array $term, int $price): string
    {
        $monthly = intdiv($price, $term['interval'] === Plan::INTERVAL_YEAR ? 12 : $term['months']);

        return sprintf(
            '%s %s契約。%s（月額換算 ¥%s）。',
            $product,
            $term['label'],
            $term['interval'] === Plan::INTERVAL_ONE_TIME
                ? '¥'.number_format($price).' 一括'
                : '年額 ¥'.number_format($price),
            number_format($monthly),
        );
    }

    /**
     * The Instagram feature set, shared by IG PREMIUM and every IG LINE term.
     *
     * The monthly post allowance is the argument because it is what a term
     * buys: IG PREMIUM and the five-year term are both 30, the shorter terms
     * less. Nothing MEO is listed, so FeatureResolver answers false or zero
     * for every MEO key — an IG LINE customer buys Instagram and not the
     * ranking product.
     *
     * LINE is in the name and not in the features: there is no LINE key in the
     * Feature enum and nothing in the application gates on one yet.
     *
     * @return array<string, int|bool|null>
     */
    protected function instagramFeatures(int $postMonthlyLimit = 30): array
    {
        return [
            Feature::InstagramEnabled->value => true,
            Feature::InstagramPostMonthlyLimit->value => $postMonthlyLimit,
            Feature::InstagramAutoPublishEnabled->value => true,
            Feature::LocationLimit->value => 1,
            Feature::BrandLimit->value => 1,
            Feature::MemberLimit->value => 3,
        ];
    }

    /**
     * What a MEO PREMIUM contract term unlocks: the monthly PREMIUM matrix,
     * plus the AIO block.
     *
     * The AIO keys are added here rather than in the matrix so that the
     * retired monthly MEO PREMIUM row keeps granting exactly what the
     * organization still sitting on it bought. AIO is sold on a term.
     *
     * @return array<string, int|bool|null>
     */
    protected function meoPremiumTermFeatures(): array
    {
        return [
            ...$this->meoFeatures(Plan::TIER_PREMIUM),
            Feature::AioMonitoringEnabled->value => true,
            Feature::AioContentSuggestionEnabled->value => true,
            Feature::AioSchemaDiagnosisEnabled->value => true,
            Feature::AioReportEnabled->value => true,
        ];
    }

    /**
     * The MEO feature values for one tier.
     *
     * @return array<string, int|bool|null>
     */
    protected function meoFeatures(string $tier): array
    {
        $column = array_search($tier, self::MEO_TIERS, true);

        return collect($this->meoFeatureMatrix())
            ->map(fn (array $values) => $values[$column])
            ->all();
    }

    /**
     * The MEO entitlement matrix, laid out as in the design document: one row
     * per feature, one column per tier (FREE, LIGHT, STANDARD, PREMIUM).
     *
     * @return array<string, array<int, int|bool|null>>
     */
    protected function meoFeatureMatrix(): array
    {
        return [
            //                                             FREE   LIGHT  STANDARD PREMIUM
            Feature::RankingEnabled->value => [true, true, true, true],
            Feature::RankingDaily->value => [false, true, true, true],
            Feature::RankingKeywordLimit->value => [3, 10, 20, 30],
            Feature::Heatmap5x5MonthlyLimit->value => [0, 1, 2, 4],
            Feature::Heatmap7x7MonthlyLimit->value => [0, 0, 1, 4],
            Feature::CompetitorLimit->value => [0, 3, 5, 10],
            Feature::ReviewAiReplyEnabled->value => [false, true, true, true],
            Feature::ReviewAiReplyMonthlyLimit->value => [0, 30, 100, 500],
            Feature::ReviewAutoReplyEnabled->value => [false, false, true, true],
            Feature::GbpPostMonthlyLimit->value => [0, 4, 12, 30],
            Feature::InstagramEnabled->value => [false, false, true, true],
            Feature::InstagramPostMonthlyLimit->value => [0, 0, 4, 12],
            Feature::InstagramAutoPublishEnabled->value => [false, false, false, true],
            Feature::BlogEnabled->value => [false, false, false, false],
            Feature::CitationEnabled->value => [false, false, true, true],
            Feature::CitationMonthlyLimit->value => [0, 0, 1, 4],
            Feature::AiDailyAnalysisEnabled->value => [false, false, false, true],
            Feature::AiWeeklyAnalysisEnabled->value => [false, false, true, true],
            Feature::AiImprovementProposalsEnabled->value => [false, false, true, true],
            Feature::PdfReportEnabled->value => [false, true, true, true],
            Feature::MultiLocationEnabled->value => [false, false, false, true],
            // null is unlimited. The single-shop tiers are held to one store
            // front by MultiLocationEnabled already; these say the same thing
            // as a number, and give PREMIUM a ceiling only if one is ever set.
            Feature::LocationLimit->value => [1, 1, 1, null],
            Feature::BrandLimit->value => [1, 1, 3, null],
            Feature::MemberLimit->value => [1, 3, 10, null],
        ];
    }
}
