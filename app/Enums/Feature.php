<?php

namespace App\Enums;

/**
 * The feature keys plans are described with, as defined by STOC MEO SYSTEM
 * DESIGN v1.3 §27-29. The plan_features table stores plain strings so a plan
 * can carry keys this enum does not know about yet, but everything the
 * application checks should be listed here.
 */
enum Feature: string
{
    // 検索順位
    case RankingEnabled = 'ranking.enabled';
    case RankingDaily = 'ranking.daily';
    case RankingKeywordLimit = 'ranking.keyword_limit';

    // ヒートマップ
    case Heatmap5x5MonthlyLimit = 'heatmap.5x5.monthly_limit';
    case Heatmap7x7MonthlyLimit = 'heatmap.7x7.monthly_limit';

    // 競合
    case CompetitorLimit = 'competitor.limit';

    // 口コミ
    case ReviewAiReplyEnabled = 'review.ai_reply.enabled';
    case ReviewAiReplyMonthlyLimit = 'review.ai_reply.monthly_limit';
    case ReviewAutoReplyEnabled = 'review.auto_reply.enabled';

    // GBP 投稿
    case GbpPostMonthlyLimit = 'gbp.post.monthly_limit';

    // Instagram
    case InstagramEnabled = 'instagram.enabled';
    case InstagramPostMonthlyLimit = 'instagram.post.monthly_limit';
    case InstagramAutoPublishEnabled = 'instagram.auto_publish.enabled';

    // ブログ
    case BlogEnabled = 'blog.enabled';

    // サイテーション
    case CitationEnabled = 'citation.enabled';
    case CitationMonthlyLimit = 'citation.monthly_limit';

    // AI 分析
    case AiDailyAnalysisEnabled = 'ai.daily_analysis.enabled';
    case AiWeeklyAnalysisEnabled = 'ai.weekly_analysis.enabled';
    case AiImprovementProposalsEnabled = 'ai.improvement_proposals.enabled';

    // レポート・複数店舗
    case PdfReportEnabled = 'pdf_report.enabled';
    case MultiLocationEnabled = 'multi_location.enabled';

    /**
     * The type a feature's value is stored and read as.
     */
    public function type(): FeatureType
    {
        return match ($this) {
            self::RankingEnabled,
            self::RankingDaily,
            self::ReviewAiReplyEnabled,
            self::ReviewAutoReplyEnabled,
            self::InstagramEnabled,
            self::InstagramAutoPublishEnabled,
            self::BlogEnabled,
            self::CitationEnabled,
            self::AiDailyAnalysisEnabled,
            self::AiWeeklyAnalysisEnabled,
            self::AiImprovementProposalsEnabled,
            self::PdfReportEnabled,
            self::MultiLocationEnabled => FeatureType::Boolean,
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
            self::Heatmap5x5MonthlyLimit,
            self::Heatmap7x7MonthlyLimit,
            self::ReviewAiReplyMonthlyLimit,
            self::GbpPostMonthlyLimit,
            self::InstagramPostMonthlyLimit,
            self::CitationMonthlyLimit,
        ], true);
    }
}
