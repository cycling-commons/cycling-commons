<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use App\Catalog\Links\LinkVerdictStore;
use App\Moderation\ModerationScope;
use App\Service\BuildVersion;
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
    /**
     * The letters served as plain feature collections — the ones featureForItem()
     * can hand the map a single feature for. A (segments), B (climbs) and
     * K (routes) carry their own shapes and are deliberately not covered.
     */
    public const array POOL_LETTERS = ['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'M'];

    public function __construct(
        private readonly Connection $db,
        private readonly BuildVersion $buildVersion,
        private readonly ConfirmationFreshness $freshness,
        private readonly LinkVerdictStore $linkVerdicts,
    ) {
    }

    /**
     * The full catalog payload, letters A–L.
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
            // F (hazards & conditions): served items with no coverage-tile
            // layer and no OSM bulk pool — they render as CATALOG point
            // features client-side (map-and-search.md §4.5 Task A). Region
            // stamping is automatic (item rows; recomputeMembership), so the
            // rid flows through the map's scope gate like every other letter.
            'F' => $this->featureCollection('F'),
            'G' => $this->featureCollection('G'),
            'H' => $this->featureCollection('H'),
            'I' => $this->featureCollection('I'),
            'J' => $this->featureCollection('J'),
            'K' => $this->routes(),
            // L (the ~6,600 heat points) is NOT here. It moved to its own
            // endpoint on 2026-08-09: the layer is Off by default, so every
            // visitor was paying its bytes on the critical path for something
            // most of them never switch on. {@see heat()} and
            // MapController::heat().
            // M · Public toilets (2026-07-30) — plain served-items collection,
            // same path as G/H utilities.
            'M' => $this->featureCollection('M'),
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
     * The places approved as "Not there anymore", for CURATOR eyes only.
     *
     * A gone item is hidden from the served payload (itemRows) while its row
     * — and the reporter's submission mapping — stays in the table for good.
     * That made it invisible EVERYWHERE, which answered removal but not
     * return: if the tap is rebuilt or the shop reopens, nobody could ever
     * find the hidden item to reactivate it (owner 2026-08-13). So the map
     * page hands curators these as a ghost layer, scoped like the pending
     * queue; reactivation is the ordinary edit form setting the condition
     * back — no new mechanic.
     *
     * @return list<array{id: int, letter: string, name: string, cc: string, lat: float, lng: float, since: string}>
     */
    public function goneForMap(ModerationScope $scope, int $limit = 500): array
    {
        $where = "i.attributes->>'condition' = 'Not there anymore'";
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
     * A compact tag that changes whenever the payload would.
     *
     * /map embeds it as `?v=` on CC_CATALOG_URL, and that is the whole cache
     * story: catalog.json is deliberately browser-cached for an hour (it is
     * the critical-path payload), so without a versioned URL a rider whose
     * submission was just approved reloads into the PRE-approval JSON and
     * watches their contribution "disappear" until the hour runs out
     * (owner-reported 2026-08-13, the Zuiderdijk approval). A new tag mints a
     * new URL; the stale cache entry is simply never asked for again, while
     * an unchanged catalog keeps hitting the browser cache exactly as before.
     *
     * Three tables feed the payload, each sampled as (row count, latest
     * change): `item` (every letter's rows — moderation decisions,
     * materialize-on-edit and the closure-expiry sweep all bump updated_at),
     * `item_confirmation` (flips the served `v` flag without touching item),
     * and `recommended_route` (K). The COUNT is not decoration: a takedown
     * deletes a row without moving any max(updated_at), and a removed item
     * kept alive by a cached payload is the one staleness with legal weight.
     *
     * The BUILD version rides the hash too: a deploy can change what the same
     * rows serialize to (the very fix that introduced this method changed the
     * A shape without touching a row), and a data-only tag would let the
     * browser replay the pre-deploy payload for an hour after every release.
     *
     * Computed per /map render, uncached: three aggregate scans over tables
     * whose biggest is ~10^4-10^5 rows — small against the page's existing
     * region queries, and a server-side TTL here would just reintroduce a
     * shorter version of the very window this exists to close.
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
     * The verified derivation (map-and-search.md §12): verified state, a rider
     * confirmation, or official-registry provenance. Tourisme Wallonie PIVOT
     * rows count as verified.
     *
     * A `form`-sourced confirmation is excluded: that is the submitter's own
     * answer on the improve form, and counting it would let anyone turn their
     * own contribution into a verified pin with nobody else ever having seen
     * the place (ConfirmationSource).
     *
     * @return list<array{id: int, name: string, geom: string, attributes: string, source_ref: string, source: string, prov: string|null, region_id: int|null, verified: bool, by_name: string|null, by_public: bool|null, by_uuid: string|null, letter: string, last_confirmed: string|null}>
     */
    private function itemRows(string $letter, ?string $source = null, ?string $excludeSource = null, ?int $onlyId = null): array
    {
        /* WHO ADDED THIS PLACE (owner 2026-08-14: "tagged via Scout but added
           by a rider, so show the rider name").

           A point item has no creator column - the person is on the submission
           that minted it, which the item points back at as `sub:<id>`. The
           join is on `submission.item_id` rather than by parsing that string:
           it is indexed (`idx_submission_item`), and a guarded cast of
           free-text source_ref is exactly the kind of thing that works until a
           row says something else. `type = 'new'` is the CREATING submission,
           never a later edit, and the earliest one wins because a rejected
           proposal may be revived rather than twinned
           (CatalogContributionService), so an item can have more than one.

           Harvested rows join to nothing and stay anonymous, which is correct:
           OSM did not "share" anything with us. */
        $sql = 'SELECT i.id, i.name, i.letter, ST_AsGeoJSON(i.geom) AS geom, i.attributes, i.source_ref, i.source, s.name AS prov, i.region_id,
                       contributor.display_name AS by_name, contributor.public_profile AS by_public, contributor.uuid AS by_uuid,
                       (i.state = \'verified\' OR i.source = \'pivot\' OR EXISTS (SELECT 1 FROM item_confirmation c WHERE c.item_id = i.id AND c.source <> \'form\')) AS verified,
                       /* When somebody last stood here. `form` excluded for the
                          same reason it is excluded from `verified` above: the
                          submitter answering their own improve form is not a
                          second pair of eyes, and letting it reset the clock
                          would let a contributor keep their own pin fresh for
                          ever without anyone visiting. */
                       (SELECT max(c2.created_at) FROM item_confirmation c2
                         WHERE c2.item_id = i.id AND c2.source <> \'form\') AS last_confirmed
                FROM item i
                LEFT JOIN world_subdivision s ON s.id = i.subdivision_id
                LEFT JOIN LATERAL (
                    SELECT u.display_name, u.public_profile, u.uuid
                      FROM submission sub
                      JOIN users u ON u.id = sub.user_id
                     WHERE sub.item_id = i.id AND sub.type = \'new\'
                  ORDER BY sub.id
                     LIMIT 1
                ) contributor ON true
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
        // One item, for featureForItem() — same derivation as the bulk payload,
        // so a live-inserted feature is byte-identical to the one the next
        // catalog fetch will carry.
        if (null !== $onlyId) {
            $sql .= ' AND i.id = :onlyId';
            $params['onlyId'] = $onlyId;
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
        /* A place a rider reported gone is not a place. It stays in the table -
           the report is a record, and a curator may disagree - but it is not
           drawn, and (deliberately) its ref is STILL claimed by curatedRefs(),
           so the OSM point it came from does not reappear in the hole it left.
           That pair is the whole behaviour: gone means gone from the map, not
           handed back to the reference layer. */
        $sql .= " AND COALESCE(i.attributes->>'condition', '') <> 'Not there anymore'";

        /* @var list<array{id: int, name: string, geom: string, attributes: string, source_ref: string, source: string, prov: string|null, region_id: int|null, verified: bool, by_name: string|null, by_public: bool|null, by_uuid: string|null, letter: string, last_confirmed: string|null}> */
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
        $refs = "SELECT DISTINCT i.source_ref AS ref FROM item i WHERE i.source = 'osm' AND i.state IN ".ItemState::servedSqlTuple()
            .' AND NOT (i.letter IN '.CoverageRetirement::lettersSqlTuple()
            .' AND '.CoverageRetirement::untouchedOsmSql('i').')';
        // Plus every way ref a served segment item SPANS (attributes
        // waysSpanned, owner 2026-08-13): a run-prefilled item covers many
        // OSM ways under one source_ref, and each covered way's tile line
        // must retire — a red dash under a green answer contradicts it.
        // jsonb_exists(), not the `?` operator: DBAL reads a bare `?` as a
        // positional placeholder (the known DBAL trap).
        $spanned = "SELECT DISTINCT jsonb_array_elements_text(i.attributes->'waysSpanned') AS ref"
            ." FROM item i WHERE jsonb_exists(i.attributes, 'waysSpanned') AND i.state IN ".ItemState::servedSqlTuple();

        /* @var list<string> */
        return $this->db->fetchFirstColumn('SELECT ref FROM ('.$refs.' UNION '.$spanned.') AS u ORDER BY ref');
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
            $features[] = $this->feature($row);
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * One served item, in the letter it belongs to — or null when the item is
     * not (yet) served, or its letter has no feature-collection payload.
     *
     * Approving a submission puts a new item on the map, but the map's pools
     * were built at page load: without this the rider's contribution stayed
     * invisible, and the pending pin simply vanished, until the curator
     * reloaded (moderation-and-contribution.md §6.2). The map inserts exactly
     * this feature into the matching pool instead.
     *
     * Two shapes, because the map has two: the pool letters come back as a
     * GeoJSON `feature`, and B · climbs as the `climb` object map.js consumes
     * (its own shape, with route/grad/steep). A · segments and K · routes are
     * still left to the next catalog fetch — a segment's geometry and a route's
     * whole domain are not worth half-supporting on this path.
     *
     * @return array{letter: string, feature?: array{type: string, properties: array<string, mixed>, geometry: mixed}, climb?: array<string, mixed>}|null
     */
    public function featureForItem(int $itemId): ?array
    {
        $letter = $this->db->fetchOne('SELECT letter FROM item WHERE id = :id', ['id' => $itemId]);
        if (!\is_string($letter)) {
            return null;
        }
        if ('B' === $letter) {
            $rows = $this->itemRows('B', onlyId: $itemId);

            return [] === $rows ? null : ['letter' => 'B', 'climb' => $this->climbFromRow($rows[0])];
        }
        if (!\in_array($letter, self::POOL_LETTERS, true)) {
            return null;
        }

        $rows = $this->itemRows($letter, onlyId: $itemId);

        return [] === $rows ? null : ['letter' => $letter, 'feature' => $this->feature($rows[0])];
    }

    /**
     * The per-row mapping shared by the bulk payload and featureForItem(), so
     * a live-inserted feature can never drift from the served one.
     *
     * @param array{id: int, name: string, geom: string, attributes: string, source_ref: string, source: string, prov: string|null, region_id: int|null, verified: bool, by_name: string|null, by_public: bool|null, by_uuid: string|null, letter: string, last_confirmed: string|null} $row
     *
     * @return array{type: string, properties: array<string, mixed>, geometry: mixed}
     */
    private function feature(array $row): array
    {
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
        /* The rider who added it, named only with their consent.
           `public_profile` is the gate the profile page itself uses, and it is
           read as a fail-closed default: a row that joined to nobody, or a
           rider who has not made their profile public, yields `by:0` and the
           drawer says "shared anonymously" rather than nothing at all - the
           contribution is still a rider's, and saying so without naming them
           is the whole point of the flag. The uuid rides along only for a
           public profile, because it is the link target; display names are not
           unique and are not identity. */
        if (null !== ($row['by_name'] ?? null)) {
            $public = (bool) $row['by_public'];
            $props['by'] = $public ? 1 : 0;
            if ($public) {
                $props['byName'] = (string) $row['by_name'];
                $props['byUuid'] = (string) $row['by_uuid'];
            }
        }
        // Real community-tier signal (map-and-search.md
        // §12): v:1 = verified state OR at least one rider confirmation.
        // Absent key = community tier (keeps unverified payloads byte-stable).
        if ($row['verified']) {
            $props['v'] = 1;
        }
        /* HOW OLD THE LAST CHECK IS (owner 2026-08-12). Only for letters whose
           confirmations go off, and only where somebody has actually
           confirmed: ConfirmationFreshness carries that reasoning. The key is
           ABSENT for everything else, so every item this does not apply to
           stays byte-identical to what it served before this existed.

           The drawer has rendered `f.freshness` since it was written and
           nothing ever produced it - the map template says so in as many words
           ("f.freshness is produced by no server path either"). This is that
           path, so the drawer's freshness line lights up with no client
           change. */
        $type = ItemType::fromLetter((string) $row['letter']);
        if (null !== $type && null !== $row['last_confirmed']) {
            $last = new \DateTimeImmutable((string) $row['last_confirmed']);
            $state = $this->freshness->state($type, $last, new \DateTimeImmutable());
            if (null !== $state) {
                $props['freshness'] = ['state' => $state, 'lastConfirmed' => $last->format('Y-m-d')];
            }
        }
        // The DB item id always makes $props non-empty, so it always
        // encodes as a JSON object, never `[]` (GeoJSON requires an
        // object; an empty array would encode as `[]` instead).
        $props['id'] = (int) $row['id'];
        // Region membership for map.js scope filtering (map-and-search.md §4.5
        // §4 / §7 Phase 2). Absent for rows outside every region (byte-stable).
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
        foreach ($this->itemRows('B') as $row) {
            $climbs[] = $this->climbFromRow($row);
        }

        return $climbs;
    }

    /**
     * One climb, in the shape map.js consumes.
     *
     * Extracted from climbs() so featureForItem() can rebuild a SINGLE climb
     * after an approval — a curator approving an edit has to see it applied
     * without reloading the page, and rebuilding it here rather than in a
     * second mapper is what stops the live-updated climb drifting from the
     * served one.
     *
     * Unsealed (`...`) because both callers hand over the FULL served row,
     * which carries the columns this mapper does not read (source_ref, prov,
     * the contributor join). Sealing it would force a caller to strip fields
     * before calling, which is work done only to satisfy a docblock.
     *
     * @param array{id: int, name: string, geom: string, attributes: string, source: string, region_id: int|null, verified: bool, ...} $row
     *
     * @return array<string, mixed>
     */
    private function climbFromRow(array $row): array
    {
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
            // srcType is the raw ItemSource enum value (see featureCollection()).
            $seg = ['id' => (int) $row['id'], 'name' => $row['name'], 'srcType' => $row['source']] + $this->decode($row['attributes']);
            // The map draws a segment in the class layer `cls` names, but only
            // HARVESTED rows ever stored one — a rider-materialized segment
            // (the confirm/correct flow) stores the form vocabulary and no
            // cls, and drew in the grey 'other' fallback (owner-reported
            // 2026-08-13, the approved Zuiderdijk). Derived at serve time so
            // every past and future row heals at once; a stored cls is never
            // second-guessed.
            if (!isset($seg['cls'])) {
                $cls = SurfaceVocabulary::tileClassFor(\is_string($seg['surface'] ?? null) ? $seg['surface'] : null);
                if (null !== $cls) {
                    $seg['cls'] = $cls;
                }
            }
            // Real community-tier signal, same derivation as featureCollection()
            // (map-and-search.md §12); absent key = community, byte-stable.
            if ($row['verified']) {
                $seg['v'] = 1;
            }
            if (null !== $row['region_id']) {
                $seg['rid'] = (int) $row['region_id'];
            }
            if (str_starts_with($row['source_ref'], 'way/')) {
                $seg['wayId'] = (int) substr($row['source_ref'], 4);
            }
            /** @var array{type?: string, coordinates: list<array{0: float, 1: float}>} $geo */
            $geo = $this->decode($row['geom']);
            // A is the one letter whose geometry MUST be a line. A row that is
            // not — a legacy import, or a bug upstream — would otherwise be
            // flipped as if its coordinates were vertex pairs and corrupt the
            // whole payload, taking the map with it. Skip the row instead: one
            // missing segment is a gap, a broken catalog.json is an outage.
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
            // srcType is the raw ItemSource enum value (see featureCollection()).
            $route = ['id' => (int) $row['id'], 'name' => $row['name'], 'srcType' => $row['source']];
            // Raw ItemState value so the map can badge unverified ("proposed")
            // routes distinct from verified ones.
            $route['state'] = (string) $row['state'];
            // Region membership for map.js scope filtering (map-and-search.md §4.5
            // §4 / §7 Phase 2). Absent for routes outside every region.
            if (null !== $row['region_id']) {
                $route['rid'] = (int) $row['region_id'];
            }
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
     * The ride heatmap's points — served by `/map/heat.json`, NOT by
     * catalog.json, and fetched only when a rider first turns the layer on.
     *
     * Shape: [[lat, lng, season, rid], …] in import order.
     * rid = region_id (07-20 review finding 5): the heat layer scope-filters
     * client-side like every served layer; null renders only in Everywhere
     * (the leak-safe rid-less default, map-and-search.md §4.5).
     *
     * @return list<array{0: float, 1: float, 2: string|null, 3: int|null}>
     */
    public function heat(): array
    {
        // Only 'auto' heat is served today. Rows from any future source
        // (e.g. user-contributed traces) are deliberately absent until a
        // future decision adds them.
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

    /** @return array<string, mixed> */
    /**
     * One decode for every served payload, and the RENDER-side half of the
     * Safe Browsing pair (App\Catalog\Links\SafeBrowsing).
     *
     * It fails CLOSED: a url whose last verdict was UNSAFE is stripped out of
     * `links` before it can reach an `<a href>` on the public map. Here rather
     * than at each caller because this method is the one chokepoint every
     * served attribute set passes through, and a withhold any one forgotten
     * path could skip is not a withhold.
     *
     * UNKNOWN still renders. Withholding everything unchecked would empty the
     * map the day a key expired, which is the failure mode a security control
     * is not allowed to have - the SUBMIT side is where unknown is treated
     * generously, and it fails open for the opposite reason.
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
