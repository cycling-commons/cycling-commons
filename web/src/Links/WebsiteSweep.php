<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Links;

use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * The sweep (docs/specs/website-liveness.md section 2): the hosts behind the
 * coverage points' website tags and the served items' `web` attribute that
 * are due, hosts never checked first, then the longest overdue, each fetched
 * once and its verdict kept. Runs on the worker, daily within a limit that
 * cycles the whole set in about a month, and after each coverage harvest.
 *
 * @api
 */
final class WebsiteSweep
{
    public function __construct(
        private readonly Connection $db,
        private readonly WebsiteChecker $checker,
        private readonly WebsiteVerdictStore $store,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The hosts due, with the first address seen for each.
     *
     * @return list<array{host: string, url: string}>
     */
    public function due(int $limit): array
    {
        $now = $this->clock->now();
        /** @var list<array{host: string, url: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "WITH urls AS (
                SELECT COALESCE(tags->>'website', tags->>'contact:website', tags->>'url') AS url
                  FROM coverage_poi
                 WHERE jsonb_exists_any(tags, ARRAY['website', 'contact:website', 'url'])
                UNION ALL
                SELECT i.attributes->>'web' FROM item i
                 WHERE jsonb_exists(i.attributes, 'web') AND i.state IN ".ItemState::servedSqlTuple().'
             ), hosts AS (
                SELECT DISTINCT ON (host) host, url FROM (
                    SELECT '.WebsiteVerdictStore::hostSql('url')." AS host, url
                      FROM urls WHERE url ~* '^https?://'
                ) h WHERE host IS NOT NULL AND host <> '' ORDER BY host, url
             )
             SELECT h.host, h.url
               FROM hosts h
               LEFT JOIN website_check w ON w.host = h.host
              WHERE w.host IS NULL OR w.next_check_at <= :now
              ORDER BY (w.host IS NULL) DESC, w.next_check_at ASC NULLS FIRST, h.host
              LIMIT :limit",
            ['now' => $now, 'limit' => max(1, $limit)],
            ['now' => 'datetime_immutable', 'limit' => ParameterType::INTEGER],
        );

        return $rows;
    }

    /**
     * Check what is due and keep every verdict.
     *
     * @return array<string, int> verdict => how many, plus 'hosts'
     */
    public function run(int $limit): array
    {
        $due = $this->due($limit);
        $counts = ['hosts' => \count($due), WebsiteVerdict::LIVE => 0, WebsiteVerdict::PARKED => 0, WebsiteVerdict::DEAD => 0, WebsiteVerdict::UNKNOWN => 0];
        foreach (array_chunk($due, WebsiteChecker::CONCURRENCY) as $batch) {
            $urls = array_map(static fn (array $r): string => $r['url'], $batch);
            $verdicts = $this->checker->checkMany($urls);
            $now = $this->clock->now();
            foreach ($batch as $row) {
                $verdict = $verdicts[$row['url']] ?? WebsiteVerdict::failed('no answer');
                $this->store->record($row['host'], $row['url'], $verdict, $now);
                ++$counts[$verdict->verdict];
            }
        }

        return $counts;
    }
}
