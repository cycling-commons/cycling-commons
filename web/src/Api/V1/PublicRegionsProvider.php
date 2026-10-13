<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api\V1;

use App\BulkExport\BulkExportBuilder;
use App\Catalog\ItemState;
use App\Catalog\OperationalRegions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * `/v1/regions` and `/v1/regions/{id}` (docs/specs/public-api.md §2.2): the
 * regions the site lists on /regions, keyed by their public slug, with what
 * each one holds.
 *
 * The list draws each region by its stored coarse outline (a few hundred
 * points, the shape the rankings use), because the full boundaries weigh
 * about 100 MB together. One region is drawn by its boundary simplified to
 * about 100 m, the shape the map draws (RegionBoundaryProvider).
 *
 * @api
 */
final readonly class PublicRegionsProvider
{
    /** Degrees; the map's own boundary tolerance (RegionBoundaryProvider::SIMPLIFY_TOLERANCE). */
    private const float SIMPLIFY_TOLERANCE = 0.001;

    public function __construct(private Connection $db)
    {
    }

    /**
     * Regions by slug, after `$afterSlug`, one country or all.
     *
     * @return list<array<string, mixed>> GeoJSON features
     */
    public function list(int $limit, ?string $afterSlug, ?string $country): array
    {
        $sql = $this->select('r.outline::text AS shape').' AND r.slug > :after';
        $params = ['after' => $afterSlug ?? '', 'lim' => $limit];
        if (null !== $country) {
            $sql .= ' AND r.country_code = :cc';
            $params['cc'] = $country;
        }
        $rows = $this->db->fetchAllAssociative($sql.' ORDER BY r.slug LIMIT :lim', $params, ['lim' => ParameterType::INTEGER]);

        return array_map(fn (array $row): array => $this->feature($row, self::outlineGeometry($row['shape'] ?? null), null), $rows);
    }

    /**
     * One region with its counts per letter, or null when no listed region has that slug.
     *
     * @return array<string, mixed>|null GeoJSON feature
     */
    public function region(string $slug): ?array
    {
        $row = $this->db->fetchAssociative(
            $this->select('ST_AsGeoJSON(ST_Multi(ST_SimplifyPreserveTopology(r.geom, '.self::SIMPLIFY_TOLERANCE.')), 6) AS shape').' AND r.slug = :slug',
            ['slug' => $slug],
        );
        if (false === $row) {
            return null;
        }
        $geometry = \is_string($row['shape']) ? json_decode($row['shape'], true) : null;

        $items = $this->db->fetchAllKeyValue(
            'SELECT i.letter, COUNT(*) FROM item i WHERE i.region_id = :id AND '.BulkExportBuilder::servedItemSql('i').' GROUP BY i.letter ORDER BY i.letter',
            ['id' => (int) $row['id']],
        );
        if ((int) $row['routes'] > 0) {
            $items['R'] = (int) $row['routes'];
        }
        // The kept counts of OpenStreetMap points no item stands for
        // (coverage-provider.md §11), the numbers the map's chips show.
        $coverage = $this->db->fetchAllKeyValue(
            'SELECT letter, SUM(n) FROM coverage_count WHERE region_id = :id GROUP BY letter HAVING SUM(n) > 0 ORDER BY letter',
            ['id' => (int) $row['id']],
        );

        return $this->feature($row, \is_array($geometry) ? $geometry : null, [
            'items_by_letter' => (object) array_map(intval(...), $items),
            'coverage_by_letter' => (object) array_map(intval(...), $coverage),
        ]);
    }

    /**
     * The regions /regions lists: a boundary, a country, and the finest
     * level that country is split into (OperationalRegions).
     */
    private function select(string $shape): string
    {
        return 'SELECT r.id, r.slug, r.name, r.labels::text AS labels, r.country_code, r.area_km2,
                       r.bbox_w, r.bbox_s, r.bbox_e, r.bbox_n, '.$shape.',
                       (SELECT COUNT(*) FROM item i WHERE i.region_id = r.id AND '.BulkExportBuilder::servedItemSql('i').') AS items,
                       (SELECT COUNT(*) FROM item i WHERE i.region_id = r.id AND i.state = \'verified\' AND '.BulkExportBuilder::servedItemSql('i').') AS verified,
                       (SELECT COUNT(*) FROM recommended_route rr WHERE rr.region_id = r.id AND rr.state IN '.ItemState::servedSqlTuple().') AS routes
                FROM region r
                JOIN world_country wc ON wc.iso2 = r.country_code
                WHERE r.geom IS NOT NULL AND r.country_code <> \'\' AND '.OperationalRegions::predicate('r');
    }

    /**
     * @param array<string, mixed>       $row
     * @param array<string, mixed>|null  $geometry
     * @param array<string, object>|null $byLetter only on one region
     *
     * @return array<string, mixed>
     */
    private function feature(array $row, ?array $geometry, ?array $byLetter): array
    {
        $labels = \is_string($row['labels'] ?? null) ? json_decode($row['labels'], true) : null;
        $bbox = [$row['bbox_w'], $row['bbox_s'], $row['bbox_e'], $row['bbox_n']];
        $properties = [
            'region_id' => (string) $row['slug'],
            'name' => (string) $row['name'],
            // The region's name per language where we hold one; `name` otherwise.
            'names' => (object) (\is_array($labels) ? array_filter($labels, is_string(...)) : []),
            'country' => (string) $row['country_code'],
            'area_km2' => null !== $row['area_km2'] ? (int) round((float) $row['area_km2']) : null,
            'stats' => [
                'items' => (int) $row['items'],
                'verified' => (int) $row['verified'],
                'routes' => (int) $row['routes'],
            ],
        ];
        if (null !== $byLetter) {
            $properties['stats'] += $byLetter;
        }

        $feature = [
            'type' => 'Feature',
            'id' => (string) $row['slug'],
            'geometry' => $geometry,
            'properties' => $properties,
        ];
        // West above east is a region across the antimeridian; GeoJSON allows it (RFC 7946 §5.2).
        if (!\in_array(null, $bbox, true)) {
            $feature['bbox'] = array_map(static fn (mixed $v): float => round((float) $v, 6), $bbox);
        }

        return $feature;
    }

    /**
     * The stored outline (`region.outline`, a list of flat `[x, y, ...]`
     * exterior rings) as a MultiPolygon.
     *
     * @return array{type: string, coordinates: list<list<list<array{0: float, 1: float}>>>}|null
     */
    private static function outlineGeometry(mixed $raw): ?array
    {
        $rings = \is_string($raw) ? json_decode($raw, true) : null;
        if (!\is_array($rings)) {
            return null;
        }
        $polygons = [];
        foreach ($rings as $flat) {
            if (!\is_array($flat) || \count($flat) < 8) {
                continue;
            }
            $ring = [];
            foreach (array_chunk(array_values($flat), 2) as $pair) {
                if (2 === \count($pair) && is_numeric($pair[0]) && is_numeric($pair[1])) {
                    $ring[] = [(float) $pair[0], (float) $pair[1]];
                }
            }
            if (\count($ring) < 3) {
                continue;
            }
            if ($ring[0] !== $ring[\count($ring) - 1]) {
                $ring[] = $ring[0];
            }
            $polygons[] = [$ring];
        }

        return [] === $polygons ? null : ['type' => 'MultiPolygon', 'coordinates' => $polygons];
    }
}
