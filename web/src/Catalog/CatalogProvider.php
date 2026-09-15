<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Catalog\Links\LinkVerdictStore;
use App\Coverage\CoverageRepository;
use App\Media\PhotoPlace;
use App\Media\PhotoValidator;
use App\Moderation\ModerationScope;
use App\Provider\ProviderCitations;
use App\Service\BuildVersion;
use Doctrine\DBAL\Connection;

/**
 * Serves catalog tables in the map client's fixture shapes. Raw DBAL; never hydrates entities.
 *
 * @see docs/specs/catalog-data-model.md §9
 *
 * @api
 */
final class CatalogProvider
{
    /**
     * Letters served as GeoJSON feature collections. A, N, and R have their own shapes.
     */
    public const array POOL_LETTERS = ['B', 'C', 'D', 'E', 'F', 'G', 'O', 'P', 'Q'];

    public function __construct(
        private readonly Connection $db,
        private readonly BuildVersion $buildVersion,
        private readonly ConfirmationFreshness $freshness,
        private readonly LinkVerdictStore $linkVerdicts,
        // Who published each authority row, sent with the payload so the map
        // never holds a table of providers in a constant
        // (data-provider-hierarchy.md §7).
        private readonly ProviderCitations $citations,
        // Custody and rung for every served point (data-provider-hierarchy.md
        // §6.7.7), computed here so a pin and the API read one answer.
        private readonly ItemEvidenceResolver $evidence,
    ) {
    }

    /**
     * The full catalog payload: practical letters A-G, experiential letters N-R.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'A' => $this->surfaceSegments(),
            'B' => $this->featureCollection('B'),
            'C' => $this->featureCollection('C'),
            'D' => $this->featureCollection('D'),
            'E' => $this->featureCollection('E'),
            'F' => $this->featureCollection('F'),
            'G' => $this->featureCollection('G'),
            'N' => $this->climbs(),
            // O splits by source: an authority's rows are their own
            // bucket, because they carry their publisher's citation and
            // licence; every other source lands in 'osm'.
            'O' => [
                'osm' => $this->featureCollection('O', excludeSource: 'authority'),
                'authority' => $this->featureCollection('O', 'authority'),
            ],
            'P' => $this->featureCollection('P'),
            'Q' => $this->featureCollection('Q'),
            'R' => $this->routes(),
            // The heat layer is derived and carries no letter; it is /map/heat.json, not this payload.
            // docs/specs/osm-data-architecture.md §8 — client-side tile dedupe.
            'refs' => $this->curatedRefs(),
            // Who to credit, keyed by the `pk` a feature carries. The map used
            // to hold one provider's citation as a front-end constant; a table
            // of them belongs where the table is (data-provider-hierarchy.md §7).
            'providers' => $this->citations->all(),
        ];
    }

    /** Encoded once so the controller can ETag the exact bytes. */
    public function json(): string
    {
        return json_encode($this->payload(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Curator-only ghost layer of `condition = 'Not there anymore'` so a gone place can be reactivated.
     *
     * @see docs/specs/catalog-data-model.md §7
     *
     * @return list<array{id: int, letter: string, name: string, cc: string, lat: float, lng: float, since: string}>
     */
    public function goneForMap(ModerationScope $scope, int $limit = 500): array
    {
        $where = "i.attributes->>'condition' = '".GoneRows::CONDITION."'";
        $params = ['limit' => $limit];
        $types = ['limit' => \Doctrine\DBAL\ParameterType::INTEGER];
        $frag = $scope->sqlFragment('i');
        if ('' !== $frag['sql']) {
            $where .= ' AND '.$frag['sql'];
            $params += $frag['params'];
            $types += $frag['types'];
        }

        /** @var list<array{id: int|string, letter: string, name: string, cc: string, lat: float|string, lng: float|string, since: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT i.id, i.letter, i.name, i.country_code AS cc,
                    ST_Y(ST_PointOnSurface(i.geom)) AS lat, ST_X(ST_PointOnSurface(i.geom)) AS lng,
                    to_char(i.updated_at, 'YYYY-MM-DD') AS since
             FROM item i WHERE {$where}
             ORDER BY i.updated_at DESC LIMIT :limit",
            $params,
            $types,
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'letter' => (string) $r['letter'],
            'name' => (string) $r['name'],
            'cc' => (string) $r['cc'],
            'lat' => (float) $r['lat'],
            'lng' => (float) $r['lng'],
            'since' => (string) $r['since'],
        ], $rows);
    }

    /**
     * Cache-busting `?v=` tag. Includes COUNT (deletes do not move max(updated_at)) and the build stamp (same rows can serialize differently after a deploy).
     *
     * @see docs/specs/catalog-data-model.md §9
     */
    public function versionTag(): string
    {
        $row = $this->db->fetchNumeric(
            'SELECT (SELECT count(*) FROM item),
                    (SELECT coalesce(max(updated_at)::text, \'\') FROM item),
                    (SELECT count(*) FROM item_confirmation),
                    (SELECT coalesce(max(created_at)::text, \'\') FROM item_confirmation),
                    (SELECT count(*) FROM recommended_route),
                    (SELECT coalesce(max(updated_at)::text, \'\') FROM recommended_route)',
        ) ?: [];
        $row[] = $this->buildVersion->stamp()['number'];

        return substr(hash('xxh128', implode('|', array_map(strval(...), $row))), 0, 16);
    }

    /**
     * Verified = `state = verified`, and nothing else (owner 2026-09-09: "we
     * must draw 1 line else everything gets too confusing"). The pin and the
     * record now say the same thing, because they read the same column.
     *
     * Two things used to short-circuit this query and no longer do. A single
     * confirmation drew a full pin while the record stayed Unverified, so a
     * `?` disappearing meant nothing a curator would recognise; the tally that
     * earns the state lives in `ItemConfirmationService` instead. And
     * provenance never verified anything: an authority row (a register tap) is
     * a place nobody here has stood at until a rider confirms it, and it wears
     * the dashed "?" pin until then (catalog-data-model.md §5; owner
     * 2026-09-06, after 3283 RIVM taps drew the plain pin). The register's
     * authority is its RANK, not a dot.
     *
     * @see docs/specs/map-and-search.md §12
     *
     * @return list<array{id: int, name: string, geom: string, attributes: string, source_ref: string, source: string, prov: string|null, pk: string|null, region_id: int|null, verified: bool, by_name: string|null, by_public: bool|null, by_uuid: string|null, letter: string, last_confirmed: string|null, state: string, imported_at: string|null, ev_provider: bool, ev_scope: bool|null, ev_conf: int|string, ev_last: string|null, ev_witness: string|null, ev_reclaimed: string|null, pin_lat: float|string|null, pin_lng: float|string|null}>
     */
    private function itemRows(string $letter, ?string $source = null, ?string $excludeSource = null, ?int $onlyId = null, bool $anyState = false): array
    {
        // Creator is the earliest type=new submission; harvested rows stay anonymous.
        $sql = 'SELECT i.id, i.name, i.letter, ST_AsGeoJSON(i.geom) AS geom, i.attributes, i.source_ref, i.source, s.name AS prov, i.region_id, dp.provider_key AS pk,
                       -- The pin a photo is measured against (PhotoValidator).
                       ST_Y(ST_PointOnSurface(i.geom)) AS pin_lat, ST_X(ST_PointOnSurface(i.geom)) AS pin_lng,
                       contributor.display_name AS by_name, contributor.public_profile AS by_public, contributor.uuid AS by_uuid,
                       (i.state = \'verified\') AS verified,
                       -- docs/specs/moderation-and-contribution.md 6.3: `form` must not reset freshness.
                       (SELECT max(c2.created_at) FROM item_confirmation c2
                         WHERE c2.item_id = i.id AND c2.source <> \'form\') AS last_confirmed,
                       i.state, i.imported_at, '.ItemEvidenceResolver::selectSql('i').'
                FROM item i
                LEFT JOIN world_subdivision s ON s.id = i.subdivision_id
                -- Which authority published the row, for the citation line in
                -- the drawer. NULL for every other source
                -- (data-provider-hierarchy.md 7).
                LEFT JOIN data_provider dp ON dp.id = i.provider_id
                LEFT JOIN LATERAL (
                    SELECT u.display_name, u.public_profile, u.uuid
                      FROM submission sub
                      JOIN users u ON u.id = sub.user_id
                     WHERE sub.item_id = i.id AND sub.type = \'new\'
                  ORDER BY sub.id
                     LIMIT 1
                ) contributor ON true
                WHERE i.letter = :letter'
                // anyState: a curator previewing a submitted item in its final form (map drawer);
                // every served payload keeps the state gate.
                .($anyState ? '' : ' AND i.state IN '.ItemState::servedSqlTuple());
        $params = ['letter' => $letter];
        if (null !== $source) {
            $sql .= ' AND i.source = :source';
            $params['source'] = $source;
        }
        if (null !== $excludeSource) {
            $sql .= ' AND i.source != :excludeSource';
            $params['excludeSource'] = $excludeSource;
        }
        // Same derivation as the bulk payload so a live-inserted feature stays byte-identical.
        if (null !== $onlyId) {
            $sql .= ' AND i.id = :onlyId';
            $params['onlyId'] = $onlyId;
        }
        // docs/specs/coverage-provider.md §9 — drop untouched OSM that coverage_poi now serves.
        if (\in_array($letter, CoverageRetirement::LETTERS, true)) {
            $sql .= ' AND NOT ('.CoverageRetirement::untouchedOsmSql('i').')';
        }
        // docs/specs/catalog-data-model.md §7 — gone from the map; curatedRefs() still claims the OSM ref.
        $sql .= ' AND '.GoneRows::notGoneSql('i');

        /* @var list<array{id: int, name: string, geom: string, attributes: string, source_ref: string, source: string, prov: string|null, region_id: int|null, verified: bool, by_name: string|null, by_public: bool|null, by_uuid: string|null, letter: string, last_confirmed: string|null, state: string, imported_at: string|null, ev_provider: bool, ev_scope: bool|null, ev_conf: int|string, ev_last: string|null, ev_witness: string|null, ev_reclaimed: string|null, pin_lat: float|string|null, pin_lng: float|string|null}> */
        return $this->db->fetchAllAssociative($sql.' ORDER BY i.id', $params);
    }

    /**
     * OSM refs the payload serves, for client-side tile dedupe. The claim rule is ClaimedOsmRefs, which mirrors itemRows() so a dropped row is not also suppressed in tiles.
     *
     * @see docs/specs/osm-data-architecture.md §8
     * @see docs/specs/coverage-provider.md §6
     *
     * @return list<string>
     */
    private function curatedRefs(): array
    {
        /* @var list<string> */
        return $this->db->fetchFirstColumn('SELECT ref FROM ('.ClaimedOsmRefs::selectSql().') AS u ORDER BY ref');
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
        $photoRefs = $this->osmPhotoRefs($letter);
        foreach ($this->itemRows($letter, $source, $excludeSource) as $row) {
            $features[] = $this->feature($row, $photoRefs[(int) $row['id']] ?? null);
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * One served item for live insert after approve, or null if the letter has no feature-collection payload.
     *
     * @see docs/specs/moderation-and-contribution.md §6.3
     *
     * @return array{letter: string, feature?: array{type: string, properties: array<string, mixed>, geometry: mixed}, climb?: array<string, mixed>}|null
     */
    public function featureForItem(int $itemId, bool $anyState = false): ?array
    {
        $letter = $this->db->fetchOne('SELECT letter FROM item WHERE id = :id', ['id' => $itemId]);
        if (!\is_string($letter)) {
            return null;
        }
        if ('N' === $letter) {
            $rows = $this->itemRows('N', onlyId: $itemId, anyState: $anyState);

            return [] === $rows ? null : ['letter' => 'N', 'climb' => $this->climbFromRow($rows[0])];
        }
        if (!\in_array($letter, self::POOL_LETTERS, true)) {
            return null;
        }

        $rows = $this->itemRows($letter, onlyId: $itemId, anyState: $anyState);

        return [] === $rows ? null : ['letter' => $letter, 'feature' => $this->feature($rows[0], $this->osmPhotoRefs($letter, $itemId)[$itemId] ?? null)];
    }

    /**
     * The OSM point whose photo each item's drawer may borrow, keyed by item id.
     *
     * An item stands for an OSM point through `source_ref` (materialized from
     * it) or `osm_ref` (an authority row attached to it): the same link
     * CoverageRepository::detail() joins on. The point qualifies when
     * CoverageRepository::photoPossible() says its tags could resolve a
     * Commons photo, the answer its own drawer gets as `photo`. Each ref is
     * read from the coverage row detail() reads (lowest letter), so the photo
     * endpoint later resolves exactly these tags.
     *
     * Empty where the pipeline has not created coverage_poi (it is not a
     * migration table).
     *
     * @see docs/specs/coverage-provider.md §7
     *
     * @return array<int, string>
     */
    private function osmPhotoRefs(string $letter, ?int $onlyId = null): array
    {
        if (null === $this->db->fetchOne("SELECT to_regclass('public.coverage_poi')")) {
            return [];
        }
        $params = ['letter' => $letter];
        $sql = "SELECT i.id, cp.ref, cp.tags
                FROM item i
                CROSS JOIN LATERAL (SELECT DISTINCT r FROM unnest(ARRAY[i.source_ref, i.osm_ref]) AS r WHERE r IS NOT NULL) refs
                JOIN LATERAL (
                    SELECT c.ref,
                           jsonb_strip_nulls(jsonb_build_object('wikimedia_commons', c.tags->'wikimedia_commons',
                                                                'image', c.tags->'image', 'wikidata', c.tags->'wikidata')) AS tags
                      FROM coverage_poi c
                     WHERE c.ref = refs.r
                  ORDER BY c.letter
                     LIMIT 1
                ) cp ON true
                WHERE i.letter = :letter AND i.state IN ".ItemState::servedSqlTuple();
        if (null !== $onlyId) {
            $sql .= ' AND i.id = :onlyId';
            $params['onlyId'] = $onlyId;
        }

        /** @var list<array{id: int|string, ref: string, tags: string}> $rows */
        $rows = $this->db->fetchAllAssociative($sql.' ORDER BY i.id, cp.ref = i.source_ref DESC, cp.ref', $params);
        $refs = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (isset($refs[$id])) {
                continue;
            }
            /** @var array<string, mixed> $tags */
            $tags = json_decode($row['tags'], true, 512, \JSON_THROW_ON_ERROR);
            if (CoverageRepository::photoPossible($tags)) {
                $refs[$id] = $row['ref'];
            }
        }

        return $refs;
    }

    /**
     * The per-row mapping shared by the bulk payload and featureForItem(), so
     * a live-inserted feature can never drift from the served one. `$photoRef`
     * is the OSM point whose photo the item may borrow (osmPhotoRefs()).
     *
     * @param array{id: int, name: string, geom: string, attributes: string, source_ref: string, source: string, prov: string|null, pk: string|null, region_id: int|null, verified: bool, by_name: string|null, by_public: bool|null, by_uuid: string|null, letter: string, last_confirmed: string|null, state: string, imported_at: string|null, ev_provider: bool, ev_scope: bool|null, ev_conf: int|string, ev_last: string|null, ev_witness: string|null, ev_reclaimed: string|null, pin_lat: float|string|null, pin_lng: float|string|null} $row
     *
     * @return array{type: string, properties: array<string, mixed>, geometry: mixed}
     */
    private function feature(array $row, ?string $photoRef = null): array
    {
        $props = $this->decode($row['attributes']);
        // Only the photos PhotoValidator shows on this place: the same answer
        // every photo got when it was linked, so a scenic pin never promises a
        // view that was photographed somewhere else.
        $props = PhotoValidator::sift($props, PhotoPlace::of($row['letter'], $row['pin_lat'], $row['pin_lng']))['attributes'];
        // No photo of its own: the drawer asks /map/coverage/photo for the OSM
        // point's, judged against this item's pin (coverage-provider.md §7).
        // Absent otherwise, so every other row stays byte-stable.
        if (null !== $photoRef && !\array_key_exists('photo', $props) && !\array_key_exists('photos', $props)) {
            $props['photoRef'] = $photoRef;
        }
        if ('' !== $row['name']) {
            $props['n'] = $row['name'];
        }
        if (null !== $row['prov']) {
            $props['prov'] = $row['prov'];
        }
        $props['srcType'] = $row['source'];
        // The publisher's slug, resolved against the payload's own `providers`
        // map by the drawer. Absent for every source that has no publisher, so
        // the payload stays byte-stable for the rows that had no such key
        // before (data-provider-hierarchy.md §7).
        if (null !== $row['pk']) {
            $props['pk'] = $row['pk'];
        }
        // Named only with consent. Fail-closed: missing/private profile → by:0 (anonymous), never a leaked name.
        if (null !== ($row['by_name'] ?? null)) {
            $public = (bool) $row['by_public'];
            $props['by'] = $public ? 1 : 0;
            if ($public) {
                $props['byName'] = (string) $row['by_name'];
                $props['byUuid'] = (string) $row['by_uuid'];
            }
        }
        // docs/specs/map-and-search.md §12 — absent key = community (byte-stable).
        if ($row['verified']) {
            $props['v'] = 1;
        }
        $now = new \DateTimeImmutable();
        // The two axes the pin draws (data-provider-hierarchy.md §6.7): the
        // border reads `custody`, the badge reads `rung`. Neither is derived
        // client-side.
        $evidence = $this->evidence->fromRow($row, $now);
        $props['rung'] = $evidence->rung;
        $props['custody'] = $evidence->custody->value;
        // The public half of data-provider-hierarchy.md §6.7.3: the day the
        // provider's survey took the record back, on the cacheable body.
        // Absent unless it happened, so untouched rows stay byte-stable.
        if (null !== ($row['ev_reclaimed'] ?? null)) {
            $props['reclaimed'] = (new \DateTimeImmutable((string) $row['ev_reclaimed']))->format('Y-m-d');
        }
        // docs/specs/moderation-and-contribution.md §10.1a — key absent when freshness does not apply.
        $type = ItemType::fromLetter((string) $row['letter']);
        if (null !== $type && null !== $row['last_confirmed']) {
            $last = new \DateTimeImmutable((string) $row['last_confirmed']);
            $state = $this->freshness->state($type, $last, $now);
            if (null !== $state) {
                $props['freshness'] = ['state' => $state, 'lastConfirmed' => $last->format('Y-m-d')];
            }
        }
        // `id` keeps properties a JSON object (GeoJSON forbids `[]`).
        $props['id'] = (int) $row['id'];
        // docs/specs/map-and-search.md §4.5 — omit rid when outside every region.
        if (null !== $row['region_id']) {
            $props['rid'] = (int) $row['region_id'];
        }

        return [
            'type' => 'Feature',
            'properties' => $props,
            'geometry' => $this->decode($row['geom']),
        ];
    }

    /**
     * CC_CLIMBS shape: bare objects, geom.ll = [lat, lng], citation back on top.
     *
     * @return list<array<string, mixed>>
     */
    private function climbs(): array
    {
        $climbs = [];
        foreach ($this->itemRows('N') as $row) {
            $climbs[] = $this->climbFromRow($row);
        }

        return $climbs;
    }

    /**
     * One climb in the map.js shape. Unsealed (`...`) so callers can pass the full served row.
     *
     * @param array{id: int, name: string, geom: string, attributes: string, source: string, region_id: int|null, verified: bool, state: string, imported_at: string|null, ev_provider: bool, ev_scope: bool|null, ev_conf: int|string, ev_last: string|null, ev_witness: string|null, ev_reclaimed: string|null, ...} $row
     *
     * @return array<string, mixed>
     */
    private function climbFromRow(array $row): array
    {
        $attrs = $this->decode($row['attributes']);
        // Fixture top-level "source" is a citation; provenance owns the column, so import stores it as "attribution".
        if (\array_key_exists('attribution', $attrs)) {
            $attrs['source'] = $attrs['attribution'];
            unset($attrs['attribution']);
        }
        /** @var array{coordinates: array{0: float, 1: float}} $geo */
        $geo = $this->decode($row['geom']);
        $climb = ['id' => (int) $row['id'], 'name' => $row['name'], 'srcType' => $row['source'], 'geom' => ['ll' => [$geo['coordinates'][1], $geo['coordinates'][0]]]] + $attrs;
        // docs/specs/map-and-search.md §12 — absent key = community.
        if ($row['verified']) {
            $climb['v'] = 1;
        }
        $evidence = $this->evidence->fromRow($row, new \DateTimeImmutable());
        $climb['rung'] = $evidence->rung;
        $climb['custody'] = $evidence->custody->value;
        if (null !== $row['region_id']) {
            $climb['rid'] = (int) $row['region_id'];
        }

        return $climb;
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
            $seg = ['id' => (int) $row['id'], 'name' => $row['name'], 'srcType' => $row['source']] + $this->decode($row['attributes']);
            // Derive `cls` at serve time; a stored cls is never second-guessed.
            if (!isset($seg['cls'])) {
                $cls = SurfaceVocabulary::tileClassFor(\is_string($seg['surface'] ?? null) ? $seg['surface'] : null);
                if (null !== $cls) {
                    $seg['cls'] = $cls;
                }
            }
            // docs/specs/map-and-search.md §12 — absent key = community.
            if ($row['verified']) {
                $seg['v'] = 1;
            }
            if (null !== $row['region_id']) {
                $seg['rid'] = (int) $row['region_id'];
            }
            /* Who filed it, on the same terms as every other layer
               ({@see mapRow()}): named only with consent, fail-closed to
               anonymous. A road surface is a rider's work as much as a water
               tap is, and this shape carried no contributor at all, so the
               drawer fell back to citing OSM for values a rider typed
               (owner-reported 2026-08-31). OSM is still the source of the LINE,
               which is what srcType and the provenance line under the name say.
               Duplicated from mapRow() rather than shared because the two build
               different shapes; the consent rule is what has to stay identical,
               and SurfaceContributorTest pins that. */
            if (null !== ($row['by_name'] ?? null)) {
                $public = (bool) $row['by_public'];
                $seg['by'] = $public ? 1 : 0;
                if ($public) {
                    $seg['byName'] = (string) $row['by_name'];
                    $seg['byUuid'] = (string) $row['by_uuid'];
                }
            }
            if (str_starts_with($row['source_ref'], 'way/')) {
                $seg['wayId'] = (int) substr($row['source_ref'], 4);
            }
            /** @var array{type?: string, coordinates: list<array{0: float, 1: float}>} $geo */
            $geo = $this->decode($row['geom']);
            // Skip a non-line: flipping vertex pairs would corrupt the whole payload.
            if ('LineString' !== ($geo['type'] ?? null)) {
                continue;
            }
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
        /** @var list<array{id: int, name: string, geom: string, distance_m: int, ascent_m: int, attributes: string, source: string, state: string, region_id: int|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, ST_AsGeoJSON(geom) AS geom, distance_m, ascent_m, attributes, source, state, region_id
             FROM recommended_route WHERE state IN '.ItemState::servedSqlTuple().' ORDER BY id',
        );

        $routes = [];
        foreach ($rows as $row) {
            $attrs = $this->decode($row['attributes']);
            $route = ['id' => (int) $row['id'], 'name' => $row['name'], 'srcType' => $row['source']];
            $route['state'] = (string) $row['state'];
            // docs/specs/map-and-search.md §4.5
            if (null !== $row['region_id']) {
                $route['rid'] = (int) $row['region_id'];
            }
            if (isset($attrs['season'])) {
                $route['season'] = $attrs['season'];
            }
            // Force float: PHP `/` yields int for even km; the fixture serializes 87.0.
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
            foreach ([
                'difficulty', 'uploader', 'photo',
                'dominantSurface', 'surfaces', 'note', 'quietness', 'scenic', 'friendliness',
                'bikeTypes', 'gradientLimited', 'bestDirection',
            ] as $key) {
                if (isset($attrs[$key])) {
                    $route[$key] = $attrs[$key];
                }
            }
            // docs/specs/route-domain.md §9 — one canonical shape on every serving path.
            $canonicalDifficulty = DifficultyVocabulary::canonical($attrs['difficulty'] ?? null);
            if (null !== $canonicalDifficulty) {
                $route['difficulty'] = $canonicalDifficulty;
            } else {
                unset($route['difficulty']);
            }
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
     * Heat points for `/map/heat.json`, not catalog.json. Null `rid` renders only in Everywhere.
     *
     * @see docs/specs/map-and-search.md §11
     *
     * @return list<array{0: float, 1: float, 2: string|null, 3: int|null}>
     */
    public function heat(): array
    {
        // Only `auto` heat is served until a decision adds other sources.
        /** @var list<array{geom: string, season: string|null, region_id: int|string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT ST_AsGeoJSON(geom) AS geom, season, region_id FROM heat_point WHERE source = 'auto' ORDER BY id",
        );

        $points = [];
        foreach ($rows as $row) {
            /** @var array{coordinates: array{0: float, 1: float}} $geo */
            $geo = $this->decode($row['geom']);
            $points[] = [
                $geo['coordinates'][1],
                $geo['coordinates'][0],
                $row['season'],
                null === $row['region_id'] ? null : (int) $row['region_id'],
            ];
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

    /**
     * Decode attributes and fail-closed strip UNSAFE `links`. UNKNOWN still renders.
     *
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> $attrs */
        $attrs = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (isset($attrs['links'])) {
            $attrs['links'] = $this->linkVerdicts->withhold($attrs['links']);
            if ([] === $attrs['links']) {
                unset($attrs['links']);
            }
        }

        return $attrs;
    }
}
