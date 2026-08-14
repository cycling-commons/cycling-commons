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

    /**
     * Does the pipeline-owned coverage table exist yet?
     *
     * Memoized per instance: the provider is a per-request service, so this is
     * one information_schema lookup per page rather than one per region.
     */
    private ?bool $coverageTable = null;

    private function hasCoverageTable(): bool
    {
        // `to_regclass`, NOT the schema manager. config/packages/doctrine.yaml
        // sets `schema_filter: '~^(?!topology\.|coverage_)~'` so that Doctrine's
        // schema tooling never touches the pipeline's tables — and
        // createSchemaManager()->tablesExist() honours that filter, so it
        // answers FALSE for a coverage_poi that is sitting right there with two
        // million rows in it. That silently zeroed every coverage count on the
        // region pages, which looked like the feature had never been built.
        // to_regclass asks Postgres directly, returns NULL rather than raising
        // (so it cannot poison the transaction), and is unaffected by any
        // Doctrine configuration.
        return $this->coverageTable ??= null !== $this->db->fetchOne("SELECT to_regclass('public.coverage_poi')");
    }

    /**
     * Who looks after this region, by name where they have said we may.
     *
     * NAMING A CURATOR IS AN OPT-IN, and this is a public page, so the rule is
     * the strict one used on the moderation desks and the contributors wall:
     * `public_profile` is an explicit choice that already puts a rider's name
     * on `/riders/{uuid}`, and only that choice puts it here. A curator without
     * it is counted but not named — the region still says somebody looks after
     * it, which is the fact a visitor needs, without publishing who.
     *
     * The pseudonym (`rider#1a2b`) is deliberately NOT used as a fallback: it
     * is a moderation-desk device for telling two submitters apart, and on a
     * public page it would only look like a name that had been withheld.
     *
     * Local first, then country-wide: a region with its own curator is looked
     * after more closely than one covered from the capital, and the order says
     * so without needing a second label.
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

        /* TWO NUMBERS PER KIND, not one (owner, 2026-08-14: "What riders find
           here looks a bit minimal now as it does not mention the base OSM
           layer only the confirmed items").

           Wallonia read "Water & food 2" while the map there draws 1,650 water
           points, because this counted `item` rows in state `verified` and
           nothing else. That is the CURATED plane; the reference plane is
           `coverage_poi`, which is most of what a rider actually sees.
           Reporting only the first told a rider the region was nearly empty
           when the map is not.

           They stay two numbers rather than a sum: they mean different things.
           Verified is "somebody stood here and checked"; coverage is "OSM knows
           about this". Adding them would erase exactly the distinction the
           three view modes are built on. */
        $verifiedRows = $this->db->fetchAllKeyValue(
            'SELECT letter, COUNT(*) FROM item WHERE region_id = :id AND state = \'verified\' GROUP BY letter ORDER BY letter',
            ['id' => (int) $row['id']],
        );
        /* ASKED BEFORE QUERYING, not caught after. coverage_poi is
           pipeline-owned DDL and does not exist on a fresh contributor stack or
           in the test database, so this has to degrade to the verified counts
           alone rather than 500.
           A try/catch around the SELECT is the obvious shape and it does not
           work: Postgres aborts the entire transaction on a failed statement,
           so every later query in the same request fails too with "current
           transaction is aborted" — which is what the test suite showed,
           because DAMA wraps each test in one. Catching the exception left the
           page just as broken and hid the cause. */
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
            // A kind with neither is genuinely absent here and is left out; one
            // with only coverage still belongs, because a rider can ride to it.
            if ($verifiedN > 0 || $coverageN > 0) {
                $byKind[] = [
                    'labelKey' => $type->labelKey(),
                    'count' => $verifiedN,
                    'coverage' => $coverageN,
                ];
            }
        }

        $cc = (string) $row['country_code'];

        return $this->shape($row) + [
            // The silhouette needs the row id to read `region.outline`.
            'id' => (int) $row['id'],
            'curators' => $this->curators((int) $row['id'], $cc),
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
