<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Serve-time best-of ranking of `verified` routes. Empty facet lists mean "narrowed by nothing".
 *
 * @see docs/specs/route-domain.md §8
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

    public function __construct(private readonly Connection $db)
    {
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
        $params = [];
        $types = [];
        $where = ["rr.state = 'verified'"];

        // Empty list drops the facet; it does not mean "match nothing".
        if ([] !== $seasons) {
            $where[] = 'rv.season IN (:seasons)';
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
                    // docs/specs/route-domain.md §8.3 — JSONB containment.
                    $params[$key.'text'] = $bike->value;
                    $clauses[] = "(rv.bike_type = :$key AND rr.attributes -> 'bikeTypes' @> to_jsonb(:{$key}text::text))";
                } else {
                    $clauses[] = "rv.bike_type = :$key";
                }
            }
            $where[] = '('.implode(' OR ', $clauses).')';
        }
        if ([] !== $regionIds) {
            $where[] = 'rr.region_id IN (:rids)';
            $params['rids'] = $regionIds;
            $types['rids'] = ArrayParameterType::INTEGER;
        }

        $sql = 'SELECT rv.route_id
                FROM route_vote rv
                JOIN recommended_route rr ON rr.id = rv.route_id
                WHERE '.implode(' AND ', $where).'
                GROUP BY rv.route_id
                ORDER BY COUNT(*) DESC, MAX(rv.created_at) DESC, rv.route_id ASC
                LIMIT '.self::MAX_RESULTS;

        /** @var list<array{route_id: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative($sql, $params, $types);

        return array_map(static fn (array $r): int => (int) $r['route_id'], $rows);
    }
}
