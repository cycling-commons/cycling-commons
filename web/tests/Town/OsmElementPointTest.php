<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Town;

use App\Town\OsmElementApi;
use App\Town\TownSourceUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Where OpenStreetMap puts a town (OsmElementApi::point()), the point that
 * files its texts in a region (docs/specs/map-and-search.md §6.5).
 */
final class OsmElementPointTest extends TestCase
{
    public function testANodeIsItsOwnPoint(): void
    {
        $api = $this->api([
            'node/59518.json' => ['elements' => [['type' => 'node', 'id' => 59518, 'lat' => 51.2194, 'lon' => 4.4025]]],
        ]);

        self::assertSame(['lat' => 51.2194, 'lng' => 4.4025], $api->point('node', 59518));
    }

    public function testAWayIsTheMeanOfItsNodes(): void
    {
        $api = $this->api([
            'way/7/full.json' => ['elements' => [
                ['type' => 'node', 'id' => 1, 'lat' => 50.0, 'lon' => 4.0],
                ['type' => 'node', 'id' => 2, 'lat' => 52.0, 'lon' => 6.0],
                ['type' => 'way', 'id' => 7, 'nodes' => [1, 2]],
            ]],
        ]);

        self::assertSame(['lat' => 51.0, 'lng' => 5.0], $api->point('way', 7));
    }

    /** A town boundary names its centre; one node read instead of the whole outline. */
    public function testARelationIsItsAdminCentre(): void
    {
        $api = $this->api([
            'relation/71525.json' => ['elements' => [['type' => 'relation', 'id' => 71525, 'members' => [
                ['type' => 'way', 'ref' => 10, 'role' => 'outer'],
                ['type' => 'node', 'ref' => 20, 'role' => 'label'],
                ['type' => 'node', 'ref' => 30, 'role' => 'admin_centre'],
            ]]]],
            'node/30.json' => ['elements' => [['type' => 'node', 'id' => 30, 'lat' => 48.8566, 'lon' => 2.3522]]],
        ]);

        self::assertSame(['lat' => 48.8566, 'lng' => 2.3522], $api->point('relation', 71525));
    }

    public function testARelationWithNoCentreIsTheMeanOfItsNodes(): void
    {
        $api = $this->api([
            'relation/5.json' => ['elements' => [['type' => 'relation', 'id' => 5, 'members' => [['type' => 'way', 'ref' => 10, 'role' => 'outer']]]]],
            'relation/5/full.json' => ['elements' => [
                ['type' => 'node', 'id' => 1, 'lat' => 10.0, 'lon' => 20.0],
                ['type' => 'node', 'id' => 2, 'lat' => 12.0, 'lon' => 22.0],
            ]],
        ]);

        self::assertSame(['lat' => 11.0, 'lng' => 21.0], $api->point('relation', 5));
    }

    public function testAnElementOpenStreetMapNoLongerHasHasNoPoint(): void
    {
        self::assertNull($this->api([])->point('node', 404));
    }

    public function testAnOutageIsNotAnAnswer(): void
    {
        $api = new OsmElementApi(new MockHttpClient(new MockResponse('', ['http_code' => 503])), 'CyclingCommons-test/1.0');

        $this->expectException(TownSourceUnavailable::class);
        $api->point('node', 1);
    }

    /**
     * An API that answers each listed path and 404s every other.
     *
     * @param array<string, array<string, mixed>> $paths
     */
    private function api(array $paths): OsmElementApi
    {
        $client = new MockHttpClient(static function (string $method, string $url) use ($paths): MockResponse {
            $path = substr($url, \strlen('https://api.openstreetmap.org/api/0.6/'));

            return isset($paths[$path])
                ? new MockResponse(json_encode($paths[$path], \JSON_THROW_ON_ERROR), ['http_code' => 200])
                : new MockResponse('', ['http_code' => 404]);
        });

        return new OsmElementApi($client, 'CyclingCommons-test/1.0');
    }
}
