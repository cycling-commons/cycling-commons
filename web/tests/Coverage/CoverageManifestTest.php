<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
 * coverage-provider.md §4: the per-country tile entries are read
 * server-side from the stable manifest key, cached 3600 s, and EVERY failure
 * path degrades to [] — the map must always render, tiles or not. Failures
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

    public function testAV2ManifestServesOneEntryPerCountry(): void
    {
        $http = new MockHttpClient(new JsonMockResponse([
            'version' => 2,
            'countries' => [
                'be' => ['stamp' => '20260924-0312', 'bounds' => [2.5, 49.4, 6.4, 51.5],
                    'tiles' => ['points' => 'https://t/coverage/be/20260924-0312/points.pmtiles']],
                'zz' => ['stamp' => '20260924-0312', 'bounds' => [-180.0, -85.0511, 180.0, 85.0511],
                    'tiles' => ['points' => 'https://t/coverage/zz/20260924-0312/points.pmtiles']],
            ],
        ]));
        $m = $this->manifest($http);

        $tiles = $m->countryTiles();
        self::assertSame(['be', 'zz'], array_keys($tiles));
        self::assertSame('https://t/coverage/be/20260924-0312/points.pmtiles', $tiles['be']['tiles']['points']);
        // 'zz' is the unstamped bucket, not a country: it stays out of countryCodes().
        self::assertSame(['BE'], $m->countryCodes());
    }

    public function testAV1ManifestServesOneWorldEntry(): void
    {
        $http = new MockHttpClient(new JsonMockResponse([
            'version' => 1,
            'url' => 'https://maps.test/coverage/20260716-0400.pmtiles',
            'country_codes' => ['BE', 'NL'],
        ]));
        $m = $this->manifest($http);

        self::assertSame(['*' => ['tiles' => ['points' => 'https://maps.test/coverage/20260716-0400.pmtiles'],
            'bounds' => [-180.0, -85.0511, 180.0, 85.0511], 'stamp' => '20260716-0400']], $m->countryTiles());
        self::assertSame(['BE', 'NL'], $m->countryCodes());
    }

    public function testFlagOffReturnsNoTilesWithoutFetching(): void
    {
        $http = new MockHttpClient();
        self::assertSame([], $this->manifest($http, enabled: false)->countryTiles());
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testEmptyManifestUrlReturnsNoTiles(): void
    {
        $http = new MockHttpClient();
        self::assertSame([], $this->manifest($http, url: '')->countryTiles());
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testResolvesVersionedUrlAndCachesIt(): void
    {
        $http = new MockHttpClient(new JsonMockResponse([
            'version' => 1,
            'url' => 'https://maps.test/coverage/20260716-0400.pmtiles',
            'built_at' => '2026-07-16T04:00:00+00:00',
            'counts' => ['B' => 3184],
            'regions' => ['europe/belgium'],
        ]));
        $manifest = $this->manifest($http);

        self::assertSame('https://maps.test/coverage/20260716-0400.pmtiles', $manifest->countryTiles()['*']['tiles']['points']);
        self::assertSame('https://maps.test/coverage/20260716-0400.pmtiles', $manifest->countryTiles()['*']['tiles']['points']);
        self::assertSame(1, $http->getRequestsCount());   // second read served from cache
    }

    public function testHttpErrorReturnsNoTiles(): void
    {
        $http = new MockHttpClient(new MockResponse('gone', ['http_code' => 500]));
        self::assertSame([], $this->manifest($http)->countryTiles());
    }

    public function testTransportErrorReturnsNoTiles(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('connection refused');
        });
        self::assertSame([], $this->manifest($http)->countryTiles());
    }

    public function testManifestWithoutUrlKeyReturnsNoTiles(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['version' => 1]));
        self::assertSame([], $this->manifest($http)->countryTiles());
    }

    public function testFailureIsNegativeCachedWithinTheHoldoffWindow(): void
    {
        $http = new MockHttpClient([
            new MockResponse('boom', ['http_code' => 500]),
            new JsonMockResponse(['version' => 1, 'url' => 'https://maps.test/coverage/20260716-0500.pmtiles']),
        ]);
        $manifest = $this->manifest($http);

        self::assertSame([], $manifest->countryTiles());
        // Second render inside the negative-TTL window: [] again, but served
        // from cache — a degraded bucket must not cost a fetch per request.
        self::assertSame([], $manifest->countryTiles());
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

        self::assertSame([], $manifest->countryTiles());
        $clock->modify('+31 seconds');   // past NEGATIVE_TTL (30 s)
        self::assertSame('https://maps.test/coverage/20260716-0500.pmtiles', $manifest->countryTiles()['*']['tiles']['points']);
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
        self::assertSame('https://maps.test/coverage/old.pmtiles', $first->countryTiles()['*']['tiles']['points']);

        $second = new CoverageManifest(
            new MockHttpClient(new JsonMockResponse(['version' => 1, 'url' => 'https://maps.test/coverage/new.pmtiles'])),
            $cache, new NullLogger(), true, 'https://maps.test/new/manifest.json',
        );
        self::assertSame('https://maps.test/coverage/new.pmtiles', $second->countryTiles()['*']['tiles']['points']);
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
        // Same cached decoded manifest serves countryTiles() + countryCodes(): one fetch.
        self::assertSame('https://maps.test/coverage/20260716-0400.pmtiles', $manifest->countryTiles()['*']['tiles']['points']);
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
        // Same degradation contract as countryTiles(): flag off → [] and no fetch.
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

        self::assertNotSame([], $this->manifest($http)->countryTiles());
        self::assertIsArray($captured);
        self::assertEquals(5, $captured['timeout'] ?? null, 'idle timeout must bound the fetch');
        self::assertEquals(5, $captured['max_duration'] ?? null, 'max_duration must bound total request time (slow-drip host)');
    }
}
