<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution;

use Doctrine\DBAL\Connection;

/**
 * Assigns a proposed route to its operational region at intake (route-domain
 * spec §4.2) with the exact membership rule the importer applies wholesale
 * (ImportCatalogCommand::recomputeMembership): the region polygon that
 * contains the route's point-on-surface. When overlapping regions contain the
 * point, the smallest by area wins — a deterministic tie-break so membership
 * never depends on row order once regions multiply beyond the single Wallonia
 * seed (region-scoping-design.md §3). NULL when no region matches, so a
 * proposal outside every region is still reviewable.
 *
 * @api Region assignment at route intake (docs/specs/route-domain.md §4.2); consumed by
 *      RouteProposalService, covered by RegionResolverTest.
 */
final class RegionResolver
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Callers pass parser-produced GeoJSON (valid by construction), so the
     * PostGIS parse-failure path below is not expected in normal operation.
     *
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
