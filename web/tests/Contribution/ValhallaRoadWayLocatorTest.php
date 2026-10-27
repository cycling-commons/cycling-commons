<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Contribution\ValhallaRoadWayLocator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The ways under a drawn road (edit-items/A-road-surface.md, "A drawn road links itself"): the engine matches the
 * line to the roads, and the ways are summed and ordered by how far the line follows them.
 */
final class ValhallaRoadWayLocatorTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $body = [];

    private function locator(MockResponse $response, string $url = 'http://valhalla.test'): ValhallaRoadWayLocator
    {
        $http = new MockHttpClient(function (string $method, string $u, array $options) use ($response): MockResponse {
            $this->body = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return $response;
        });

        return new ValhallaRoadWayLocator($http, new NullLogger(), $url);
    }

    public function testTheWaysTheLineFollowsTheLongestFirstAndNotTheJunctionsItCrosses(): void
    {
        $locator = $this->locator(new MockResponse((string) json_encode(['edges' => [
            ['way_id' => 7, 'length' => 0.004],          // 4 m of the crossing road at the start
            ['way_id' => 716714249, 'length' => 0.15],
            ['way_id' => 716714250, 'length' => 0.3],
            ['way_id' => 716714250, 'length' => 0.2],
            ['way_id' => 8, 'length' => 0.012],          // 12 m at the end
        ]])));

        self::assertSame(['way/716714250', 'way/716714249'], $locator->waysAlong([[5.16, 52.65], [5.15, 52.651], [5.14, 52.652]]));
        self::assertSame('map_snap', $this->body['shape_match']);
        self::assertSame(['lat' => 52.65, 'lon' => 5.16], $this->body['shape'][0], '[lng, lat] in, the engine\'s lat and lon out');
    }

    public function testNoEngineOrNoLineOrNoAnswerIsNoWay(): void
    {
        self::assertSame([], $this->locator(new MockResponse('{}'), '')->waysAlong([[5.16, 52.65], [5.14, 52.652]]));
        self::assertSame([], $this->locator(new MockResponse('{}'))->waysAlong([[5.16, 52.65]]));
        self::assertSame([], $this->locator(new MockResponse('{"error":"no route"}', ['http_code' => 400]))->waysAlong([[5.16, 52.65], [5.14, 52.652]]));
        self::assertSame([], $this->locator(new MockResponse('', ['error' => 'timeout']))->waysAlong([[5.16, 52.65], [5.14, 52.652]]));
    }
}
