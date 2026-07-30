<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

use App\Catalog\OperationalRegions;
use Doctrine\DBAL\Connection;

/**
 * Derives the rider's My-area region set from a coarse base point + radius:
 * ST_DWithin over region polygons, containing region always first, capped at
 * MAX_REGIONS (region-scoping-design.md §3 "User base location").
 * Raw DBAL like SpatialResolver/RegionResolver. Operational-only
 * (2026-07-30-dynamic-region-pages-design.md §4): a lister of regions, so the
 * infrastructure-only L2 country outline (which always contains its
 * operational L4 subdivisions) must never occupy one of the 8 slots.
 *
 * @api Autowired by the DI container; consumed by BaseLocationService's
 *      apply()/rederiveAll() (settings save, the map "Set my area" endpoint,
 *      and import re-derivation).
 */
final class BaseAreaResolver
{
    public const int MAX_REGIONS = 8;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{regionIds: list<int>, countryCodes: list<string>} */
    public function resolve(float $lat, float $lng, int $radiusKm): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT r.id, r.country_code
               FROM region r
              WHERE r.geom IS NOT NULL
                AND ST_DWithin(r.geom::geography, ST_SetSRID(ST_Point(:lng, :lat), 4326)::geography, :m)
                AND '.OperationalRegions::predicate('r').'
              ORDER BY ST_Contains(r.geom, ST_SetSRID(ST_Point(:lng, :lat), 4326)) DESC,
                       ST_Distance(r.geom::geography, ST_SetSRID(ST_Point(:lng, :lat), 4326)::geography) ASC,
                       r.area_km2 ASC NULLS LAST, r.id ASC
              LIMIT '.self::MAX_REGIONS,
            ['lat' => $lat, 'lng' => $lng, 'm' => (float) $radiusKm * 1000.0],
        );

        $ids = [];
        $ccs = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
            $cc = strtoupper((string) $row['country_code']);
            if ('' !== $cc && !\in_array($cc, $ccs, true)) {
                $ccs[] = $cc;
            }
        }

        return ['regionIds' => $ids, 'countryCodes' => $ccs];
    }
}
