<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Links\SafeBrowsing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The reputation lookup for rider-submitted links.
 *
 * Two things here are security properties rather than features, and both fail
 * silently if they regress: an unreachable Google must never turn into "safe",
 * and the allowlist must never match a look-alike domain.
 */
final class SafeBrowsingTest extends TestCase
{
    private function client(MockHttpClient $http, string $key = 'test-key'): SafeBrowsing
    {
        return new SafeBrowsing($http, new NullLogger(), $key);
    }

    public function testAMatchedUrlIsUnsafeAndTheRestAreSafe(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'matches' => [['threat' => ['url' => 'https://bad.example/']]],
        ], \JSON_THROW_ON_ERROR)));

        $verdicts = $this->client($http)->check(['https://bad.example/', 'https://good.example/']);

        self::assertSame(SafeBrowsing::UNSAFE, $verdicts['https://bad.example/']);
        // Not "absent": a caller must never have to read a missing key as safe.
        self::assertSame(SafeBrowsing::SAFE, $verdicts['https://good.example/']);
    }

    /**
     * An outage must not read as a clean bill of health. This is the whole
     * reason the class has three states rather than a boolean.
     */
    public function testAnUnreachableLookupIsUnknownAndNeverSafe(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new \RuntimeException('connection refused');
        });

        $verdicts = $this->client($http)->check(['https://unknown.example/']);
        self::assertSame(SafeBrowsing::UNKNOWN, $verdicts['https://unknown.example/']);
    }

    /** Malformed JSON is an outage by another name. */
    public function testAGarbageResponseIsAlsoUnknown(): void
    {
        $http = new MockHttpClient(new MockResponse('<html>502</html>'));
        $verdicts = $this->client($http)->check(['https://unknown.example/']);
        self::assertSame(SafeBrowsing::UNKNOWN, $verdicts['https://unknown.example/']);
    }

    /** No key: off, and honest about it rather than quietly passing everything. */
    public function testWithoutAKeyTheLayerIsOffAndEverythingIsUnknown(): void
    {
        $off = $this->client(new MockHttpClient([]), '');
        self::assertFalse($off->isEnabled());
        self::assertSame(SafeBrowsing::UNKNOWN, $off->check(['https://anything.example/'])['https://anything.example/']);
    }

    /**
     * The allowlist is a SUFFIX match on the host. A `str_contains` version of
     * this would wave through `wikipedia.org.example.com` and
     * `evil.example/?ref=wikipedia.org`, which is the attack the list would
     * otherwise open.
     */
    public function testTheAllowlistCannotBeSpoofedByALookAlikeDomain(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'matches' => [
                ['threat' => ['url' => 'https://wikipedia.org.example.com/x']],
                ['threat' => ['url' => 'https://evil.example/?ref=wikipedia.org']],
            ],
        ], \JSON_THROW_ON_ERROR)));

        $verdicts = $this->client($http)->check([
            'https://en.wikipedia.org/wiki/Muiderslot',
            'https://wikipedia.org.example.com/x',
            'https://evil.example/?ref=wikipedia.org',
        ]);

        self::assertSame(SafeBrowsing::SAFE, $verdicts['https://en.wikipedia.org/wiki/Muiderslot'],
            'a real subdomain is allowlisted and never asked about');
        self::assertSame(SafeBrowsing::UNSAFE, $verdicts['https://wikipedia.org.example.com/x']);
        self::assertSame(SafeBrowsing::UNSAFE, $verdicts['https://evil.example/?ref=wikipedia.org']);
    }

    /** One bad link in five makes the whole submission worth a second look. */
    public function testTheWorstVerdictWins(): void
    {
        self::assertSame(SafeBrowsing::UNSAFE, SafeBrowsing::worst([
            'a' => SafeBrowsing::SAFE, 'b' => SafeBrowsing::UNKNOWN, 'c' => SafeBrowsing::UNSAFE,
        ]));
        self::assertSame(SafeBrowsing::UNKNOWN, SafeBrowsing::worst([
            'a' => SafeBrowsing::SAFE, 'b' => SafeBrowsing::UNKNOWN,
        ]));
        self::assertSame(SafeBrowsing::SAFE, SafeBrowsing::worst(['a' => SafeBrowsing::SAFE]));
        self::assertSame(SafeBrowsing::SAFE, SafeBrowsing::worst([]));
    }

    /** Every url inside the two-level `links` shape, and nothing else. */
    public function testUrlsAreFlattenedOutOfTheTwoLevelShape(): void
    {
        self::assertSame(
            ['https://a.example/', 'https://b.example/'],
            SafeBrowsing::urlsIn([
                ['label' => 'Wikipedia', 'urls' => [
                    ['url' => 'https://a.example/', 'locale' => 'en'],
                    ['url' => 'https://b.example/', 'locale' => 'nl'],
                    ['locale' => 'de'],
                ]],
                ['urls' => [['url' => 'https://a.example/']]],
                'not an entry',
            ]),
        );
        self::assertSame([], SafeBrowsing::urlsIn(null));
        self::assertSame([], SafeBrowsing::urlsIn('a string'));
    }
}
