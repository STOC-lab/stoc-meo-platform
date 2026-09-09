<?php

namespace App\Enums;

/**
 * The grids a heatmap can be run on, as settled in design v1.3. Each size is
 * entitled separately, so a plan may grant the smaller grid and not the
 * larger.
 */
enum HeatmapGridSize: string
{
    case Grid5x5 = '5x5';
    case Grid7x7 = '7x7';

    /**
     * Points along one side of the grid.
     */
    public function dimension(): int
    {
        return match ($this) {
            self::Grid5x5 => 5,
            self::Grid7x7 => 7,
        };
    }

    /**
     * How many searches one run of this grid costs: 25 or 49.
     */
    public function pointCount(): int
    {
        return $this->dimension() ** 2;
    }

    /**
     * The monthly allowance this grid is counted against.
     */
    public function feature(): Feature
    {
        return match ($this) {
            self::Grid5x5 => Feature::Heatmap5x5MonthlyLimit,
            self::Grid7x7 => Feature::Heatmap7x7MonthlyLimit,
        };
    }

    /**
     * How far apart neighbouring points sit, in kilometres. The wider grid
     * covers more ground without spreading so thin that the map stops
     * describing the neighbourhood.
     */
    public function spacingKm(): float
    {
        return match ($this) {
            self::Grid5x5 => 1.0,
            self::Grid7x7 => 1.5,
        };
    }
}
