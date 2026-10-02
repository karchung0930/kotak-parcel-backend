<?php

namespace App\Support;

class Geo
{
    /**
     * Mean radius of the Earth in kilometres.
     */
    public const EARTH_RADIUS_KM = 6371.0;

    /**
     * Get the great-circle distance between two coordinates using the haversine formula.
     */
    public static function distanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $latDelta = deg2rad($toLat - $fromLat);
        $lngDelta = deg2rad($toLng - $fromLng);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($lngDelta / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
