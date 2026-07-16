<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Coverage\CoverageManifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * coverage-provider.md §4: the versioned tile URL is read
 * server-side from the stable manifest key, cached 3600 s, and EVERY failure
 * path degrades to null — the map must always render, tiles or not.
 */
final class CoverageManifestTest extends TestCase
{
    private const string MANIFEST_URL = 'https://maps.test/coverage/manifest.json';

    private function manifest(HttpClientInterface $http, bool $enabled = true, string $url = self::MANIFEST_URL): CoverageManifest
    {
        return new CoverageManifest($http, new ArrayAdapter(), new NullLogger(), $enabled, $url);
    }

    public function testFlagOffReturnsNullWithoutFetching(): void
    {
        $http = new MockHttpClient();
        self::assertNull($this->manifest($http, enabled: false)->currentTileUrl());
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testEmptyManifestUrlReturnsNull(): void
    {
        $http = new MockHttpClient();
        self::assertNull($this->manifest($http, url: '')->currentTileUrl());
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testResolvesVersionedUrlAndCachesIt(): void
    {
        $http = new MockHttpClient(new JsonMockResponse([
            'version' => 1,
            'url' => 'https://maps.test/coverage/20260716-0400.pmtiles',
            'built_at' => '2026-07-16T04:00:00+00:00',
            'counts' => ['C' => 3184],
            'regions' => ['europe/belgium'],
        ]));
        $manifest = $this->manifest($http);

        self::assertSame('https://maps.test/coverage/20260716-0400.pmtiles', $manifest->currentTileUrl());
        self::assertSame('https://maps.test/coverage/20260716-0400.pmtiles', $manifest->currentTileUrl());
        self::assertSame(1, $http->getRequestsCount());   // second read served from cache
    }

    public function testHttpErrorReturnsNull(): void
    {
        $http = new MockHttpClient(new MockResponse('gone', ['http_code' => 500]));
        self::assertNull($this->manifest($http)->currentTileUrl());
    }

    public function testTransportErrorReturnsNull(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('connection refused');
        });
        self::assertNull($this->manifest($http)->currentTileUrl());
    }

    public function testManifestWithoutUrlKeyReturnsNull(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['version' => 1]));
        self::assertNull($this->manifest($http)->currentTileUrl());
    }

    public function testFailureIsNotCachedSoNextRequestRetries(): void
    {
        $manifest = $this->manifest(new MockHttpClient([
            new MockResponse('boom', ['http_code' => 500]),
            new JsonMockResponse(['version' => 1, 'url' => 'https://maps.test/coverage/20260716-0500.pmtiles']),
        ]));
        self::assertNull($manifest->currentTileUrl());
        self::assertSame('https://maps.test/coverage/20260716-0500.pmtiles', $manifest->currentTileUrl());
    }
}
