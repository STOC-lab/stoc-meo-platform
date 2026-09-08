<?php

namespace App\Enums;

/**
 * The feature keys plans are described with. The plan_features table stores
 * plain strings so a plan can carry keys this enum does not know about yet,
 * but everything the application checks should be listed here.
 */
enum Feature: string
{
    // Tenancy allowances
    case LocationsMax = 'locations.max';
    case BrandsMax = 'brands.max';
    case UsersMax = 'users.max';

    // MEO
    case KeywordsMax = 'keywords.max';
    case RankTracking = 'rank_tracking';
    case GbpPostsMonthly = 'gbp_posts.monthly';
    case AiPostsMonthly = 'ai_posts.monthly';
    case AiRepliesMonthly = 'ai_replies.monthly';
    case CompetitorAnalysis = 'competitor_analysis';
    case InsightsHistoryDays = 'insights_history.days';

    // Instagram
    case InstagramAccountsMax = 'instagram.accounts.max';
    case InstagramPostsMonthly = 'instagram.posts.monthly';
    case InstagramHashtagAnalysis = 'instagram.hashtag_analysis';
    case InstagramAutoReply = 'instagram.auto_reply';

    // Cross-cutting
    case CsvExport = 'csv_export';
    case ApiAccess = 'api_access';
    case WhiteLabel = 'white_label';
    case PrioritySupport = 'priority_support';

    /**
     * The type a feature's value is stored and read as.
     */
    public function type(): FeatureType
    {
        return match ($this) {
            self::RankTracking,
            self::CompetitorAnalysis,
            self::InstagramHashtagAnalysis,
            self::InstagramAutoReply,
            self::CsvExport,
            self::ApiAccess,
            self::WhiteLabel,
            self::PrioritySupport => FeatureType::Boolean,
            default => FeatureType::Limit,
        };
    }

    /**
     * Whether the feature counts consumption over a billing period, and so is
     * tracked by the UsageTracker rather than counted from live rows.
     */
    public function isMetered(): bool
    {
        return in_array($this, [
            self::GbpPostsMonthly,
            self::AiPostsMonthly,
            self::AiRepliesMonthly,
            self::InstagramPostsMonthly,
        ], true);
    }
}
