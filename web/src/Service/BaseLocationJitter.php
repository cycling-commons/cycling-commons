<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Service;

/**
 * Moves a base location to a random point within RADIUS_KM of where the rider
 * clicked, evenly over the disc (docs/specs/map-and-search.md §4.5; privacy
 * notice). About 5 km precision: the stored point scopes the rider's own map
 * view and never gives away a home address.
 *
 * @api
 */
final class BaseLocationJitter
{
    public const float RADIUS_KM = 2.5;
    private const float KM_PER_DEGREE = 111.32;

    /** @param (\Closure(): float)|null $random a number in [0, 1]; tests pass a fixed one */
    public function __construct(private readonly ?\Closure $random = null)
    {
    }

    /** @return array{0: float, 1: float} latitude, longitude */
    public function apply(float $lat, float $lng): array
    {
        // sqrt keeps the points even over the disc rather than bunched at its centre.
        $r = self::RADIUS_KM * sqrt($this->unit());
        $angle = 2 * M_PI * $this->unit();
        $dLat = $r * cos($angle) / self::KM_PER_DEGREE;
        $dLng = $r * sin($angle) / (self::KM_PER_DEGREE * max(cos(deg2rad($lat)), 0.01));

        return [max(-90.0, min(90.0, $lat + $dLat)), fmod($lng + $dLng + 540.0, 360.0) - 180.0];
    }

    private function unit(): float
    {
        return null !== $this->random ? ($this->random)() : random_int(0, \PHP_INT_MAX) / \PHP_INT_MAX;
    }
}
