<?php

namespace App\Enums;

/**
 * Where a campaign stands. Draft is being put together, Active is running or
 * waiting for its moment, and the two end states are reached when every post
 * has settled or someone stopped it.
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /**
     * Whether the campaign may still produce or publish posts.
     */
    public function isRunnable(): bool
    {
        return in_array($this, [self::Draft, self::Active], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => '下書き',
            self::Active => '実行中',
            self::Completed => '完了',
            self::Cancelled => '中止',
        };
    }
}
