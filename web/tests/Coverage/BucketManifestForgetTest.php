<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Coverage\CoverageManifest;
use App\Coverage\RoutesManifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * forget() drops the cached manifest, so a tile build that lands mid-TTL is read on the
 * next page view instead of up to an hour later (country onboarding's mark-live).
 */
final class BucketManifestForgetTest extends TestCase
{
    private const ROUTES = 'https://tiles.example/cc-maps/routes/manifest.json';
    private const COVERAGE = 'https://tiles.example/cc-maps/coverage/manifest.json';

    private static function json(array $payload): MockResponse
    {
        return new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']]);
    }

    public function testAForgottenManifestIsReadAgain(): void
    {
        $http = new MockHttpClient([
            self::json(['tiles' => ['routes' => 'https://tiles.example/r/old/routes.pmtiles']]),
            self::json(['tiles' => ['routes' => 'https://tiles.example/r/new/routes.pmtiles']]),
        ]);
        $cache = new ArrayAdapter();
        $routes = new RoutesManifest($http, $cache, new NullLogger(), self::ROUTES);
        self::assertSame('https://tiles.example/r/old/routes.pmtiles', $routes->countryTiles()['*']['tiles']['routes']);

        self::assertTrue($routes->forget());

        $next = new RoutesManifest($http, $cache, new NullLogger(), self::ROUTES);
        self::assertSame('https://tiles.example/r/new/routes.pmtiles', $next->countryTiles()['*']['tiles']['routes']);
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testForgetLeavesOtherManifestsCached(): void
    {
        $http = new MockHttpClient([
            self::json(['url' => 'https://tiles.example/c/one.pmtiles']),
            self::json(['tiles' => ['routes' => 'https://tiles.example/r/one.pmtiles']]),
        ]);
        $cache = new ArrayAdapter();
        $coverage = new CoverageManifest($http, $cache, new NullLogger(), true, self::COVERAGE);
        $routes = new RoutesManifest($http, $cache, new NullLogger(), self::ROUTES);
        $coverage->countryTiles();
        $routes->countryTiles();

        $routes->forget();

        (new CoverageManifest($http, $cache, new NullLogger(), true, self::COVERAGE))->countryTiles();
        self::assertSame(2, $http->getRequestsCount(), 'the coverage manifest must stay cached');
    }

    public function testABrokenCacheIsReportedNotThrown(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('delete')->willThrowException(new \RuntimeException('redis gone'));
        $routes = new RoutesManifest(new MockHttpClient(), $cache, new NullLogger(), self::ROUTES);

        self::assertFalse($routes->forget());
    }
}
