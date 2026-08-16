<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * How far a photo was taken from the pin, in whole metres - the only thing that
 * survives of a photo's coordinates (docs/specs/photo-uploads.md §3).
 *
 * Its own class because two callers now compute it and they must agree to the
 * metre: MediaClaimService, when the rider submits, and
 * ScanAndReleaseUploadHandler, for the photo that was claimed while it was
 * still quarantined and so had no coordinates to work from at claim time.
 *
 * @api Called by MediaClaimService and ScanAndReleaseUploadHandler.
 */
final class GpsDistance
{
    private const int EARTH_RADIUS_M = 6_371_000;

    /**
     * Null whenever either end is missing: an absent distance is honest, a zero
     * would be a claim.
     */
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
