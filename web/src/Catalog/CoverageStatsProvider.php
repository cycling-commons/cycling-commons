<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Countries;

/**
 * Read side of the DB-driven /coverage page: live KPIs, per-country volumes,
 * and the thinnest catalog categories. Replaces the demo's hardcoded numbers
 * — every figure here is a real COUNT, and countries appear only once they
 * are operational (same predicate discipline as RegionDirectoryProvider:
 * geom present, country code set, L2 infrastructure rows excluded).
 *
 * coverage_poi is pipeline-owned DDL (coverage-provider.md §2) and absent on
 * a fresh contributor stack until the harvest has run, so every read of it is
 * guarded by to_regclass() and degrades to zero rather than 500ing the page.
 *
 * Per-request raw DBAL, deliberately no cache — the same posture as
 * RegionDirectoryProvider;
 * the biggest COUNT (coverage_poi, ~430k rows) is still milliseconds.
 *
 * @api Consumed by PageController::coverage.
 */
final class CoverageStatsProvider
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{coveragePois:int, items:int, itemsVerified:int, routes:int, countries:int} */
    public function kpis(): array
    {
        /** @var array<string, int|string> $row */
        $row = (array) $this->db->fetchAssociative(
            'SELECT
                (SELECT COUNT(*) FROM item WHERE state IN '.ItemState::servedSqlTuple().') AS items,
                (SELECT COUNT(*) FROM item WHERE state = \'verified\') AS items_verified,
                (SELECT COUNT(*) FROM recommended_route WHERE state IN '.ItemState::servedSqlTuple().') AS routes,
                (SELECT COUNT(DISTINCT r.country_code) FROM region r
                  WHERE r.geom IS NOT NULL AND r.country_code <> \'\'
                    AND '.OperationalRegions::predicate('r').') AS countries',
        );

        return [
            'coveragePois' => array_sum($this->poisByCountry()),
            'items' => (int) $row['items'],
            'itemsVerified' => (int) $row['items_verified'],
            'routes' => (int) $row['routes'],
            'countries' => (int) $row['countries'],
        ];
    }

    /**
     * Operational countries with their real volumes, biggest reference base
     * first. `share` pre-scales the table's bar as DENSITY (POIs per km² of
     * onboarded area, percent of the densest country) — an absolute-volume
     * bar would dwarf every small country under the biggest one forever
     * (owner correction 2026-07-30: Luxembourg vs Germany), while density is
     * size-fair. The printed number stays the absolute count.
     *
     * @return list<array{code:string, name:string, flag:string, regions:int,
     *                    coveragePois:int, items:int, itemsVerified:int,
     *                    routes:int, share:int}>
     */
    public function countries(string $locale): array
    {
        $served = ItemState::servedSqlTuple();
        /** @var list<array<string, int|string>> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT r.country_code AS cc, COUNT(*) AS regions,
                    COALESCE(SUM(r.area_km2), 0) AS area_km2,
                    COALESCE(MAX(it.n), 0) AS items, COALESCE(MAX(iv.n), 0) AS verified,
                    COALESCE(MAX(rt.n), 0) AS routes
               FROM region r
               LEFT JOIN (SELECT country_code, COUNT(*) AS n FROM item
                           WHERE state IN '.$served.' GROUP BY country_code) it
                      ON it.country_code = r.country_code
               LEFT JOIN (SELECT country_code, COUNT(*) AS n FROM item
                           WHERE state = \'verified\' GROUP BY country_code) iv
                      ON iv.country_code = r.country_code
               LEFT JOIN (SELECT r2.country_code, COUNT(*) AS n
                            FROM recommended_route rr JOIN region r2 ON r2.id = rr.region_id
                           WHERE rr.state IN '.$served.' GROUP BY r2.country_code) rt
                      ON rt.country_code = r.country_code
              WHERE r.geom IS NOT NULL AND r.country_code <> \'\'
                AND '.OperationalRegions::predicate('r').'
              GROUP BY r.country_code',
        );

        $pois = $this->poisByCountry();
        $maxDensity = 0.0;
        foreach ($rows as $row) {
            $area = (float) $row['area_km2'];
            if ($area > 0) {
                $maxDensity = max($maxDensity, (float) ($pois[(string) $row['cc']] ?? 0) / $area);
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $cc = (string) $row['cc'];
            $n = $pois[$cc] ?? 0;
            $area = (float) $row['area_km2'];
            $density = $area > 0 ? (float) $n / $area : 0.0;
            $out[] = [
                'code' => $cc,
                'name' => Countries::exists($cc) ? Countries::getName($cc, $locale) : $cc,
                'flag' => 'flags/'.strtolower($cc).'.svg',
                'regions' => (int) $row['regions'],
                'coveragePois' => $n,
                'items' => (int) $row['items'],
                'itemsVerified' => (int) $row['verified'],
                'routes' => (int) $row['routes'],
                'share' => $maxDensity > 0 ? (int) round(100.0 * $density / $maxDensity) : 0,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['coveragePois'], $a['code']] <=> [$a['coveragePois'], $b['code']]);

        return $out;
    }

    /**
     * The three catalog categories with the fewest publicly-served items —
     * the honest version of the demo's invented "biggest gaps" cards.
     * Routes (K) are excluded: their volume is governed by the per-region
     * cap (route-domain.md §5), not by coverage.
     *
     * @return list<array{labelKey:string, count:int}>
     */
    public function thinnestCategories(): array
    {
        /** @var array<string, int|string> $byLetter */
        $byLetter = $this->db->fetchAllKeyValue(
            'SELECT letter, COUNT(*) FROM item
              WHERE state IN '.ItemState::servedSqlTuple().'
              GROUP BY letter',
        );

        $counts = [];
        foreach (ItemType::cases() as $type) {
            if (ItemType::QualityRides === $type) {
                continue;
            }
            $counts[] = ['labelKey' => $type->labelKey(), 'count' => (int) ($byLetter[$type->letter()] ?? 0)];
        }
        usort($counts, static fn (array $a, array $b): int => [$a['count'], $a['labelKey']] <=> [$b['count'], $b['labelKey']]);

        return \array_slice($counts, 0, 3);
    }

    /**
     * POI counts per country, `[]` when the pipeline table does not exist
     * yet. Rows with a NULL country (pre-normalization harvests) are ignored
     * for the per-country table but absent from the KPI sum too — the page
     * shows what is attributable, not a number that cannot be broken down.
     *
     * @return array<string, int>
     */
    private function poisByCountry(): array
    {
        // to_regclass returns the relation name, or SQL NULL when it does
        // not exist — a null/false fetchOne() means "pipeline never ran".
        $exists = $this->db->fetchOne("SELECT to_regclass('public.coverage_poi')");
        if (null === $exists || false === $exists) {
            return [];
        }

        /** @var array<string, int|string> $rows */
        $rows = $this->db->fetchAllKeyValue(
            "SELECT country_code, COUNT(*) FROM coverage_poi
              WHERE country_code IS NOT NULL AND country_code <> ''
              GROUP BY country_code",
        );

        $out = [];
        foreach ($rows as $cc => $n) {
            $out[(string) $cc] = (int) $n;
        }

        return $out;
    }
}
