<?php

namespace Database\Seeders;

use App\Enums\Feature;
use App\Models\Plan;
use App\Services\FeatureResolver;
use Illuminate\Database\Seeder;

/**
 * The plan catalogue: four MEO tiers and three Instagram tiers.
 *
 * Prices are monthly and in JPY. stripe_price_id is left null until the
 * matching price exists in Stripe; fill it in before allowing checkout.
 *
 * NOTE: the price points and allowances below follow the structure of the
 * design document but are placeholders — confirm them against STOC MEO SYSTEM
 * DESIGN v1.3 Section 27-29 before launch. Re-running this seeder updates
 * existing plans in place and drops features no longer listed.
 */
class PlanSeeder extends Seeder
{
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
        foreach ($features as $key => $value) {
            $plan->features()->updateOrCreate(
                ['key' => $key],
                [
                    'type' => FeatureResolver::typeFor($key),
                    'value' => $this->stringify($value),
                ],
            );
        }

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
                'description' => '1店舗から始められる無料プラン。順位計測と基本的な投稿機能をお試しいただけます。',
                'price' => 0,
                'trial_days' => 0,
                'sort_order' => 10,
                'features' => [
                    Feature::LocationsMax->value => 1,
                    Feature::BrandsMax->value => 1,
                    Feature::UsersMax->value => 2,
                    Feature::KeywordsMax->value => 5,
                    Feature::RankTracking->value => true,
                    Feature::GbpPostsMonthly->value => 5,
                    Feature::AiPostsMonthly->value => 3,
                    Feature::AiRepliesMonthly->value => 5,
                    Feature::CompetitorAnalysis->value => false,
                    Feature::InsightsHistoryDays->value => 30,
                    Feature::CsvExport->value => false,
                    Feature::ApiAccess->value => false,
                    Feature::WhiteLabel->value => false,
                    Feature::PrioritySupport->value => false,
                ],
            ],
            [
                'code' => 'meo_light',
                'name' => 'MEO LIGHT',
                'product' => Plan::PRODUCT_MEO,
                'tier' => Plan::TIER_LIGHT,
                'description' => '数店舗規模の運用向け。AI投稿と口コミ返信を日常的にご利用いただけます。',
                'price' => 9800,
                'trial_days' => 14,
                'sort_order' => 20,
                'features' => [
                    Feature::LocationsMax->value => 3,
                    Feature::BrandsMax->value => 1,
                    Feature::UsersMax->value => 5,
                    Feature::KeywordsMax->value => 20,
                    Feature::RankTracking->value => true,
                    Feature::GbpPostsMonthly->value => 30,
                    Feature::AiPostsMonthly->value => 20,
                    Feature::AiRepliesMonthly->value => 50,
                    Feature::CompetitorAnalysis->value => false,
                    Feature::InsightsHistoryDays->value => 90,
                    Feature::CsvExport->value => true,
                    Feature::ApiAccess->value => false,
                    Feature::WhiteLabel->value => false,
                    Feature::PrioritySupport->value => false,
                ],
            ],
            [
                'code' => 'meo_standard',
                'name' => 'MEO STANDARD',
                'product' => Plan::PRODUCT_MEO,
                'tier' => Plan::TIER_STANDARD,
                'description' => '複数ブランド・多店舗運用向け。競合分析と長期のインサイト履歴が使えます。',
                'price' => 29800,
                'trial_days' => 14,
                'sort_order' => 30,
                'features' => [
                    Feature::LocationsMax->value => 10,
                    Feature::BrandsMax->value => 3,
                    Feature::UsersMax->value => 15,
                    Feature::KeywordsMax->value => 50,
                    Feature::RankTracking->value => true,
                    Feature::GbpPostsMonthly->value => 100,
                    Feature::AiPostsMonthly->value => 100,
                    Feature::AiRepliesMonthly->value => 200,
                    Feature::CompetitorAnalysis->value => true,
                    Feature::InsightsHistoryDays->value => 180,
                    Feature::CsvExport->value => true,
                    Feature::ApiAccess->value => false,
                    Feature::WhiteLabel->value => false,
                    Feature::PrioritySupport->value => false,
                ],
            ],
            [
                'code' => 'meo_premium',
                'name' => 'MEO PREMIUM',
                'product' => Plan::PRODUCT_MEO,
                'tier' => Plan::TIER_PREMIUM,
                'description' => 'チェーン・代理店向けの上位プラン。店舗数無制限、API とホワイトラベルに対応します。',
                'price' => 79800,
                'trial_days' => 14,
                'sort_order' => 40,
                'features' => [
                    Feature::LocationsMax->value => null,
                    Feature::BrandsMax->value => null,
                    Feature::UsersMax->value => null,
                    Feature::KeywordsMax->value => 200,
                    Feature::RankTracking->value => true,
                    Feature::GbpPostsMonthly->value => null,
                    Feature::AiPostsMonthly->value => 500,
                    Feature::AiRepliesMonthly->value => null,
                    Feature::CompetitorAnalysis->value => true,
                    Feature::InsightsHistoryDays->value => 365,
                    Feature::CsvExport->value => true,
                    Feature::ApiAccess->value => true,
                    Feature::WhiteLabel->value => true,
                    Feature::PrioritySupport->value => true,
                ],
            ],
            [
                'code' => 'ig_light',
                'name' => 'IG LIGHT',
                'product' => Plan::PRODUCT_INSTAGRAM,
                'tier' => Plan::TIER_LIGHT,
                'description' => 'Instagram アカウント 1 件の運用向け。投稿予約と基本レポートをご利用いただけます。',
                'price' => 9800,
                'trial_days' => 14,
                'sort_order' => 50,
                'features' => [
                    Feature::UsersMax->value => 3,
                    Feature::InstagramAccountsMax->value => 1,
                    Feature::InstagramPostsMonthly->value => 30,
                    Feature::InstagramHashtagAnalysis->value => false,
                    Feature::InstagramAutoReply->value => false,
                    Feature::CsvExport->value => false,
                    Feature::ApiAccess->value => false,
                    Feature::PrioritySupport->value => false,
                ],
            ],
            [
                'code' => 'ig_standard',
                'name' => 'IG STANDARD',
                'product' => Plan::PRODUCT_INSTAGRAM,
                'tier' => Plan::TIER_STANDARD,
                'description' => '複数アカウント運用向け。ハッシュタグ分析と CSV 出力に対応します。',
                'price' => 19800,
                'trial_days' => 14,
                'sort_order' => 60,
                'features' => [
                    Feature::UsersMax->value => 10,
                    Feature::InstagramAccountsMax->value => 3,
                    Feature::InstagramPostsMonthly->value => 100,
                    Feature::InstagramHashtagAnalysis->value => true,
                    Feature::InstagramAutoReply->value => false,
                    Feature::CsvExport->value => true,
                    Feature::ApiAccess->value => false,
                    Feature::PrioritySupport->value => false,
                ],
            ],
            [
                'code' => 'ig_premium',
                'name' => 'IG PREMIUM',
                'product' => Plan::PRODUCT_INSTAGRAM,
                'tier' => Plan::TIER_PREMIUM,
                'description' => 'アカウント数無制限。自動リプライと API 連携まで含む上位プランです。',
                'price' => 49800,
                'trial_days' => 14,
                'sort_order' => 70,
                'features' => [
                    Feature::UsersMax->value => null,
                    Feature::InstagramAccountsMax->value => null,
                    Feature::InstagramPostsMonthly->value => null,
                    Feature::InstagramHashtagAnalysis->value => true,
                    Feature::InstagramAutoReply->value => true,
                    Feature::CsvExport->value => true,
                    Feature::ApiAccess->value => true,
                    Feature::PrioritySupport->value => true,
                ],
            ],
        ];
    }
}
