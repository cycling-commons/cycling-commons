<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use App\Contribution\Gpx\GpxParser;
use App\Contribution\Gpx\TrackProcessor;
use Doctrine\DBAL\Connection;

/**
 * Ride-check (map-and-search.md §9): given an uploaded GPX, list the served
 * catalog items inside a rider-chosen corridor of the track, grouped by
 * letter and ordered by distance along the ride, plus the Commons routes the
 * ride genuinely follows. Read-only indication: the GPX is parsed in memory,
 * answered, and discarded; this service never writes anything.
 *
 * Corridor query is the SurfaceProfiler idiom (ST_DWithin over ::geography,
 * GIST-indexed); the along-the-ride ordering key is ST_LineLocatePoint of the
 * item's closest point projected onto the track. Letter A (road-surface
 * segments) is excluded from the listing: every metre of a mapped ride
 * would "match", which is corridor noise, and the surface story already has
 * its own feature (SurfaceProfiler).
 *
 * Deliberately NO privacy trim (unlike route intake, docs/specs/route-domain.md §4.3):
 * the track is shown only back to its uploader and never persisted, and
 * trimming would silently drop matches near the rider's actual start/end.
 *
 * @api Consumed by RideCheckController; covered by RideCheckServiceTest.
 */
final class RideCheckService
{
    public const array ALLOWED_RADII = [100, 250, 500, 1000];
    public const int DEFAULT_RADIUS = 250;

    /**
     * Utility coverage letters surfaced alongside curated items
     * (2026-07-26-ride-check-coverage-design.md §2): water/bakery (C), bike
     * services (D), transport — ferry/train (G), shelter (H). Experiential
     * E-stays / I-scenic / J-history are left to the curated arm.
     */
    public const array COVERAGE_LETTERS = ['C', 'D', 'G', 'H'];

    private const int MIN_RAW_M = 500;      // shorter is a click, not a ride
    private const int MAX_RAW_M = 400_000;  // route-domain cap (spec §5.3)
    private const int MAX_PER_LETTER = 200; // payload sanity; flagged as truncated

    /**
     * A route only counts as "followed" when the shared stretch clearly
     * exceeds what a mere crossing produces: a perpendicular route yields
     * about 2x the radius of overlap inside the corridor buffer, so the
     * floor scales with the radius. This keeps the check valid no matter
     * which radius is chosen.
     */
    private const int ROUTE_MIN_OVERLAP_BASE_M = 300;

    public function __construct(
        private readonly Connection $db,
        private readonly GpxParser $parser,
        private readonly TrackProcessor $processor,
    ) {
    }

    /**
     * @return array{
     *     track: list<array{0: float, 1: float}>,
     *     distanceKm: float,
     *     ascentM: int|null,
     *     radiusM: int,
     *     groups: list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float}>, truncated: bool}>,
     *     coverage: list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float, ref: string}>, truncated: bool}>,
     *     routes: list<array{id: int, name: string, sharedKm: float}>
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

        return [
            'track' => array_map(static fn (array $p): array => [$p[0], $p[1]], $points),
            'distanceKm' => round($rawM / 1000.0, 1),
            'ascentM' => $this->processor->ascentM($track->points),
            'radiusM' => $radiusM,
            'groups' => $this->corridorGroups($geoJson, $radiusM, $rawM),
            'coverage' => $this->corridorCoverage($geoJson, $radiusM, $rawM),
            'routes' => $this->followedRoutes($geoJson, $radiusM),
        ];
    }

    /**
     * @return list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float}>, truncated: bool}>
     */
    private function corridorGroups(string $geoJson, int $radiusM, float $rawM): array
    {
        // MATERIALIZED is load-bearing twice over: an inlined `track` CTE
        // re-parses the whole GeoJSON per row per ST_* occurrence, and the
        // one-off `corridor` buffer turns the containment test into a plain
        // ST_Intersects the idx_item_geom GIST index can serve. The naive
        // ST_DWithin(::geography) formulation seq-scanned with spheroid maths
        // against the full track per item (62 s down to sub-second, dev catalog).
        /** @var list<array{id: int|string, letter: string, name: string, geom: string, dist_m: string|float, frac: string|float}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'WITH track AS MATERIALIZED (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326) AS g),
                  corridor AS MATERIALIZED (SELECT ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry AS b)
             SELECT i.id, i.letter, i.name, ST_AsGeoJSON(i.geom) AS geom,
                    ST_Distance(i.geom::geography, (SELECT g FROM track)::geography) AS dist_m,
                    ST_LineLocatePoint((SELECT g FROM track), ST_ClosestPoint(i.geom, (SELECT g FROM track))) AS frac
             FROM item i
             WHERE i.letter <> \'A\'
               AND i.state IN '.ItemState::servedSqlTuple().'
               AND ST_Intersects(i.geom, (SELECT b FROM corridor))
             ORDER BY frac, i.id',
            ['geom' => $geoJson, 'radius' => $radiusM],
        );

        return $this->groupByLetter($rows, $rawM);
    }

    /**
     * Open `coverage_poi` utility points (C/D/G/H) in the same corridor
     * (2026-07-26-ride-check-coverage-design.md §3.1), returned as a parallel
     * arm so the frontend can render them with the smaller coverage icon while
     * curated items keep their bigger spot icons. Deduped against SERVED curated
     * items on (source_ref, letter): if a rider already curated this OSM entity,
     * it is shown once, as the curated pick — never twice.
     *
     * Same MATERIALIZED corridor idiom as corridorGroups() (ST_Intersects rides
     * coverage_poi_geom_idx); coverage_poi and item are co-located on CC's own
     * cluster, so the dedup NOT EXISTS stays a local join. coverage_poi has no
     * `state` column (a pipeline cache) — the served filter applies only to the
     * curated item it is deduped against.
     *
     * @return list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float, ref: string}>, truncated: bool}>
     */
    private function corridorCoverage(string $geoJson, int $radiusM, float $rawM): array
    {
        $letters = "'".implode("','", self::COVERAGE_LETTERS)."'";
        /** @var list<array{id: int|string, letter: string, name: string|null, ref: string, geom: string, dist_m: string|float, frac: string|float}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'WITH track AS MATERIALIZED (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326) AS g),
                  corridor AS MATERIALIZED (SELECT ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry AS b)
             SELECT cp.id, cp.letter, cp.name, cp.ref, ST_AsGeoJSON(cp.geom) AS geom,
                    ST_Distance(cp.geom::geography, (SELECT g FROM track)::geography) AS dist_m,
                    ST_LineLocatePoint((SELECT g FROM track), ST_ClosestPoint(cp.geom, (SELECT g FROM track))) AS frac
             FROM coverage_poi cp
             WHERE cp.letter IN ('.$letters.')
               AND ST_Intersects(cp.geom, (SELECT b FROM corridor))
               AND NOT EXISTS (
                   SELECT 1 FROM item d
                   WHERE d.source_ref = cp.ref AND d.letter = cp.letter
                     AND d.state IN '.ItemState::servedSqlTuple().'
               )
             ORDER BY frac, cp.id',
            ['geom' => $geoJson, 'radius' => $radiusM],
        );

        return $this->groupByLetter($rows, $rawM);
    }

    /**
     * Fold corridor rows (id, letter, name, geom, dist_m, frac — already ordered
     * by along-the-ride fraction) into per-letter groups, capped at
     * MAX_PER_LETTER with a `truncated` flag, each item anchored to a
     * renderable [lat, lng]. Shared by the curated and coverage arms.
     *
     * A row's `ref` is carried through when present. Only the coverage arm
     * selects one, and it is not decoration: `id` there is a coverage_poi row
     * id, which no endpoint accepts, so the frontend needs the `ref` to open a
     * coverage POI at all (/map/coverage/poi/{ref}, via openCoverageByRef).
     * Curated rows have no `ref` and the key stays absent for them.
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
        // Same MATERIALIZED corridor as corridorGroups() (see the note there);
        // ST_Intersects rides idx_route_geom, and the intersection length is
        // measured only for the handful of candidate routes.
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
     * A feature's map anchor as [lat, lng]: the point itself, or a line's
     * first vertex (matches map.js featurePoint()).
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
}
