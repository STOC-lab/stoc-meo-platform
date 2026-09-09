<?php

namespace App\Services\Heatmap;

use App\Enums\HeatmapGridSize;
use App\Services\Ranking\GeoPoint;

/**
 * Lays a square grid of search points over the ground around a store front.
 *
 * The spacing is given in kilometres and converted to degrees rather than the
 * other way round, so a grid covers the same distance wherever it is placed.
 * Latitude is a constant conversion; longitude narrows towards the poles, so
 * it is divided by the cosine of the centre's latitude. Over a grid a few
 * kilometres across the error from using the centre's latitude throughout is
 * far below the precision a rank check can resolve.
 */
class GridGenerator
{
    /**
     * Kilometres per degree of latitude, and of longitude at the equator.
     */
    protected const KM_PER_LATITUDE_DEGREE = 110.574;

    protected const KM_PER_LONGITUDE_DEGREE = 111.320;

    /**
     * Every point of the grid, in reading order: the top-left corner first,
     * then along each row.
     *
     * @return array<int, GridPoint>
     */
    public function generate(GeoPoint $centre, HeatmapGridSize $size): array
    {
        $dimension = $size->dimension();
        $spacing = $size->spacingKm();
        $middle = ($dimension - 1) / 2;
        $points = [];

        for ($row = 0; $row < $dimension; $row++) {
            for ($col = 0; $col < $dimension; $col++) {
                // Rows run north to south, so the offset falls as the row
                // index rises; columns run west to east and rise with it.
                $points[] = new GridPoint($row, $col, new GeoPoint(
                    $centre->latitude + $this->latitudeOffset(($middle - $row) * $spacing),
                    $centre->longitude + $this->longitudeOffset(($col - $middle) * $spacing, $centre->latitude),
                ));
            }
        }

        return $points;
    }

    protected function latitudeOffset(float $km): float
    {
        return $km / self::KM_PER_LATITUDE_DEGREE;
    }

    protected function longitudeOffset(float $km, float $latitude): float
    {
        $shrink = cos(deg2rad($latitude));

        // At the poles a degree of longitude is no distance at all; nothing
        // the product tracks is there, but the division still has to be safe.
        if (abs($shrink) < 1e-9) {
            return 0.0;
        }

        return $km / (self::KM_PER_LONGITUDE_DEGREE * $shrink);
    }
}
