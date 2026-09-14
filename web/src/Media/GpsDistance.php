<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * The one great-circle distance for photos: a rider photo's GPS position to
 * the submission pin, and a Commons file's camera point to a scenic pin.
 *
 * @see docs/specs/photo-uploads.md §3
 * @see docs/specs/scenic-views.md §8
 *
 * @api
 */
final class GpsDistance
{
    /** Mean Earth radius (IUGG). */
    private const float EARTH_RADIUS_M = 6_371_008.8;

    /** Great-circle distance in metres (haversine). */
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $halfLat = sin(deg2rad($lat2 - $lat1) / 2.0);
        $halfLng = sin(deg2rad($lng2 - $lng1) / 2.0);
        $a = $halfLat * $halfLat + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * $halfLng * $halfLng;

        return 2.0 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    /**
     * Whole metres between two positions either of which may be unknown.
     *
     * Null if either end is missing: zero would be a false claim.
     */
    public static function between(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?int
    {
        if (null === $lat1 || null === $lng1 || null === $lat2 || null === $lng2) {
            return null;
        }

        return (int) round(self::metres($lat1, $lng1, $lat2, $lng2));
    }
}
