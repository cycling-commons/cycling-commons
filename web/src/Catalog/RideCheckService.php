<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use App\Contribution\Gpx\GpxParser;
use App\Contribution\Gpx\TrackProcessor;
use Doctrine\DBAL\Connection;

/**
 * Ride-check (spec 2026-07-14 §4.2): given an uploaded GPX, list the served
 * catalog items inside a rider-chosen corridor of the track — grouped by
 * letter, ordered by distance along the ride — plus the Commons routes the
 * ride genuinely follows. Read-only indication: the GPX is parsed in memory,
 * answered, and discarded; this service never writes anything.
 *
 * Corridor query is the SurfaceProfiler idiom (ST_DWithin over ::geography,
 * GIST-indexed); the along-the-ride ordering key is ST_LineLocatePoint of the
 * item's closest point projected onto the track. Letter A (road-surface
 * segments) is excluded from the listing — every metre of a mapped ride
 * would "match", which is corridor noise, and the surface story already has
 * its own feature (SurfaceProfiler).
 *
 * Deliberately NO privacy trim (unlike route intake, spec D4): the track is
 * shown only back to its uploader and never persisted, and trimming would
 * silently drop matches near the rider's actual start/end.
 *
 * @api Consumed by RideCheckController; covered by RideCheckServiceTest.
 */
final class RideCheckService
{
    public const array ALLOWED_RADII = [100, 250, 500, 1000];
    public const int DEFAULT_RADIUS = 250;

    private const int MIN_RAW_M = 500;      // shorter is a click, not a ride
    private const int MAX_RAW_M = 400_000;  // route-domain cap (spec §5.3)
    private const int MAX_PER_LETTER = 200; // payload sanity; flagged as truncated

    /**
     * A route only counts as "followed" when the shared stretch clearly
     * exceeds what a mere crossing produces: a perpendicular route yields
     * ~2×radius of overlap inside the corridor buffer, so the floor scales
     * with the radius (Upstream's penetration-filter idea, radius-proof).
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
            'distanceKm' => round($rawM / 1000, 1),
            'ascentM' => $this->processor->ascentM($track->points),
            'radiusM' => $radiusM,
            'groups' => $this->corridorGroups($geoJson, $radiusM, $rawM),
            'routes' => $this->followedRoutes($geoJson, $radiusM),
        ];
    }

    /**
     * @return list<array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float}>, truncated: bool}>
     */
    private function corridorGroups(string $geoJson, int $radiusM, float $rawM): array
    {
        /** @var list<array{id: int|string, letter: string, name: string, geom: string, dist_m: string|float, frac: string|float}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'WITH track AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326) AS g)
             SELECT i.id, i.letter, i.name, ST_AsGeoJSON(i.geom) AS geom,
                    ST_Distance(i.geom::geography, (SELECT g FROM track)::geography) AS dist_m,
                    ST_LineLocatePoint((SELECT g FROM track), ST_ClosestPoint(i.geom, (SELECT g FROM track))) AS frac
             FROM item i
             WHERE i.letter <> \'A\'
               AND i.state IN '.ItemState::servedSqlTuple().'
               AND ST_DWithin(i.geom::geography, (SELECT g FROM track)::geography, :radius)
             ORDER BY frac, i.id',
            ['geom' => $geoJson, 'radius' => $radiusM],
        );

        /** @var array<string, array{letter: string, items: list<array{id: int, name: string, ll: array{0: float, 1: float}, distM: int, alongKm: float}>, truncated: bool}> $groups */
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
                continue; // unrenderable geometry — nothing to point at
            }
            $groups[$letter]['items'][] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'll' => $ll,
                'distM' => (int) round((float) $row['dist_m']),
                'alongKm' => round((float) $row['frac'] * $rawM / 1000, 1),
            ];
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * @return list<array{id: int, name: string, sharedKm: float}>
     */
    private function followedRoutes(string $geoJson, int $radiusM): array
    {
        /** @var list<array{id: int|string, name: string, overlap_m: string|float|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'WITH track AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326) AS g)
             SELECT r.id, r.name,
                    ST_Length(ST_Intersection(r.geom,
                      ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry)::geography) AS overlap_m
             FROM recommended_route r
             WHERE r.state IN '.ItemState::servedSqlTuple().'
               AND ST_DWithin(r.geom::geography, (SELECT g FROM track)::geography, :radius)',
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
                    'sharedKm' => round($overlapM / 1000, 1),
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
