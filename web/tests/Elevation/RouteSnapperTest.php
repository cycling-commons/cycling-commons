<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Elevation\RouteSnapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The road snapper, and the ways it is allowed to fail.
 *
 * The polyline test pins the decoder to Valhalla's actual encoding (Google
 * polyline at 1e-6): an off-by-a-decimal decoder produces a line 10× the
 * world away, and nothing downstream would say so.
 */
final class RouteSnapperTest extends TestCase
{
    private function snapper(MockResponse $response, string $url = 'http://valhalla.test'): RouteSnapper
    {
        return new RouteSnapper(new MockHttpClient($response), new NullLogger(), $url);
    }

    /**
     * Encode [lat,lng] pairs the way Valhalla does (polyline, 1e-6).
     *
     * @param list<array{0: float, 1: float}> $points
     */
    private static function encode(array $points): string
    {
        $out = '';
        $prevLat = $prevLng = 0;
        foreach ($points as [$lat, $lng]) {
            foreach ([(int) round($lat * 1e6) - $prevLat, (int) round($lng * 1e6) - $prevLng] as $k => $delta) {
                $v = $delta < 0 ? ~($delta << 1) : ($delta << 1);
                while ($v >= 0x20) {
                    $out .= \chr((0x20 | ($v & 0x1F)) + 63);
                    $v >>= 5;
                }
                $out .= \chr($v + 63);
            }
            $prevLat = (int) round($lat * 1e6);
            $prevLng = (int) round($lng * 1e6);
        }

        return $out;
    }

    public function testDecodesTheShapeIntoGeoJsonOrder(): void
    {
        $shape = self::encode([[50.4926, 5.8637], [50.4900, 5.8650], [50.4837, 5.8683]]);
        $snapper = $this->snapper(new MockResponse(json_encode([
            'trip' => ['legs' => [['shape' => $shape, 'summary' => ['length' => 1.234]]]],
        ], \JSON_THROW_ON_ERROR)));

        $result = $snapper->snap([50.4926, 5.8637], [50.4837, 5.8683]);

        self::assertNotNull($result);
        // [lng, lat] out — GeoJSON order, what the editor draws.
        self::assertEqualsWithDelta(5.8637, $result['coordinates'][0][0], 1e-6);
        self::assertEqualsWithDelta(50.4926, $result['coordinates'][0][1], 1e-6);
        self::assertEqualsWithDelta(5.8683, $result['coordinates'][2][0], 1e-6);
        self::assertCount(3, $result['coordinates']);
        self::assertSame(1234.0, $result['distanceM']);
    }

    public function testMultiLegTripsJoinWithoutRepeatingTheSeam(): void
    {
        $legA = self::encode([[50.0, 5.0], [50.001, 5.001]]);
        $legB = self::encode([[50.001, 5.001], [50.002, 5.002]]);
        $snapper = $this->snapper(new MockResponse(json_encode([
            'trip' => ['legs' => [
                ['shape' => $legA, 'summary' => ['length' => 0.5]],
                ['shape' => $legB, 'summary' => ['length' => 0.5]],
            ]],
        ], \JSON_THROW_ON_ERROR)));

        $result = $snapper->snap([50.0, 5.0], [50.002, 5.002]);

        self::assertNotNull($result);
        self::assertCount(3, $result['coordinates']);   // 2 + 2 − shared seam
        self::assertSame(1000.0, $result['distanceM']);
    }

    /** No route is null, not an exception: the editor keeps its straight line. */
    public function testAnErrorReplyIsNull(): void
    {
        $snapper = $this->snapper(new MockResponse(
            json_encode(['error' => 'No path could be found'], \JSON_THROW_ON_ERROR),
            ['http_code' => 400],
        ));

        self::assertNull($snapper->snap([50.0, 5.0], [50.1, 5.1]));
    }

    public function testAnUnreachableRouterIsNull(): void
    {
        $snapper = $this->snapper(new MockResponse('', ['error' => 'connection refused']));

        self::assertNull($snapper->snap([50.0, 5.0], [50.1, 5.1]));
    }

    /** ELEVATION_URL is the master switch for routing too: unset = no snapping, never a guess. */
    public function testAnEmptyUrlDisablesSnapping(): void
    {
        $snapper = $this->snapper(new MockResponse('never called'), '');

        self::assertNull($snapper->snap([50.0, 5.0], [50.1, 5.1]));
    }
}
