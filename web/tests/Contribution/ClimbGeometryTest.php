<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Contribution\ClimbGeometry;
use PHPUnit\Framework\TestCase;

final class ClimbGeometryTest extends TestCase
{
    public function testDecodesWellFormedRouteGradSteep(): void
    {
        $out = ClimbGeometry::fromPayload([
            'route' => '[[50.51,5.24],[50.52,5.25]]', // [lat,lng] — stored order
            'grad' => '[6,9,13]',
            'steep' => '{"at":[50.517,5.247],"pct":"26%","manual":true}',
        ]);
        self::assertSame([[50.51, 5.24], [50.52, 5.25]], $out['route']);
        self::assertSame([6, 9, 13], $out['grad']);
        self::assertSame([50.517, 5.247], $out['steep']['at']);
        self::assertSame('26%', $out['steep']['pct']);
        self::assertTrue($out['steep']['manual']);
    }

    public function testOmitsAbsentOrBlankKeys(): void
    {
        self::assertSame([], ClimbGeometry::fromPayload(['route' => '', 'grad' => null]));
    }

    public function testRejectsMalformedRoute(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClimbGeometry::fromPayload(['route' => '{"not":"an array of pairs"}']);
    }

    public function testRejectsNonNumericGrad(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClimbGeometry::fromPayload(['grad' => '["x",2]']);
    }

    public function testSteepManualDefaultsFalse(): void
    {
        $out = ClimbGeometry::fromPayload(['steep' => '{"at":[50.5,5.2],"pct":"12%"}']);
        self::assertFalse($out['steep']['manual']);
    }

    public function testRejectsNonFiniteRoute(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClimbGeometry::fromPayload(['route' => '[[1e400,5.24],[50.52,5.25]]']);
    }

    public function testRejectsNonFiniteGrad(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClimbGeometry::fromPayload(['grad' => '[1e400]']);
    }

    public function testRejectsNonFiniteSteepAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClimbGeometry::fromPayload(['steep' => '{"at":[1e400,5.2],"pct":"12%"}']);
    }

    public function testRejectsOverlongRoute(): void
    {
        // Above MAX_POINTS, which rose to 8000 once a real 28.5 km alpine
        // pass turned out to be 2,146 points at router resolution.
        $pairs = array_fill(0, 8001, [50.5, 5.2]);
        $this->expectException(\InvalidArgumentException::class);
        ClimbGeometry::fromPayload(['route' => (string) json_encode($pairs)]);
    }

    public function testRejectsOverlongGrad(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClimbGeometry::fromPayload(['grad' => (string) json_encode(array_fill(0, 8001, 5))]);
    }

    public function testRejectsOutOfRangeRouteLatitude(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClimbGeometry::fromPayload(['route' => '[[99999,5.24],[50.52,5.25]]']);
    }

    public function testRejectsOutOfRangeRouteLongitude(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClimbGeometry::fromPayload(['route' => '[[50.5,999],[50.52,5.25]]']);
    }

    public function testRejectsNonScalarPct(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClimbGeometry::fromPayload(['steep' => '{"at":[50.5,5.2],"pct":["x"]}']);
    }

    public function testRejectsGarbagePct(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClimbGeometry::fromPayload(['steep' => '{"at":[50.5,5.2],"pct":"<script>"}']);
    }

    public function testAcceptsPlainNumericAndPercentPct(): void
    {
        $a = ClimbGeometry::fromPayload(['steep' => '{"at":[50.5,5.2],"pct":"12"}']);
        $b = ClimbGeometry::fromPayload(['steep' => '{"at":[50.5,5.2],"pct":"12.5%"}']);
        self::assertSame('12', $a['steep']['pct']);
        self::assertSame('12.5%', $b['steep']['pct']);
    }

    /**
     * The catalog's OWN values. Five of the six seeded climbs record their
     * steepest pitch as "about that much" — "~20%", "~11%" — which is honest
     * for a ramp nobody has surveyed, and the map prints it verbatim on the
     * steepest marker.
     *
     * Refusing it made those climbs uneditable: the editor loads the stored
     * marker, carries its pct into the hidden field, and every submission that
     * touched the geometry came back as `invalid_geometry` — the recurring
     * "it just sends me back to the first page" report. A validator that
     * rejects the application's own data is the bug.
     */
    public function testAcceptsTheApproximateFormTheCatalogActuallyStores(): void
    {
        foreach (['~20%', '~11%', '~9.5%', '~20'] as $pct) {
            $out = ClimbGeometry::fromPayload(['steep' => '{"at":[50.49077,5.70583],"pct":"'.$pct.'"}']);
            self::assertSame($pct, $out['steep']['pct'], $pct.' is a value the catalog stores and the map displays');
        }
    }

    /**
     * The rider's steepest point is a SEPARATE attribute from our derived one.
     *
     * Ours is the steepest sustained 100 m the elevation model can see, measured
     * identically on every climb, which is what makes it comparable and
     * sortable. Theirs is knowledge the model does not have — a hairpin smaller
     * than one DEM cell is invisible at any window width. If they shared a
     * field, the published value would mean something different on every climb
     * depending on who had edited it (climb-elevation.md §5a).
     */
    public function testTheRidersSteepestPointIsItsOwnAttribute(): void
    {
        $out = ClimbGeometry::fromPayload([
            'steep' => '{"at":[50.5,5.2],"pct":"17%","manual":false}',
            'steepPoint' => '{"at":[50.51,5.21],"pct":"26%","note":"the hairpin after the chapel"}',
        ]);

        self::assertSame([50.5, 5.2], $out['steep']['at'], 'the derived marker is untouched');
        self::assertSame('17%', $out['steep']['pct']);
        self::assertSame([50.51, 5.21], $out['steepPoint']['at']);
        self::assertSame('26%', $out['steepPoint']['pct']);
        self::assertSame('the hairpin after the chapel', $out['steepPoint']['note']);
    }

    /**
     * A rider may know WHERE the wall is without knowing how steep. Demanding a
     * number would invite an invented one.
     */
    public function testAPlaceWithoutAPercentageIsAccepted(): void
    {
        $out = ClimbGeometry::fromPayload(['steepPoint' => '{"at":[50.51,5.21]}']);

        self::assertSame([50.51, 5.21], $out['steepPoint']['at']);
        self::assertSame('', $out['steepPoint']['pct']);
        self::assertSame('', $out['steepPoint']['note']);
    }

    public function testTheRidersPointIsHeldToTheSameGradientShape(): void
    {
        foreach (['<script>', 'Array', '200%', 'steep!', '~~9'] as $bad) {
            try {
                ClimbGeometry::fromPayload(['steepPoint' => '{"at":[50.5,5.2],"pct":"'.$bad.'"}']);
                self::fail(sprintf('"%s" should not be accepted as a gradient', $bad));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheRidersPointMustBeSomewhereOnEarth(): void
    {
        foreach (['{"at":[95,5.2]}', '{"at":[50.5,200]}', '{"at":[50.5]}', '{"at":"nope"}'] as $bad) {
            try {
                ClimbGeometry::fromPayload(['steepPoint' => $bad]);
                self::fail(sprintf('%s should not be accepted as a position', $bad));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /** A landmark, not a paragraph — free text reaching published attributes is bounded. */
    public function testTheNoteIsBounded(): void
    {
        $out = ClimbGeometry::fromPayload([
            'steepPoint' => '{"at":[50.5,5.2],"note":"'.str_repeat('x', 400).'"}',
        ]);

        self::assertSame(120, mb_strlen($out['steepPoint']['note']));
    }

    /** The '~' buys nothing else: it is one optional character, not a bypass. */
    public function testTheApproximateMarkerDoesNotOpenTheRuleUp(): void
    {
        foreach (['~', '~~20%', '~abc', '~<script>', '~200%'] as $bad) {
            try {
                ClimbGeometry::fromPayload(['steep' => '{"at":[50.5,5.2],"pct":"'.$bad.'"}']);
                self::fail(sprintf('"%s" should not be accepted as a gradient', $bad));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testARealAlpinePassIsNotTooLongToRead(): void
    {
        // The Susten from Innertkirchen is 2,146 points over 28.5 km at router
        // resolution, and the editor stores the ROUTER's line. Under the old
        // 2000-point cap that shape was rejected as unreadable, so an owner
        // could not correct the climb's summit and redrawing could never have
        // helped (owner-reported 2026-08-08).
        $route = [];
        for ($i = 0; $i < 2146; ++$i) {
            $route[] = [46.70 + $i * 0.00001, 8.22 + $i * 0.0001];
        }

        $out = ClimbGeometry::fromPayload(['route' => json_encode($route)]);

        self::assertCount(2146, $out['route']);
    }
}
