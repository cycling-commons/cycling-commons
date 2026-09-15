<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

/**
 * The one great-circle distance for photos: a rider photo's GPS position to
 * the submission pin, and a Commons file's camera point to a scenic pin.
 *
 * A photo sent for a recommended route is measured to the nearest point of
 * the route's line instead (nearestOnLine()): a route has no single pin.
 *
 * @see docs/specs/photo-uploads.md §3, §5i
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

    /**
     * The point of a line nearest to a position, `[lat, lng]`, or null for a
     * line with no usable vertex.
     *
     * Each segment is projected onto a local flat plane around the position
     * (equirectangular, fine at the scale of a photo and a road), clamped to
     * the segment, and the nearest candidate by great-circle distance wins.
     *
     * @param list<mixed> $lineLngLat GeoJSON LineString coordinates, `[lng, lat]`
     *
     * @return array{0: float, 1: float}|null
     */
    public static function nearestOnLine(float $lat, float $lng, array $lineLngLat): ?array
    {
        $vertices = [];
        foreach ($lineLngLat as $c) {
            if (\is_array($c) && isset($c[0], $c[1]) && is_numeric($c[0]) && is_numeric($c[1])) {
                $vertices[] = [(float) $c[1], (float) $c[0]];
            }
        }
        if ([] === $vertices) {
            return null;
        }
        if (1 === \count($vertices)) {
            return $vertices[0];
        }

        $kx = cos(deg2rad($lat));
        $best = null;
        $bestM = \INF;
        for ($i = 1, $n = \count($vertices); $i < $n; ++$i) {
            [$aLat, $aLng] = $vertices[$i - 1];
            [$bLat, $bLng] = $vertices[$i];
            $ax = ($aLng - $lng) * $kx;
            $ay = $aLat - $lat;
            $dx = ($bLng - $aLng) * $kx;
            $dy = $bLat - $aLat;
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0.0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $len2)) : 0.0;
            $candidate = [$aLat + $t * ($bLat - $aLat), $aLng + $t * ($bLng - $aLng)];
            $m = self::metres($lat, $lng, $candidate[0], $candidate[1]);
            if ($m < $bestM) {
                $bestM = $m;
                $best = $candidate;
            }
        }

        return $best;
    }
}
