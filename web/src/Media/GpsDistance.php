<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * Pin distance in metres — the only surviving GPS fact.
 *
 * @see docs/specs/photo-uploads.md §3
 *
 * @api
 */
final class GpsDistance
{
    private const int EARTH_RADIUS_M = 6_371_000;

    /** Null if either end is missing: zero would be a false claim. */
    public static function metres(?float $photoLat, ?float $photoLng, ?float $pinLat, ?float $pinLng): ?int
    {
        if (null === $photoLat || null === $photoLng || null === $pinLat || null === $pinLng) {
            return null;
        }

        $halfLat = \sin(deg2rad($pinLat - $photoLat) / 2.0);
        $halfLng = \sin(deg2rad($pinLng - $photoLng) / 2.0);
        $a = $halfLat * $halfLat
            + \cos(deg2rad($photoLat)) * \cos(deg2rad($pinLat)) * $halfLng * $halfLng;

        return (int) round(2.0 * (float) self::EARTH_RADIUS_M * \asin(min(1.0, \sqrt($a))));
    }
}
