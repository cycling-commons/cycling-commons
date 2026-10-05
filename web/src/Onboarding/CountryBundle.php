<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding;

use Doctrine\DBAL\Connection;

/**
 * A live country as one JSON document, so production receives the exact rows staging produced.
 *
 * @api
 */
final class CountryBundle
{
    public const int VERSION = 1;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{version: int, country: array<string, mixed>, extracts: list<string>, regions: list<array<string, mixed>>} */
    public function export(string $cc): array
    {
        $c = $this->db->fetchAssociative(
            'SELECT code, name, subtype, bbox::text AS bbox, labels::text AS labels, array_to_json(timezones)::text AS timezones, overture_release FROM country WHERE code = :cc',
            ['cc' => $cc],
        ) ?: [];
        $regions = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT slug, iso_code, name, labels::text AS labels, admin_level, area_km2, ST_AsGeoJSON(geom, 15) AS geojson
               FROM region WHERE country_code = :cc AND geom IS NOT NULL ORDER BY admin_level DESC, slug',
            ['cc' => $cc],
        ) as $r) {
            $regions[] = [
                'slug' => (string) $r['slug'],
                'iso_code' => null === $r['iso_code'] ? null : (string) $r['iso_code'],
                'name' => (string) $r['name'],
                'labels' => self::labelObject($r['labels']),
                'admin_level' => (int) $r['admin_level'],
                'area_km2' => null === $r['area_km2'] ? 0.0 : (float) $r['area_km2'],
                'geometry' => json_decode((string) $r['geojson'], true, 512, \JSON_THROW_ON_ERROR),
            ];
        }

        return [
            'version' => self::VERSION,
            'country' => [
                'code' => trim((string) ($c['code'] ?? $cc)),
                'name' => (string) ($c['name'] ?? ''),
                'subtype' => (string) ($c['subtype'] ?? ''),
                'bbox' => null === ($c['bbox'] ?? null) ? null : json_decode((string) $c['bbox'], true, 512, \JSON_THROW_ON_ERROR),
                'labels' => self::labelObject($c['labels'] ?? null),
                'timezones' => json_decode((string) ($c['timezones'] ?? '[]'), true, 512, \JSON_THROW_ON_ERROR),
                'overture_release' => $c['overture_release'] ?? null,
            ],
            'extracts' => (new Countries($this->db))->extracts($cc),
            'regions' => $regions,
        ];
    }

    /**
     * @return array{country: array{code: string, name: string, subtype: string, bbox: list<float>|null, labels: array<string, string>, timezones: list<string>, overture_release: string|null}, extracts: list<string>, regions: list<array{slug: string, iso_code: string|null, name: string, labels: array<string, string>, admin_level: int, area_km2: float, geometry: array<string, mixed>}>}
     *
     * @throws \InvalidArgumentException
     */
    public static function validate(mixed $bundle): array
    {
        $fail = static fn (string $why): \InvalidArgumentException => new \InvalidArgumentException('Not a country bundle: '.$why.'.');
        if (!\is_array($bundle) || self::VERSION !== ($bundle['version'] ?? null)) {
            throw $fail('version 1 expected');
        }
        $c = $bundle['country'] ?? null;
        if (!\is_array($c) || !\is_string($c['code'] ?? null) || !\is_string($c['name'] ?? null) || '' === $c['name']
            || !\is_string($c['subtype'] ?? null) || !self::isLabelObject($c['labels'] ?? null)) {
            throw $fail('country needs code, name, subtype and labels');
        }
        $code = Countries::code($c['code']);
        $bbox = $c['bbox'] ?? null;
        if (null !== $bbox && (!\is_array($bbox) || 4 !== \count($bbox) || [] !== array_filter($bbox, static fn ($v): bool => !\is_int($v) && !\is_float($v)))) {
            throw $fail('bbox must be four numbers or null');
        }
        $timezones = $c['timezones'] ?? null;
        if (!\is_array($timezones) || !array_is_list($timezones) || [] !== array_filter($timezones, static fn ($z): bool => !\is_string($z) || '' === $z)) {
            throw $fail('timezones must be a list of IANA zone names');
        }
        $extracts = $bundle['extracts'] ?? null;
        if (!\is_array($extracts) || [] === $extracts || [] !== array_filter($extracts, static fn ($e): bool => !\is_string($e) || '' === $e)) {
            throw $fail('extracts must be a non-empty list of Geofabrik slugs');
        }
        $regions = $bundle['regions'] ?? null;
        if (!\is_array($regions) || [] === $regions) {
            throw $fail('regions must not be empty');
        }
        $out = [];
        foreach ($regions as $r) {
            if (!\is_array($r) || !\is_string($r['slug'] ?? null) || 1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $r['slug'])
                || !\is_string($r['name'] ?? null) || !\is_int($r['admin_level'] ?? null) || !is_numeric($r['area_km2'] ?? null)
                || !self::isLabelObject($r['labels'] ?? null) || !\is_array($r['geometry'] ?? null)
                || !\in_array($r['geometry']['type'] ?? null, ['Polygon', 'MultiPolygon'], true)
                || (null !== ($r['iso_code'] ?? null) && !\is_string($r['iso_code']))) {
                throw $fail('a region needs slug, name, admin_level, area_km2, labels and a (Multi)Polygon geometry');
            }
            $out[] = [
                'slug' => $r['slug'], 'iso_code' => $r['iso_code'] ?? null, 'name' => $r['name'],
                'labels' => array_map('strval', $r['labels']), 'admin_level' => $r['admin_level'],
                'area_km2' => (float) $r['area_km2'], 'geometry' => $r['geometry'],
            ];
        }

        return [
            'country' => [
                'code' => $code, 'name' => $c['name'], 'subtype' => $c['subtype'],
                'bbox' => null === $bbox ? null : array_map('floatval', array_values($bbox)),
                'labels' => array_map('strval', $c['labels']),
                'timezones' => array_map('strval', $timezones),
                'overture_release' => \is_string($c['overture_release'] ?? null) ? $c['overture_release'] : null,
            ],
            'extracts' => array_values(array_map('strval', $extracts)),
            'regions' => $out,
        ];
    }

    /**
     * Writes a validated bundle as a plan (status planned), replacing an earlier plan, never a seeded or live country.
     *
     * @param array{country: array{code: string, name: string, subtype: string, bbox: list<float>|null, labels: array<string, string>, timezones: list<string>, overture_release: string|null}, extracts: list<string>, regions: list<array{slug: string, iso_code: string|null, name: string, labels: array<string, string>, admin_level: int, area_km2: float, geometry: array<string, mixed>}>} $bundle
     *
     * @throws \InvalidArgumentException when the country is already seeded or live here
     */
    public function importPlan(array $bundle): int
    {
        $c = $bundle['country'];
        $json = static fn (mixed $v): string => json_encode($v, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        return $this->db->transactional(function (Connection $db) use ($bundle, $c, $json): int {
            // The WHERE re-checks status inside the transaction, so a concurrent seed cannot be overwritten.
            $written = $db->executeStatement(
                "INSERT INTO country (code, name, subtype, bbox, labels, timezones, status, overture_release, planned_at)
                 VALUES (:code, :name, :subtype, CAST(:bbox AS jsonb), CAST(:labels AS jsonb),
                         ARRAY(SELECT jsonb_array_elements_text(CAST(:timezones AS jsonb))), 'planned', :release, NOW())
                 ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, subtype = EXCLUDED.subtype, bbox = EXCLUDED.bbox,
                   labels = EXCLUDED.labels, timezones = EXCLUDED.timezones, status = 'planned',
                   overture_release = EXCLUDED.overture_release, planned_at = NOW(), seeded_at = NULL, live_at = NULL
                 WHERE country.status = 'planned'",
                ['code' => $c['code'], 'name' => $c['name'], 'subtype' => $c['subtype'],
                    'bbox' => null === $c['bbox'] ? null : $json($c['bbox']), 'labels' => $json((object) $c['labels']),
                    'timezones' => $json($c['timezones']), 'release' => $c['overture_release']],
            );
            if (0 === $written) {
                throw new \InvalidArgumentException(\sprintf('%s is already seeded or live here; its slugs are frozen identity.', $c['code']));
            }
            $db->executeStatement('DELETE FROM country_plan_region WHERE country_code = :cc', ['cc' => $c['code']]);
            $db->executeStatement('DELETE FROM country_extract WHERE country_code = :cc', ['cc' => $c['code']]);
            foreach ($bundle['extracts'] as $slug) {
                $db->executeStatement('INSERT INTO country_extract (slug, country_code) VALUES (:slug, :cc)', ['slug' => $slug, 'cc' => $c['code']]);
            }
            foreach ($bundle['regions'] as $r) {
                $db->executeStatement(
                    'INSERT INTO country_plan_region (country_code, slug, iso_code, name, labels, admin_level, area_km2, geom)
                     VALUES (:cc, :slug, :iso, :name, CAST(:labels AS jsonb), :admin, :area, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326)))',
                    ['cc' => $c['code'], 'slug' => $r['slug'], 'iso' => $r['iso_code'], 'name' => $r['name'],
                        'labels' => $json((object) $r['labels']), 'admin' => $r['admin_level'], 'area' => $r['area_km2'],
                        'geom' => $json($r['geometry'])],
                );
            }

            return \count($bundle['regions']);
        });
    }

    /** An empty array or a string-keyed array; a JSON list is not a label object. */
    private static function isLabelObject(mixed $v): bool
    {
        return \is_array($v) && ([] === $v || !array_is_list($v));
    }

    /** Non-object JSON reads as no labels; always returns something that encodes as a JSON object. */
    private static function labelObject(mixed $raw): \stdClass
    {
        $v = \is_string($raw) ? json_decode($raw, true) : null;

        return (object) (self::isLabelObject($v) ? $v : []);
    }
}
