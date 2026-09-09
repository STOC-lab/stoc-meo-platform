<?php

namespace App\Enums;

/**
 * How urgently a proposal should be acted on. Ordered so a list can be sorted
 * by it without knowing the labels.
 */
enum ProposalPriority: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function level(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::High => '高',
            self::Medium => '中',
            self::Low => '低',
        };
    }
}
