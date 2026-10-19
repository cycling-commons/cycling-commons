<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Elevation\LegSurfaces;
use App\Elevation\RecordedWays;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The surfaces under a planned leg (docs/specs/route-planner.md section 4.4):
 * the engine walks the leg's edges and says each one's surface and length,
 * and this class folds the engine's eight surface words into the three a
 * rider plans with (paved, gravel, rough) plus unknown, in metres.
 */
final class LegSurfacesTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $lastBody = [];

    /**
     * @param array<int, array{surface: string|null, smoothness: string|null, tracktype: string|null}>|null $recorded what the table holds, or null for a leg outside a loaded country
     */
    private function client(MockResponse $response, string $url = 'http://valhalla.test', ?array $recorded = null, bool $withWays = false): LegSurfaces
    {
        $ways = $withWays ? new class($recorded) implements RecordedWays {
            /** @var list<int> */
            public array $asked = [];

            /** @param array<int, array{surface: string|null, smoothness: string|null, tracktype: string|null}>|null $recorded */
            public function __construct(private readonly ?array $recorded)
            {
            }

            public function forLeg(float $lat, float $lng, array $wayIds): ?array
            {
                $this->asked = $wayIds;

                return $this->recorded;
            }
        } : null;
        $http = new MockHttpClient(function (string $method, string $u, array $options) use ($response): MockResponse {
            $this->lastBody = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return $response;
        });

        return new LegSurfaces($http, new NullLogger(), $url, null, $ways);
    }

    public function testItFoldsTheEnginesSurfacesIntoPavedGravelRoughAndUnknownMetres(): void
    {
        $client = $this->client(new MockResponse((string) json_encode(['units' => 'kilometers', 'edges' => [
            // A primary road without a lane is busy; a secondary one with a separated lane and a tertiary one are not.
            ['surface' => 'paved_smooth', 'length' => 2.0, 'road_class' => 'primary', 'cycle_lane' => 'none'],
            ['surface' => 'paved', 'length' => 0.5, 'road_class' => 'secondary', 'cycle_lane' => 'separated'],
            ['surface' => 'paved_rough', 'length' => 0.1, 'road_class' => 'tertiary', 'cycle_lane' => 'none'],
            ['surface' => 'compacted', 'length' => 0.3],
            ['surface' => 'gravel', 'length' => 0.2],
            ['surface' => 'dirt', 'length' => 0.05],
            ['surface' => 'path', 'length' => 0.05],
            ['length' => 0.4, 'road_class' => 'trunk', 'cycle_lane' => 'shared'],
        ]])));

        $mix = $client->along([[50.49, 5.86], [50.47, 5.86]]);

        self::assertSame(['paved' => 2500, 'gravel' => 500, 'rough' => 200, 'unknown' => 400, 'busy' => 2400, 'cycle' => 500, 'signals' => 0], $mix);
        // The busy stretches: the primary road from 0 to 2000 m, the trunk road at the end, 3200 to 3600 m.
        $read = $client->read([[50.49, 5.86], [50.47, 5.86]]);
        self::assertNotNull($read);
        self::assertSame([[0, 2000], [3200, 3600]], $read['busyRuns']);
        self::assertSame(['edge.surface', 'edge.length', 'edge.road_class', 'edge.cycle_lane', 'edge.names', 'edge.use', 'edge.traffic_signal', 'node.traffic_signal', 'edge.way_id', 'edge.speed_limit'], $this->lastBody['filters']['attributes']);
        // The leg's own line, walked edge by edge, under the bicycle costing.
        self::assertSame('edge_walk', $this->lastBody['shape_match']);
        self::assertSame('bicycle', $this->lastBody['costing']);
        self::assertSame([['lat' => 50.49, 'lon' => 5.86], ['lat' => 50.47, 'lon' => 5.86]], $this->lastBody['shape']);
    }

    /**
     * The metres on a cycle path, away from cars, and the traffic lights passed
     * (route-planner.md section 4.2a, owner 2026-10-16): "Why this way?" said a
     * shortest way through Hoorn "suits your bike less", asphalt against
     * asphalt, where the bike's way kept to cycle paths. A cycleway, or a road
     * with a separated lane, is a cycle path; a painted lane is not. The engine
     * marks a light on the edge that ends at it, now and then on the node.
     */
    public function testItMeasuresTheCyclePathsAndCountsTheTrafficLights(): void
    {
        $client = $this->client(new MockResponse((string) json_encode(['units' => 'kilometers', 'edges' => [
            ['surface' => 'paved', 'length' => 1.2, 'use' => 'cycleway', 'road_class' => 'service_other'],
            ['surface' => 'paved', 'length' => 0.4, 'use' => 'road', 'road_class' => 'secondary', 'cycle_lane' => 'separated', 'traffic_signal' => true],
            ['surface' => 'paved', 'length' => 0.3, 'use' => 'road', 'road_class' => 'tertiary', 'cycle_lane' => 'dedicated'],
            ['surface' => 'paved', 'length' => 0.6, 'use' => 'road', 'road_class' => 'residential', 'end_node' => ['traffic_signal' => true]],
            ['surface' => 'paved', 'length' => 0.1, 'use' => 'road', 'road_class' => 'residential', 'traffic_signal' => false],
        ]])));

        $mix = $client->along([[52.64, 5.05], [52.66, 5.09]]);

        self::assertNotNull($mix);
        self::assertSame(1600, $mix['cycle'], 'the cycleway and the separated lane, not the painted lane');
        self::assertSame(2, $mix['signals']);
    }

    public function testNoEdgesOrNoEngineIsNoAnswer(): void
    {
        self::assertNull($this->client(new MockResponse((string) json_encode(['edges' => []])))->along([[50.49, 5.86], [50.47, 5.86]]));
        self::assertNull($this->client(new MockResponse('', ['error' => 'connection refused']))->along([[50.49, 5.86], [50.47, 5.86]]));
        self::assertNull($this->client(new MockResponse('{}'), '')->along([[50.49, 5.86], [50.47, 5.86]]));
    }

    /** The street names along the leg (route-planner.md section 4.4a): each name with the metre it starts at, a run of one name merged, an unnamed road null. */
    public function testItReadsTheStreetNamesAlongTheLegWhereEachStarts(): void
    {
        $client = $this->client(new MockResponse((string) json_encode(['units' => 'kilometers', 'edges' => [
            ['names' => ['Place Royale', 'N62'], 'length' => 0.02, 'surface' => 'paved'],
            ['names' => ['Place Royale', 'N62'], 'length' => 0.049, 'surface' => 'paved'],
            ['names' => ['Place du Monument'], 'length' => 0.077, 'surface' => 'paved'],
            ['length' => 0.1, 'surface' => 'dirt', 'use' => 'track'],
            ['names' => ['N62'], 'length' => 0.3, 'surface' => 'paved'],
        ]])));

        $read = $client->read([[50.49, 5.86], [50.47, 5.86]]);

        self::assertNotNull($read);
        // An unnamed road says what it is (owner 2026-10-15: the hover was empty on a road OSM names nothing).
        self::assertSame([[0, 'Place Royale', 'road'], [69, 'Place du Monument', 'road'], [146, null, 'track'], [246, 'N62', 'road']], $read['streets']);
        // The surface runs: paved to 146 m, the dirt track rough to 246 m, paved again (owner 2026-10-17: hover lights them up).
        self::assertSame([[0, 'paved'], [146, 'rough'], [246, 'paved']], $read['runs']);
        self::assertSame(546, array_sum(array_slice($read['surfaces'], 0, 4)));
        self::assertContains('edge.names', $this->lastBody['filters']['attributes']);
        self::assertContains('edge.use', $this->lastBody['filters']['attributes']);
    }

    /**
     * Where OpenStreetMap is silent the engine guesses (owner 2026-10-15, Chevrouheid: roads with no surface tag the
     * engine called asphalt and dirt). In a country whose ways are loaded the table says what is recorded: a way it
     * holds with a surface takes that class, a track or an unclassified road it does not hold is not recorded (the
     * map's to-do lines), and any other road keeps the engine's word, as an untagged street is paved all but always
     * (a residential road, a footway). The
     * smoothness and the tracktype come with it, in metres (owner 2026-10-19: "why more detailed surfaces in
     * bikerouter?").
     */
    public function testInALoadedCountryTheRecordedSurfacesStandForTheEnginesGuess(): void
    {
        $client = $this->client(new MockResponse((string) json_encode(['units' => 'kilometers', 'edges' => [
            ['way_id' => 11, 'surface' => 'paved', 'length' => 1.0, 'use' => 'road', 'road_class' => 'tertiary'],
            ['way_id' => 12, 'surface' => 'paved', 'length' => 0.5, 'use' => 'track', 'road_class' => 'service_other'],
            ['way_id' => 13, 'surface' => 'paved', 'length' => 0.3, 'use' => 'road', 'road_class' => 'unclassified'],
            ['way_id' => 16, 'surface' => 'paved', 'length' => 0.25, 'use' => 'road', 'road_class' => 'primary'],
            ['way_id' => 14, 'surface' => 'dirt', 'length' => 0.2, 'use' => 'footway', 'road_class' => 'service_other'],
            ['way_id' => 15, 'surface' => 'gravel', 'length' => 0.4, 'use' => 'track', 'road_class' => 'service_other'],
        ]])), 'http://valhalla.test', [
            11 => ['surface' => 'paved', 'smoothness' => 'good', 'tracktype' => null],
            12 => ['surface' => 'pave', 'smoothness' => 'bad', 'tracktype' => null],
            15 => ['surface' => null, 'smoothness' => null, 'tracktype' => 'grade3'],
        ], true);

        $read = $client->read([[50.49, 5.86], [50.47, 5.86]]);

        self::assertNotNull($read);
        self::assertTrue($read['recorded']);
        // 11 paved; 12 the table's cobbles, rough; 13 not recorded; 16 the engine's paved; 14 the engine's dirt, rough;
        // 15 a track with no surface tag, not recorded.
        self::assertSame(['paved' => 1250, 'gravel' => 0, 'rough' => 700, 'unknown' => 700, 'busy' => 250, 'cycle' => 0, 'signals' => 0], $read['surfaces']);
        self::assertSame([[0, 'paved'], [1000, 'rough'], [1500, 'unknown'], [1800, 'paved'], [2050, 'rough'], [2250, 'unknown']], $read['runs']);
        self::assertSame(['smoothness' => ['good' => 1000, 'bad' => 500], 'tracktype' => ['grade3' => 400]], $read['quality']);
        // Where each one lies, so the panel lights it on the map (owner 2026-10-19: "what part of the road is that about?").
        self::assertSame(['smoothness' => [[0, 1000, 'good'], [1000, 1500, 'bad']], 'tracktype' => [[2250, 2650, 'grade3']], 'speeds' => [[0, 2650, 'unset']]], $read['detailRuns']);
    }

    public function testOutsideALoadedCountryTheEngineSpeaksAndNothingIsRecorded(): void
    {
        $client = $this->client(new MockResponse((string) json_encode(['units' => 'kilometers', 'edges' => [
            ['way_id' => 11, 'surface' => 'paved', 'length' => 1.0, 'use' => 'track', 'road_class' => 'service_other'],
        ]])), 'http://valhalla.test', null, true);

        $read = $client->read([[50.49, 5.86], [50.47, 5.86]]);

        self::assertNotNull($read);
        self::assertFalse($read['recorded']);
        self::assertSame(1000, $read['surfaces']['paved']);
        self::assertSame(['smoothness' => [], 'tracktype' => []], $read['quality']);
    }

    /**
     * The speed limits along the leg, from OpenStreetMap's maxspeed as the engine carries it (owner 2026-10-19: "can
     * we also get max speeds for roads"): metres per limit in km/h, and the metres with none set.
     */
    public function testItMeasuresTheMetresPerSpeedLimit(): void
    {
        $client = $this->client(new MockResponse((string) json_encode(['units' => 'kilometers', 'edges' => [
            ['surface' => 'paved', 'length' => 1.0, 'speed_limit' => 50],
            ['surface' => 'paved', 'length' => 0.5, 'speed_limit' => 30],
            ['surface' => 'paved', 'length' => 0.25, 'speed_limit' => 50],
            ['surface' => 'paved', 'length' => 0.2],
            ['surface' => 'paved', 'length' => 0.05, 'speed_limit' => 0],
        ]])));

        $read = $client->read([[50.49, 5.86], [50.47, 5.86]]);

        self::assertNotNull($read);
        self::assertSame(['30' => 500, '50' => 1250, 'unset' => 250], $read['speeds']);
        // Two stretches of 50 km/h, one of 30 between them; a run of one limit is one stretch.
        self::assertSame([[0, 1000, '50'], [1000, 1500, '30'], [1500, 1750, '50'], [1750, 2000, 'unset']], $read['detailRuns']['speeds']);
    }

    /**
     * A recommended route's own line, read in pieces of at most 50 km (route-domain.md §6.4, owner 2026-10-19: "A
     * recommended route should also have the road 'quality' info"): the engine's trace has a length limit, and a
     * route runs to 267 km. Each piece is matched to the roads (map_snap: the line is not the engine's own), and the
     * answers are joined, every stretch placed after the pieces before it.
     */
    public function testALongLineIsReadInPiecesAndJoined(): void
    {
        $bodies = [];
        $answers = [
            ['edges' => [['way_id' => 1, 'surface' => 'paved', 'length' => 40.0, 'speed_limit' => 50], ['way_id' => 2, 'surface' => 'gravel', 'length' => 10.0, 'road_class' => 'primary']]],
            ['edges' => [['way_id' => 2, 'surface' => 'gravel', 'length' => 5.0, 'road_class' => 'primary'], ['way_id' => 3, 'surface' => 'paved', 'length' => 15.0, 'speed_limit' => 50]]],
        ];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies, &$answers): MockResponse {
            $bodies[] = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse((string) json_encode(['units' => 'kilometers'] + array_shift($answers)));
        });
        // About 70 km due north, a point every 0.9 km.
        $line = array_map(static fn (int $i): array => [50.0 + $i * 0.0081, 5.8], range(0, 77));

        $read = (new LegSurfaces($http, new NullLogger(), 'http://valhalla.test'))->readLine($line);

        self::assertCount(2, $bodies, 'two pieces of at most 50 km');
        self::assertSame('map_snap', $bodies[0]['shape_match']);
        self::assertSame(end($bodies[0]['shape']), $bodies[1]['shape'][0], 'the pieces share their joining point');
        self::assertNotNull($read);
        self::assertSame(['paved' => 55000, 'gravel' => 15000, 'rough' => 0, 'unknown' => 0, 'busy' => 15000, 'cycle' => 0, 'signals' => 0], $read['surfaces']);
        self::assertSame(70000, $read['distanceM']);
        self::assertSame([[0, 'paved'], [40000, 'gravel'], [55000, 'paved']], $read['runs'], 'one gravel run across the join');
        self::assertSame([[40000, 55000]], $read['busyRuns']);
        self::assertSame([[0, 40000, '50'], [40000, 55000, 'unset'], [55000, 70000, '50']], $read['detailRuns']['speeds']);
        self::assertSame(['50' => 55000, 'unset' => 15000], $read['speeds']);
    }

    public function testALineWithAPieceTheEngineCannotReadIsNoAnswer(): void
    {
        $answers = [new MockResponse((string) json_encode(['edges' => [['surface' => 'paved', 'length' => 40.0]]])), new MockResponse('', ['error' => 'down'])];
        $line = array_map(static fn (int $i): array => [50.0 + $i * 0.0081, 5.8], range(0, 77));

        self::assertNull((new LegSurfaces(new MockHttpClient($answers), new NullLogger(), 'http://valhalla.test'))->readLine($line));
    }
}
