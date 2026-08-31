<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Live /coverage page KPIs. Reads of `coverage_poi` are guarded by to_regclass() and degrade to zero.
 *
 * @see docs/specs/coverage-provider.md §9.1
 *
 * @api
 */
final class CoverageStatsProvider
{
    /**
     * How long, in seconds, the per-country POI counts stay cached.
     *
     * They change only when the pipeline harvests, which is not on a page
     * view's timescale. Ten minutes because the number is a headline figure on
     * a stats page, not a fact anybody acts on within the minute.
     */
    private const int POIS_TTL = 600;

    /**
     * Within one request, so kpis() and countries() do not both pay for it.
     *
     * @var array<string, int>|null
     */
    private ?array $poisMemo = null;

    public function __construct(
        private readonly Connection $db,
        private readonly CacheInterface $cache,
    ) {
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

    /** The two orders the table offers, and the default. */
    public const string SORT_DENSITY = 'density';
    public const string SORT_TOTAL = 'total';

    /**
     * Operational countries. `share` is density (POIs/km² vs densest), not absolute volume.
     *
     * Ordered here rather than in the browser. It used to be a client-side
     * toggle, which needed an inline script, which needed a CSP nonce, which is
     * the one thing a shared cache cannot hold (page-caching.md §3.2). Sorting
     * on the server makes the two orders two URLs, so both are cacheable and
     * both work without JavaScript. `$sort` is validated by the caller against
     * the two constants above; anything else falls back to density.
     *
     * @return list<array{code:string, name:string, flag:string, regions:int,
     *                    areaKm2:float, coveragePois:int, poisPerKm2:float,
     *                    items:int, itemsVerified:int, sources:list<array{key:string, count:int}>,
     *                    routes:int, share:int}>
     */
    public function countries(string $locale, string $sort = self::SORT_DENSITY): array
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
        // Country code as tiebreaker either way, so the order is total and a
        // cached page cannot differ from the next render of the same URL.
        $key = self::SORT_TOTAL === $sort ? 'coveragePois' : 'poisPerKm2';
        usort($out, static fn (array $a, array $b): int => [$b[$key], $a['code']] <=> [$a[$key], $b['code']]);

        return $out;
    }

    /**
     * Thinnest catalog categories. R is excluded — volume is the per-region cap, not coverage.
     *
     * @see docs/specs/route-domain.md §5.1
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
     * Items per country by provenance. Unrecognised sources fall into `derived` rather than vanishing.
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
     * POI counts per country; `[]` when coverage_poi does not exist yet. NULL-country rows are ignored.
     *
     * @see docs/specs/coverage-provider.md §2
     *
     * @return array<string, int>
     */
    private function poisByCountry(): array
    {
        // Twice per render before this: once for the headline total in kpis()
        // and once for the per-country table in countries(). Each pass was an
        // index-only scan over every coverage POI, 167 ms against two million
        // rows on staging (measured 2026-08-31), so the page spent most of its
        // time counting the same thing twice.
        if (null !== $this->poisMemo) {
            return $this->poisMemo;
        }

        try {
            $counts = $this->cache->get('coverage.pois_by_country.v1', function (ItemInterface $item): array {
                $item->expiresAfter(self::POIS_TTL);

                return $this->countPoisByCountry();
            });
        } catch (\Throwable) {
            // A cache backend that cannot answer is not a reason to show a
            // wrong page. Count it, as this always used to.
            $counts = $this->countPoisByCountry();
        }

        return $this->poisMemo = $counts;
    }

    /**
     * The count itself, straight from the table.
     *
     * @return array<string, int>
     */
    private function countPoisByCountry(): array
    {
        // to_regclass: null/false fetchOne() means the pipeline never ran.
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
