<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Links;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Safe Browsing, the cheap reputation layer for rider-submitted links
 * (catalog-data-model.md §7 `links`).
 *
 * A moderator clicking every submitted URL is one hostile link away from a bad
 * day, and the SEO-spam incentive for submitting links at all is exactly what
 * attracts the URLs worth checking. This is the free Lookup API - the same list
 * Chrome and Firefox use - asked at SUBMIT and again at RENDER.
 *
 * **Both, and the pair is the point.** A URL that was clean when it was
 * submitted is precisely how a link farm gets past a one-time check; a check
 * only at render lets a hostile URL sit in the moderation queue where a curator
 * clicks it first.
 *
 * **The two calls fail in OPPOSITE directions, and this is the one thing to get
 * right.** They are different questions:
 *
 *  - **Submit fails OPEN.** A rider must not lose a contribution because a
 *    Google endpoint is down. An unreachable lookup stores the link and records
 *    the verdict as UNKNOWN for the curator to see.
 *  - **Render fails CLOSED.** On the public map that same unknown must never
 *    become a clean bill of health, so a link whose last verdict was UNSAFE
 *    stays withheld until a later check clears it.
 *
 * **Flag, never silently reject** (the owner's rule). A flagged submission
 * still reaches the queue carrying its verdict, because a false positive that
 * vanishes is indistinguishable from a bug.
 *
 * **Off by default.** With no `SAFE_BROWSING_KEY` the layer is disabled and
 * says so, rather than quietly passing everything: a security control that
 * cannot be told apart from an absent one is worse than admitting it is absent.
 *
 * @api Consumed by the links write path and by the moderation queue.
 */
final class SafeBrowsing
{
    /** Everything Google's `threatMatches` can answer with that we act on. */
    public const string SAFE = 'safe';
    public const string UNSAFE = 'unsafe';
    /** Asked and not answered - a timeout, an outage, or no key at all. */
    public const string UNKNOWN = 'unknown';

    private const string ENDPOINT = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';

    /**
     * Hosts whose reputation is not in question and whose links we produce in
     * bulk ourselves. Re-checking them spends a quota on a known answer.
     * Suffix-matched on the registrable host, so a look-alike domain
     * (`wikipedia.org.example.com`) does NOT match.
     */
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
     * Verdicts for a batch of urls, keyed by url.
     *
     * Batched because the Lookup API takes up to 500 entries per call and a
     * submission carries at most a couple of dozen: one request per save rather
     * than one per link, which is also what keeps the free quota viable.
     *
     * A url this cannot judge comes back UNKNOWN rather than missing, so a
     * caller can never mistake "not in the result" for "safe".
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
            /* Every failure is the same answer: everything asked stays UNKNOWN.
               One catch rather than three, because naming the transport and
               JSON exceptions separately only invites a third kind to escape as
               a 500 on a rider's save.

               Which DIRECTION unknown fails in is the CALLER's decision, never
               this class's: submit treats it as allowed and render treats it as
               not-yet-cleared, and deciding here would collapse two different
               questions into one. */
            $this->logger->warning('Safe Browsing lookup failed', ['error' => $e->getMessage()]);

            return $verdicts;
        }

        // Everything we asked about and Google did NOT match is safe; only the
        // matches are named in the response.
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
     * The worst verdict in a set, which is what a submission card shows.
     *
     * UNSAFE beats UNKNOWN beats SAFE: one bad link in five makes the whole
     * submission worth a second look, and a card that averaged them would hide
     * the only one that mattered.
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
     * Every url inside a `links` attribute, flattened.
     *
     * @param mixed $links the two-level structure from catalog-data-model.md §7
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
     *
     * `str_contains($url, 'wikipedia.org')` would allowlist
     * `https://wikipedia.org.example.com/` and `https://evil.com/?x=wikipedia.org`,
     * which is the whole attack this list would otherwise open.
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
