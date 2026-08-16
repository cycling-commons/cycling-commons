<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Links;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Where a Safe Browsing verdict lives (catalog-data-model.md §7 `links`).
 *
 * **Not inside the `links` attribute, and this is the whole design.** `links`
 * flows through the wizard's change diff, so a verdict written there would
 * surface on a moderation card as a rider-made edit and manufacture curator
 * work out of a background check. The verdict is a fact about a URL, not about
 * an item.
 *
 * So it is keyed by URL, in its own table, and shared by every item pointing at
 * the same page. That is also what makes the scheduled re-check ONE sweep over
 * the distinct URLs rather than one per item.
 *
 * The primary key is a hash, not the URL: a btree index over an unbounded TEXT
 * column has a size limit somewhere around 2.7 KB, and a URL that exceeded it
 * would fail the INSERT rather than the validation - a rider's save lost to an
 * index detail.
 *
 * @api Written at submit and by the re-check sweep; read by the moderation
 *      queue and by the map payload.
 */
final class LinkVerdictStore
{
    /**
     * How long a verdict is trusted before the sweep asks again. A week: the
     * threat lists move in hours, but so does the free quota, and a link farm
     * that goes bad on day three is caught by the render-side check being
     * fail-closed rather than by polling harder.
     */
    public const int STALE_DAYS = 7;

    /**
     * Loaded once per request: the set of urls the map must not serve.
     *
     * @var array<string, true>|null
     */
    private ?array $unsafe = null;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Records what the lookup said. Upsert by url, because the fact belongs to
     * the url and the newest answer is the only one worth keeping.
     *
     * UNKNOWN is stored like any other verdict rather than skipped. "We asked
     * and got nothing" is a different state from "we never asked", and only the
     * stored one lets the sweep tell them apart.
     *
     * @param array<string, string> $verdicts url => SafeBrowsing::SAFE|UNSAFE|UNKNOWN
     */
    public function record(array $verdicts): void
    {
        if ([] === $verdicts) {
            return;
        }

        $now = $this->clock->now();
        foreach ($verdicts as $url => $verdict) {
            $this->db->executeStatement(
                'INSERT INTO link_verdict (url_hash, url, verdict, checked_at)
                 VALUES (:hash, :url, :verdict, :now)
                 ON CONFLICT (url_hash) DO UPDATE
                   SET verdict = EXCLUDED.verdict, checked_at = EXCLUDED.checked_at, url = EXCLUDED.url',
                ['hash' => self::hash($url), 'url' => $url, 'verdict' => $verdict, 'now' => $now],
                ['now' => 'datetime_immutable'],
            );
        }
        $this->unsafe = null;
    }

    /**
     * The last verdict for each url asked about, defaulting to UNKNOWN.
     *
     * Never "missing": a caller that could not tell an unasked url from a safe
     * one would be a caller that fails open by accident.
     *
     * @param list<string> $urls
     *
     * @return array<string, string>
     */
    public function verdictsFor(array $urls): array
    {
        $urls = array_values(array_unique($urls));
        if ([] === $urls) {
            return [];
        }

        $verdicts = array_fill_keys($urls, SafeBrowsing::UNKNOWN);
        $rows = $this->db->fetchAllAssociative(
            'SELECT url, verdict FROM link_verdict WHERE url_hash IN (?)',
            [array_map(self::hash(...), $urls)],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );
        foreach ($rows as $row) {
            $url = (string) $row['url'];
            if (\array_key_exists($url, $verdicts)) {
                $verdicts[$url] = (string) $row['verdict'];
            }
        }

        return $verdicts;
    }

    /**
     * Strips every url whose last verdict was UNSAFE out of a `links`
     * structure, and drops an entry left with no urls at all.
     *
     * The RENDER-side half of the pair, and it fails CLOSED
     * (App\Catalog\Links\SafeBrowsing): on the public map, "we do not know" and
     * "it is fine" must not be the same answer for a url we have already been
     * told is hostile. UNKNOWN still renders - withholding every unchecked link
     * would empty the map the moment the key expired, which is the failure mode
     * a security control is not allowed to have.
     *
     * @param mixed $links the two-level structure from catalog-data-model.md §7
     */
    public function withhold(mixed $links): mixed
    {
        if (!\is_array($links) || [] === $links) {
            return $links;
        }
        $unsafe = $this->unsafeUrls();
        if ([] === $unsafe) {
            return $links;
        }

        $kept = [];
        foreach ($links as $entry) {
            if (!\is_array($entry) || !\is_array($entry['urls'] ?? null)) {
                $kept[] = $entry;
                continue;
            }
            $urls = [];
            foreach ($entry['urls'] as $url) {
                if (\is_array($url) && \is_string($url['url'] ?? null) && isset($unsafe[$url['url']])) {
                    continue;
                }
                $urls[] = $url;
            }
            if ([] === $urls) {
                continue;   // nothing left to point at
            }
            $entry['urls'] = $urls;
            $kept[] = $entry;
        }

        return $kept;
    }

    /**
     * Every url currently judged unsafe, as a lookup set.
     *
     * The whole set in one query, memoized for the request, because the map
     * payload asks per item and the answer is the same every time. It stays
     * small by construction: it is the bad ones, not all of them.
     *
     * @return array<string, true>
     */
    public function unsafeUrls(): array
    {
        if (null !== $this->unsafe) {
            return $this->unsafe;
        }

        $set = [];
        foreach ($this->db->fetchFirstColumn(
            'SELECT url FROM link_verdict WHERE verdict = ?',
            [SafeBrowsing::UNSAFE],
        ) as $url) {
            $set[(string) $url] = true;
        }

        return $this->unsafe = $set;
    }

    /**
     * Urls whose verdict has aged out, oldest first - the sweep's work list.
     *
     * @return list<string>
     */
    public function stale(int $limit, int $days = self::STALE_DAYS): array
    {
        $cutoff = $this->clock->now()->modify(\sprintf('-%d days', $days));

        return array_map(strval(...), $this->db->fetchFirstColumn(
            'SELECT url FROM link_verdict WHERE checked_at < ? ORDER BY checked_at ASC LIMIT '.max(1, $limit),
            [$cutoff],
            ['datetime_immutable'],
        ));
    }

    /**
     * Urls that live in a served item's `links` and have never been checked at
     * all. The other half of the sweep's work list: the layer can be switched
     * on after links already exist, and a link nobody ever asked about is
     * exactly the one a curator is about to click.
     *
     * @return list<string>
     */
    public function unchecked(int $limit): array
    {
        return array_map(strval(...), $this->db->fetchFirstColumn(
            "SELECT DISTINCT u.url
               FROM item i,
                    LATERAL jsonb_array_elements(i.attributes->'links') AS e(entry),
                    LATERAL jsonb_array_elements(e.entry->'urls') AS l(link),
                    LATERAL (SELECT l.link->>'url' AS url) AS u
              WHERE jsonb_typeof(i.attributes->'links') = 'array'
                AND u.url IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM link_verdict v WHERE v.url_hash = encode(sha256(u.url::bytea), 'hex'))
              LIMIT ".max(1, $limit),
        ));
    }

    public static function hash(string $url): string
    {
        return hash('sha256', $url);
    }
}
