<?php

namespace App\Services\Ranking;

/**
 * A point a search is run from. Providers that accept a coordinate are given
 * one of these; the daily rank check passes none and is answered for the
 * provider's configured region instead.
 */
final class GeoPoint
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
    ) {}

    /**
     * The "lat,lng,zoom" triple DataForSEO expects in location_coordinate.
     */
    public function toCoordinateString(int $zoom = 14): string
    {
        return sprintf('%.7f,%.7f,%d', $this->latitude, $this->longitude, $zoom);
    }
}
