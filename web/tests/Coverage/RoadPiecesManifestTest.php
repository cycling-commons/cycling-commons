<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Coverage\RoadPiecesManifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Road-piece tiles for traffic matching (docs/specs/traffic-measurements.md §2):
 * the routes manifest's contract, one `roadpieces` arm, per-country only.
 */
final class RoadPiecesManifestTest extends TestCase
{
    private const MANIFEST = 'https://tiles.example/cc-maps/roadpieces/manifest.json';

    private function manifest(MockHttpClient $http, string $pin = ''): RoadPiecesManifest
    {
        return new RoadPiecesManifest($http, new ArrayAdapter(), new NullLogger(), self::MANIFEST, $pin);
    }

    public function testEveryCountryGetsItsOwnArchive(): void
    {
        $http = new MockHttpClient([new MockResponse(json_encode(['version' => 2, 'countries' => [
            'nl' => ['stamp' => '20261005-0300', 'bounds' => [3.3, 50.7, 7.2, 53.6],
                'tiles' => ['roadpieces' => 'https://t/roadpieces/nl/20261005-0300/roadpieces.pmtiles']],
            'be' => ['stamp' => '20261005-0301', 'bounds' => [2.5, 49.4, 6.4, 51.5],
                'tiles' => ['roadpieces' => 'https://t/roadpieces/be/20261005-0301/roadpieces.pmtiles']],
        ]]), ['response_headers' => ['content-type' => 'application/json']])]);

        $tiles = $this->manifest($http)->countryTiles();

        self::assertSame(['be', 'nl'], array_keys($tiles));
        self::assertSame('https://t/roadpieces/nl/20261005-0300/roadpieces.pmtiles', $tiles['nl']['tiles']['roadpieces']);
        self::assertSame([3.3, 50.7, 7.2, 53.6], $tiles['nl']['bounds']);
    }

    public function testAPinServesOneWorldEntryWithoutFetching(): void
    {
        $http = new MockHttpClient(static fn () => throw new \LogicException('a pin must not fetch'));

        $tiles = $this->manifest($http, 'https://t/pinned/roadpieces.pmtiles')->countryTiles();

        self::assertCount(1, $tiles);
        self::assertSame('https://t/pinned/roadpieces.pmtiles', array_values($tiles)[0]['tiles']['roadpieces']);
    }

    public function testABrokenOrMissingManifestMeansNoTilesNeverAnError(): void
    {
        self::assertSame([], $this->manifest(new MockHttpClient([new MockResponse('', ['http_code' => 404])]))->countryTiles());
        self::assertSame([], $this->manifest(new MockHttpClient([new MockResponse('{"version":2,"countries":{}}')]))->countryTiles());
        self::assertSame([], new RoadPiecesManifest(new MockHttpClient(), new ArrayAdapter(), new NullLogger(), '')->countryTiles());
    }
}
