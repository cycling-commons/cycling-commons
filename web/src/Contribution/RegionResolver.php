<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use Doctrine\DBAL\Connection;

/**
 * Assigns a proposed route to its operational region (docs/specs/route-domain.md §4.2).
 * Smallest-area-wins on overlap. NULL when no region matches.
 *
 * @api
 */
final class RegionResolver
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @throws \Doctrine\DBAL\Exception when the GeoJSON cannot be parsed by PostGIS
     */
    public function resolve(string $lineStringGeoJson): ?int
    {
        $id = $this->db->fetchOne(
            'SELECT r.id FROM region r
             WHERE ST_Contains(r.geom, ST_PointOnSurface(ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326)))
             ORDER BY r.area_km2 ASC NULLS LAST, r.id ASC LIMIT 1',
            ['geom' => $lineStringGeoJson],
        );

        return false === $id ? null : (int) $id;
    }
}
