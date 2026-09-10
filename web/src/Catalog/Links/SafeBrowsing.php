<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Links;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Safe Browsing for rider-submitted links. Submit fails open; render fails closed. Flag, never silently reject. Off without `SAFE_BROWSING_KEY`.
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
        $urls = array_values(array_unique(array_filter($urls, static fn (string $u): bool => '' !== trim($u))));
        if ([] === $urls) {
            return [];
        }

        $verdicts = [];
        $ask = [];
        foreach ($urls as $url) {
            if (self::isAllowlisted($url)) {
                $verdicts[$url] = self::SAFE;
                continue;
            }
            $verdicts[$url] = self::UNKNOWN;
            $ask[] = $url;
        }

        if (!$this->isEnabled() || [] === $ask) {
            return $verdicts;
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
            /** @var array{matches?: list<array{threat?: array{url?: string}}>} $data */
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            /* Failures stay UNKNOWN. Fail-open vs fail-closed is the caller's decision. */
            $this->logger->warning('Safe Browsing lookup failed', ['error' => $e->getMessage()]);

            return $verdicts;
        }

        // Unmatched lookups are safe; only matches are named.
        foreach ($ask as $url) {
            $verdicts[$url] = self::SAFE;
        }
        foreach ($data['matches'] ?? [] as $match) {
            $url = $match['threat']['url'] ?? null;
            if (\is_string($url) && \array_key_exists($url, $verdicts)) {
                $verdicts[$url] = self::UNSAFE;
            }
        }

        return $verdicts;
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
