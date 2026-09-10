<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Region spotlight polygon as a simplified GeoJSON Feature. Vertex-exact fidelity is wasted bytes.
 *
 * @see docs/specs/map-and-search.md §4.5
 *
 * @api
 */
final class RegionBoundaryProvider
{
    /** Douglas–Peucker tolerance in SRID-4326 degrees (~70–110 m at Belgian latitudes). */
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
     * UNION of regions in a scope for the country/multi-region dim mask. Null when nothing matches.
     *
     * @see docs/specs/coverage-provider.md §4
     *
     * @param list<int> $rids
     */
    public function unionFeatureJson(array $rids, ?string $cc): ?string
    {
        if ([] === $rids && (null === $cc || '' === $cc)) {
            return null;
        }

        // Build scope arms conditionally: a static `OR country_code = :cc` bound to '' would union leftover default-'' rows.
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
