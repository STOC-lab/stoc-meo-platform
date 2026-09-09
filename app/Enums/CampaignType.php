<?php

namespace App\Enums;

/**
 * How a campaign's posts are timed, as defined by STOC MEO SYSTEM DESIGN v1.3
 * §22.
 */
enum CampaignType: string
{
    /** Published when someone asks for it. */
    case Manual = 'manual';

    /** Published once, at scheduled_at. */
    case Scheduled = 'scheduled';

    /** Published again on a cadence — the weekly Instagram sweep. */
    case Recurring = 'recurring';

    public function label(): string
    {
        return match ($this) {
            self::Manual => '手動',
            self::Scheduled => '予約',
            self::Recurring => '定期',
        };
    }
}
