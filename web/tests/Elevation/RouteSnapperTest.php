<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Catalog\BikeType;
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

    /** A whole ride in one call: each leg its own line and length; a leg missing makes the ride null (route-planner.md section 8). */
    public function testARideSnapsEachLegInOneCall(): void
    {
        $legA = self::encode([[50.0, 5.0], [50.001, 5.001]]);
        $legB = self::encode([[50.001, 5.001], [50.002, 5.002]]);
        $reply = json_encode(['trip' => ['legs' => [
            ['shape' => $legA, 'summary' => ['length' => 0.5]],
            ['shape' => $legB, 'summary' => ['length' => 0.75]],
        ]]], \JSON_THROW_ON_ERROR);
        $stops = [[50.0, 5.0], [50.001, 5.001], [50.002, 5.002]];

        $legs = $this->snapper(new MockResponse($reply))->snapEach($stops);
        self::assertNotNull($legs);
        self::assertCount(2, $legs);
        self::assertSame([[5.0, 50.0], [5.001, 50.001]], $legs[0]['coordinates']);
        self::assertSame(500.0, $legs[0]['distanceM']);
        self::assertSame(750.0, $legs[1]['distanceM']);

        self::assertNull($this->snapper(new MockResponse($reply))->snapEach([...$stops, [50.003, 5.003]]), 'a leg missing');
        self::assertNull($this->snapper(new MockResponse('never called'), '')->snapEach($stops), 'no engine');
    }

    /** A file's track pieces are matched to the roads, each on its own: trace_route, map_snap, the bike's costing. */
    public function testATrackPieceIsMatchedToTheRoadsOnItsOwn(): void
    {
        $bodies = [];
        $shape = self::encode([[50.0, 5.0], [50.001, 5.001], [50.002, 5.002]]);
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies, $shape): MockResponse {
            $bodies[] = [$url, json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR)];

            return new MockResponse((string) json_encode(['trip' => ['legs' => [['shape' => $shape, 'summary' => ['length' => 0.3]]]]]));
        });
        $snapper = new RouteSnapper($http, new NullLogger(), 'http://valhalla.test');
        $long = array_map(static fn (int $i): array => [50.0 + $i * 1e-5, 5.0], range(0, 4999));

        $out = $snapper->matchMany([[[50.0, 5.0], [50.002, 5.002]], $long], BikeType::Road);

        self::assertCount(2, $out);
        self::assertSame(300.0, $out[0]['distanceM'] ?? null);
        self::assertSame('http://valhalla.test/trace_route', $bodies[0][0]);
        self::assertSame('map_snap', $bodies[0][1]['shape_match']);
        self::assertSame('bicycle', $bodies[0][1]['costing']);
        self::assertArrayHasKey('bicycle', $bodies[0][1]['costing_options']);
        self::assertCount(RouteSnapper::MAX_MATCH_POINTS, $bodies[1][1]['shape'], 'a long piece is not thinned');
        self::assertSame(['lat' => 50.0, 'lon' => 5.0], $bodies[1][1]['shape'][0]);
        self::assertSame(['lat' => 50.0 + 4999 * 1e-5, 'lon' => 5.0], $bodies[1][1]['shape'][RouteSnapper::MAX_MATCH_POINTS - 1], 'the last point is dropped');
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

    /** @var array<string, mixed> what the last mock request carried, decoded */
    private array $lastBody = [];

    /** A mock client that keeps the JSON body it was asked to send. */
    private function recording(MockResponse $response): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->lastBody = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return $response;
        });
    }

    public function testAPlannedLegSendsTheBikeCosting(): void
    {
        $shape = self::encode([[50.0, 5.0], [50.001, 5.001]]);
        $client = $this->recording(new MockResponse(json_encode([
            'trip' => ['legs' => [['shape' => $shape, 'summary' => ['length' => 0.1]]]],
        ], \JSON_THROW_ON_ERROR)));
        $snapper = new RouteSnapper($client, new NullLogger(), 'http://valhalla.test');

        self::assertNotNull($snapper->snap([50.0, 5.0], [50.001, 5.001], BikeType::Gravel));

        self::assertSame('bicycle', $this->lastBody['costing']);
        self::assertSame(['bicycle' => ['bicycle_type' => 'Cross']], $this->lastBody['costing_options']);
    }

    /**
     * "Why this way?" (route-planner.md section 4.2a, owner 2026-10-15): the
     * shortest way the same bike may ride, the engine's `shortest` on top of
     * the bike's own block, so it still never takes a road a cyclist may not use.
     */
    public function testTheShortestWayKeepsTheBikeCostingAndAddsShortest(): void
    {
        $shape = self::encode([[50.0, 5.0], [50.001, 5.001]]);
        $client = $this->recording(new MockResponse(json_encode([
            'trip' => ['legs' => [['shape' => $shape, 'summary' => ['length' => 0.1]]]],
        ], \JSON_THROW_ON_ERROR)));
        $snapper = new RouteSnapper($client, new NullLogger(), 'http://valhalla.test');

        self::assertNotNull($snapper->snap([50.0, 5.0], [50.001, 5.001], BikeType::Road, shortest: true));

        self::assertSame(['bicycle' => ['bicycle_type' => 'Road', 'shortest' => true]], $this->lastBody['costing_options']);
    }

    /**
     * Never the same road twice (route-planner.md section 4.2, owner 2026-10-18): the points of a road another leg
     * already takes go to the engine as `exclude_locations`; without them the request carries none.
     */
    public function testRoadsToKeepOffGoToTheEngineAsExcludedLocations(): void
    {
        $shape = self::encode([[50.0, 5.0], [50.001, 5.001]]);
        $reply = static fn (): MockResponse => new MockResponse(json_encode(['trip' => ['legs' => [['shape' => $shape, 'summary' => ['length' => 0.1]]]]], \JSON_THROW_ON_ERROR));
        $snapper = new RouteSnapper($this->recording($reply()), new NullLogger(), 'http://valhalla.test');

        self::assertNotNull($snapper->snap([50.0, 5.0], [50.01, 5.01], BikeType::Road, false, [[50.004, 5.004], [50.006, 5.006]]));
        self::assertSame([['lat' => 50.004, 'lon' => 5.004], ['lat' => 50.006, 'lon' => 5.006]], $this->lastBody['exclude_locations']);

        $snapper = new RouteSnapper($this->recording($reply()), new NullLogger(), 'http://valhalla.test');
        self::assertNotNull($snapper->snap([50.0, 5.0], [50.01, 5.01], BikeType::Road));
        self::assertArrayNotHasKey('exclude_locations', $this->lastBody);
    }

    public function testTheClimbEditorSnapSendsNoCostingOptions(): void
    {
        // The editor's snap stays exactly what it was: no bike, no costing
        // block, so a climb's line does not move with this change.
        $shape = self::encode([[50.0, 5.0], [50.001, 5.001]]);
        $client = $this->recording(new MockResponse(json_encode([
            'trip' => ['legs' => [['shape' => $shape, 'summary' => ['length' => 0.1]]]],
        ], \JSON_THROW_ON_ERROR)));
        $snapper = new RouteSnapper($client, new NullLogger(), 'http://valhalla.test');

        self::assertNotNull($snapper->snap([50.0, 5.0], [50.001, 5.001]));

        self::assertArrayNotHasKey('costing_options', $this->lastBody);
    }
}
