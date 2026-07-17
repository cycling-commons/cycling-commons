<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * Serializes the catalog tables back into the exact fixture shapes the map
 * client has always consumed: the data source changed, not the shape. One
 * payload, all layers keyed by letter; E (stays) splits by provenance source
 * and L is the heat point set. Raw DBAL: the map read path never hydrates
 * entities.
 *
 * @api Consumed by MapController::catalog().
 */
final class CatalogProvider
{
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
            // E is the only letter split by source. 'pivot' (official
            // Tourisme Wallonie accommodation) is its own bucket; every other
            // source (osm, user, manual, wikidata, auto) lands in the 'osm'
            // bucket, matching catalog-load.js's merge (window.CC_STAYS_OSM is
            // the generic stays collection; only PIVOT rows get tagged apart).
            'E' => [
                'osm' => $this->featureCollection('E', excludeSource: 'pivot'),
                'pivot' => $this->featureCollection('E', 'pivot'),
            ],
            'G' => $this->featureCollection('G'),
            'H' => $this->featureCollection('H'),
            'I' => $this->featureCollection('I'),
            'J' => $this->featureCollection('J'),
            'K' => $this->routes(),
            'L' => $this->heat(),
            // Served OSM refs for client-side tile dedupe: map.js filters
            // coverage tile features whose ref is listed here
            // (osm-data-architecture.md §8: the object appears once, as curated).
            'refs' => $this->curatedRefs(),
        ];
    }

    /** Encoded once so the controller can ETag the exact bytes. */
    public function json(): string
    {
        return json_encode($this->payload(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * The verified derivation (map-and-search.md §12): verified state, a rider
     * confirmation, or official-registry provenance. Tourisme Wallonie PIVOT
     * rows count as verified.
     *
     * @return list<array{id: int, name: string, geom: string, attributes: string, source_ref: string, source: string, prov: string|null, verified: bool}>
     */
    private function itemRows(string $letter, ?string $source = null, ?string $excludeSource = null): array
    {
        $sql = 'SELECT i.id, i.name, ST_AsGeoJSON(i.geom) AS geom, i.attributes, i.source_ref, i.source, s.name AS prov,
                       (i.state = \'verified\' OR i.source = \'pivot\' OR EXISTS (SELECT 1 FROM item_confirmation c WHERE c.item_id = i.id)) AS verified
                FROM item i
                LEFT JOIN world_subdivision s ON s.id = i.subdivision_id
                WHERE i.letter = :letter AND i.state IN '.ItemState::servedSqlTuple();
        $params = ['letter' => $letter];
        if (null !== $source) {
            $sql .= ' AND i.source = :source';
            $params['source'] = $source;
        }
        if (null !== $excludeSource) {
            $sql .= ' AND i.source != :excludeSource';
            $params['excludeSource'] = $excludeSource;
        }
        // Coverage retirement predicate (coverage-provider.md
        // §9): a pure uncurated OSM row (source=osm, state=unverified, never
        // touched by any human: no change history, no confirmation, no
        // submission) is exactly what coverage_poi serves now, so catalog.json
        // drops it unconditionally. These are the exact rows
        // app:coverage:retire-legacy deletes (keep the two in sync). Anything
        // a human ever touched stays served. Letter-scoped like
        // curatedRefs()'s mirror and the retirement command's guard
        // (CoverageRetirement docblock: "callers compose the letter scope
        // themselves"): A (road surface) never entered the coverage artifact
        // and B (climbs) is wikidata-sourced, so neither may ever match, even
        // if a stray row happens to carry source='osm'.
        if (\in_array($letter, CoverageRetirement::LETTERS, true)) {
            $sql .= ' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').')';
        }

        /* @var list<array{id: int, name: string, geom: string, attributes: string, source_ref: string, source: string, prov: string|null, verified: bool}> */
        return $this->db->fetchAllAssociative($sql.' ORDER BY i.id', $params);
    }

    /**
     * Source_ref of every source='osm' item the payload itself serves
     * (osm-data-architecture.md §8, client half). DISTINCT because one entity
     * may carry two letters (UNIQUE(source, source_ref, letter) on item).
     * Mirrors itemRows() exactly (coverage-provider.md §6): coverage-served
     * (untouched) rows are excluded here too, unconditionally, because their
     * tile twins must render as community POIs. Listing their refs would
     * suppress the twins while the payload drops the rows, and the object
     * would display nowhere until retire-legacy --force removes it. The
     * exclusion is letter-scoped like the payload's: only the coverage
     * letters (CoverageRetirement::LETTERS) ever drop rows. An untouched A
     * surface row keeps serving, so its ref keeps listing.
     *
     * @return list<string>
     */
    private function curatedRefs(): array
    {
        $sql = "SELECT DISTINCT i.source_ref FROM item i WHERE i.source = 'osm' AND i.state IN ".ItemState::servedSqlTuple();
        $sql .= ' AND NOT (i.letter IN '.CoverageRetirement::lettersSqlTuple()
            .' AND '.CoverageRetirement::untouchedOsmSql('i').')';

        /* @var list<string> */
        return $this->db->fetchFirstColumn($sql.' ORDER BY i.source_ref');
    }

    /**
     * POI fixture shape: properties = attributes + n (when named) + prov (when
     * resolved) + id (the DB item id, used by the map edit-bridge's `?item=` target).
     *
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function featureCollection(string $letter, ?string $source = null, ?string $excludeSource = null): array
    {
        $features = [];
        foreach ($this->itemRows($letter, $source, $excludeSource) as $row) {
            $props = $this->decode($row['attributes']);
            if ('' !== $row['name']) {
                $props['n'] = $row['name'];
            }
            if (null !== $row['prov']) {
                $props['prov'] = $row['prov'];
            }
            // Display-safe provenance: the raw ItemSource value (osm/pivot/
            // wikidata/auto/user/manual), never the internal provenance detail.
            // Lets the drawer show "Rider-contributed" for user/manual items
            // instead of a hardcoded per-layer OSM string (map.js sourceLabel()).
            $props['srcType'] = $row['source'];
            // Real community-tier signal (map-and-search.md
            // §12): v:1 = verified state OR at least one rider confirmation.
            // Absent key = community tier (keeps unverified payloads byte-stable).
            if ($row['verified']) {
                $props['v'] = 1;
            }
            // The DB item id always makes $props non-empty, so it always
            // encodes as a JSON object, never `[]` (GeoJSON requires an
            // object; an empty array would encode as `[]` instead).
            $props['id'] = (int) $row['id'];
            $features[] = [
                'type' => 'Feature',
                'properties' => $props,
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
            // column. Rename it back. Nested photo.source is untouched.
            if (\array_key_exists('attribution', $attrs)) {
                $attrs['source'] = $attrs['attribution'];
                unset($attrs['attribution']);
            }
            /** @var array{coordinates: array{0: float, 1: float}} $geo */
            $geo = $this->decode($row['geom']);
            // 'source' above is the free-text citation (attribution); 'srcType'
            // is the raw ItemSource enum value, kept separate so map.js can tell a
            // rider-added/edited climb apart from an OSM/Wikidata one.
            $climb = ['id' => (int) $row['id'], 'name' => $row['name'], 'srcType' => $row['source'], 'geom' => ['ll' => [$geo['coordinates'][1], $geo['coordinates'][0]]]] + $attrs;
            // Real community-tier signal, same derivation as featureCollection()
            // (map-and-search.md §12): absent key = community, unverified
            // payloads stay byte-stable. The demo 'cur' attribute keeps its
            // best-of/badge meaning; the tier keys on this real signal.
            if ($row['verified']) {
                $climb['v'] = 1;
            }
            $climbs[] = $climb;
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
            // srcType is the raw ItemSource enum value (see featureCollection()).
            $seg = ['id' => (int) $row['id'], 'name' => $row['name'], 'srcType' => $row['source']] + $this->decode($row['attributes']);
            // Real community-tier signal, same derivation as featureCollection()
            // (map-and-search.md §12); absent key = community, byte-stable.
            if ($row['verified']) {
                $seg['v'] = 1;
            }
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
        /** @var list<array{id: int, name: string, geom: string, distance_m: int, ascent_m: int, attributes: string, source: string, state: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, ST_AsGeoJSON(geom) AS geom, distance_m, ascent_m, attributes, source, state
             FROM recommended_route WHERE state IN '.ItemState::servedSqlTuple().' ORDER BY id',
        );

        $routes = [];
        foreach ($rows as $row) {
            $attrs = $this->decode($row['attributes']);
            // srcType is the raw ItemSource enum value (see featureCollection()).
            $route = ['id' => (int) $row['id'], 'name' => $row['name'], 'srcType' => $row['source']];
            // Raw ItemState value so the map can badge unverified ("proposed")
            // routes distinct from verified ones.
            $route['state'] = (string) $row['state'];
            if (isset($attrs['season'])) {
                $route['season'] = $attrs['season'];
            }
            // Force float: PHP's / returns int for evenly divisible ints, but the
            // fixture serializes whole-number km as 87.0. Keep the bytes identical.
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
            // The QualityRides registry's suitability/rating fields
            // (CatalogFormRegistry::for(QualityRides)) are forwarded the same
            // way as difficulty/uploader/photo, so an approved improve-form edit
            // reaches map.js's route drawer instead of being silently dropped.
            foreach ([
                'difficulty', 'uploader', 'photo',
                'dominantSurface', 'surfaces', 'note', 'quietness', 'scenic', 'friendliness',
                'bikeTypes', 'gradientLimited', 'bestDirection',
            ] as $key) {
                if (isset($attrs[$key])) {
                    $route[$key] = $attrs[$key];
                }
            }
            // Canonicalize difficulty to {score,label} regardless of how it was
            // stored (legacy import string, rider vocab string, or already
            // canonical), so every serving path emits one shape.
            $canonicalDifficulty = DifficultyVocabulary::canonical($attrs['difficulty'] ?? null);
            if (null !== $canonicalDifficulty) {
                $route['difficulty'] = $canonicalDifficulty;
            } else {
                unset($route['difficulty']);
            }
            // Serve bikeTypes as a deduplicated list of valid BikeType values
            // (docs/specs/route-domain.md §9), so every serving path emits one
            // shape.
            $canonicalBikeTypes = BikeTypeVocabulary::normalize($attrs['bikeTypes'] ?? null);
            if ([] !== $canonicalBikeTypes) {
                $route['bikeTypes'] = $canonicalBikeTypes;
            } else {
                unset($route['bikeTypes']);
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
        // Only 'auto' heat is served today. Rows from any future source
        // (e.g. user-contributed traces) are deliberately absent until a
        // future decision adds them.
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
