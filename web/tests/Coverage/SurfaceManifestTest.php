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

    public function testItServesTheThreeArmsOfThePublishedBuild(): void
    {
        $http = new MockHttpClient([self::body([
            'classified' => 'https://tiles.example/s/20260812-2015/classified.pmtiles',
            'todo' => 'https://tiles.example/s/20260812-2015/todo.pmtiles',
            'gaps' => 'https://tiles.example/s/20260812-2015/gaps.pmtiles',
        ])]);
        $m = $this->manifest($http);

        self::assertSame('https://tiles.example/s/20260812-2015/classified.pmtiles', $m->classifiedUrl());
        self::assertSame('https://tiles.example/s/20260812-2015/todo.pmtiles', $m->todoUrl());
        self::assertSame('https://tiles.example/s/20260812-2015/gaps.pmtiles', $m->gapsUrl());
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

        self::assertSame('https://pinned/c.pmtiles', $m->classifiedUrl());
        self::assertSame('https://pinned/t.pmtiles', $m->todoUrl());
        self::assertSame('https://pinned/g.pmtiles', $m->gapsUrl());
    }

    public function testAPinnedArmComposesWithAPublishedOne(): void
    {
        $http = new MockHttpClient([self::body([
            'classified' => 'https://tiles.example/published/classified.pmtiles',
            'todo' => 'https://tiles.example/published/todo.pmtiles',
            'gaps' => 'https://tiles.example/published/gaps.pmtiles',
        ])]);
        $m = $this->manifest($http, '', 'https://pinned/todo.pmtiles');

        self::assertSame('https://tiles.example/published/classified.pmtiles', $m->classifiedUrl());
        self::assertSame('https://pinned/todo.pmtiles', $m->todoUrl());
    }

    public function testAnUnreachableManifestDegradesToNoLayerRatherThanAnError(): void
    {
        // A missing surface layer is a smaller harm than a 500 on /map.
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 503])]);
        $m = $this->manifest($http);

        self::assertNull($m->classifiedUrl());
        self::assertNull($m->todoUrl());
        self::assertNull($m->gapsUrl());
    }

    public function testAManifestNamingNoTilesIsTreatedAsUnavailable(): void
    {
        $http = new MockHttpClient([self::body([])]);
        self::assertNull($this->manifest($http)->classifiedUrl());
    }

    public function testAnUnsetManifestUrlIsSilentlyNoLayer(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('an empty manifest URL must not be fetched');
        });
        $m = new SurfaceManifest($http, new ArrayAdapter(), new NullLogger(), '');
        self::assertNull($m->classifiedUrl());
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

        self::assertSame('https://tiles.example/s/classified.pmtiles', $m->classifiedUrl());
        self::assertNull($m->gapsUrl());
    }
}
