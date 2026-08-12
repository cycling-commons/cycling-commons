<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Moderation\SubmissionQueue;
use PHPUnit\Framework\TestCase;

/**
 * A road-surface stretch is reviewed by LOOKING at it.
 *
 * It used to arrive at the desk as `{"a":[5.265,50.276],"b":…,"line":[[…]]}`
 * printed into the textual diff — hundreds of coordinates a curator cannot
 * check, pushing the fields they *can* check off the card (owner-reported
 * 2026-08-12). Geometry now goes to the before/after switch, like a redrawn
 * climb, and stays out of the text.
 */
final class SegmentShapeReviewTest extends TestCase
{
    /** @param array<string, mixed> $changes */
    private function call(string $method, array $changes): mixed
    {
        $ref = new \ReflectionMethod(SubmissionQueue::class, $method);

        return $ref->invoke(null, json_encode($changes, \JSON_THROW_ON_ERROR));
    }

    public function testASegmentBecomesADrawableShape(): void
    {
        $shape = $this->call('shapeSides', ['segment' => ['was' => null, 'now' => [
            'a' => [6.04, 50.49],
            'b' => [6.06, 50.51],
            'line' => [[6.04, 50.49], [6.05, 50.50], [6.06, 50.51]],
        ]]]);

        self::assertIsArray($shape);
        self::assertNull($shape['before'], 'a new stretch has no previous shape');
        // Flipped to [lat,lng]: showPendingShape() reads climb order, and one
        // renderer for both is the point.
        self::assertSame([[50.49, 6.04], [50.50, 6.05], [50.51, 6.06]], $shape['after']['route']);
    }

    public function testWithoutARouterPathTheTwoTapsAreStillDrawable(): void
    {
        // No route found, or an older client: the chord is a worse shape but
        // never a wrong one, and a curator still needs to see something.
        $shape = $this->call('shapeSides', ['segment' => ['was' => null, 'now' => [
            'a' => [6.04, 50.49], 'b' => [6.06, 50.51],
        ]]]);

        self::assertSame([[50.49, 6.04], [50.51, 6.06]], $shape['after']['route']);
    }

    public function testGeometryNeverReachesTheTextualDiff(): void
    {
        $rows = $this->call('changeRows', [
            'segment' => ['was' => null, 'now' => ['a' => [6.04, 50.49], 'b' => [6.06, 50.51]]],
            'route' => ['was' => null, 'now' => [[50.4, 6.0]]],
            'surface' => ['was' => 'Asphalt', 'now' => 'Gravel'],
        ]);

        $keys = array_column($rows, 'key');
        self::assertSame(['surface'], $keys, 'only the reviewable field survives as text');
    }

    public function testANonSegmentSubmissionIsUnaffected(): void
    {
        self::assertNull($this->call('shapeSides', ['surface' => ['was' => 'Asphalt', 'now' => 'Gravel']]));
    }
}
