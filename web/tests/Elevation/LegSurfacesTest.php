<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Elevation\LegSurfaces;
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

    private function client(MockResponse $response, string $url = 'http://valhalla.test'): LegSurfaces
    {
        $http = new MockHttpClient(function (string $method, string $u, array $options) use ($response): MockResponse {
            $this->lastBody = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return $response;
        });

        return new LegSurfaces($http, new NullLogger(), $url);
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

        self::assertSame(['paved' => 2500, 'gravel' => 500, 'rough' => 200, 'unknown' => 400, 'busy' => 2400], $mix);
        self::assertSame(['edge.surface', 'edge.length', 'edge.road_class', 'edge.cycle_lane', 'edge.names', 'edge.use'], $this->lastBody['filters']['attributes']);
        // The leg's own line, walked edge by edge, under the bicycle costing.
        self::assertSame('edge_walk', $this->lastBody['shape_match']);
        self::assertSame('bicycle', $this->lastBody['costing']);
        self::assertSame([['lat' => 50.49, 'lon' => 5.86], ['lat' => 50.47, 'lon' => 5.86]], $this->lastBody['shape']);
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
        self::assertSame(546, array_sum(array_slice($read['surfaces'], 0, 4)));
        self::assertContains('edge.names', $this->lastBody['filters']['attributes']);
        self::assertContains('edge.use', $this->lastBody['filters']['attributes']);
    }
}
