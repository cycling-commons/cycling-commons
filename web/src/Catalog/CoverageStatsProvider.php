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
     * `poisPerKm2` is that same density as a NUMBER, because a bar without one
     * is a shape: "Germany is longer than Luxembourg" was all this column could
     * say, and the figure it was scaled by went unprinted.
     *
     * `sources` breaks the catalog count down by where each row came from,
     * bucketed from ItemSource (osm · partner · riders · derived). This is the
     * column with a real mix — the reference layer is OSM top to bottom, while
     * the catalog already holds imported partner data (Wallonia PIVOT stays and
     * drinking-water taps) beside rider contributions and pipeline-derived
     * rows, and a bare total hides all of it.
     *
     * @return list<array{code:string, name:string, flag:string, regions:int,
     *                    areaKm2:float, coveragePois:int, poisPerKm2:float,
     *                    items:int, itemsVerified:int, sources:list<array{key:string, count:int}>,
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

        $sources = $this->itemSourcesByCountry();
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
                'areaKm2' => $area,
                'coveragePois' => $n,
                'poisPerKm2' => $density,
                'items' => (int) $row['items'],
                'itemsVerified' => (int) $row['verified'],
                'sources' => $sources[$cc] ?? [],
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
     * Catalog items per country, bucketed by provenance.
     *
     * The six ItemSource values are collapsed to four the page can say out
     * loud: `osm` mirrors OpenStreetMap, `partner` is data imported from an
     * open dataset somebody else maintains (PIVOT, Wikidata), `riders` is
     * contributed here (`user`, and `manual` — which the enum already defines
     * as a hand-added row treated like a contribution), `derived` is computed
     * by the pipeline. Anything unrecognised falls into `derived` rather than
     * vanishing, so a new source shows up as an unexplained number instead of
     * silently shrinking the total.
     *
     * Buckets are emitted in a fixed order and zeroes are dropped, so a country
     * with only OSM rows shows one word rather than four with three noughts.
     *
     * @return array<string, list<array{key:string, count:int}>>
     */
    private function itemSourcesByCountry(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT country_code AS cc, source, COUNT(*) AS n FROM item
              WHERE state IN '.ItemState::servedSqlTuple()." AND country_code <> ''
              GROUP BY country_code, source",
        );

        $bucketOf = [
            ItemSource::Osm->value => 'osm',
            ItemSource::Pivot->value => 'partner',
            ItemSource::Wikidata->value => 'partner',
            ItemSource::User->value => 'riders',
            ItemSource::Manual->value => 'riders',
            ItemSource::Auto->value => 'derived',
        ];

        $totals = [];
        foreach ($rows as $row) {
            $cc = (string) $row['cc'];
            $bucket = $bucketOf[(string) $row['source']] ?? 'derived';
            $totals[$cc][$bucket] = ($totals[$cc][$bucket] ?? 0) + (int) $row['n'];
        }

        $out = [];
        foreach ($totals as $cc => $byBucket) {
            foreach (['osm', 'partner', 'riders', 'derived'] as $bucket) {
                if (($byBucket[$bucket] ?? 0) > 0) {
                    $out[$cc][] = ['key' => $bucket, 'count' => $byBucket[$bucket]];
                }
            }
        }

        return $out;
    }

    /**
     * POI counts per country, `[]` when the pipeline table does not exist
     * yet. Rows with a NULL country (pre-normalization harvests) are ignored
     * for the per-country table but absent from the KPI sum too — the page
     * shows what is attributable, not a number that cannot be broken down.
     *
     * Not broken down by provenance, because this layer has exactly one: every
     * row is OSM-derived, which is why `coverage_poi` carries `ref`,
     * `osm_version` and `osm_ts` and no source column at all
     * (coverage-provider.md §2). Partner datasets do not land here — they are
     * imported as catalog `item` rows with their own source, which is where
     * `itemSourcesByCountry()` finds them.
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
