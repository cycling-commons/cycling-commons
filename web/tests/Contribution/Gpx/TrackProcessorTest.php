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

    public function testAscentIsNullForEmptyTrack(): void
    {
        // <2 points → no meaningful ascent → null (not 0).
        self::assertNull($this->processor->ascentM([]));
    }

    public function testAscentRoundsFractionalGains(): void
    {
        // Gains of +1.3 then +1.4 → 2.7 m total → round() → 3.
        $points = [[50.0, 5.0, 10.0], [50.01, 5.0, 11.3], [50.02, 5.0, 12.7]];
        self::assertSame(3, $this->processor->ascentM($points));
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

    public function testTrimPropagatesNullElevationThroughInterpolatedEndpoint(): void
    {
        // Long (~2.2 km) endpoint-adjacent segments guarantee both the start and
        // end cuts (350–750 m) land on the first/last segment. The inner point
        // lacks ele, so the interpolated endpoint must inherit ele = null
        // (the null-propagation branch in cutFromStart).
        $points = [[50.0, 5.0, 100.0], [50.02, 5.0, null], [50.04, 5.0, 120.0]];
        $trimmed = $this->processor->trim($points, hash('sha256', 'null-ele'));

        self::assertNull($trimmed[0][2], 'interpolated start endpoint inherits null ele');
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

    public function testSimplifyHandlesLargeZigzagWithoutStackOverflow(): void
    {
        // ~8k-point ±11 m zigzag. The amplitude exceeds the 10 m tolerance, so
        // Douglas-Peucker keeps nearly every point — precisely the input that
        // recurses ~O(n) deep (stack overflow risk in the classic recursive
        // form) *and* does O(n^2) inner-scan work. The explicit-stack loop
        // means it no longer overflows the call stack, but it still exceeds
        // the DP work budget (carry-in §12.1) — so it now fails cleanly and
        // quickly instead of either overflowing or silently burning CPU.
        $points = [];
        for ($i = 0; $i < 8_000; ++$i) {
            $lat = 50.0 + (0 === $i % 2 ? 0.0001 : -0.0001); // ±~11 m in latitude
            $lng = 5.0 + $i * 0.0002;                        // advance ~14 m east per step
            $points[] = [$lat, $lng, 100.0];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contribute.error.route_too_complex');
        $this->processor->simplify($points, 10.0);
    }

    public function testSimplifyRejectsAnAdversarialZigzagInsteadOfBurningCpu(): void
    {
        // A saw-tooth where every point is > tolerance from its neighbours: DP
        // keeps them all, so naive recursion is O(n^2). With ~6000 points the
        // budget (200n) trips and we reject cleanly rather than hang.
        $points = [];
        for ($i = 0; $i < 6000; ++$i) {
            $lat = 50.0 + ($i % 2 === 0 ? 0.0 : 0.001); // ~110 m vertical saw-tooth
            $lng = 5.0 + $i * 0.001;                     // ~70 m horizontal step
            $points[] = [$lat, $lng, null];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contribute.error.route_too_complex');
        (new \App\Contribution\Gpx\TrackProcessor())->simplify($points, 10.0);
    }

    public function testSimplifyStillReturnsForANormalDenseTrack(): void
    {
        // A smooth arc of 6000 points collapses fine — well under budget.
        $points = [];
        for ($i = 0; $i < 6000; ++$i) {
            $points[] = [50.0 + sin($i / 500) * 0.05, 5.0 + $i * 0.0005, null];
        }
        $out = (new \App\Contribution\Gpx\TrackProcessor())->simplify($points, 10.0);
        self::assertLessThan(\count($points), \count($out));
    }
}
