<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Best-of ranking (docs/specs/route-domain.md §8): ranks `verified` routes by their
 * typed-seasonal-vote count for a (season, bike, region?) facet, computed in SQL
 * at serve time (regions hold at most the active cap, so no materialization
 * is needed). Reads only; raw DBAL like CatalogProvider. Consumed by
 * MapController::bestOf.
 *
 * @api Serving entry point for the map's Curated best-of.
 */
final class RouteRankingService
{
    /**
     * Hard top-N cap on the best-of aggregate. Without a region filter the query
     * aggregates across every region unbounded — the flagged latent constraint
     * (route-domain.md §12, item 1). Region-scoped facets hold ≤ the active cap,
     * far below this, so the cap only ever bounds the Everywhere facet and never
     * truncates a real region list. Landed in Phase 1, before any widening UI
     * exists (map-and-search.md §4.5 Phase 1).
     */
    public const int MAX_RESULTS = 200;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Ranked verified-route ids for the facet, best first. `$bike === null`
     * means "all bikes" (aggregate across bike types). `$regionIds === []`
     * means Everywhere (unbounded, subject to MAX_RESULTS); a non-empty set
     * merges votes across every listed region in one ranking, the My-area
     * derived scope (map-and-search.md §4.5 Phase 4). Only routes with
     * ≥1 matching vote are returned; the four specialty bike types are
     * additionally gated by declared suitability (docs/specs/route-domain.md §8.3).
     *
     * @param list<int> $regionIds
     *
     * @return list<int>
     */
    public function bestOf(Season $season, ?BikeType $bike, array $regionIds = []): array
    {
        $params = ['season' => $season->value];
        $types = [];
        $where = ["rr.state = 'verified'", 'rv.season = :season'];

        if (null !== $bike) {
            $where[] = 'rv.bike_type = :bike';
            $params['bike'] = $bike->value;
            if ($bike->isSpecialty()) {
                // JSONB containment: the route must declare this bike suitable.
                $where[] = "rr.attributes -> 'bikeTypes' @> to_jsonb(:bikeText::text)";
                $params['bikeText'] = $bike->value;
            }
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
