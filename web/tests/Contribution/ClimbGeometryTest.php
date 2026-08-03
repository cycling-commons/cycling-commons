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
        $pairs = array_fill(0, 2001, [50.5, 5.2]);
        $this->expectException(\InvalidArgumentException::class);
        ClimbGeometry::fromPayload(['route' => (string) json_encode($pairs)]);
    }

    public function testRejectsOverlongGrad(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClimbGeometry::fromPayload(['grad' => (string) json_encode(array_fill(0, 2001, 5))]);
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
}
