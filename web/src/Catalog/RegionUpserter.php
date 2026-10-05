<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * Writes region rows by slug, for the catalog import and country onboarding alike.
 *
 * @see docs/specs/map-and-search.md §4.5
 *
 * @api
 */
final class RegionUpserter
{
    /** Same-level overlap above this fraction of the smaller area is a bad import; at or below it is a digitization sliver. */
    private const float OVERLAP_TOLERANCE = 0.001;

    private const string SQL = <<<'SQL'
        INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, labels, created_at, updated_at)
        VALUES (:slug, :name, ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326), :area, :cc, :iso, :admin, :source,
                COALESCE(CAST(:labels AS jsonb), '{}'::jsonb), NOW(), NOW())
        ON CONFLICT (slug) DO UPDATE SET name = EXCLUDED.name, geom = EXCLUDED.geom,
          area_km2 = EXCLUDED.area_km2, country_code = EXCLUDED.country_code,
          iso_code = EXCLUDED.iso_code, admin_level = EXCLUDED.admin_level, source = EXCLUDED.source,
          labels = COALESCE(CAST(:labels AS jsonb), region.labels),
          updated_at = CASE WHEN (region.name, ST_AsEWKB(region.geom), region.area_km2, region.country_code, region.iso_code, region.admin_level, region.source, region.labels)
                            IS DISTINCT FROM (EXCLUDED.name, ST_AsEWKB(EXCLUDED.geom), EXCLUDED.area_km2, EXCLUDED.country_code, EXCLUDED.iso_code, EXCLUDED.admin_level, EXCLUDED.source, COALESCE(CAST(:labels AS jsonb), region.labels))
                       THEN NOW() ELSE region.updated_at END
        SQL;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string, mixed>       $props  slug, name, area_km2, country_code, iso_code, admin_level, source
     * @param array<string, string>|null $labels null or empty keeps the stored labels
     *
     * @throws \InvalidArgumentException when country_code is missing or malformed
     */
    public function upsert(array $props, string $geometryJson, string $context, ?array $labels = null): void
    {
        [$countryCode, $isoCode, $adminLevel, $source] = self::provenance($props, $context);
        $this->db->executeStatement(self::SQL, [
            'slug' => \is_string($props['slug'] ?? null) ? $props['slug'] : '',
            'name' => \is_string($props['name'] ?? null) ? $props['name'] : '',
            'geom' => $geometryJson,
            'area' => \is_numeric($props['area_km2'] ?? null) ? (float) $props['area_km2'] : null,
            'cc' => $countryCode,
            'iso' => $isoCode,
            'admin' => $adminLevel,
            'source' => $source,
            // An empty map never replaces stored labels (a re-import without labels keeps the seeded ones).
            'labels' => null === $labels || [] === $labels ? null : json_encode($labels, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param array<string, mixed> $props
     *
     * @return array{0: string, 1: string|null, 2: int|null, 3: string|null} [countryCode, isoCode, adminLevel, source]
     */
    public static function provenance(array $props, string $context): array
    {
        $rawCc = \is_string($props['country_code'] ?? null) ? strtoupper(trim($props['country_code'])) : '';
        if (1 !== preg_match('/^[A-Z]{2}$/', $rawCc)) {
            throw new \InvalidArgumentException(sprintf('%s: region artifact missing required 2-letter country_code (got %s): an unstamped region is a silent moderation-jurisdiction hole (map-and-search.md §4.5).', $context, '' === $rawCc ? '<missing>' : sprintf('"%s"', $rawCc)));
        }

        $iso = \is_string($props['iso_code'] ?? null) && '' !== trim($props['iso_code'])
            ? strtoupper(trim($props['iso_code'])) : null;
        $admin = \is_numeric($props['admin_level'] ?? null) ? (int) $props['admin_level'] : null;
        $source = \is_string($props['source'] ?? null) && '' !== trim($props['source'])
            ? trim($props['source']) : null;

        return [$rawCc, $iso, $admin, $source];
    }

    /**
     * @throws \InvalidArgumentException when two same-country, same-level regions overlap
     */
    public function assertTessellates(): void
    {
        // docs/specs/map-and-search.md §4.5: same (country, admin_level) must tessellate; IS NOT DISTINCT FROM keeps NULL-level rows in the guard. Cross-level containment is expected.
        /** @var array{a: string, b: string}|false $overlap */
        $overlap = $this->db->fetchAssociative(
            "SELECT a.slug AS a, b.slug AS b
               FROM region a JOIN region b ON a.id < b.id
              WHERE a.country_code = b.country_code AND a.country_code <> ''
                AND a.admin_level IS NOT DISTINCT FROM b.admin_level
                AND a.geom IS NOT NULL AND b.geom IS NOT NULL
                AND ST_Overlaps(a.geom, b.geom)
                AND ST_Area(ST_Intersection(a.geom, b.geom))
                    > :tol * LEAST(ST_Area(a.geom), ST_Area(b.geom))
              LIMIT 1",
            ['tol' => self::OVERLAP_TOLERANCE],
        );
        if (false !== $overlap) {
            throw new \InvalidArgumentException(sprintf('Region overlap: "%s" and "%s" share more than a boundary sliver at the same admin level within one country; same-level regions must tessellate, not overlap (map-and-search.md §4.5; cross-level containment is expected, map-and-search.md §4.5).', $overlap['a'], $overlap['b']));
        }
    }
}
