<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Links;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Safe Browsing verdict keyed by URL hash, not stored in `links` (that would surface as a rider edit).
 *
 * @see docs/specs/catalog-data-model.md §7
 *
 * @api
 */
final class LinkVerdictStore
{
    /** Verdicts older than this are re-asked. Render-side withhold is still fail-closed. */
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
     * Upsert by url. Store UNKNOWN too — that is not the same as never asked.
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
     * Last verdict per url, default UNKNOWN. Never missing — missing would fail open.
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
     * Drop UNSAFE urls from `links`. UNKNOWN still renders (fail-closed on UNSAFE only).
     *
     * @param mixed $links docs/specs/catalog-data-model.md §7
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
                continue;
            }
            $entry['urls'] = $urls;
            $kept[] = $entry;
        }

        return $kept;
    }

    /**
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
     * Aged-out urls, oldest first.
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
     * Served `links` urls with no verdict row.
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
