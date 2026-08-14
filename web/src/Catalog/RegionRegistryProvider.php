<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * The client-side region registry (map-and-search.md §4.5 Phase 2):
 * every region's id, slug, country code and bounding box, handed to the map page
 * so window.CCScope can resolve the scope selector, compute scope viewport
 * bounds, and know a country's region set for the "All <country>" scope. Display
 * labels come from the messages domain (region.<slug>.label), never from here.
 * Reads only; raw DBAL like CatalogProvider / RegionBoundaryProvider.
 *
 * @api Consumed by MapController::map (window.CC_REGIONS) and CCScope.init.
 */
final class RegionRegistryProvider
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * `countryCode` (not `cc`) matches the CCScope scope-object contract, so the
     * client can thread a registry entry straight into a scope.
     *
     * `outline` is the simplified ranking geometry CCScope's rankByGroundDistance
     * measures to — rings as flat [lng,lat,lng,lat,…].
     * Not a boundary
     * source: the real polygons still come from RegionBoundaryProvider. An empty
     * list is normal (a region imported before the outline column existed); the
     * client falls back to the bbox centre.
     *
     * `defaultMode` is the moderator setting that makes the map OPEN this
     * region in Curated mode;
     * false everywhere until a region earns it, and the global default is
     * Everything.
     *
     * @return list<array{id: int, slug: string, countryCode: string, bbox: array{0: float, 1: float, 2: float, 3: float}, adj: list<int>, outline: list<list<float>>, defaultMode: string}>
     */
    public function all(): array
    {
        /** @var list<array{id: int, slug: string, cc: string, w: float, s: float, e: float, n: float, adj: string, outline: ?string, default_map_mode: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, slug, country_code AS cc,
                    ST_XMin(geom) AS w, ST_YMin(geom) AS s, ST_XMax(geom) AS e, ST_YMax(geom) AS n,
                    to_json(COALESCE(adj, ARRAY[]::int[])) AS adj,
                    outline, default_map_mode
             FROM region
             WHERE geom IS NOT NULL AND country_code <> \'\'
               AND '.OperationalRegions::predicate('region').'
             ORDER BY area_km2 DESC, slug',
        );

        return array_map(static function (array $r): array {
            // JSON_THROW_ON_ERROR would turn a hand-edited row into a 500 on the
            // map page; the ranking degrades to bbox centres instead.
            $outline = null === $r['outline'] ? null : json_decode((string) $r['outline'], true);

            return [
                'id' => (int) $r['id'],
                'slug' => (string) $r['slug'],
                'countryCode' => (string) $r['cc'],
                'bbox' => [(float) $r['w'], (float) $r['s'], (float) $r['e'], (float) $r['n']],
                /* Where to POINT THE CAMERA, which is not the same box.
                   `bbox` is the region's true extent and must stay that way:
                   membership and the nearest-region ranking depend on it.
                   Framing on it puts the map in the ocean whenever a region
                   owns a distant island. Western Cape reaches 46.98°S because
                   the Prince Edward Islands are part of it, so scoping to it
                   centred the map 800 km off Cape Town on empty water with
                   "0 places shown"; Valparaíso reaches 109.45°W for Easter
                   Island and lands mid-Pacific. Both arrived with the
                   2026-08-14 rollout, which is why nobody had seen it.
                   The MAIN LANDMASS is what a rider means by the region, so
                   the camera box is the largest outline ring. */
                'view' => self::mainPartBox(\is_array($outline) ? $outline : [])
                    ?? [(float) $r['w'], (float) $r['s'], (float) $r['e'], (float) $r['n']],
                'adj' => array_map('intval', json_decode((string) $r['adj'], true, 512, \JSON_THROW_ON_ERROR)),
                'outline' => \is_array($outline) ? $outline : [],
                // The mode this region OPENS in, as the toggle's own token
                // ('curated' | 'confirmed' | 'all'); 'everything' is stored and
                // 'all' is what the client calls it (MapViewMode::clientToken).
                'defaultMode' => 'everything' === $r['default_map_mode'] ? 'all' : (string) $r['default_map_mode'],
            ];
        }, $rows);
    }

    /**
     * Bounding box of the region's LARGEST outline ring, for framing the map.
     *
     * Largest by cos-latitude-corrected bbox area, not by vertex count: a
     * heavily-indented small island can carry more points than a smooth big
     * one, and it is ground area that decides which part a rider means.
     *
     * The outline is already the right input — it drops parts under
     * max(1% of the region, 5 km²) and always keeps the biggest
     * (ImportCatalogCommand::OUTLINE_SQL) — so the ring set here is small and
     * the largest of it is the mainland by construction.
     *
     * @param list<list<float>> $outline flat [x,y,x,y,…] rings
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null null when there is no usable ring, so the caller keeps the true bbox
     */
    private static function mainPartBox(array $outline): ?array
    {
        $best = null;
        $bestArea = 0.0;
        foreach ($outline as $ring) {
            // Fewer than three points encloses nothing; the outline is
            // machine-written, so this is a rounding artifact rather than data.
            if (\count($ring) < 6) {
                continue;
            }
            $minX = $minY = \INF;
            $maxX = $maxY = -\INF;
            for ($i = 0, $n = \count($ring) - 1; $i < $n; $i += 2) {
                $x = (float) $ring[$i];
                $y = (float) $ring[$i + 1];
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
            if (\INF === $minX) {
                continue;
            }
            $area = ($maxX - $minX) * cos(deg2rad(($minY + $maxY) / 2)) * ($maxY - $minY);
            if ($area > $bestArea) {
                $bestArea = $area;
                $best = [$minX, $minY, $maxX, $maxY];
            }
        }

        return $best;
    }
}
