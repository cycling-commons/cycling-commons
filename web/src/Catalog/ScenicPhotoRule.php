<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Contribution\BikeWayReading;

/**
 * Which photos a scenic view (letter P) may show.
 *
 * A scenic pin promises a view from that spot. A photo shows the view from
 * wherever its camera stood, so a photo taken elsewhere promises a view the
 * rider will not get at the pin (owner 2026-09-14). A photo is therefore shown
 * on a scenic view only when we know where the camera stood AND it stood within
 * MAX_CAMERA_DISTANCE_M of the pin. Not knowing is a refusal: no photo is
 * better than a misleading one.
 *
 * Two ways a photo entry says where its camera stood:
 *
 * - `cameraAt: [lat, lng]` for a Commons file, the file's primary coordinate of
 *   type camera (CommonsApi, stored on `commons_photo`);
 * - `distanceM: int|null` for a rider's upload, the distance from the photo's
 *   GPS position to the submission pin (`media_upload.gps_distance_m`).
 *
 * Every other letter is unaffected: a castle's photo from across the valley is
 * still a photo of the castle.
 *
 * @see docs/specs/scenic-views.md §8
 * @see docs/specs/photo-uploads.md §5
 *
 * @api
 */
final class ScenicPhotoRule
{
    /**
     * How far from the pin the camera may have stood.
     *
     * The scenic reach, the same distance a scenic pin may sit from a bike way
     * (BikeWayReading::SCENIC_WITHIN_M): one number for "near enough to count
     * as here" on a scenic view.
     */
    public const int MAX_CAMERA_DISTANCE_M = BikeWayReading::SCENIC_WITHIN_M;

    private const float EARTH_RADIUS_M = 6_371_008.8;

    public static function appliesTo(string $letter): bool
    {
        return ItemType::ScenicViews->letter() === $letter;
    }

    /**
     * Whether one photo entry may be shown on a scenic pin at (lat, lng).
     *
     * A null pin refuses a Commons photo, since there is nothing to measure it
     * against. A rider photo's distance was measured against the submission
     * pin, so it does not need one.
     */
    public static function allows(mixed $photo, ?float $lat, ?float $lng): bool
    {
        if (!\is_array($photo)) {
            return false;
        }

        $distance = $photo['distanceM'] ?? null;
        if ((\is_int($distance) || \is_float($distance)) && $distance >= 0 && $distance <= self::MAX_CAMERA_DISTANCE_M) {
            return true;
        }

        $camera = self::camera($photo['cameraAt'] ?? null);
        if (null === $camera || null === $lat || null === $lng) {
            return false;
        }

        return self::metres($lat, $lng, $camera[0], $camera[1]) <= self::MAX_CAMERA_DISTANCE_M;
    }

    /**
     * The attributes with every photo the rule refuses taken out.
     *
     * `photo` (the single legacy entry) is removed when refused; `photos` (the
     * gallery) keeps its shown entries as a list and is removed when none are
     * left, so the drawer sees the same shape as an item that never had one.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public static function filterAttributes(array $attributes, ?float $lat, ?float $lng): array
    {
        if (\array_key_exists('photo', $attributes) && !self::allows($attributes['photo'], $lat, $lng)) {
            unset($attributes['photo']);
        }

        if (\array_key_exists('photos', $attributes)) {
            $gallery = \is_array($attributes['photos']) ? $attributes['photos'] : [];
            $kept = array_values(array_filter($gallery, static fn (mixed $p): bool => self::allows($p, $lat, $lng)));
            if ([] === $kept) {
                unset($attributes['photos']);
            } else {
                $attributes['photos'] = $kept;
            }
        }

        return $attributes;
    }

    /** Great-circle distance in metres (haversine). */
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    /** @return array{0: float, 1: float}|null */
    private static function camera(mixed $raw): ?array
    {
        if (!\is_array($raw) || !array_is_list($raw) || 2 !== \count($raw)) {
            return null;
        }
        [$lat, $lng] = $raw;
        if (!(\is_int($lat) || \is_float($lat)) || !(\is_int($lng) || \is_float($lng))) {
            return null;
        }
        if (abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return [(float) $lat, (float) $lng];
    }
}
