<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Countries;

/**
 * Region pages: operational regions grouped by country. Maturity and stewardship are independent facts, not one ladder.
 *
 * @see docs/specs/map-and-search.md §4.5
 *
 * @api
 */
final class RegionDirectoryProvider
{
    public function __construct(private readonly Connection $db)
    {
    }

    private const BASE_SELECT = '
        SELECT r.id, r.slug, r.area_km2, r.country_code, r.default_map_mode,
               EXISTS (SELECT 1 FROM moderator_area ma WHERE ma.region_id = r.id)              AS curator_local,
               EXISTS (SELECT 1 FROM moderator_area ma WHERE ma.country_code = r.country_code) AS curator_national,
               COALESCE(iv.n, 0) AS items_verified,
               COALESCE(rt.n, 0) AS routes,
               wc.name AS country_name_en, COALESCE(cont.name, \'\') AS continent_name,
               COALESCE(cont.code, \'\') AS continent_code
        FROM region r
        JOIN world_country wc ON wc.iso2 = r.country_code
        LEFT JOIN world_continent cont ON cont.id = wc.continent_id
        LEFT JOIN (SELECT region_id, COUNT(*) AS n FROM item
                   WHERE state = \'verified\' GROUP BY region_id) iv ON iv.region_id = r.id
        LEFT JOIN (SELECT region_id, COUNT(*) AS n FROM recommended_route
                   WHERE state IN %s GROUP BY region_id) rt ON rt.region_id = r.id
        WHERE r.geom IS NOT NULL AND r.country_code <> \'\'';

    /** @return list<array<string, mixed>> */
    public function directory(string $locale): array
    {
        $rows = $this->db->fetchAllAssociative(
            sprintf(self::BASE_SELECT, ItemState::servedSqlTuple())
            .' AND '.OperationalRegions::predicate('r')
            .' ORDER BY continent_name, country_name_en, r.slug',
        );

        $countries = [];
        foreach ($rows as $row) {
            $cc = (string) $row['country_code'];
            $countries[$cc] ??= [
                'code' => $cc,
                'name' => Countries::exists($cc) ? Countries::getName($cc, $locale) : (string) $row['country_name_en'],
                'flag' => 'flags/'.strtolower($cc).'.svg',
                'continentCode' => (string) $row['continent_code'],
                'continentName' => (string) $row['continent_name'],
                'regions' => [],
            ];
            $countries[$cc]['regions'][] = $this->shape($row);
        }

        // Locale collation, not byte order — accents must sort correctly.
        $collator = new \Collator($locale);
        $list = array_values($countries);
        usort($list, static function (array $a, array $b) use ($collator): int {
            $continentCmp = $collator->compare($a['continentName'], $b['continentName']);
            if (0 !== $continentCmp) {
                return $continentCmp;
            }

            return $collator->compare($a['name'], $b['name']);
        });

        return $list;
    }

    /** Does coverage_poi exist? Memoized per request. */
    private ?bool $coverageTable = null;

    private function hasCoverageTable(): bool
    {
        // to_regclass, not Doctrine schema manager — schema_filter hides coverage_poi from tablesExist().
        return $this->coverageTable ??= null !== $this->db->fetchOne("SELECT to_regclass('public.coverage_poi')");
    }

    /**
     * Who looks after this region. Naming a curator is opt-in (`public_profile`); never fall back to rider#.
     *
     * @return list<array{name: string, uuid: ?string, scope: string}>
     */
    private function curators(int $regionId, string $countryCode): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT u.display_name, u.public_profile, u.uuid,
                    CASE WHEN ma.region_id IS NOT NULL THEN 'local' ELSE 'country' END AS scope
               FROM moderator_area ma
               JOIN users u ON u.id = ma.user_id
              WHERE ma.region_id = :rid OR ma.country_code = :cc
           ORDER BY scope, u.display_name",
            ['rid' => $regionId, 'cc' => $countryCode],
        );

        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            // One person may hold both a region and their country; name them once.
            $uuid = (string) ($r['uuid'] ?? '');
            if ('' !== $uuid && isset($seen[$uuid])) {
                continue;
            }
            $seen[$uuid] = true;
            $public = (bool) ($r['public_profile'] ?? false);
            $name = trim((string) ($r['display_name'] ?? ''));
            $out[] = [
                'name' => $public && '' !== $name ? $name : '',
                'uuid' => $public && '' !== $uuid ? $uuid : null,
                'scope' => (string) $r['scope'],
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function region(string $slug, string $locale): ?array
    {
        $row = $this->db->fetchAssociative(
            sprintf(self::BASE_SELECT, ItemState::servedSqlTuple())
            .' AND r.slug = :slug AND '.OperationalRegions::predicate('r'),
            ['slug' => $slug],
        );
        if (false === $row) {
            return null;
        }

        /* Two numbers per kind, never a sum: verified ≠ coverage_poi. */
        /* SERVED states for "what riders find", not verified-only. Hero/maturity keep the stricter reading. */
        $verifiedRows = $this->db->fetchAllKeyValue(
            'SELECT letter, COUNT(*) FROM item WHERE region_id = :id AND state IN '
            .ItemState::servedSqlTuple().' GROUP BY letter ORDER BY letter',
            ['id' => (int) $row['id']],
        );
        /* Probe existence first: a failed SELECT aborts the whole transaction. */
        $coverageRows = $this->hasCoverageTable()
            ? $this->db->fetchAllKeyValue(
                'SELECT letter, COUNT(*) FROM coverage_poi WHERE region_id = :id GROUP BY letter ORDER BY letter',
                ['id' => (int) $row['id']],
            )
            : [];

        $byKind = [];
        foreach (ItemType::cases() as $type) {
            $verifiedN = (int) ($verifiedRows[$type->letter()] ?? 0);
            $coverageN = (int) ($coverageRows[$type->letter()] ?? 0);
            // A kind with neither is absent; coverage-only still belongs.
            if ($verifiedN > 0 || $coverageN > 0) {
                $byKind[] = [
                    'labelKey' => $type->labelKey(),
                    'count' => $verifiedN,
                    'coverage' => $coverageN,
                ];
            }
        }

        $cc = (string) $row['country_code'];

        /* Wikipedia lead for this locale only — the list page never renders it. Attribution must travel with the extract. */
        /* Curator override beats harvest for that locale; RegionLead owns credit. */
        $ctx = $this->db->fetchAssociative(
            'SELECT context, context_curated FROM region WHERE id = :id',
            ['id' => (int) $row['id']],
        );
        $context = RegionLead::resolve(
            self::decodeJson(\is_array($ctx) ? $ctx['context'] : null),
            self::decodeJson(\is_array($ctx) ? $ctx['context_curated'] : null),
            $locale,
        );

        return $this->shape($row) + [
            'context' => $context,
            // The silhouette needs the row id to read `region.outline`.
            'id' => (int) $row['id'],
            'curators' => $this->curators((int) $row['id'], $cc),
            'countryCode' => $cc,
            'countryName' => Countries::exists($cc) ? Countries::getName($cc, $locale) : (string) $row['country_name_en'],
            'flag' => 'flags/'.strtolower($cc).'.svg',
            'byKind' => $byKind,
            /* Hero coverage total, same three figures as /coverage. Zero when the pipeline table is absent. */
            'coveragePois' => array_sum(array_map('intval', $coverageRows)),
        ];
    }

    /** @param array<string, mixed> $row
     * @return array{slug: string, areaKm2: ?float, tier: string, stewardship: string, itemsVerified: int, routes: int} */
    private function shape(array $row): array
    {
        $verified = (int) $row['items_verified'];

        return [
            'slug' => (string) $row['slug'],
            'areaKm2' => null === $row['area_km2'] ? null : (float) $row['area_km2'],
            // Established supersedes growing. docs/specs/map-and-search.md §4.2
            'tier' => match (true) {
                'curated' === $row['default_map_mode'] => 'established',
                $verified > 0 => 'growing',
                default => 'onboarded',
            },
            // Local cover wins over national.
            'stewardship' => match (true) {
                (bool) $row['curator_local'] => 'curated',
                (bool) $row['curator_national'] => 'countrywide',
                default => 'none',
            },
            'itemsVerified' => $verified,
            'routes' => (int) $row['routes'],
        ];
    }

    /**
     * JSONB as an array, or null. Malformed JSON must not 500 a public page.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeJson(mixed $raw): ?array
    {
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);

        /* @var array<string, mixed>|null */
        return \is_array($decoded) ? $decoded : null;
    }
}
