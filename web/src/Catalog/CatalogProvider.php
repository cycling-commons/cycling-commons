<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * Serializes the catalog tables back into the exact fixture shapes the map
 * client has always consumed (spec §7: the data changes address, not shape).
 * One payload, all layers keyed by letter; E (stays) splits by provenance
 * source and L is the heat point set. Raw DBAL — the map read path never
 * hydrates entities.
 *
 * @api Consumed by MapController::catalog().
 */
final class CatalogProvider
{
    /** Spec §8: only these lifecycle states are ever served. */
    private const string SERVED_STATES = "('unverified', 'verified')";

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * The full catalog payload, letters A–L (F/hazards has no data).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'A' => $this->surfaceSegments(),
            'B' => $this->climbs(),
            'C' => $this->featureCollection('C'),
            'D' => $this->featureCollection('D'),
            // Phase-B note: E is the only letter filtered by source — a row with
            // any other source (e.g. a user submission) lands in NEITHER bucket.
            // Revisit the split when submissions can create stays.
            'E' => [
                'osm' => $this->featureCollection('E', 'osm'),
                'pivot' => $this->featureCollection('E', 'pivot'),
            ],
            'G' => $this->featureCollection('G'),
            'H' => $this->featureCollection('H'),
            'I' => $this->featureCollection('I'),
            'J' => $this->featureCollection('J'),
            'K' => $this->routes(),
            'L' => $this->heat(),
        ];
    }

    /** Encoded once so the controller can ETag the exact bytes. */
    public function json(): string
    {
        return json_encode($this->payload(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @return list<array{name: string, geom: string, attributes: string, source_ref: string, prov: string|null}>
     */
    private function itemRows(string $letter, ?string $source = null): array
    {
        $sql = 'SELECT i.name, ST_AsGeoJSON(i.geom) AS geom, i.attributes, i.source_ref, s.name AS prov
                FROM item i
                LEFT JOIN world_subdivision s ON s.id = i.subdivision_id
                WHERE i.letter = :letter AND i.state IN '.self::SERVED_STATES;
        $params = ['letter' => $letter];
        if (null !== $source) {
            $sql .= ' AND i.source = :source';
            $params['source'] = $source;
        }

        /* @var list<array{name: string, geom: string, attributes: string, source_ref: string, prov: string|null}> */
        return $this->db->fetchAllAssociative($sql.' ORDER BY i.id', $params);
    }

    /**
     * POI fixture shape: properties = attributes + n (when named) + prov (when resolved).
     *
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function featureCollection(string $letter, ?string $source = null): array
    {
        $features = [];
        foreach ($this->itemRows($letter, $source) as $row) {
            $props = $this->decode($row['attributes']);
            if ('' !== $row['name']) {
                $props['n'] = $row['name'];
            }
            if (null !== $row['prov']) {
                $props['prov'] = $row['prov'];
            }
            $features[] = [
                'type' => 'Feature',
                // GeoJSON requires an object; [] would encode as a JSON array.
                'properties' => [] === $props ? new \stdClass() : $props,
                'geometry' => $this->decode($row['geom']),
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * CC_CLIMBS shape: bare objects, geom.ll = [lat, lng], citation back on top.
     *
     * @return list<array<string, mixed>>
     */
    private function climbs(): array
    {
        $climbs = [];
        foreach ($this->itemRows('B') as $row) {
            $attrs = $this->decode($row['attributes']);
            // The fixture's top-level "source" is a citation string; the import
            // stores it as "attribution" because provenance owns the source
            // column. Rename it back — nested photo.source is untouched.
            if (\array_key_exists('attribution', $attrs)) {
                $attrs['source'] = $attrs['attribution'];
                unset($attrs['attribution']);
            }
            /** @var array{coordinates: array{0: float, 1: float}} $geo */
            $geo = $this->decode($row['geom']);
            $climbs[] = ['name' => $row['name'], 'geom' => ['ll' => [$geo['coordinates'][1], $geo['coordinates'][0]]]] + $attrs;
        }

        return $climbs;
    }

    /**
     * CC_SURFACE.segments shape: path = [[lat, lng], …]; wayId only for OSM way refs.
     *
     * @return list<array<string, mixed>>
     */
    private function surfaceSegments(): array
    {
        $segments = [];
        foreach ($this->itemRows('A') as $row) {
            $seg = ['name' => $row['name']] + $this->decode($row['attributes']);
            if (str_starts_with($row['source_ref'], 'way/')) {
                $seg['wayId'] = (int) substr($row['source_ref'], 4);
            }
            /** @var array{coordinates: list<array{0: float, 1: float}>} $geo */
            $geo = $this->decode($row['geom']);
            $seg['path'] = $this->flip($geo['coordinates']);
            $segments[] = $seg;
        }

        return $segments;
    }

    /**
     * CC_ROUTES.routes shape, fixture key order; optional keys only when present.
     *
     * @return list<array<string, mixed>>
     */
    private function routes(): array
    {
        /** @var list<array{name: string, geom: string, distance_m: int, ascent_m: int, attributes: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT name, ST_AsGeoJSON(geom) AS geom, distance_m, ascent_m, attributes
             FROM recommended_route WHERE state IN '.self::SERVED_STATES.' ORDER BY id',
        );

        $routes = [];
        foreach ($rows as $row) {
            $attrs = $this->decode($row['attributes']);
            $route = ['name' => $row['name']];
            if (isset($attrs['season'])) {
                $route['season'] = $attrs['season'];
            }
            // Force float: PHP's / returns int for evenly divisible ints, but the
            // fixture serializes whole-number km as 87.0 — keep the bytes identical.
            $route['km'] = (float) ($row['distance_m'] / 1000);
            if (isset($attrs['start'])) {
                $route['start'] = $attrs['start'];
            }
            /** @var array{coordinates: list<array{0: float, 1: float}>} $geo */
            $geo = $this->decode($row['geom']);
            $route['loop'] = $this->flip($geo['coordinates']);
            if (isset($attrs['elev'])) {
                $route['elev'] = $attrs['elev'];
            }
            $route['gain'] = $row['ascent_m'];
            foreach (['difficulty', 'uploader', 'photo'] as $key) {
                if (isset($attrs[$key])) {
                    $route[$key] = $attrs[$key];
                }
            }
            $routes[] = $route;
        }

        return $routes;
    }

    /**
     * CC_ROUTES.heat shape: [[lat, lng, season], …] in import order.
     *
     * @return list<array{0: float, 1: float, 2: string|null}>
     */
    private function heat(): array
    {
        // Phase-B note: only 'auto' heat is served (spec §2 non-goal); rows from
        // any future source (e.g. user-contributed traces) are deliberately absent
        // until that phase decides how they surface.
        /** @var list<array{geom: string, season: string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT ST_AsGeoJSON(geom) AS geom, season FROM heat_point WHERE source = 'auto' ORDER BY id",
        );

        $points = [];
        foreach ($rows as $row) {
            /** @var array{coordinates: array{0: float, 1: float}} $geo */
            $geo = $this->decode($row['geom']);
            $points[] = [$geo['coordinates'][1], $geo['coordinates'][0], $row['season']];
        }

        return $points;
    }

    /**
     * @param list<array{0: float, 1: float}> $coords
     *
     * @return list<array{0: float, 1: float}>
     */
    private function flip(array $coords): array
    {
        return array_map(static fn (array $c): array => [$c[1], $c[0]], $coords);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        /* @var array<string, mixed> */
        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }
}
