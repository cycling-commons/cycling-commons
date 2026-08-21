<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use App\Contribution\Gpx\GpxParser;
use App\Contribution\Gpx\TrackProcessor;
use Doctrine\DBAL\Connection;

/**
 * Given an uploaded GPX, list served items in a corridor of the track. The GPX is never persisted. No privacy trim — unlike route intake.
 *
 * @see docs/specs/map-and-search.md §9
 *
 * @api
 */
final class RideCheckService
{
    public const array ALLOWED_RADII = [100, 250, 500, 1000];
    public const int DEFAULT_RADIUS = 250;

    /** Utility coverage letters (C/D/G/H). Experiential E/I/J stay on the curated arm. */
    public const array COVERAGE_LETTERS = ['C', 'D', 'G', 'H'];

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
        // MATERIALIZED is load-bearing: an inlined track CTE re-parses GeoJSON per ST_*; ST_Intersects can use the GIST index.
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
     * Open coverage_poi utilities in the same corridor, deduped against served curated items on (source_ref, letter).
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
}
