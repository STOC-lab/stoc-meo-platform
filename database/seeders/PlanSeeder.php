<?php

namespace Database\Seeders;

use App\Enums\Feature;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Services\FeatureResolver;
use Illuminate\Database\Seeder;

/**
 * The plan catalogue: four MEO tiers and three Instagram tiers, with the
 * prices and allowances defined by STOC MEO SYSTEM DESIGN v1.3 §27-29.
 *
 * Prices are monthly and in JPY, and no plan offers a trial. stripe_price_id
 * is left null until the matching price exists in Stripe; fill it in before
 * allowing checkout.
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
                'features' => [
                    Feature::InstagramEnabled->value => true,
                    Feature::InstagramPostMonthlyLimit->value => 4,
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
                'features' => [
                    Feature::InstagramEnabled->value => true,
                    Feature::InstagramPostMonthlyLimit->value => 12,
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
                'features' => [
                    Feature::InstagramEnabled->value => true,
                    Feature::InstagramPostMonthlyLimit->value => 30,
                    Feature::InstagramAutoPublishEnabled->value => true,
                ],
            ],
        ];
    }

    /**
     * The MEO feature values for one tier.
     *
     * @return array<string, int|bool>
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
     * @return array<string, array<int, int|bool>>
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
        ];
    }
}
