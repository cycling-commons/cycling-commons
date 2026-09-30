<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Contribution\Gpx\GpxParser;
use App\Contribution\Gpx\TrackProcessor;
use Doctrine\DBAL\Connection;

/**
 * Given an uploaded GPX, list served items in a corridor of the track. The GPX is never persisted. No privacy trim, unlike route intake.
 * The same corridor arms answer for a recommended route's own line (alongRoute).
 * Climbs are not corridor rows by their stored point: a ride lists the climbs it rides, by the route drawer's rule, in `climbs`, and its letter N group holds the climbs whose line comes within the radius that it does not ride (RouteClimbService::climbsAlong()). A route's along list holds its near climbs the same way.
 * `alongKm` is true distance along the line (LineMetres), scaled so the line's end is the ride's own distance.
 *
 * @see docs/specs/map-and-search.md §9
 *
 * @api
 */
final class RideCheckService
{
    public const array ALLOWED_RADII = [100, 250, 500, 1000];
    public const int DEFAULT_RADIUS = 250;

    /** Utility coverage letters (B/D/F/G). Experiential O/P/Q stay on the curated arm. */
    public const array COVERAGE_LETTERS = ['B', 'D', 'F', 'G'];

    /* Public so the controller can format bounds in the reader's units. docs/specs/account-and-auth.md §9 */
    public const int MIN_RAW_M = 500;
    public const int MAX_RAW_M = 400_000;   // docs/specs/map-and-search.md §9
    private const int MAX_PER_LETTER = 200;

    /**
     * Overlap floor so a mere crossing does not count as "followed". Scales with radius.
     */
    private const int ROUTE_MIN_OVERLAP_BASE_M = 300;

    public function __construct(
        private readonly Connection $db,
        private readonly GpxParser $parser,
        private readonly TrackProcessor $processor,
        private readonly RouteClimbService $climbs,
    ) {
    }

    /**
     * @return array{
     *     track: list<array{0: float, 1: float}>,
     *     distanceKm: float,
     *     ascentM: int|null,
     *     radiusM: int,
     *     climbs: list<array{id: int, name: string, alongKm: float, avgGradient: string|null, ll: array{0: float, 1: float}, distM: int}>,
     *     groups: list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float, avgGradient?: string|null}>, truncated: bool}>,
     *     coverage: list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float, ref: string}>, truncated: bool}>,
     *     routes: list<array{id: int, name: string, sharedKm: float}>,
     *     regions: list<array{id: int, slug: string, name: string, countryCode: string|null}>
     * }
     *
     * @throws \InvalidArgumentException validation failure (message = translation key)
     */
    public function check(string $gpxContent, int $radiusM): array
    {
        if (!\in_array($radiusM, self::ALLOWED_RADII, true)) {
            throw new \InvalidArgumentException('ride_check.error.radius');
        }

        $track = $this->parser->parse($gpxContent);

        $rawM = $this->processor->distanceM($track->points);
        if ($rawM < self::MIN_RAW_M || $rawM > self::MAX_RAW_M) {
            throw new \InvalidArgumentException('ride_check.error.length_range');
        }

        // Query/display geometry: simplified, untrimmed. [lat,lng] → GeoJSON [lng,lat].
        $points = $this->processor->simplify($track->points);
        $coords = array_map(static fn (array $p): array => [$p[1], $p[0]], $points);
        $geoJson = json_encode(
            ['type' => 'LineString', 'coordinates' => $coords],
            \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
        );

        $climbs = $this->climbs->climbsAlong($geoJson, $rawM, $radiusM);

        return [
            'track' => array_map(static fn (array $p): array => [$p[0], $p[1]], $points),
            'distanceKm' => round($rawM / 1000.0, 1),
            'ascentM' => $this->processor->ascentM($track->points),
            'radiusM' => $radiusM,
            'climbs' => $climbs['ridden'],
            'groups' => $this->withNearClimbs($this->corridorGroups($geoJson, $radiusM, $rawM, ['A', 'N']), $climbs['near']),
            'coverage' => $this->corridorCoverage($geoJson, $radiusM, $rawM),
            'routes' => $this->followedRoutes($geoJson, $radiusM),
            'regions' => $this->crossedRegions($geoJson),
        ];
    }

    /**
     * What is along a recommended route: the ride check's two corridor arms, run on the route's own stored line at the ride check's default radius.
     *
     * Climbs come from RouteClimbService, which decides which climbs the route
     * really rides (the drawer's "Climbs on this route") and which it only
     * passes near in this same corridor: the near ones are the letter N group
     * here, and a climb's stored point never lists it. Null when there is no
     * such route, or it is not served and $allowSubmitted does not let a
     * waiting route through.
     *
     * @see docs/specs/map-and-search.md §6.3
     * @see docs/specs/route-domain.md §6.4
     *
     * @return array{
     *     radiusM: int,
     *     groups: list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float, avgGradient?: string|null}>, truncated: bool}>,
     *     coverage: list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float, ref: string}>, truncated: bool}>
     * }|null
     */
    public function alongRoute(int $routeId, bool $allowSubmitted, int $radiusM = self::DEFAULT_RADIUS): ?array
    {
        $states = $allowSubmitted ? ItemState::servedOrSubmittedSqlTuple() : ItemState::servedSqlTuple();
        /** @var array{geom: string, len_m: string|float}|false $route */
        $route = $this->db->fetchAssociative(
            'SELECT ST_AsGeoJSON(geom) AS geom, ST_Length(geom::geography) AS len_m
               FROM recommended_route
              WHERE id = :id AND state IN '.$states.' AND geom IS NOT NULL',
            ['id' => $routeId],
        );
        if (false === $route) {
            return null;
        }

        $lengthM = (float) $route['len_m'];

        return [
            'radiusM' => $radiusM,
            'groups' => $this->withNearClimbs(
                $this->corridorGroups($route['geom'], $radiusM, $lengthM, ['A', 'N']),
                $this->climbs->climbsAlong($route['geom'], $lengthM, $radiusM)['near'],
            ),
            'coverage' => $this->corridorCoverage($route['geom'], $radiusM, (float) $route['len_m']),
        ];
    }

    /**
     * @param list<string> $excludedLetters surface segments (A) are corridor noise on every list
     *
     * @return list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float}>, truncated: bool}>
     */
    private function corridorGroups(string $geoJson, int $radiusM, float $rawM, array $excludedLetters = ['A']): array
    {
        // Catalog letters from this class only, never request input.
        $excluded = "('".implode("', '", $excludedLetters)."')";
        // The rows the map payload serves (CatalogProvider::itemRows()): an
        // untouched OSM import is the coverage tiles' point, and a row reported
        // gone is served nowhere. The ride lists a place where the map draws it.
        // MATERIALIZED is load-bearing: an inlined track CTE re-parses GeoJSON per ST_*; ST_Intersects can use the GIST index.
        // `frac` is the share of the track's geodesic length before the row's
        // closest point (LineMetres), so frac * $rawM is true km along.
        /** @var list<array{id: int|string, letter: string, name: string, geom: string, dist_m: string|float, frac: string|float}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'WITH track AS MATERIALIZED (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326) AS g),
                  corridor AS MATERIALIZED (SELECT ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry AS b),
                  '.LineMetres::segmentsCte('track_seg', '(SELECT g FROM track)').',
                  '.LineMetres::indexCte('track_idx', 'track_seg').',
                  hit AS MATERIALIZED (
                      SELECT i.id, i.letter, i.name, ST_AsGeoJSON(i.geom) AS geom,
                             ST_Distance(i.geom::geography, (SELECT g FROM track)::geography) AS dist_m,
                             ST_LineLocatePoint((SELECT g FROM track), ST_ClosestPoint(i.geom, (SELECT g FROM track))) AS flat_frac
                      FROM item i
                      WHERE i.letter NOT IN '.$excluded.'
                        AND i.state IN '.ItemState::servedSqlTuple().'
                        AND NOT (i.letter IN '.CoverageRetirement::lettersSqlTuple().' AND '.CoverageRetirement::untouchedOsmSql('i').')
                        AND '.GoneRows::notGoneSql('i').'
                        AND ST_Intersects(i.geom, (SELECT b FROM corridor))
                  )
             SELECT h.id, h.letter, h.name, h.geom, h.dist_m,
                    COALESCE(along.m / NULLIF(x.len_m, 0), 0) AS frac
             FROM hit h
             CROSS JOIN track_idx x
             '.LineMetres::metresJoin('h.flat_frac', 'x', 'along').'
             ORDER BY frac, h.id',
            ['geom' => $geoJson, 'radius' => $radiusM],
        );

        return $this->groupByLetter($rows, $rawM);
    }

    /**
     * Open coverage_poi utilities in the same corridor, minus every point a served item claims (ClaimedOsmRefs, the same rule the map's tile dedupe uses).
     *
     * @return list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float, ref: string}>, truncated: bool}>
     */
    private function corridorCoverage(string $geoJson, int $radiusM, float $rawM): array
    {
        $letters = "'".implode("','", self::COVERAGE_LETTERS)."'";
        // `frac` as in corridorGroups(): true share of the track (LineMetres).
        /** @var list<array{id: int|string, letter: string, name: string|null, ref: string, geom: string, dist_m: string|float, frac: string|float}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'WITH track AS MATERIALIZED (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326) AS g),
                  corridor AS MATERIALIZED (SELECT ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry AS b),
                  '.LineMetres::segmentsCte('track_seg', '(SELECT g FROM track)').',
                  '.LineMetres::indexCte('track_idx', 'track_seg').',
                  hit AS MATERIALIZED (
                      SELECT cp.id, cp.letter, cp.name, cp.ref, ST_AsGeoJSON(cp.geom) AS geom,
                             ST_Distance(cp.geom::geography, (SELECT g FROM track)::geography) AS dist_m,
                             ST_LineLocatePoint((SELECT g FROM track), ST_ClosestPoint(cp.geom, (SELECT g FROM track))) AS flat_frac
                      FROM coverage_poi cp
                      WHERE cp.letter IN ('.$letters.')
                        AND ST_Intersects(cp.geom, (SELECT b FROM corridor))
                        AND NOT '.ClaimedOsmRefs::claimedSql('cp.ref').'
                  )
             SELECT h.id, h.letter, h.name, h.ref, h.geom, h.dist_m,
                    COALESCE(along.m / NULLIF(x.len_m, 0), 0) AS frac
             FROM hit h
             CROSS JOIN track_idx x
             '.LineMetres::metresJoin('h.flat_frac', 'x', 'along').'
             ORDER BY frac, h.id',
            ['geom' => $geoJson, 'radius' => $radiusM],
        );

        return $this->groupByLetter($rows, $rawM);
    }

    /**
     * Fold corridor rows into per-letter groups. Coverage rows carry `ref` because `id` is a coverage_poi row no endpoint accepts.
     *
     * @param list<array{id: int|string, letter: string, name: string|null, geom: string, dist_m: string|float, frac: string|float, ref?: string|null}> $rows
     *
     * @return list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float, ref?: string}>, truncated: bool}>
     */
    private function groupByLetter(array $rows, float $rawM): array
    {
        /** @var array<string, array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float, ref?: string}>, truncated: bool}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $letter = $row['letter'];
            $groups[$letter] ??= ['letter' => $letter, 'items' => [], 'truncated' => false];
            if (\count($groups[$letter]['items']) >= self::MAX_PER_LETTER) {
                $groups[$letter]['truncated'] = true;
                continue;
            }
            /** @var array{type: string, coordinates: mixed} $geo */
            $geo = json_decode($row['geom'], true, 512, \JSON_THROW_ON_ERROR);
            $ll = self::representativeLatLng($geo);
            if (null === $ll) {
                continue; // unrenderable geometry: nothing to point at
            }
            $item = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'll' => $ll,
                'distM' => (int) round((float) $row['dist_m']),
                'alongKm' => round((float) $row['frac'] * $rawM / 1000.0, 1),
            ];
            if (isset($row['ref']) && '' !== $row['ref']) {
                $item['ref'] = (string) $row['ref'];
            }
            $groups[$letter]['items'][] = $item;
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * @return list<array{id: int, name: string, sharedKm: float}>
     */
    private function followedRoutes(string $geoJson, int $radiusM): array
    {
        // Same MATERIALIZED corridor as corridorGroups(); ST_Intersects uses idx_route_geom.
        /** @var list<array{id: int|string, name: string, overlap_m: string|float|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'WITH track AS MATERIALIZED (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326) AS g),
                  corridor AS MATERIALIZED (SELECT ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry AS b)
             SELECT r.id, r.name,
                    ST_Length(ST_Intersection(r.geom, (SELECT b FROM corridor))::geography) AS overlap_m
             FROM recommended_route r
             WHERE r.state IN '.ItemState::servedSqlTuple().'
               AND ST_Intersects(r.geom, (SELECT b FROM corridor))',
            ['geom' => $geoJson, 'radius' => $radiusM],
        );

        $minOverlapM = max(self::ROUTE_MIN_OVERLAP_BASE_M, 2 * $radiusM + 100);
        $routes = [];
        foreach ($rows as $row) {
            $overlapM = (float) $row['overlap_m'];
            if ($overlapM >= $minOverlapM) {
                $routes[] = [
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                    'sharedKm' => round($overlapM / 1000.0, 1),
                ];
            }
        }
        usort($routes, static fn (array $a, array $b): int => $b['sharedKm'] <=> $a['sharedKm']);

        return $routes;
    }

    /**
     * The corridor groups plus letter N: the climbs whose line comes within the corridor that the line does not ride (RouteClimbService::climbsAlong()), in km order.
     *
     * A row's km is the line's point closest to the climb line, `distM` the
     * metres between them; it stands at the climb's foot (`ll`), where the map
     * pins it.
     *
     * @param list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float, ref?: string}>, truncated: bool}> $groups
     * @param list<array{id: int, name: string, alongKm: float, avgGradient: string|null, ll: array{0: float, 1: float}, distM: int}>                                          $near
     *
     * @return list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float, ref?: string, avgGradient?: string|null}>, truncated: bool}>
     */
    private function withNearClimbs(array $groups, array $near): array
    {
        if ([] === $near) {
            return $groups;
        }
        $groups[] = [
            'letter' => 'N',
            'items' => \array_slice($near, 0, self::MAX_PER_LETTER),
            'truncated' => \count($near) > self::MAX_PER_LETTER,
        ];
        usort($groups, static fn (array $a, array $b): int => strcmp($a['letter'], $b['letter']));

        return $groups;
    }

    /**
     * Map anchor as [lat, lng]: the point, or a line's first vertex.
     *
     * @param array{type: string, coordinates: mixed} $geo
     *
     * @return array{0: float, 1: float}|null
     */
    private static function representativeLatLng(array $geo): ?array
    {
        $c = $geo['coordinates'];
        if (!\is_array($c) || [] === $c) {
            return null;
        }
        if ('Point' === $geo['type'] && \is_numeric($c[0] ?? null) && \is_numeric($c[1] ?? null)) {
            return [(float) $c[1], (float) $c[0]];
        }
        $first = $c[0];
        if (\is_array($first) && \is_numeric($first[0] ?? null) && \is_numeric($first[1] ?? null)) {
            return [(float) $first[1], (float) $first[0]];
        }

        return null;
    }

    /**
     * Every operational region the track passes through, in ride order.
     *
     * The map scope is a set of region ids (map-and-search.md §4.5), so a ride
     * that crosses three provinces answers with three: the client widens to all
     * of them rather than picking a winner and leaving the rest of the ride
     * unscoped. Ordered by where the track first enters each one, so the chip
     * reads the way the ride was ridden. Level-2 country outlines are excluded
     * exactly as everywhere else — they are not scope chips
     * (catalog-data-model.md §2.4).
     *
     * @return list<array{id: int, slug: string, name: string, countryCode: string|null}>
     */
    private function crossedRegions(string $geoJson): array
    {
        /** @var list<array{id: int|string, slug: string, name: string, country_code: string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'WITH track AS MATERIALIZED (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326) AS g)
             SELECT r.id, r.slug, r.name, r.country_code
             FROM region r
             WHERE ST_Intersects(r.geom, (SELECT g FROM track))
               AND '.OperationalRegions::predicate('r').'
             ORDER BY ST_LineLocatePoint(
                 (SELECT g FROM track),
                 ST_ClosestPoint(ST_Intersection(r.geom, (SELECT g FROM track)), ST_StartPoint((SELECT g FROM track)))
             ), r.id',
            ['geom' => $geoJson],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'name' => $r['name'],
            'countryCode' => $r['country_code'],
        ], $rows);
    }
}
