<?php

namespace App\Enums;

/**
 * Which analysis a stored record is. Each is entitled separately: the weekly
 * one comes with STANDARD and the daily one with PREMIUM.
 */
enum AnalysisType: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    /**
     * The plan feature that grants this analysis.
     */
    public function feature(): Feature
    {
        return match ($this) {
            self::Daily => Feature::AiDailyAnalysisEnabled,
            self::Weekly => Feature::AiWeeklyAnalysisEnabled,
        };
    }

    /**
     * How many days the analysis looks back over.
     */
    public function windowDays(): int
    {
        return match ($this) {
            self::Daily => 1,
            self::Weekly => 7,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Daily => '日次分析',
            self::Weekly => '週次分析',
        };
    }
}
