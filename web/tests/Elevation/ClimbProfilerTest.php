<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Elevation\ClimbProfiler;
use App\Elevation\ElevationClient;
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

    public function testTheDisplayProfileAlwaysHasElevenBars(): void
    {
        $elev = [];
        for ($i = 0; $i < 30; ++$i) {
            $elev[] = 100.0 + $i * 3;
        }
        $p = $this->profilerFor($elev)->profile($this->line(30));

        self::assertNotNull($p);
        self::assertCount(11, $p['grad']);
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
}
