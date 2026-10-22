<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Service\BaseLocationService;
use Doctrine\DBAL\ArrayParameterType;
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

    /** Neighbours, ranking outlines and drawn silhouettes; run right after the region rows change. */
    public function shapes(): void
    {
        $this->db->executeStatement(self::adjacencySql());
        $this->db->executeStatement(self::OUTLINE_SQL);
        $this->db->executeStatement(self::silhouetteSql());
    }

    /**
     * The shape a region page draws (RegionSilhouette), as detailed as that
     * drawing can show. A region is drawn in its country's frame, so the
     * frame sets the scale: one drawing unit is the country's span over the
     * drawing box. Each part is simplified at RegionSilhouette's own path
     * step (0.1 unit), finer than any screen pixel at the largest size, and a
     * part is kept from 1.5 units across, so an island a rider can see stays.
     * The Netherlands keeps about 1,000 points and its Wadden islands,
     * Germany about 1,800. Its own column, so the map's region registry and
     * the globe keep the lighter outline they download.
     */
    public static function silhouetteSql(): string
    {
        return strtr(self::SILHOUETTE_SQL, [
            '{step}' => (string) (10 ** -RegionSilhouette::DECIMALS),
            '{min_part}' => '1.5',
            '{box_w}' => (string) (RegionSilhouette::W - 2 * RegionSilhouette::PAD),
            '{box_h}' => (string) (RegionSilhouette::H - 2 * RegionSilhouette::PAD),
            '{min_k}' => (string) RegionSilhouette::MIN_K,
        ]);
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
     * dependents() for rows within $bandDeg of the listed countries' regions; writes only rows whose value changes.
     *
     * @param list<string> $countries ISO 3166-1 alpha-2, upper case
     *
     * @return array{assigned: int, rederived: int, surfaced: int} rows written
     */
    public function dependentsNear(array $countries, float $bandDeg): array
    {
        if ([] === $countries) {
            return ['assigned' => 0, 'rederived' => 0, 'surfaced' => 0];
        }
        $assigned = $this->membershipNear('item', 'ST_PointOnSurface(m.geom)', $countries, $bandDeg)
            + $this->membershipNear('recommended_route', 'ST_PointOnSurface(m.geom)', $countries, $bandDeg)
            + $this->membershipNear('heat_point', 'm.geom', $countries, $bandDeg);
        $rederived = $this->baseLocations->rederiveNear($countries, $bandDeg);
        $surfaced = $this->surfaces->recomputeNear($countries, $bandDeg);

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

    /**
     * silhouetteSql() before its numbers: `f.unit` is the degrees one drawing
     * unit spans in the region's frame (its country's level-2 row, else the
     * region itself, as RegionSilhouette frames it), `f.k` the frame's
     * longitude shrink. `f` is materialized from bounding boxes, so each
     * frame is read once and not again for every row the update joins.
     * Coordinates keep four decimals (about 10 m), finer than the
     * simplification of the smallest country.
     */
    private const string SILHOUETTE_SQL = <<<'SQL'
        WITH f AS MATERIALIZED (
            SELECT q.id, q.k,
                   GREATEST((ST_YMax(q.fb) - ST_YMin(q.fb)) / {box_h}, (ST_XMax(q.fb) - ST_XMin(q.fb)) * q.k / {box_w}) AS unit
            FROM (
                SELECT r2.id, fr.fb, GREATEST({min_k}, cos(radians((ST_YMax(fr.fb) + ST_YMin(fr.fb)) / 2))) AS k
                FROM region r2
                CROSS JOIN LATERAL (
                    SELECT COALESCE(
                        (SELECT Box2D(c.geom) FROM region c WHERE c.country_code = r2.country_code AND c.admin_level = 2 LIMIT 1),
                        Box2D(r2.geom)
                    ) AS fb
                ) fr
                WHERE r2.geom IS NOT NULL
            ) q
        )
        UPDATE region r SET silhouette = COALESCE((
            SELECT json_agg(ring)
            FROM (
                SELECT (
                    SELECT json_agg(round(v::numeric, 4) ORDER BY o)
                    FROM (
                        SELECT unnest(ARRAY[ST_X(p.geom), ST_Y(p.geom)]) AS v,
                               (p.path[1] * 2) + generate_series(0, 1) AS o
                        FROM ST_DumpPoints(ST_ExteriorRing(z.g)) p
                    ) pt
                ) AS ring
                FROM (
                    SELECT ST_SimplifyPreserveTopology(d.geom, f.unit * {step}) AS g,
                           GREATEST((ST_XMax(d.geom) - ST_XMin(d.geom)) * f.k, ST_YMax(d.geom) - ST_YMin(d.geom)) / f.unit AS drawn
                    FROM ST_Dump(r.geom) d
                ) z
                WHERE z.g IS NOT NULL
                  AND GeometryType(z.g) = 'POLYGON'
                  AND z.drawn >= {min_part}
            ) rings
        ), '[]'::json)::jsonb
        FROM f
        WHERE f.id = r.id
          AND f.unit > 0
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

    /**
     * Smallest-area-wins, as recomputeMembership(), for rows in the band or held by a listed region.
     *
     * @param list<string> $countries
     */
    private function membershipNear(string $table, string $point, array $countries, float $bandDeg): int
    {
        return (int) $this->db->executeStatement(
            "WITH scope AS (SELECT id, geom FROM region WHERE country_code IN (:ccs) AND geom IS NOT NULL),
                  cand AS (
                    SELECT t.id FROM {$table} t JOIN scope s ON ST_DWithin(s.geom, t.geom, :deg)
                    UNION
                    SELECT t.id FROM {$table} t WHERE t.region_id IN (SELECT id FROM region WHERE country_code IN (:ccs))
                  ),
                  m AS (
                    SELECT m.id, (SELECT r.id FROM region r WHERE ST_Contains(r.geom, {$point})
                                   ORDER BY r.area_km2 ASC NULLS LAST, r.id ASC LIMIT 1) AS region_id
                      FROM {$table} m JOIN cand ON cand.id = m.id
                  )
             UPDATE {$table} t SET region_id = m.region_id FROM m
              WHERE t.id = m.id AND t.region_id IS DISTINCT FROM m.region_id",
            ['ccs' => $countries, 'deg' => $bandDeg],
            ['ccs' => ArrayParameterType::STRING],
        );
    }
}
