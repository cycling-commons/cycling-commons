<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution\Gpx;

use App\Contribution\Gpx\TrackProcessor;
use PHPUnit\Framework\TestCase;

final class TrackProcessorTest extends TestCase
{
    private TrackProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new TrackProcessor();
    }

    /** 1° of latitude ≈ 111.19 km (haversine, R = 6371 km). */
    public function testDistanceOfOneDegreeLatitude(): void
    {
        $d = $this->processor->distanceM([[50.0, 5.0, null], [51.0, 5.0, null]]);
        self::assertEqualsWithDelta(111_195.0, $d, 100.0);
    }

    public function testAscentSumsOnlyPositiveDeltas(): void
    {
        $points = [[50.0, 5.0, 100.0], [50.01, 5.0, 140.0], [50.02, 5.0, 120.0], [50.03, 5.0, 150.0]];
        self::assertSame(70, $this->processor->ascentM($points)); // +40, −20, +30 → 70
    }

    public function testAscentIsNullWhenAnyElevationMissing(): void
    {
        self::assertNull($this->processor->ascentM([[50.0, 5.0, 100.0], [50.01, 5.0, null]]));
    }

    /** Spec D4: same content hash → identical trim; endpoints move ≥350 m and ≤750 m. */
    public function testTrimIsDeterministicAndMovesEndpointsWithinSpecRange(): void
    {
        // Straight ~11 km line along a meridian, a point every ~111 m.
        $points = [];
        for ($i = 0; $i <= 100; ++$i) {
            $points[] = [50.0 + $i * 0.001, 5.0, 100.0 + $i];
        }
        $seed = hash('sha256', 'fixture-content');

        $a = $this->processor->trim($points, $seed);
        $b = $this->processor->trim($points, $seed);
        self::assertSame($a, $b, 'same seed → identical trim');

        $cutStart = $this->processor->distanceM([$points[0], $a[0]]);
        $cutEnd = $this->processor->distanceM([end($points), end($a)]);
        self::assertGreaterThanOrEqual(350.0, $cutStart);
        self::assertLessThanOrEqual(750.0, $cutStart);
        self::assertGreaterThanOrEqual(350.0, $cutEnd);
        self::assertLessThanOrEqual(750.0, $cutEnd);

        $other = $this->processor->trim($points, hash('sha256', 'different-content'));
        self::assertNotSame($a[0], $other[0], 'different seed → different cut');
    }

    public function testTrimInterpolatesOnSparseTracks(): void
    {
        // Two points 2 km apart: the cut must interpolate new endpoints, not
        // drop the only points.
        $points = [[50.0, 5.0, 100.0], [50.018, 5.0, 120.0]];
        $trimmed = $this->processor->trim($points, hash('sha256', 'sparse'));

        self::assertCount(2, $trimmed);
        self::assertGreaterThan(50.0, $trimmed[0][0], 'start endpoint moved inward');
        self::assertLessThan(50.018, $trimmed[1][0], 'end endpoint moved inward');
    }

    public function testSimplifyDropsCollinearPointsButKeepsCorners(): void
    {
        $points = [
            [50.0, 5.0, null], [50.001, 5.0, null], [50.002, 5.0, null], // collinear
            [50.002, 5.01, null],                                        // corner
        ];
        $simplified = $this->processor->simplify($points, 10.0);

        self::assertSame([50.0, 5.0, null], $simplified[0]);
        self::assertSame([50.002, 5.01, null], end($simplified));
        self::assertLessThan(4, \count($simplified), 'collinear midpoints dropped');
        self::assertContains([50.002, 5.0, null], $simplified, 'the corner survives');
    }
}
