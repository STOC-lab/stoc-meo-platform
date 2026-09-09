<?php

namespace App\Enums;

/**
 * What an alert is about. The column is a plain string so a later type can be
 * added without a migration.
 */
enum AlertType: string
{
    case RankDrop = 'rank_drop';
    case GbpTokenExpired = 'gbp_token_expired';

    public function label(): string
    {
        return match ($this) {
            self::RankDrop => '順位急落',
            self::GbpTokenExpired => 'Google連携の再認証が必要',
        };
    }
}
