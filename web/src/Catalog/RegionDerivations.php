<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Service\BaseLocationService;
use Doctrine\DBAL\Connection;

/**
 * What the database derives once region rows change: neighbours, ranking
 * outlines, item/route/heat membership, rider base areas and route surfaces.
 * Shared by app:catalog:import and app:country:apply.
 *
 * @api
 */
final class RegionDerivations
{
    public function __construct(
        private readonly Connection $db,
        private readonly BaseLocationService $baseLocations,
        private readonly SurfaceProfiler $surfaces,
    ) {
    }

    /** Neighbours and ranking outlines; run right after the region rows change. */
    public function shapes(): void
    {
        $this->db->executeStatement(self::adjacencySql());
        $this->db->executeStatement(self::OUTLINE_SQL);
    }

    /**
     * Membership, then everything that reads it (docs/specs/map-and-search.md §4.5).
     *
     * @return array{assigned: int, rederived: int, surfaced: int}
     */
    public function dependents(): array
    {
        // The caller must hold a transaction: bases and surfaces have to land with membership.
        $assigned = $this->recomputeMembership();
        $rederived = $this->baseLocations->rederiveAll();
        $surfaced = $this->surfaces->recomputeAll();

        return ['assigned' => $assigned, 'rederived' => $rederived, 'surfaced' => $surfaced];
    }

    /**
     * Neighbours are OPERATIONAL regions only, on both sides (catalog-data-model.md §2.4).
     *
     * Every region intersects its own country outline, so the unfiltered
     * version made the level-2 row a neighbour of all twelve Dutch provinces -
     * and the spotlight, which punches its clear hole through the union of
     * region + neighbours, then lit the whole Netherlands instead of North
     * Holland and its four real neighbours (owner 2026-08-24).
     *
     * The `a` predicate sits INSIDE the subquery deliberately: for a
     * non-operational row it matches nothing, so COALESCE writes the empty
     * array. A country outline ends up with no neighbours, which is the truth
     * about a row that is not a scope.
     *
     * Shared with Version20260824120000 (through ImportCatalogCommand::adjacencySql()), so a fix here cannot drift from what
     * deployed databases were backfilled with.
     */
    public static function adjacencySql(): string
    {
        return 'UPDATE region a SET adj = COALESCE((
                SELECT array_agg(b.id ORDER BY b.id)
                FROM region b
                WHERE b.id <> a.id AND ST_Intersects(a.geom, b.geom)
                  AND '.OperationalRegions::predicate('b').'
                  AND '.OperationalRegions::predicate('a').'
             ), ARRAY[]::int[])
             WHERE a.geom IS NOT NULL';
    }

    /**
     * Compute part area once (correlated ST_Area of the whole geom is quadratic). Must match Version20260727120000.
     */
    public const string OUTLINE_SQL = <<<'SQL'
        UPDATE region r SET outline = COALESCE((
            SELECT json_agg(ring)
            FROM (
                SELECT (
                    SELECT json_agg(round(v::numeric, 3) ORDER BY o)
                    FROM (
                        SELECT unnest(ARRAY[ST_X(p.geom), ST_Y(p.geom)]) AS v,
                               (p.path[1] * 2) + generate_series(0, 1) AS o
                        FROM ST_DumpPoints(ST_ExteriorRing(z.g)) p
                    ) pt
                ) AS ring
                FROM (
                    SELECT parts.g,
                           parts.a,
                           max(parts.a) OVER () AS mx,
                           sum(parts.a) OVER () AS total
                    FROM (
                        SELECT ST_SimplifyPreserveTopology(d.geom, 0.05) AS g,
                               ST_Area(d.geom::geography) AS a
                        FROM ST_Dump(r.geom) d
                    ) parts
                ) z
                WHERE z.g IS NOT NULL
                  AND GeometryType(z.g) = 'POLYGON'
                  AND z.a >= LEAST(GREATEST(z.total * 0.01, 5e6), z.mx)
            ) rings
        ), '[]'::json)::jsonb
        WHERE r.geom IS NOT NULL
        SQL;

    private function recomputeMembership(): int
    {
        // docs/specs/catalog-data-model.md §6: smallest-area-wins; uncontained rows stay NULL.
        $this->db->executeStatement('UPDATE item SET region_id = NULL');
        $assigned = (int) $this->db->executeStatement(
            'UPDATE item SET region_id = m.region_id FROM (
                SELECT DISTINCT ON (i.id) i.id AS item_id, r.id AS region_id
                FROM item i JOIN region r ON ST_Contains(r.geom, ST_PointOnSurface(i.geom))
                ORDER BY i.id, r.area_km2 ASC NULLS LAST, r.id ASC
             ) m WHERE item.id = m.item_id',
        );

        $this->db->executeStatement('UPDATE recommended_route SET region_id = NULL');
        $assigned += (int) $this->db->executeStatement(
            'UPDATE recommended_route SET region_id = m.region_id FROM (
                SELECT DISTINCT ON (rr.id) rr.id AS route_id, r.id AS region_id
                FROM recommended_route rr JOIN region r ON ST_Contains(r.geom, ST_PointOnSurface(rr.geom))
                ORDER BY rr.id, r.area_km2 ASC NULLS LAST, r.id ASC
             ) m WHERE recommended_route.id = m.route_id',
        );

        // docs/specs/catalog-data-model.md §6: heat points need rid; unstamped would render in every scope or none.
        $this->db->executeStatement('UPDATE heat_point SET region_id = NULL');
        $assigned += (int) $this->db->executeStatement(
            'UPDATE heat_point SET region_id = m.region_id FROM (
                SELECT DISTINCT ON (h.id) h.id AS heat_id, r.id AS region_id
                FROM heat_point h JOIN region r ON ST_Contains(r.geom, h.geom)
                ORDER BY h.id, r.area_km2 ASC NULLS LAST, r.id ASC
             ) m WHERE heat_point.id = m.heat_id',
        );

        return $assigned;
    }
}
