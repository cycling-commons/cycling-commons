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
     * `$ttls` is how many seconds each verdict may be used without asking
     * again ({@see SafeBrowsing::lookup()}); a url without one gets its
     * verdict's longest: {@see SafeBrowsing::SAFE_TTL}, the terms' 30 minutes
     * for unsafe, none for unknown.
     *
     * @param array<string, string> $verdicts url => SafeBrowsing::SAFE|UNSAFE|UNKNOWN
     * @param array<string, int>    $ttls     url => seconds
     */
    public function record(array $verdicts, array $ttls = []): void
    {
        if ([] === $verdicts) {
            return;
        }

        $now = $this->clock->now();
        foreach ($verdicts as $url => $verdict) {
            $ttl = $ttls[$url] ?? match ($verdict) {
                SafeBrowsing::SAFE => SafeBrowsing::SAFE_TTL,
                SafeBrowsing::UNSAFE => SafeBrowsing::UNSAFE_TTL_MAX,
                default => 0,
            };
            $this->db->executeStatement(
                'INSERT INTO link_verdict (url_hash, url, verdict, checked_at, fresh_until)
                 VALUES (:hash, :url, :verdict, :now, :until)
                 ON CONFLICT (url_hash) DO UPDATE
                   SET verdict = EXCLUDED.verdict, checked_at = EXCLUDED.checked_at, url = EXCLUDED.url, fresh_until = EXCLUDED.fresh_until',
                ['hash' => self::hash($url), 'url' => $url, 'verdict' => $verdict, 'now' => $now, 'until' => $now->modify(sprintf('+%d seconds', max(0, $ttl)))],
                ['now' => 'datetime_immutable', 'until' => 'datetime_immutable'],
            );
        }
    }

    /**
     * The verdicts that may still be used without asking Google again; a url
     * whose verdict expired, or was never asked, is left out.
     *
     * @param list<string> $urls
     *
     * @return array<string, string> url => verdict
     */
    public function freshVerdicts(array $urls): array
    {
        $urls = array_values(array_unique($urls));
        if ([] === $urls) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT url, verdict FROM link_verdict WHERE url_hash IN (?) AND fresh_until > ?',
            [array_map(self::hash(...), $urls), $this->clock->now()],
            [\Doctrine\DBAL\ArrayParameterType::STRING, 'datetime_immutable'],
        );
        $out = [];
        foreach ($rows as $row) {
            $url = (string) $row['url'];
            if (\in_array($url, $urls, true)) {
                $out[$url] = (string) $row['verdict'];
            }
        }

        return $out;
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
