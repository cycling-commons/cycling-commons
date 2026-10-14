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
            ['surface' => 'paved_smooth', 'length' => 2.0],
            ['surface' => 'paved', 'length' => 0.5],
            ['surface' => 'paved_rough', 'length' => 0.1],
            ['surface' => 'compacted', 'length' => 0.3],
            ['surface' => 'gravel', 'length' => 0.2],
            ['surface' => 'dirt', 'length' => 0.05],
            ['surface' => 'path', 'length' => 0.05],
            ['length' => 0.4],
        ]])));

        $mix = $client->along([[50.49, 5.86], [50.47, 5.86]]);

        self::assertSame(['paved' => 2500, 'gravel' => 500, 'rough' => 200, 'unknown' => 400], $mix);
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
}
