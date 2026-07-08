<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution;

use Doctrine\DBAL\Connection;

/**
 * Assigns a proposed route to its operational region at intake (route-domain
 * spec §5.4) with the exact membership rule the importer applies wholesale
 * (ImportCatalogCommand::recomputeMembership): the region polygon that
 * contains the route's point-on-surface. NULL when no region matches —
 * a proposal outside every region is still reviewable.
 */
final class RegionResolver
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function resolve(string $lineStringGeoJson): ?int
    {
        $id = $this->db->fetchOne(
            'SELECT r.id FROM region r
             WHERE ST_Contains(r.geom, ST_PointOnSurface(ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326)))
             ORDER BY r.id LIMIT 1',
            ['geom' => $lineStringGeoJson],
        );

        return false === $id ? null : (int) $id;
    }
}
