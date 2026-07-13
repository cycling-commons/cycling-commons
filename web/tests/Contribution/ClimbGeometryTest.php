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
}
