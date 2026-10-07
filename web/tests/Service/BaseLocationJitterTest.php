<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BaseLocationJitter;
use PHPUnit\Framework\TestCase;

/**
 * A base location is stored as a random point within 2.5 km of where the rider
 * clicked (owner, 2026-10-07; privacy notice): about 5 km precision, so the
 * stored point does not give away a home address.
 */
final class BaseLocationJitterTest extends TestCase
{
    private static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function testThePointStaysWithinTheRadius(): void
    {
        $jitter = new BaseLocationJitter();
        for ($i = 0; $i < 500; ++$i) {
            [$lat, $lng] = $jitter->apply(52.3702, 4.8952);
            self::assertLessThanOrEqual(BaseLocationJitter::RADIUS_KM + 0.001, self::km(52.3702, 4.8952, $lat, $lng));
        }
    }

    public function testThePointsSpreadOverTheDiscNotOnlyItsCentre(): void
    {
        $jitter = new BaseLocationJitter();
        $far = 0;
        for ($i = 0; $i < 400; ++$i) {
            [$lat, $lng] = $jitter->apply(52.3702, 4.8952);
            if (self::km(52.3702, 4.8952, $lat, $lng) > 1.25) {
                ++$far;
            }
        }
        // Uniform over the disc: three quarters of the points lie beyond half the radius.
        self::assertGreaterThan(240, $far);
    }

    public function testTheEdgeOfTheDiscIsReachedInEveryDirection(): void
    {
        $edge = new BaseLocationJitter(static fn (): float => 1.0);
        [$lat] = $edge->apply(0.0, 0.0);
        self::assertEqualsWithDelta(BaseLocationJitter::RADIUS_KM, self::km(0.0, 0.0, $lat, 0.0), 0.01, 'u = v = 1: due north at the full radius');
    }
}
