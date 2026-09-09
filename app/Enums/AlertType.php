<?php

namespace App\Enums;

/**
 * What an alert is about. Only the rank drop exists so far; the column is a
 * plain string so a later type can be added without a migration.
 */
enum AlertType: string
{
    case RankDrop = 'rank_drop';

    public function label(): string
    {
        return match ($this) {
            self::RankDrop => '順位急落',
        };
    }
}
