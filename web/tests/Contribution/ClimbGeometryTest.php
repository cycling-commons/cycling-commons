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
}
