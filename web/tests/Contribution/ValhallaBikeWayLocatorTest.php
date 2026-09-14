<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Contribution\ValhallaBikeWayLocator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * How far a point is from a way a bike may ride, read from Valhalla's /locate
 * (docs/specs/scenic-views.md, "Adding a scenic view").
 *
 * The trap this pins: Valhalla's bicycle costing will happily snap to a
 * climbers' path. The Matterhorn's summit is 4 m from "a way a bike can use",
 * and that way is a path graded difficult alpine hiking.
 */
final class ValhallaBikeWayLocatorTest extends TestCase
{
    /** @param list<array{distance: float, use: string, bicycle?: bool, cycle_lane?: string, sac?: string, cls?: string}> $edges */
    private static function locate(array $edges): string
    {
        return json_encode([[
            'input_lat' => 46.0, 'input_lon' => 7.0,
            'edges' => array_map(static fn (array $e): array => [
                'distance' => $e['distance'],
                'edge' => [
                    'classification' => ['classification' => $e['cls'] ?? 'service_other', 'use' => $e['use']],
                    'access' => ['bicycle' => $e['bicycle'] ?? true],
                    'cycle_lane' => $e['cycle_lane'] ?? 'none',
                    'sac_scale' => $e['sac'] ?? 'none',
                ],
            ], $edges),
        ]], \JSON_THROW_ON_ERROR);
    }

    private function locator(MockResponse $response, string $url = 'http://valhalla.test'): ValhallaBikeWayLocator
    {
        return new ValhallaBikeWayLocator(new MockHttpClient($response), new NullLogger(), $url);
    }

    public function testARoadCounts(): void
    {
        $reading = $this->locator(new MockResponse(self::locate([['distance' => 12.4, 'use' => 'road', 'cls' => 'residential']])))->nearest(52.37, 4.53);

        self::assertTrue($reading->known);
        self::assertSame(12.4, $reading->nearestM);
        self::assertFalse($reading->far());
    }

    public function testAClimbersPathDoesNotCountButACycleway300mAwayIsFar(): void
    {
        $reading = $this->locator(new MockResponse(self::locate([
            ['distance' => 4, 'use' => 'path', 'sac' => 'difficult alpine hiking'],
            ['distance' => 330, 'use' => 'cycleway', 'cycle_lane' => 'dedicated'],
        ])))->nearest(45.97639, 7.65861);

        self::assertTrue($reading->known);
        self::assertSame(330.0, $reading->nearestM);
        self::assertTrue($reading->far(), 'beyond 250 m');
    }

    public function testAPathCountsOnlyWhenItIsMarkedForBikes(): void
    {
        $plain = $this->locator(new MockResponse(self::locate([['distance' => 5, 'use' => 'path']])))->nearest(46.0, 7.0);
        $marked = $this->locator(new MockResponse(self::locate([['distance' => 5, 'use' => 'path', 'cycle_lane' => 'dedicated']])))->nearest(46.0, 7.0);

        self::assertNull($plain->nearestM);
        self::assertTrue($plain->far(), 'known, and nothing a bike may ride in range');
        self::assertSame(5.0, $marked->nearestM);
    }

    public function testAWayBikesMayNotUseDoesNotCount(): void
    {
        $reading = $this->locator(new MockResponse(self::locate([
            ['distance' => 3, 'use' => 'road', 'cls' => 'motorway', 'bicycle' => false],
        ])))->nearest(46.0, 7.0);

        self::assertNull($reading->nearestM);
    }

    public function testUnreachableValhallaIsUnknownNotFar(): void
    {
        $reading = $this->locator(new MockResponse('', ['http_code' => 503]))->nearest(46.0, 7.0);

        self::assertFalse($reading->known);
        self::assertFalse($reading->far(), 'no warning when we cannot tell');
    }

    public function testNoValhallaConfiguredIsUnknown(): void
    {
        $reading = $this->locator(new MockResponse('[]'), '')->nearest(46.0, 7.0);

        self::assertFalse($reading->known);
    }
}
