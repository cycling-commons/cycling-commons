<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Coverage\CoverageManifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * coverage-provider.md §4: the versioned tile URL is read
 * server-side from the stable manifest key, cached 3600 s, and EVERY failure
 * path degrades to null — the map must always render, tiles or not. Failures
 * are negative-cached briefly (hardening, spec-neutral) so a degraded bucket
 * does not cost a fetch timeout on every /map render under load.
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

    public function testFailureIsNegativeCachedWithinTheHoldoffWindow(): void
    {
        $http = new MockHttpClient([
            new MockResponse('boom', ['http_code' => 500]),
            new JsonMockResponse(['version' => 1, 'url' => 'https://maps.test/coverage/20260716-0500.pmtiles']),
        ]);
        $manifest = $this->manifest($http);

        self::assertNull($manifest->currentTileUrl());
        // Second render inside the negative-TTL window: null again, but served
        // from cache — a degraded bucket must not cost a fetch per request.
        self::assertNull($manifest->currentTileUrl());
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testFailureIsRetriedOnceTheNegativeTtlExpires(): void
    {
        $clock = new MockClock();
        $http = new MockHttpClient([
            new MockResponse('boom', ['http_code' => 500]),
            new JsonMockResponse(['version' => 1, 'url' => 'https://maps.test/coverage/20260716-0500.pmtiles']),
        ]);
        $manifest = new CoverageManifest($http, new ArrayAdapter(clock: $clock), new NullLogger(), true, self::MANIFEST_URL);

        self::assertNull($manifest->currentTileUrl());
        $clock->modify('+31 seconds');   // past NEGATIVE_TTL (30 s)
        self::assertSame('https://maps.test/coverage/20260716-0500.pmtiles', $manifest->currentTileUrl());
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testCacheKeyFollowsTheManifestUrl(): void
    {
        // Operator repoints COVERAGE_MANIFEST_URL against a persistent pool:
        // the old entry must not be served for up to a full TTL.
        $cache = new ArrayAdapter();
        $first = new CoverageManifest(
            new MockHttpClient(new JsonMockResponse(['version' => 1, 'url' => 'https://maps.test/coverage/old.pmtiles'])),
            $cache, new NullLogger(), true, 'https://maps.test/old/manifest.json',
        );
        self::assertSame('https://maps.test/coverage/old.pmtiles', $first->currentTileUrl());

        $second = new CoverageManifest(
            new MockHttpClient(new JsonMockResponse(['version' => 1, 'url' => 'https://maps.test/coverage/new.pmtiles'])),
            $cache, new NullLogger(), true, 'https://maps.test/new/manifest.json',
        );
        self::assertSame('https://maps.test/coverage/new.pmtiles', $second->currentTileUrl());
    }

    public function testCountryCodesReturnsManifestArrayWhenPresent(): void
    {
        $http = new MockHttpClient(new JsonMockResponse([
            'version' => 1,
            'url' => 'https://maps.test/coverage/20260716-0400.pmtiles',
            'country_codes' => ['BE', 'NL'],
        ]));
        $manifest = $this->manifest($http);

        self::assertSame(['BE', 'NL'], $manifest->countryCodes());
        // Same cached decoded manifest serves url() + countryCodes(): one fetch.
        self::assertSame('https://maps.test/coverage/20260716-0400.pmtiles', $manifest->currentTileUrl());
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testCountryCodesIsEmptyWhenKeyAbsent(): void
    {
        // A pre-split manifest (url only, no country_codes): the client falls
        // back to a single unsplit layer per letter, so [] not null.
        $http = new MockHttpClient(new JsonMockResponse([
            'version' => 1,
            'url' => 'https://maps.test/coverage/20260716-0400.pmtiles',
        ]));
        self::assertSame([], $this->manifest($http)->countryCodes());
    }

    public function testCountryCodesIsEmptyOnNonArrayValue(): void
    {
        // A malformed country_codes (scalar, not a list) degrades to [], same
        // as absent — never a TypeError.
        $http = new MockHttpClient(new JsonMockResponse([
            'version' => 1,
            'url' => 'https://maps.test/coverage/20260716-0400.pmtiles',
            'country_codes' => 'BE',
        ]));
        self::assertSame([], $this->manifest($http)->countryCodes());
    }

    public function testCountryCodesDropsNonStringMembers(): void
    {
        // Defensive is_string filter: a malformed member (number, null, nested
        // array) is dropped, and the list is re-indexed so it stays a list<string>.
        $http = new MockHttpClient(new JsonMockResponse([
            'version' => 1,
            'url' => 'https://maps.test/coverage/20260716-0400.pmtiles',
            'country_codes' => ['BE', 42, null, 'NL', ['x']],
        ]));
        self::assertSame(['BE', 'NL'], $this->manifest($http)->countryCodes());
    }

    public function testCountryCodesIsEmptyWhenTilesOffWithoutFetching(): void
    {
        // Same degradation contract as currentTileUrl(): flag off → [] and no fetch.
        $http = new MockHttpClient();
        self::assertSame([], $this->manifest($http, enabled: false)->countryCodes());
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testFetchBoundsIdleAndTotalTime(): void
    {
        $captured = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): JsonMockResponse {
            $captured = $options;

            return new JsonMockResponse(['version' => 1, 'url' => 'https://maps.test/coverage/20260716-0400.pmtiles']);
        });

        self::assertNotNull($this->manifest($http)->currentTileUrl());
        self::assertIsArray($captured);
        self::assertEquals(5, $captured['timeout'] ?? null, 'idle timeout must bound the fetch');
        self::assertEquals(5, $captured['max_duration'] ?? null, 'max_duration must bound total request time (slow-drip host)');
    }
}
