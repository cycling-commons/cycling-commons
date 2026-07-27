<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * The client-side region registry (region-scoping-design.md §4 / §7 Phase 2):
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
     * measures to — rings as flat [lng,lat,lng,lat,…]
     * (2026-07-27-region-edge-distance-ranking-design.md §3). Not a boundary
     * source: the real polygons still come from RegionBoundaryProvider. An empty
     * list is normal (a region imported before the outline column existed); the
     * client falls back to the bbox centre.
     *
     * `curatedDefault` is the moderator flag that makes the map OPEN this
     * region in Curated mode (2026-07-27-map-view-mode-default-design.md §3);
     * false everywhere until a region earns it, and the global default is
     * Everything.
     *
     * @return list<array{id: int, slug: string, countryCode: string, bbox: array{0: float, 1: float, 2: float, 3: float}, adj: list<int>, outline: list<list<float>>, curatedDefault: bool}>
     */
    public function all(): array
    {
        /** @var list<array{id: int, slug: string, cc: string, w: float, s: float, e: float, n: float, adj: string, outline: ?string, curated_default: bool}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, slug, country_code AS cc,
                    ST_XMin(geom) AS w, ST_YMin(geom) AS s, ST_XMax(geom) AS e, ST_YMax(geom) AS n,
                    to_json(COALESCE(adj, ARRAY[]::int[])) AS adj,
                    outline, curated_default
             FROM region
             WHERE geom IS NOT NULL AND country_code <> \'\'
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
                'adj' => array_map('intval', json_decode((string) $r['adj'], true, 512, \JSON_THROW_ON_ERROR)),
                'outline' => \is_array($outline) ? $outline : [],
                'curatedDefault' => (bool) $r['curated_default'],
            ];
        }, $rows);
    }
}
