<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Coverage\CoverageManifest;
use App\Coverage\RoutesManifest;
use App\Coverage\SurfaceManifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * prefetch() lets /map and /v1/map-config start every bucket fetch at once
 * instead of waiting for each in turn (BucketManifest, "Why prefetch exists").
 *
 * The saving is wall-clock and cannot be asserted against a mock transport, so
 * what is pinned here is the contract that makes it safe: a prefetch issues
 * exactly one request, the read that follows reuses it rather than fetching
 * again, and nothing is fetched that would not have been fetched anyway.
 */
final class BucketManifestPrefetchTest extends TestCase
{
    private const COVERAGE = 'https://tiles.example/cc-maps/coverage/manifest.json';
    private const ROUTES = 'https://tiles.example/cc-maps/routes/manifest.json';
    private const SURFACE = 'https://tiles.example/cc-maps/surface/manifest.json';

    /** @var list<string> */
    private array $fetched = [];

    private function http(MockResponse ...$bodies): MockHttpClient
    {
        $queue = $bodies;

        return new MockHttpClient(function (string $method, string $url) use (&$queue): MockResponse {
            $this->fetched[] = $url;
            $next = array_shift($queue);
            self::assertNotNull($next, 'more bucket fetches than the test allowed: '.$url);

            return $next;
        });
    }

    private static function json(array $payload): MockResponse
    {
        return new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']]);
    }

    public function testAPrefetchedManifestIsReadWithoutASecondFetch(): void
    {
        $http = $this->http(self::json([
            'tiles' => ['routes' => 'https://tiles.example/r/20260830-1200/routes.pmtiles'],
        ]));
        $routes = new RoutesManifest($http, new ArrayAdapter(), new NullLogger(), self::ROUTES);

        $routes->prefetch();
        self::assertSame([self::ROUTES], $this->fetched, 'prefetch must start the request');

        self::assertSame('https://tiles.example/r/20260830-1200/routes.pmtiles', $routes->tilesUrl());
        self::assertSame([self::ROUTES], $this->fetched, 'the read must reuse the prefetched request');
    }

    public function testEveryManifestOfAPageCanBeStartedBeforeAnyIsRead(): void
    {
        // What /map does: three prefetches, then three reads. All three
        // requests must be in flight before the first read blocks on one.
        $http = $this->http(
            self::json(['url' => 'https://tiles.example/c/20260830.pmtiles']),
            self::json(['tiles' => ['classified' => 'https://tiles.example/s/classified.pmtiles']]),
            self::json(['tiles' => ['routes' => 'https://tiles.example/r/routes.pmtiles']]),
        );
        $coverage = new CoverageManifest($http, new ArrayAdapter(), new NullLogger(), true, self::COVERAGE);
        $surface = new SurfaceManifest($http, new ArrayAdapter(), new NullLogger(), self::SURFACE);
        $routes = new RoutesManifest($http, new ArrayAdapter(), new NullLogger(), self::ROUTES);

        $coverage->prefetch();
        $surface->prefetch();
        $routes->prefetch();

        self::assertSame([self::COVERAGE, self::SURFACE, self::ROUTES], $this->fetched);

        self::assertSame('https://tiles.example/c/20260830.pmtiles', $coverage->currentTileUrl());
        self::assertSame('https://tiles.example/s/classified.pmtiles', $surface->classifiedUrl());
        self::assertSame('https://tiles.example/r/routes.pmtiles', $routes->tilesUrl());

        self::assertCount(3, $this->fetched, 'no manifest may be fetched twice');
    }

    public function testAWarmCacheIsNotPrefetched(): void
    {
        $http = $this->http(self::json([
            'tiles' => ['routes' => 'https://tiles.example/r/routes.pmtiles'],
        ]));
        $cache = new ArrayAdapter();
        $routes = new RoutesManifest($http, $cache, new NullLogger(), self::ROUTES);

        // First page view fills the cache.
        self::assertSame('https://tiles.example/r/routes.pmtiles', $routes->tilesUrl());
        self::assertCount(1, $this->fetched);

        // Second page view must cost nothing, prefetch included. A fresh
        // instance, because a long-lived one would still hold the value.
        $warm = new RoutesManifest($http, $cache, new NullLogger(), self::ROUTES);
        $warm->prefetch();
        $warm->prefetch();

        self::assertCount(1, $this->fetched, 'a cached manifest must not be re-fetched');
        self::assertSame('https://tiles.example/r/routes.pmtiles', $warm->tilesUrl());
        self::assertCount(1, $this->fetched);
    }

    public function testAPinnedOrDisabledManifestIsNeverPrefetched(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('a pinned or disabled manifest must not touch the bucket');
        });

        // The bisect hatch: every arm pinned, so the bucket is moot.
        $surface = new SurfaceManifest($http, new ArrayAdapter(), new NullLogger(), self::SURFACE,
            'https://pinned/classified.pmtiles', 'https://pinned/todo.pmtiles', 'https://pinned/gaps.pmtiles');
        $surface->prefetch();

        $routes = new RoutesManifest($http, new ArrayAdapter(), new NullLogger(), self::ROUTES,
            'https://pinned/routes.pmtiles');
        $routes->prefetch();

        // Coverage switched off by flag, and coverage with no manifest URL.
        (new CoverageManifest($http, new ArrayAdapter(), new NullLogger(), false, self::COVERAGE))->prefetch();
        (new CoverageManifest($http, new ArrayAdapter(), new NullLogger(), true, ''))->prefetch();

        self::assertSame([], $this->fetched);
    }

    public function testAFailedPrefetchStillDegradesToNoLayer(): void
    {
        // The prefetched request is the one that fails. It must not be left
        // behind for the next caller to trip over.
        $http = $this->http(new MockResponse('', ['http_code' => 503]));
        $routes = new RoutesManifest($http, new ArrayAdapter(), new NullLogger(), self::ROUTES);

        $routes->prefetch();
        self::assertNull($routes->tilesUrl());
        // Negative-cached, so the second read neither throws nor re-fetches.
        self::assertNull($routes->tilesUrl());
        self::assertCount(1, $this->fetched);
    }
}
