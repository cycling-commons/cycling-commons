<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * How much curated best-of content a region actually has, and therefore whether
 * a moderator may flip it to open in Curated mode
 * (2026-07-27-map-view-mode-default-design.md §4; owner decision "B", the GATED
 * option: the flag cannot be set prematurely).
 *
 * The count deliberately mirrors what Curated mode would SHOW, because that is
 * the thing a rider judges the region by:
 *
 *  - items on the experiential layers (`render.js` featureVisible: A surface,
 *    B climbs, E stays, I scenic, J history) that carry the curated flag —
 *    in Curated mode a non-curated item on those layers is hidden outright;
 *  - plus the region's best-of routes, i.e. verified `recommended_route` rows
 *    with at least one vote (the same set `RouteRankingService::bestOf` ranks).
 *
 * Utility layers (C water, D services, F hazards, G transit, H shelter) are
 * excluded on purpose: they render in BOTH modes, so they cannot be evidence
 * that Curated has anything to show. That is precisely the trap the owner hit —
 * a full utility map behind a rail reading "0 places shown".
 *
 * @api Consumed by the curator Regions desk and its gated toggle.
 */
final class CuratedReadiness
{
    /**
     * Letters whose items are HIDDEN in Curated mode unless curated
     * (`exp: true` in `web/assets/map/catalog.js`). Keep in step with that list.
     */
    public const array EXPERIENTIAL_LETTERS = ['A', 'B', 'E', 'I', 'J'];

    public function __construct(
        private readonly Connection $db,
        private readonly int $threshold,
    ) {
    }

    /** The advisory number a region must reach before Curated-by-default unlocks. */
    public function threshold(): int
    {
        return $this->threshold;
    }

    public function isReady(int $regionId): bool
    {
        return $this->countFor($regionId) >= $this->threshold;
    }

    public function countFor(int $regionId): int
    {
        $counts = $this->countForRegions([$regionId]);

        return $counts[$regionId] ?? 0;
    }

    /**
     * Batch form, so the desk lists N regions in two queries rather than 2N.
     * Regions with nothing curated are present in the result with 0 — the desk
     * shows "0 / 25", never a blank.
     *
     * @param list<int> $regionIds
     *
     * @return array<int, int> regionId => curated best-of count
     */
    public function countForRegions(array $regionIds): array
    {
        $out = array_fill_keys($regionIds, 0);
        if ([] === $regionIds) {
            return [];
        }

        /** @var list<array{region_id: int|string, n: int|string}> $items */
        $items = $this->db->fetchAllAssociative(
            // jsonb_exists(), NOT the `?` operator: DBAL parses `?` as a
            // positional parameter placeholder, so `attributes ? 'cur'` fails
            // the whole statement with "Positional parameter at index 0 does
            // not have a bound value". Same predicate, no ambiguity.
            "SELECT region_id, COUNT(*) AS n
               FROM item
              WHERE region_id IN (:rids)
                AND letter IN (:letters)
                AND jsonb_exists(attributes, 'cur')
                AND attributes ->> 'cur' NOT IN ('false', '0', '')
              GROUP BY region_id",
            ['rids' => $regionIds, 'letters' => self::EXPERIENTIAL_LETTERS],
            ['rids' => ArrayParameterType::INTEGER, 'letters' => ArrayParameterType::STRING],
        );
        foreach ($items as $r) {
            $out[(int) $r['region_id']] = (int) $r['n'];
        }

        // Best-of routes: verified AND voted, the same gate RouteRankingService
        // applies. A verified route nobody has voted for is not best-of yet, so
        // it must not count towards readiness.
        /** @var list<array{region_id: int|string, n: int|string}> $routes */
        $routes = $this->db->fetchAllAssociative(
            "SELECT rr.region_id, COUNT(DISTINCT rr.id) AS n
               FROM recommended_route rr
               JOIN route_vote rv ON rv.route_id = rr.id
              WHERE rr.region_id IN (:rids)
                AND rr.state = 'verified'
              GROUP BY rr.region_id",
            ['rids' => $regionIds],
            ['rids' => ArrayParameterType::INTEGER],
        );
        foreach ($routes as $r) {
            $out[(int) $r['region_id']] = ($out[(int) $r['region_id']] ?? 0) + (int) $r['n'];
        }

        return $out;
    }
}
