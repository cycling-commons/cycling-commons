<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Serves a region's spotlight polygon as a GeoJSON Feature, simplified with
 * ST_SimplifyPreserveTopology for a small cacheable payload — the client only
 * dims outside the shape and draws a dashed outline, so vertex-exact fidelity
 * is wasted bytes. Replaces the map's Nominatim boundary fetch, which was both
 * an external dependency and a Nominatim usage-policy problem in production
 * (region-scoping-design.md §4 spotlight, §7 Phase 1). Reads only; raw DBAL
 * like CatalogProvider / RouteRankingService.
 *
 * @api Serving entry point for the map's region spotlight (MapController::regionBoundary).
 */
final class RegionBoundaryProvider
{
    /**
     * Douglas–Peucker tolerance in SRID-4326 degrees (~0.001° ≈ 70–110 m at
     * Belgian latitudes): invisible under the dim/dash spotlight, but shrinks
     * the full-resolution admin polygon by roughly an order of magnitude.
     */
    private const float SIMPLIFY_TOLERANCE = 0.001;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * GeoJSON Feature (as a JSON string) for the region's simplified geometry,
     * or null when the slug is unknown or the row carries no geometry.
     */
    public function featureJson(string $slug): ?string
    {
        $geoJson = $this->db->fetchOne(
            'SELECT ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, :tol))
             FROM region WHERE slug = :slug AND geom IS NOT NULL',
            ['slug' => $slug, 'tol' => self::SIMPLIFY_TOLERANCE],
        );
        if (!\is_string($geoJson)) {
            return null;
        }

        return json_encode([
            'type' => 'Feature',
            'properties' => ['slug' => $slug],
            'geometry' => json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR),
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * GeoJSON Feature (JSON string) for the UNION of the regions in a scope —
     * an explicit id list and/or every region of a country — for the map's
     * country/multi-region dim mask (2026-07-22-coverage-scope-rendering-design.md
     * §B). Null when nothing matches (Everywhere / empty scope).
     *
     * @param list<int> $rids
     */
    public function unionFeatureJson(array $rids, ?string $cc): ?string
    {
        if ([] === $rids && (null === $cc || '' === $cc)) {
            return null;
        }

        // Scope arms are built conditionally, mirroring CoverageRepository::
        // scopeArm() (Coverage/CoverageRepository.php:83-94): a static
        // `... OR country_code = :cc` bound to '' for a rids-only call would
        // also union in every region still carrying the schema-default ''
        // country_code, not just the requested ids.
        $arms = [];
        $params = ['tol' => self::SIMPLIFY_TOLERANCE];
        $types = [];
        if ([] !== $rids) {
            $arms[] = 'id IN (:rids)';
            $params['rids'] = $rids;
            $types['rids'] = ArrayParameterType::INTEGER;
        }
        if (null !== $cc && '' !== $cc) {
            $arms[] = 'country_code = :cc';
            $params['cc'] = $cc;
        }

        $geoJson = $this->db->fetchOne(
            'SELECT ST_AsGeoJSON(ST_SimplifyPreserveTopology(ST_Union(geom), :tol))
             FROM region
             WHERE geom IS NOT NULL AND ('.implode(' OR ', $arms).')',
            $params,
            $types,
        );
        if (!\is_string($geoJson)) {
            return null;
        }

        return json_encode([
            'type' => 'Feature',
            'properties' => (object) [],
            'geometry' => json_decode($geoJson, true, 512, \JSON_THROW_ON_ERROR),
        ], \JSON_THROW_ON_ERROR);
    }
}
