<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Elevation\ClimbProfiler;
use App\Elevation\CoveredSpans;
use App\Elevation\ElevationClient;
use App\Elevation\ElevationEndpoints;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The climb measurement itself.
 *
 * These pin the definitions the catalogue publishes, so a refactor that quietly
 * changes what "average gradient" means fails here rather than on the map.
 */
final class ClimbProfilerTest extends TestCase
{
    /**
     * A straight north-bound line of `n` points, ~22 m apart, with the given
     * elevations. Latitude steps of 0.0002° are about 22 m.
     *
     * @param list<float> $elevations
     */
    private function profilerFor(array $elevations): ClimbProfiler
    {
        $client = new ElevationClient(
            new MockHttpClient(new MockResponse((string) json_encode(['height' => $elevations]))),
            new NullLogger(),
            'http://valhalla.test',
            'Copernicus DEM GLO-30',
        );

        return new ClimbProfiler($client);
    }

    /** @return list<array{0: float, 1: float}> */
    private function line(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; ++$i) {
            $out[] = [50.0 + $i * 0.0002, 5.0];
        }

        return $out;
    }

    public function testAscentOnlyIgnoresTheDescentInTheMiddle(): void
    {
        // Up 40, down 20, up 40: net 60 m, but 80 m of actual climbing.
        $elev = [];
        for ($i = 0; $i < 10; ++$i) {
            $elev[] = 100.0 + $i * 4;      // 100 -> 136
        }
        for ($i = 0; $i < 5; ++$i) {
            $elev[] = 136.0 - $i * 4;      // 136 -> 120
        }
        for ($i = 0; $i < 10; ++$i) {
            $elev[] = 120.0 + $i * 4;      // 120 -> 156
        }
        $p = $this->profilerFor($elev)->profile($this->line(\count($elev)));

        self::assertNotNull($p);
        // Net gain is 56 m; ascent-only counts both climbing stretches, so the
        // published average must exceed net-gain-over-length.
        $net = ($p['gain'] / $p['length']) * 100;
        self::assertGreaterThan($net, (float) rtrim($p['avgGradient'], '%'),
            'ascent-only must exceed net gain on a climb with a dip');
    }

    public function testALineThatOnlyDescendsIsRefusedRatherThanMeasuredAsZero(): void
    {
        // "Measure to the highest point" on a descending line puts the summit at
        // index 0 and yields 0 m over 0 m at 0% — three values that look
        // unremarkable in a database column (§4a).
        $elev = [];
        for ($i = 0; $i < 20; ++$i) {
            $elev[] = 240.0 - $i * 3;
        }

        self::assertNull($this->profilerFor($elev)->profile($this->line(20)));
    }

    public function testAFlatSummitIsNotReportedAsRunningPastTheTop(): void
    {
        // Roche-aux-Faucons finishes on a plateau: its tail is 130 m long and
        // loses 1 m. On flat ground the highest point is decided by DEM noise,
        // so a DISTANCE test flags a route that is perfectly correct.
        $elev = [];
        for ($i = 0; $i < 20; ++$i) {
            $elev[] = 100.0 + $i * 5;        // climb to 195
        }
        foreach ([195.0, 196.0, 195.0, 196.0, 195.0, 195.0] as $v) {
            $elev[] = $v;                    // plateau, wobbling within a metre
        }
        $p = $this->profilerFor($elev)->profile($this->line(\count($elev)));

        self::assertNotNull($p);
        self::assertSame(0.0, $p['overshootM'], 'a flat top is not an overshoot');
    }

    public function testARealTrailingDescentIsFlagged(): void
    {
        // La Redoute's stored contribution runs 361 m past its summit and loses
        // 10 m. That one is a descent, and must be reported.
        $elev = [];
        for ($i = 0; $i < 20; ++$i) {
            $elev[] = 100.0 + $i * 5;        // climb to 195
        }
        for ($i = 1; $i <= 12; ++$i) {
            $elev[] = 195.0 - $i;            // lose 12 m
        }
        $p = $this->profilerFor($elev)->profile($this->line(\count($elev)));

        self::assertNotNull($p);
        self::assertGreaterThan(0.0, $p['overshootM'], 'a real descent must be flagged');
        self::assertGreaterThanOrEqual(8.0, $p['overshootDropM']);
    }

    public function testLengthAndGainAreMeasuredToTheSummitNotToTheLastPoint(): void
    {
        $elev = [];
        for ($i = 0; $i < 20; ++$i) {
            $elev[] = 100.0 + $i * 5;        // summit 195 at index 19
        }
        for ($i = 1; $i <= 10; ++$i) {
            $elev[] = 195.0 - $i * 2;        // then drops away
        }
        $p = $this->profilerFor($elev)->profile($this->line(\count($elev)));

        self::assertNotNull($p);
        self::assertSame(95.0, $p['gain'], 'gain is to the high point, not the last point');
        // 30 points spaced ~22 m: the full line is ~640 m, the climb ~420 m.
        self::assertLessThan(560.0, $p['length'], 'length stops at the summit');
    }

    /**
     * A bar is a fixed distance, not a fraction of the climb.
     *
     * Eleven equal slices meant one bar was ~220 m on a 2.4 km climb and 1.5 km
     * on a 17 km one — charts that looked alike and could not be compared, and
     * a short ramp averaged flat on anything long.
     */
    public function testBinWidthClimbsTheLadderAsAClimbGetsLonger(): void
    {
        // 2.4 km still fits under the bar cap at 100 m: 24 bars.
        self::assertSame(100, ClimbProfiler::binWidthFor(2400));
        self::assertSame(100, ClimbProfiler::binWidthFor(2500));
        // Past 2.5 km, 100 m would exceed the cap, so it steps up.
        self::assertSame(150, ClimbProfiler::binWidthFor(2600));
        self::assertSame(150, ClimbProfiler::binWidthFor(3750));
        self::assertSame(200, ClimbProfiler::binWidthFor(3800));
        // Hockai, 16.9 km.
        self::assertSame(1000, ClimbProfiler::binWidthFor(16900));
    }

    public function testNoClimbEverDrawsMoreBarsThanTheCap(): void
    {
        foreach ([500, 2400, 2600, 5000, 12000, 16900, 40000] as $len) {
            $w = ClimbProfiler::binWidthFor((float) $len);
            self::assertLessThanOrEqual(25, (int) ceil($len / $w),
                "a {$len} m climb must not draw more than 25 bars");
        }
    }

    public function testTheProfileIsBinnedAtTheWidthItReports(): void
    {
        // ~29 points at ~22 m is roughly 620 m, which bins at 100 m.
        $elev = [];
        for ($i = 0; $i < 29; ++$i) {
            $elev[] = 100.0 + $i * 3;
        }
        $p = $this->profilerFor($elev)->profile($this->line(29));

        self::assertNotNull($p);
        self::assertSame(100, $p['binM']);
        self::assertSame((int) ceil($p['length'] / $p['binM']), \count($p['grad']),
            'the bar count must follow the reported bin width');
    }

    public function testProvenanceTravelsWithTheMeasurement(): void
    {
        $elev = [];
        for ($i = 0; $i < 20; ++$i) {
            $elev[] = 100.0 + $i * 4;
        }
        $p = $this->profilerFor($elev)->profile($this->line(20));

        self::assertNotNull($p);
        self::assertSame('Copernicus DEM GLO-30', $p['demSource'],
            'a published gradient must name the dataset it came from');
    }

    public function testNoElevationMeansNoProfileRatherThanAGuess(): void
    {
        $client = new ElevationClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 500])),
            new NullLogger(), 'http://valhalla.test', 'x',
        );

        self::assertNull((new ClimbProfiler($client))->profile($this->line(10)));
    }

    public function testASingleBadSampleDoesNotBecomeThePublishedSteepestFigure(): void
    {
        // A steady 5% climb, ~22 m per point, with ONE sample pushed 30 m up and
        // straight back down: an avalanche gallery, a cutting, or a rock face
        // beside the carriageway. A Digital Surface Model reads all three, and
        // before 2026-08-07 a maximum let any one of them become the headline —
        // Grimsel, Susten and Klausen each published 35%, which was the clamp
        // rather than the road, over raw windows as steep as 77%.
        $elev = [];
        for ($i = 0; $i < 80; ++$i) {
            $elev[] = 1000.0 + $i * 1.1;    // ~22 m apart at 5%
        }
        $clean = $this->profilerFor($elev)->profile($this->line(80));

        $spiked = $elev;
        $spiked[40] += 30.0;                // one cell reading the wall, not the road
        $withSpike = $this->profilerFor($spiked)->profile($this->line(80));

        self::assertNotNull($clean);
        self::assertNotNull($withSpike);
        self::assertSame(
            $clean['maxGradient'],
            $withSpike['maxGradient'],
            'one artificial sample must not move the published steepest figure',
        );
    }

    public function testTheSteepestFigureCarriesTheWidthItWasMeasuredOver(): void
    {
        // The label is built from this, so a window change cannot leave the copy
        // saying 100 m while the number means something else.
        $elev = [];
        for ($i = 0; $i < 60; ++$i) {
            $elev[] = 500.0 + $i * 1.5;
        }
        $p = $this->profilerFor($elev)->profile($this->line(60));

        self::assertNotNull($p);
        self::assertSame(250, $p['steepWindowM']);
    }

    public function testAStepUnderAGalleryIsNotTheSteepestStretch(): void
    {
        // A steady 5% climb with a 40 m step in the middle — the shape a
        // Digital Surface Model returns where the road runs under an avalanche
        // gallery and the sensor saw the mountain on top of it. Grimsel has
        // 2,082 m of exactly this, 8% of the climb.
        // The 2.0 m rise per point matters: with a gentler trend the 40 m step
        // would itself be the highest point on the line, and profile() trims to
        // the summit — which moves the distance axis out from under the span.
        $elev = [];
        for ($i = 0; $i < 80; ++$i) {
            $elev[] = 1000.0 + $i * 2.0;
        }
        for ($i = 38; $i < 44; ++$i) {
            $elev[$i] += 40.0;              // the roof, not the road
        }

        $withoutCover = (new ClimbProfiler($this->profilerClient($elev)))
            ->profile($this->line(80));

        // 21 evenly spaced shape points; the tunnel edge spans indices 8..12,
        // so the covered fraction is 0.40..0.60 — the step.
        $covered = new CoveredSpans(
            new MockHttpClient(new MockResponse((string) json_encode([
                'shape' => '_gwj~A_sdpH_q@?_q@?_q@?_q@?_q@?_q@?_q@?_q@?_q@?_q@?_q@?_q@?_q@?_q@?_q@?_q@?_q@?_q@?_q@?_q@?',
                'edges' => [['tunnel' => true, 'begin_shape_index' => 8, 'end_shape_index' => 12]],
            ]))),
            new NullLogger(),
            new ElevationEndpoints('http://valhalla.test'),
        );
        $withCover = (new ClimbProfiler($this->profilerClient($elev), $covered))
            ->profile($this->line(80));

        self::assertNotNull($withoutCover);
        self::assertNotNull($withCover);
        self::assertGreaterThan(
            (float) $withCover['maxGradient'],
            (float) $withoutCover['maxGradient'],
            'the gallery step must not survive as the published steepest stretch',
        );
    }

    private function profilerClient(array $elev): ElevationClient
    {
        return new ElevationClient(
            new MockHttpClient(new MockResponse((string) json_encode(['height' => $elev]))),
            new NullLogger(),
            'http://valhalla.test',
            'Copernicus DEM GLO-30',
        );
    }

    public function testLengthIsMeasuredAlongTheRoadAndNotAcrossTheHairpins(): void
    {
        // A zig-zag: 400 vertices of tight switchbacks, the shape a routing
        // engine returns for an alpine pass. Sampling keeps every other one, and
        // measuring along the SAMPLED line chords straight across each bend —
        // which is how the Susten published 26,956 m for a 28,215 m road and
        // then had its drawn line trimmed 1.3 km short of its own summit.
        $route = [];
        for ($i = 0; $i < 400; ++$i) {
            // North-bound, swinging east/west every vertex.
            $route[] = [50.0 + $i * 0.0002, 5.0 + (0 === $i % 2 ? 0.0 : 0.0006)];
        }
        $elev = [];
        for ($i = 0; $i < 200; ++$i) {
            $elev[] = 500.0 + $i * 2.0;
        }

        $p = $this->profilerFor($elev)->profile($route);
        self::assertNotNull($p);

        // The straight-line north extent alone is ~8.7 km; the zig-zag makes the
        // real road substantially longer. The published length must reflect the
        // road, so it has to exceed the pure north-south distance by a clear
        // margin rather than sitting on it.
        self::assertGreaterThan(
            9500.0,
            $p['length'],
            'length must follow the road, not chord across the sampled-away bends',
        );
    }
}
