<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Vote\Hemisphere;
use App\Vote\Round;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Serve-time best-of membership for the map's Curated mode: `verified` routes
 * with a season vote. Only votes in the latest started round of each picked
 * season count, in either hemisphere, so last year's favourites leave the map
 * when the season turns (route-domain.md §8c, fresh start). Empty facet lists
 * mean "narrowed by nothing".
 *
 * @see docs/specs/route-domain.md §8.1, §8.2, §8d
 *
 * @api
 */
final class RouteRankingService
{
    /**
     * Hard top-N. Bounds the Everywhere facet; region-scoped lists are never truncated.
     *
     * @see docs/specs/route-domain.md §12
     */
    public const int MAX_RESULTS = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Ranked verified-route ids, best first. `$regionIds === []` is Everywhere (subject to MAX_RESULTS).
     *
     * @see docs/specs/route-domain.md §8.2, §8.3
     *
     * @param list<Season>   $seasons
     * @param list<BikeType> $bikes
     * @param list<int>      $regionIds
     *
     * @return list<int>
     */
    public function bestOf(array $seasons, array $bikes, array $regionIds = []): array
    {
        // The round starts are computed from each round's first day, never by
        // taking a year off the clock: on 29 February that lands on 1 March
        // and drops last spring.
        $now = $this->clock->now();
        $starts = [];
        foreach ([] !== $seasons ? $seasons : Season::cases() as $season) {
            foreach (Hemisphere::cases() as $hemisphere) {
                $starts[Round::latestStarted($season, $hemisphere, $now)->startDate()] = true;
            }
        }

        $params = ['cat' => ItemType::QualityRides->value, 'starts' => array_map(strval(...), array_keys($starts))];
        $types = ['starts' => ArrayParameterType::STRING];
        $where = ["rr.state = 'verified'", 'sv.category = :cat', 'sv.round_start IN (:starts)'];

        if ([] !== $seasons) {
            $where[] = 'sv.season IN (:seasons)';
            $params['seasons'] = array_map(static fn (Season $s): string => $s->value, $seasons);
            $types['seasons'] = ArrayParameterType::STRING;
        }

        // One OR term per bike so a specialty vote cannot match a different declared type.
        if ([] !== $bikes) {
            $clauses = [];
            foreach ($bikes as $i => $bike) {
                $key = 'bike'.$i;
                $params[$key] = $bike->value;
                if ($bike->isSpecialty()) {
                    // docs/specs/route-domain.md §8.3, JSONB containment.
                    $params[$key.'text'] = $bike->value;
                    $clauses[] = "(sv.bike_type = :$key AND rr.attributes -> 'bikeTypes' @> to_jsonb(:{$key}text::text))";
                } else {
                    $clauses[] = "sv.bike_type = :$key";
                }
            }
            $where[] = '('.implode(' OR ', $clauses).')';
        }
        if ([] !== $regionIds) {
            $where[] = 'rr.region_id IN (:rids)';
            $params['rids'] = $regionIds;
            $types['rids'] = ArrayParameterType::INTEGER;
        }

        $sql = 'SELECT sv.subject_id
                FROM season_vote sv
                JOIN recommended_route rr ON rr.id = sv.subject_id
                WHERE '.implode(' AND ', $where).'
                GROUP BY sv.subject_id
                ORDER BY COUNT(*) DESC, MAX(sv.created_at) DESC, sv.subject_id ASC
                LIMIT '.self::MAX_RESULTS;

        /** @var list<array{subject_id: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative($sql, $params, $types);

        return array_map(static fn (array $r): int => (int) $r['subject_id'], $rows);
    }
}
