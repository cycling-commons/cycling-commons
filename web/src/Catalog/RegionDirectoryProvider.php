<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Countries;

/**
 * Read side of the DB-driven region pages:
 * operational regions grouped
 * by country/continent with the public status tier and modest live stats.
 * Per-request raw DBAL like RegionRegistryProvider — ~32 regions, indexed
 * COUNTs; deliberately no cache (owner decision §2.2).
 *
 * A region's public status is TWO independent facts, not one ladder:
 *
 *  - **maturity** — how much rider knowledge the region holds:
 *    `onboarded` → `growing` (anything verified) → `established`
 *    (`default_map_mode`, which the curator desk will only let a moderator raise
 *    once the region passes a readiness count of curated places and
 *    rider-backed routes — so the top rung is earned by riders, not declared).
 *  - **stewardship** — who is looking after it: `curated` with its own curator,
 *    `countrywide` when only a country-scoped moderator covers it, else `none`.
 *
 * These were ONE field derived from the region's default mode, and the legend then
 * described it as "a curator maintains this region" — which that flag does not
 * mean. A busy region can have nobody looking after it and a curated one can be
 * empty; collapsing the two makes both unsayable.
 *
 * CuratedReadiness (25/3/5) stays a curator-desk signal and is not consulted here.
 *
 * Counts: items are VERIFIED-only (the editorial signal); routes are the
 * SERVED states (unverified+verified) — what a rider actually sees on the
 * map, where community-proposed routes serve before curator verification.
 *
 * @api Consumed by PageController::regions / PageController::regionDetail.
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

        // Stable order: continent name, then the LOCALIZED country name (the
        // SQL ordered by the English name; FR/NL/DE readers sort their own).
        // Collator applies the request locale's actual collation rules
        // (accented names sort correctly); a plain <=> comparison of the PHP
        // strings sorts by raw byte order, which misplaces accents. The
        // constructor (not the ::create() factory) is used like
        // SubmissionQueue's collator: it never fails even for a garbage
        // locale string (ICU falls back to root collation), so there is no
        // failure mode to guard against.
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

        $byKind = [];
        $kindRows = $this->db->fetchAllKeyValue(
            'SELECT letter, COUNT(*) FROM item WHERE region_id = :id AND state = \'verified\' GROUP BY letter ORDER BY letter',
            ['id' => (int) $row['id']],
        );
        foreach (ItemType::cases() as $type) {
            $n = (int) ($kindRows[$type->letter()] ?? 0);
            if ($n > 0) {
                $byKind[] = ['labelKey' => $type->labelKey(), 'count' => $n];
            }
        }

        $cc = (string) $row['country_code'];

        return $this->shape($row) + [
            'countryCode' => $cc,
            'countryName' => Countries::exists($cc) ? Countries::getName($cc, $locale) : (string) $row['country_name_en'],
            'flag' => 'flags/'.strtolower($cc).'.svg',
            'byKind' => $byKind,
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
            // The top rung supersedes: a region cannot be `established` without
            // having been `growing` first, because the desk gates the flag on a
            // readiness count (map-and-search.md §4.2).
            'tier' => match (true) {
                'curated' === $row['default_map_mode'] => 'established',
                $verified > 0 => 'growing',
                default => 'onboarded',
            },
            // Local cover wins over national: a region with its own curator is
            // not merely "inside a country somebody watches".
            'stewardship' => match (true) {
                (bool) $row['curator_local'] => 'curated',
                (bool) $row['curator_national'] => 'countrywide',
                default => 'none',
            },
            'itemsVerified' => $verified,
            'routes' => (int) $row['routes'],
        ];
    }
}
