<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Coverage\SurfaceManifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The surface manifest is what makes a rebuild go live without a config change.
 * Before it, publishing meant an env edit plus a cache clear plus a deploy, and
 * the failure mode was silent: a pinned URL left pointing at a pruned artifact
 * is a map with no surfaces and nothing in any log.
 */
final class SurfaceManifestTest extends TestCase
{
    private const MANIFEST = 'https://tiles.example/cc-maps/surface/manifest.json';

    private function manifest(MockHttpClient $http, string ...$pins): SurfaceManifest
    {
        return new SurfaceManifest($http, new ArrayAdapter(), new NullLogger(), self::MANIFEST,
            $pins[0] ?? '', $pins[1] ?? '', $pins[2] ?? '');
    }

    private static function body(array $tiles): MockResponse
    {
        return new MockResponse(json_encode(['version' => 1, 'stamp' => '20260812-2015', 'tiles' => $tiles]),
            ['response_headers' => ['content-type' => 'application/json']]);
    }

    private static function v2(array $countries, ?array $gaps = null): MockResponse
    {
        $doc = ['version' => 2, 'countries' => $countries];
        if (null !== $gaps) {
            $doc['gaps'] = $gaps;
        }

        return new MockResponse(json_encode($doc), ['response_headers' => ['content-type' => 'application/json']]);
    }

    public function testAV2ManifestServesOneEntryPerCountry(): void
    {
        $http = new MockHttpClient([self::v2([
            'be' => ['stamp' => '20260924-0312', 'bounds' => [2.5, 49.4, 6.4, 51.5],
                'tiles' => ['classified' => 'https://t/surface/be/20260924-0312/classified.pmtiles',
                    'todo' => 'https://t/surface/be/20260924-0312/todo.pmtiles']],
            'nl' => ['stamp' => '20260923-0301', 'bounds' => [3.3, 50.7, 7.2, 53.6],
                'tiles' => ['classified' => 'https://t/surface/nl/20260923-0301/classified.pmtiles']],
            'xx' => ['stamp' => 's', 'bounds' => [1, 2], 'tiles' => ['classified' => 'u']],
        ], ['url' => 'https://t/surface/gaps/20260924-0312/gaps.pmtiles', 'stamp' => '20260924-0312'])]);
        $m = $this->manifest($http);

        $tiles = $m->countryTiles();
        self::assertSame(['be', 'nl'], array_keys($tiles));   // xx has no valid bounds: left out
        self::assertSame('https://t/surface/nl/20260923-0301/classified.pmtiles', $tiles['nl']['tiles']['classified']);
        self::assertArrayNotHasKey('todo', $tiles['nl']['tiles']);
        self::assertSame('https://t/surface/gaps/20260924-0312/gaps.pmtiles', $m->gaps()['url']);
    }

    public function testAV1ManifestServesOneWorldEntry(): void
    {
        $http = new MockHttpClient([self::body([
            'classified' => 'https://tiles.example/s/20260812-2015/classified.pmtiles',
            'gaps' => 'https://tiles.example/s/20260812-2015/gaps.pmtiles',
        ])]);
        $tiles = $this->manifest($http)->countryTiles();

        self::assertSame(['*'], array_keys($tiles));
        self::assertSame([-180.0, -85.0511, 180.0, 85.0511], $tiles['*']['bounds']);
        self::assertSame('20260812-2015', $tiles['*']['stamp']);
    }

    public function testPinsServeOnlyThePinnedWorldEntry(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('no fetch'));
        $tiles = $this->manifest($http, 'https://pinned/c.pmtiles', '', 'https://pinned/g.pmtiles')->countryTiles();

        self::assertSame(['*' => ['tiles' => ['classified' => 'https://pinned/c.pmtiles'],
            'bounds' => [-180.0, -85.0511, 180.0, 85.0511], 'stamp' => '']], $tiles);
    }

    public function testItServesTheThreeArmsOfThePublishedBuild(): void
    {
        $http = new MockHttpClient([self::body([
            'classified' => 'https://tiles.example/s/20260812-2015/classified.pmtiles',
            'todo' => 'https://tiles.example/s/20260812-2015/todo.pmtiles',
            'gaps' => 'https://tiles.example/s/20260812-2015/gaps.pmtiles',
        ])]);
        $m = $this->manifest($http);

        $tiles = $m->countryTiles();
        self::assertSame(['*'], array_keys($tiles));
        self::assertSame([
            'classified' => 'https://tiles.example/s/20260812-2015/classified.pmtiles',
            'todo' => 'https://tiles.example/s/20260812-2015/todo.pmtiles',
        ], $tiles['*']['tiles']);
        self::assertSame('20260812-2015', $tiles['*']['stamp']);
        self::assertSame(['url' => 'https://tiles.example/s/20260812-2015/gaps.pmtiles', 'stamp' => '20260812-2015'], $m->gaps());
        // One fetch for three arms: they are one build, and three requests for
        // one document would be three chances to serve a mixed pair.
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testAPinnedUrlWinsAndNeverTouchesTheBucket(): void
    {
        // The hatch for bisecting a rendering problem, or for serving an
        // artifact that was never published. It must work on an installation
        // that has no manifest at all, so it cannot depend on fetching one.
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('the manifest was fetched although every arm was pinned');
        });
        $m = $this->manifest($http, 'https://pinned/c.pmtiles', 'https://pinned/t.pmtiles', 'https://pinned/g.pmtiles');

        self::assertSame(['classified' => 'https://pinned/c.pmtiles', 'todo' => 'https://pinned/t.pmtiles'],
            $m->countryTiles()['*']['tiles']);
        self::assertSame(['url' => 'https://pinned/g.pmtiles', 'stamp' => ''], $m->gaps());
    }

    public function testAnUnreachableManifestDegradesToNoLayerRatherThanAnError(): void
    {
        // A missing surface layer is a smaller harm than a 500 on /map.
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 503])]);
        $m = $this->manifest($http);

        self::assertSame([], $m->countryTiles());
        self::assertNull($m->gaps());
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
        $m = new SurfaceManifest($http, new ArrayAdapter(), new NullLogger(), '');
        self::assertSame([], $m->countryTiles());
    }

    public function testAnArmMissingFromAnOtherwiseValidManifestIsNull(): void
    {
        // A build that published two arms must not make the third resolve to
        // something wrong — the client simply does not offer that layer.
        $http = new MockHttpClient([self::body([
            'classified' => 'https://tiles.example/s/classified.pmtiles',
            'todo' => 'https://tiles.example/s/todo.pmtiles',
        ])]);
        $m = $this->manifest($http);

        self::assertSame(['classified' => 'https://tiles.example/s/classified.pmtiles',
            'todo' => 'https://tiles.example/s/todo.pmtiles'], $m->countryTiles()['*']['tiles']);
        self::assertNull($m->gaps());
    }
}
