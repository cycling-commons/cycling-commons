<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Links;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Safe Browsing for rider-submitted links, asked when a submission is sent in so the curator who reviews it is warned. Fails open, flags and never rejects. Off without `SAFE_BROWSING_KEY`.
 *
 * @see docs/specs/catalog-data-model.md §7
 *
 * @api
 */
final class SafeBrowsing
{
    /** Everything Google's `threatMatches` can answer with that we act on. */
    public const string SAFE = 'safe';
    public const string UNSAFE = 'unsafe';
    /**
     * How long a clean answer is used without asking again: the Lookup API
     * gives no duration for one, and sites get compromised; 5 to 15
     * minutes is usual.
     */
    public const int SAFE_TTL = 600;

    /** The Safe Browsing terms: a URL may not be treated as unsafe on information older than 30 minutes. */
    public const int UNSAFE_TTL_MAX = 1800;

    /** Asked and not answered - a timeout, an outage, or no key at all. */
    public const string UNKNOWN = 'unknown';

    private const string ENDPOINT = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';

    /** Hosts we produce in bulk. Suffix-matched so a look-alike host does not match. */
    private const array ALLOWLIST = ['wikipedia.org', 'wikimedia.org', 'wikidata.org', 'openstreetmap.org'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey = '',
    ) {
    }

    /** Whether the layer can answer at all. False means every verdict is UNKNOWN. */
    public function isEnabled(): bool
    {
        return '' !== trim($this->apiKey);
    }

    /**
     * Verdicts keyed by url. Missing from the API response is SAFE; a url we cannot judge is UNKNOWN, never absent.
     *
     * @param list<string> $urls
     *
     * @return array<string, string> url => SAFE|UNSAFE|UNKNOWN
     */
    public function check(array $urls): array
    {
        return array_map(static fn (array $answer): string => $answer['verdict'], $this->lookup($urls));
    }

    /**
     * Verdicts with how many seconds each may be used without asking again
     * (the Safe Browsing terms): a match its `cacheDuration`, at most
     * {@see self::UNSAFE_TTL_MAX}; a clean answer {@see self::SAFE_TTL}; an
     * unknown one 0, so it is asked again next time.
     *
     * @param list<string> $urls
     *
     * @return array<string, array{verdict: string, ttl: int}>
     */
    public function lookup(array $urls): array
    {
        $urls = array_values(array_unique(array_filter($urls, static fn (string $u): bool => '' !== trim($u))));
        if ([] === $urls) {
            return [];
        }

        $answers = [];
        $ask = [];
        foreach ($urls as $url) {
            if (self::isAllowlisted($url)) {
                $answers[$url] = ['verdict' => self::SAFE, 'ttl' => self::SAFE_TTL];
                continue;
            }
            $answers[$url] = ['verdict' => self::UNKNOWN, 'ttl' => 0];
            $ask[] = $url;
        }

        if (!$this->isEnabled() || [] === $ask) {
            return $answers;
        }

        try {
            $response = $this->http->request('POST', self::ENDPOINT.'?key='.urlencode($this->apiKey), [
                'json' => [
                    'client' => ['clientId' => 'cycling-commons', 'clientVersion' => '1.0'],
                    'threatInfo' => [
                        'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE',
                            'POTENTIALLY_HARMFUL_APPLICATION'],
                        'platformTypes' => ['ANY_PLATFORM'],
                        'threatEntryTypes' => ['URL'],
                        'threatEntries' => array_map(
                            static fn (string $u): array => ['url' => $u],
                            $ask,
                        ),
                    ],
                ],
                'timeout' => 5,
            ]);
            /** @var array{matches?: list<array{threat?: array{url?: string}, cacheDuration?: string}>} $data */
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            /* Failures stay UNKNOWN. Fail-open vs fail-closed is the caller's decision. */
            $this->logger->warning('Safe Browsing lookup failed', ['error' => $e->getMessage()]);

            return $answers;
        }

        // Unmatched lookups are safe; only matches are named.
        foreach ($ask as $url) {
            $answers[$url] = ['verdict' => self::SAFE, 'ttl' => self::SAFE_TTL];
        }
        foreach ($data['matches'] ?? [] as $match) {
            $url = $match['threat']['url'] ?? null;
            if (\is_string($url) && \array_key_exists($url, $answers)) {
                $answers[$url] = ['verdict' => self::UNSAFE, 'ttl' => self::matchTtl($match['cacheDuration'] ?? null)];
            }
        }

        return $answers;
    }

    /**
     * Seconds a match may be used: its `cacheDuration` ("300.000s"), never
     * more than {@see self::UNSAFE_TTL_MAX}; without one, the shortest the
     * API gives, 300.
     */
    public static function matchTtl(mixed $cacheDuration): int
    {
        if (!\is_string($cacheDuration) || 1 !== preg_match('~^(\d+)(?:\.\d+)?s$~', $cacheDuration, $m)) {
            return min(300, self::UNSAFE_TTL_MAX);
        }

        return max(0, min((int) $m[1], self::UNSAFE_TTL_MAX));
    }

    /**
     * UNSAFE > UNKNOWN > SAFE.
     *
     * @param array<string, string> $verdicts
     */
    public static function worst(array $verdicts): string
    {
        if (\in_array(self::UNSAFE, $verdicts, true)) {
            return self::UNSAFE;
        }
        if (\in_array(self::UNKNOWN, $verdicts, true)) {
            return self::UNKNOWN;
        }

        return self::SAFE;
    }

    /**
     * Every url inside a `links` attribute.
     *
     * @param mixed $links docs/specs/catalog-data-model.md §7
     *
     * @return list<string>
     */
    public static function urlsIn(mixed $links): array
    {
        if (!\is_array($links)) {
            return [];
        }
        $out = [];
        foreach ($links as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            foreach ($entry['urls'] ?? [] as $url) {
                if (\is_array($url) && \is_string($url['url'] ?? null)) {
                    $out[] = $url['url'];
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Suffix match on the registrable host, never `str_contains`.
     */
    private static function isAllowlisted(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host)) {
            return false;
        }
        $host = strtolower($host);
        foreach (self::ALLOWLIST as $safe) {
            if ($host === $safe || str_ends_with($host, '.'.$safe)) {
                return true;
            }
        }

        return false;
    }
}
