<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Links;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Safe Browsing verdict keyed by URL hash, not stored in `links` (that would surface as a rider edit).
 *
 * Written when a rider sends a submission in, read by the curator review
 * screens to warn the curator about the links it proposes. Nothing a visitor
 * sees reads it.
 *
 * @see docs/specs/catalog-data-model.md §7
 *
 * @api
 */
final class LinkVerdictStore
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Upsert by url. Store UNKNOWN too: that is not the same as never asked.
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
    }

    /**
     * Last verdict per url, default UNKNOWN. Never missing: a missing one would read as checked.
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

    public static function hash(string $url): string
    {
        return hash('sha256', $url);
    }
}
