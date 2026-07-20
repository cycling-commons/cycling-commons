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
     * @return list<array{id: int, slug: string, countryCode: string, bbox: array{0: float, 1: float, 2: float, 3: float}}>
     */
    public function all(): array
    {
        /** @var list<array{id: int, slug: string, cc: string, w: float, s: float, e: float, n: float}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, slug, country_code AS cc,
                    ST_XMin(geom) AS w, ST_YMin(geom) AS s, ST_XMax(geom) AS e, ST_YMax(geom) AS n
             FROM region
             WHERE geom IS NOT NULL AND country_code <> \'\'
             ORDER BY area_km2 DESC, slug',
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'countryCode' => (string) $r['cc'],
            'bbox' => [(float) $r['w'], (float) $r['s'], (float) $r['e'], (float) $r['n']],
        ], $rows);
    }
}
