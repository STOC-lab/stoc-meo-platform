<?php

namespace App\Services\Heatmap;

use App\Services\Ranking\GeoPoint;

/**
 * One cell of a heatmap grid: where it sits in the layout, and the coordinate
 * the search is run from. Row 0 is the northernmost line and column 0 the
 * westernmost, so the rows read top to bottom as the map is drawn.
 */
final class GridPoint
{
    public function __construct(
        public readonly int $row,
        public readonly int $col,
        public readonly GeoPoint $coordinate,
    ) {}
}
