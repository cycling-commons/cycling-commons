<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Coverage\RoutesManifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The one-arm sibling of SurfaceManifestTest: same serving contract (manifest
 * follows the build, pin wins, every failure degrades to "no layer" rather
 * than a 500), one artifact instead of three.
 */
final class RoutesManifestTest extends TestCase
{
    private const MANIFEST = 'https://tiles.example/cc-maps/routes/manifest.json';

    private function manifest(MockHttpClient $http, string $pin = ''): RoutesManifest
    {
        return new RoutesManifest($http, new ArrayAdapter(), new NullLogger(), self::MANIFEST, $pin);
    }

    private static function body(array $tiles): MockResponse
    {
        return new MockResponse(json_encode(['version' => 1, 'stamp' => '20260813-0130', 'tiles' => $tiles]),
            ['response_headers' => ['content-type' => 'application/json']]);
    }

    private static function v2(array $countries): MockResponse
    {
        return new MockResponse(json_encode(['version' => 2, 'countries' => $countries]),
            ['response_headers' => ['content-type' => 'application/json']]);
    }

    public function testAV2ManifestServesOneEntryPerCountry(): void
    {
        $http = new MockHttpClient([self::v2([
            'be' => ['stamp' => '20260924-0312', 'bounds' => [2.5, 49.4, 6.4, 51.5],
                'tiles' => ['routes' => 'https://t/routes/be/20260924-0312/routes.pmtiles']],
            'nl' => ['stamp' => '20260923-0301', 'bounds' => [3.3, 50.7, 7.2, 53.6],
                'tiles' => ['routes' => 'https://t/routes/nl/20260923-0301/routes.pmtiles']],
        ])]);
        $tiles = $this->manifest($http)->countryTiles();

        self::assertSame(['be', 'nl'], array_keys($tiles));
        self::assertSame('https://t/routes/be/20260924-0312/routes.pmtiles', $tiles['be']['tiles']['routes']);
    }

    public function testAV1ManifestServesOneWorldEntry(): void
    {
        $http = new MockHttpClient([self::body([
            'routes' => 'https://tiles.example/r/20260813-0130/routes.pmtiles',
        ])]);
        $tiles = $this->manifest($http)->countryTiles();

        self::assertSame(['*' => ['tiles' => ['routes' => 'https://tiles.example/r/20260813-0130/routes.pmtiles'],
            'bounds' => [-180.0, -85.0511, 180.0, 85.0511], 'stamp' => '20260813-0130']], $tiles);
    }

    public function testItServesThePublishedBuild(): void
    {
        $http = new MockHttpClient([self::body([
            'routes' => 'https://tiles.example/r/20260813-0130/routes.pmtiles',
        ])]);

        self::assertSame(
            'https://tiles.example/r/20260813-0130/routes.pmtiles',
            $this->manifest($http)->countryTiles()['*']['tiles']['routes'],
        );
    }

    public function testAPinnedUrlWinsAndNeverTouchesTheBucket(): void
    {
        // The bisect hatch — it must work on an installation that has no
        // manifest at all, so it cannot depend on fetching one.
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('the manifest was fetched although the URL was pinned');
        });

        self::assertSame(['*' => ['tiles' => ['routes' => 'https://pinned/routes.pmtiles'],
            'bounds' => [-180.0, -85.0511, 180.0, 85.0511], 'stamp' => '']],
            $this->manifest($http, 'https://pinned/routes.pmtiles')->countryTiles());
    }

    public function testAnUnreachableManifestDegradesToNoLayerRatherThanAnError(): void
    {
        // A missing route layer is a smaller harm than a 500 on /map.
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 503])]);
        self::assertSame([], $this->manifest($http)->countryTiles());
    }

    public function testAManifestNamingNoTilesIsTreatedAsUnavailable(): void
    {
        $http = new MockHttpClient([self::body([])]);
        self::assertSame([], $this->manifest($http)->countryTiles());
    }

    public function testAnUnsetManifestUrlIsSilentlyNoLayer(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('an empty manifest URL must not be fetched');
        });
        $m = new RoutesManifest($http, new ArrayAdapter(), new NullLogger(), '');
        self::assertSame([], $m->countryTiles());
    }
}
