<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding;

use App\Catalog\OperationalRegions;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * DBAL access to onboarded countries, their plan rows and their neighbours.
 *
 * @api
 */
final class Countries
{
    /** Degrees: just over the harvest's 0.1026° extract buffer. Same as pipeline/onboarding/neighbours.py. */
    public const float NEIGHBOUR_DEG = 0.11;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @throws \InvalidArgumentException */
    public static function code(string $raw): string
    {
        $cc = strtoupper(trim($raw));
        if (1 !== preg_match('/^[A-Z]{2}$/', $cc)) {
            throw new \InvalidArgumentException(sprintf('Not an ISO 3166-1 alpha-2 code: "%s".', $raw));
        }

        return $cc;
    }

    public function status(string $cc): ?CountryStatus
    {
        $status = $this->db->fetchOne('SELECT status FROM country WHERE code = :cc', ['cc' => $cc]);

        return \is_string($status) ? CountryStatus::from($status) : null;
    }

    /** @return list<string> */
    public function extracts(string $cc): array
    {
        /** @var list<string> $slugs */
        $slugs = $this->db->fetchFirstColumn('SELECT slug FROM country_extract WHERE country_code = :cc ORDER BY slug', ['cc' => $cc]);

        return $slugs;
    }

    /** @return list<array{slug: string, iso_code: string|null, name: string, labels: array<string, string>, admin_level: int, area_km2: float, geojson: string}> */
    public function planRegions(string $cc): array
    {
        $out = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT slug, iso_code, name, labels::text AS labels, admin_level, area_km2, ST_AsGeoJSON(geom, 17) AS geojson
               FROM country_plan_region WHERE country_code = :cc ORDER BY admin_level DESC, slug',
            ['cc' => $cc],
        ) as $r) {
            $labels = json_decode((string) $r['labels'], true, 512, \JSON_THROW_ON_ERROR);
            $out[] = [
                'slug' => (string) $r['slug'],
                'iso_code' => null === $r['iso_code'] ? null : (string) $r['iso_code'],
                'name' => (string) $r['name'],
                'labels' => \is_array($labels) ? array_map('strval', $labels) : [],
                'admin_level' => (int) $r['admin_level'],
                'area_km2' => (float) $r['area_km2'],
                'geojson' => (string) $r['geojson'],
            ];
        }

        return $out;
    }

    /** @return list<string> seeded or live countries whose operational regions lie within NEIGHBOUR_DEG of $cc's regions */
    public function neighbours(string $cc): array
    {
        /** @var list<string> $codes */
        $codes = $this->db->fetchFirstColumn(
            "SELECT DISTINCT r.country_code FROM region r
               JOIN country c ON c.code = r.country_code AND c.status IN ('seeded', 'live')
              WHERE r.country_code <> :cc AND r.geom IS NOT NULL
                AND ".OperationalRegions::predicate('r').'
                AND ST_DWithin(r.geom, (SELECT ST_Union(geom) FROM region WHERE country_code = :cc AND geom IS NOT NULL), :deg)
              ORDER BY 1',
            ['cc' => $cc, 'deg' => self::NEIGHBOUR_DEG],
        );

        return array_map('trim', $codes);
    }

    /**
     * @param list<string> $slugs
     *
     * @return list<string> those slugs already held by a region of another country (region.slug is unique)
     */
    public function foreignSlugs(string $cc, array $slugs): array
    {
        /** @var list<string> $taken */
        $taken = $this->db->fetchFirstColumn(
            'SELECT slug FROM region WHERE slug IN (:slugs) AND country_code IS DISTINCT FROM :cc ORDER BY slug',
            ['slugs' => $slugs, 'cc' => $cc],
            ['slugs' => ArrayParameterType::STRING],
        );

        return $taken;
    }

    public function regionCount(string $cc): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM region WHERE country_code = :cc', ['cc' => $cc]);
    }

    /** @throws \InvalidArgumentException when $cc is not planned or seeded (a live country is never demoted) */
    public function markSeeded(string $cc): void
    {
        $changed = $this->db->executeStatement(
            "UPDATE country SET status = 'seeded', seeded_at = COALESCE(seeded_at, NOW()) WHERE code = :cc AND status IN ('planned', 'seeded')",
            ['cc' => $cc],
        );
        if (1 !== (int) $changed) {
            throw new \InvalidArgumentException(sprintf('%s is no longer planned or seeded; nothing was written.', $cc));
        }
    }

    /** Only while empty: the seeded lists keep their aliases and an imported bundle keeps staging's list. */
    public function fillTimezones(string $cc): void
    {
        $this->db->executeStatement(
            'UPDATE country SET timezones = ARRAY(SELECT jsonb_array_elements_text(CAST(:zones AS jsonb)))
              WHERE code = :cc AND cardinality(timezones) = 0',
            ['cc' => $cc, 'zones' => json_encode(\DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $cc), \JSON_THROW_ON_ERROR)],
        );
    }

    public function markLive(string $cc): bool
    {
        return 1 === $this->db->executeStatement(
            "UPDATE country SET status = 'live', live_at = NOW() WHERE code = :cc AND status = 'seeded'",
            ['cc' => $cc],
        );
    }
}
