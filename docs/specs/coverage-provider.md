<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Coverage Provider

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

> This is the contract for the coverage provider as built: the batch job,
> serving cache, tiles and query plane below exist, and every file path is the
> real location in the tree.

This document is the concrete realization of the coverage architecture in
[osm-data-architecture.md §5](osm-data-architecture.md): how uncurated OSM
coverage (category-1 data) is extracted, cached, tiled, and served — worldwide-
ready, searchable, and without ever calling Overpass on a user request. The
*policy* (what we cache, the tag catalogue, licensing, the reference-only
public API) is owned by [osm-data-architecture.md](osm-data-architecture.md)
and not restated here. The map/search *presentation* of coverage (markers,
search ordering, reveal behaviour) is owned by
[map-and-search.md](map-and-search.md). Canonical item identity and serving
are owned by [catalog-data-model.md](catalog-data-model.md).

---

## 1. Architecture

**Pipeline builds, Symfony serves, PMTiles display.** The Python `pipeline`
container is an internal batch worker — it never serves requests. It produces
two artifacts from one extract; the index is the source of truth and the tiles
are generated *from* it, so display and search cannot disagree.

```
Weekly, per region (pipeline container, scheduled):
  Geofabrik PBF ─ osmium tags-filter ─ pyosmium ─▶ coverage_poi (PostGIS, per-region atomic swap)
                                                        │
                                     full-index export ─▶ tippecanoe ─▶ coverage.pmtiles ─▶ CC bucket

Request time:
  pan/zoom ─▶ byte-range reads of coverage.pmtiles (bucket + nginx range proxy; zero PHP)
  search / nearby / counts / drawer detail ─▶ Symfony /map/coverage/* ─▶ coverage_poi ⋈ item
```

Invariants:

- **Never live Overpass** on any user request, anywhere
  ([osm-data-architecture.md §5](osm-data-architecture.md)). CI and tests never
  touch the network either — the pipeline test suite runs against a committed
  fixture PBF (`pipeline/tests/fixtures/mini.osm.pbf`).
- **Curated data stays canonical** in `item` (plus climbs, routes; PIVOT stays
  canonical with its CC-BY attribution). Coverage and canonical are merged at
  read time and deduped by ref (coverage-provider.md §5).
- **Regions are independent.** Each Geofabrik region (`COVERAGE_REGIONS`, comma-
  separated, e.g. `europe/belgium,europe/netherlands`) refreshes as a whole on its
  own run; regions can stagger across the week (the nightly dispatcher, below,
loads the stalest first). The onboarded extracts are
  read from the `country_extract` table (`python -m coverage.regions`), so a
  country cannot be onboarded without its extract being harvested; a run
  that sets `COVERAGE_REGIONS` replaces that list on purpose. An unknown
  region hard-fails rather than silently disabling ownership. *Border caveat:* Geofabrik
  extracts overlap in a border buffer, so one OSM entity can arrive staged in
  two adjacent extracts with the same `(ref, letter)`. Ownership is decided at
  staging, by geometry, not by write order: each staged row is
  resolved to the **single nearest region** within `BOUNDARY_SNAP_DEG`, ordered
  by distance then area then id, and the extract keeps the row only if that
  region's country is its own. Because that lookup ignores which extract is
  asking, every extract computes the same answer, so exactly one ever inserts a
  given `(ref, letter)` and a shared border entity never collides with the
  global `UNIQUE(ref, letter)`. *"Within `BOUNDARY_SNAP_DEG` of a region of my
  own country" is NOT sufficient*: Geofabrik's overlap reaches ~0.10°, ten
  times the snap, so both neighbours would satisfy that weaker test and
  ownership would fall back to write order (measured: ~1,691 rows).
  Nearest-wins is what makes it exclusive; containment still beats proximity
  automatically at distance 0. `load_region`'s `INSERT … ON CONFLICT (ref,
  letter) DO UPDATE` runs after that filter and derives `country_code` from the
  geometric region; it does not decide ownership, it is the safety net for a
  stale row a former owner has not deleted yet. A staged row that falls in no onboarded region at all is dropped rather
  than kept with a NULL region. Planet scale is config + disk, gated on a
  measured dry-run (see Open questions).
- **Own object-storage bucket** from day one, separate from any
  shared basemap bucket, so coverage cost stays observable. The production
  bucket's name is deployment configuration, never repository content
  (media-storage-architecture.md §2.0 owns that rule); the dev stack's MinIO
  mirror is named `cc-maps` (`developers/docker/compose.yaml`, profile
  `storage`).

## 2. Coverage cache schema: `coverage_poi`

**Pipeline-owned DDL, not a Doctrine migration.** The pipeline creates the
table idempotently at run start (`pipeline/coverage/load.py::ensure_schema`).
Doctrine's `schema_filter` (`web/config/packages/doctrine.yaml`) excludes
`coverage_` on the same pattern as `topology.`, so migrations and
`schema:validate` never touch it. The table is a **disposable
cache** — never edited by the app or by hand.

```sql
-- Provenance lookup: the Geofabrik extract slug is stored ONCE here, not
-- repeated per POI. Self-fills via get-or-create in load_region (no enum DDL,
-- no pre-seeding); smallint holds far more than Geofabrik's ~700 extracts.
CREATE TABLE IF NOT EXISTS coverage_source (
    id   smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug varchar(64) NOT NULL UNIQUE      -- Geofabrik extract ('europe/belgium')
);

CREATE TABLE IF NOT EXISTS coverage_poi (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ref           varchar(160) NOT NULL,  -- 'node/61146471' | 'way/…' = item.source_ref format
    letter        char(1)      NOT NULL,  -- B C D F G O P Q (osm-data-architecture.md §5 catalogue)
    kind          varchar(16),            -- serviceKind for D (shop|station|pump), placeKind for P and Q (osm-data-architecture.md §5a), NULL otherwise
    name          varchar(255),           -- OSM name tag, NULL when unnamed
    geom          geometry(Point, 4326) NOT NULL, -- nodes as-is; ways centroid at load
    tags          jsonb        NOT NULL,  -- trimmed to the contract's storedTagKeys (§2.1), never the full tag set
    osm_version   int,                    -- upstream version
    osm_ts        timestamptz,            -- upstream last-edit timestamp
    src_region_id smallint     NOT NULL REFERENCES coverage_source(id), -- harvest extract (normalized: 2 bytes/row, not a repeated ~16-byte string)
    country_code  char(2),                -- stamped from extract config
    region_id     int,                    -- ST_Contains(region.geom, geom) at load; NULL until polygons exist. Soft ref to region.id (4 bytes: region count never nears int4)
    UNIQUE (ref, letter)                  -- one entity may carry two letters (item's uniq_item_source_ref_letter, source-scoped: catalog-data-model.md §3)
);
-- Indexes (load.py _INDEX_DDL): GIST(geom), GIST((geom::geography)), (letter), (region_id),
-- (country_code), (src_region_id), GIN(name gin_trgm_ops).
-- coverage_count_install() adds the bucket index of §11.
```

- `ref` matches `item.source_ref` (`web/src/Catalog/Entity/Item.php`) — the
  dedupe join key of [osm-data-architecture.md §8](osm-data-architecture.md).
- **Narrow serving cache, not an OSM copy.** `coverage_poi` is the §5 serving
  cache of a *defined, narrow* OSM subset
  ([osm-data-architecture.md §1](osm-data-architecture.md) principle 4) — **not**
  a bulk copy of OSM (principle 1). Which *objects* we cache is §5's catalogue;
  which *tag keys* we keep on them is §2.1 below. What *leaves* the server is
  narrower still (tiles carry the thin property set, §4; the detail endpoint
  whitelists display tags, §5).
- **Measured sizing.** At 377,558 rows (BE + NL + DE + LU, none unstamped),
  compacted steady-state:
  **341 B/row heap + 176 B/row indexes = 517 B/row**, before the tag trim of
  §2.1 and before `coverage_poi_geog_idx`.
  `coverage_poi_geog_idx` is a functional GiST on `(geom::geography)` (about
  +60 B/row). Every radius query the app runs casts to geography
  (`ST_DWithin(cp.geom::geography, …)` in `OsmLinker` and
  `/map/coverage/nearby`), and the geometry GiST cannot serve that predicate:
  without it the planner walks the letter index, measured at 375k rows and
  2.8 s per queue card on `/moderate/submissions`; with it, 2 ms.
  Pipeline-owned like the rest (`load.py` `_INDEX_DDL`, created CONCURRENTLY
  by `ensure_schema` on every harvest).
  The load is a **diff-merge** (upsert changed rows, delete disappeared ones),
  so a weekly harvest rewrites only the OSM delta: no mid-swap doubling, dead
  tuples bounded by the churn, and no `VACUUM FULL` (prod-unsafe on the shared
  host; `pg_repack` is the option if a one-off file shrink is ever wanted). The per-row figure is
  stable across countries (tags average 205 B/row in DE, 199 in BE, 197 in NL —
  Germany is the most exhaustively tagged country on Earth, so the worldwide
  average should drift down, not up; the one item that grows is the `name`
  trigram index once names are multibyte CJK/Cyrillic/Arabic, ≈ +20 B/row).
  Against the ≈ 4.7 M planet-wide subset that projects to **≈ 2.4 GB
  all-in** for full world coverage (≈ 1.8 GB at 75 %), with a credible band of
  1.3-2.7 GB driven entirely by the row-count estimate, not by per-row cost.
  The whole coverage index fits in page cache on the existing DB host.
- Region membership (`region_id`, `country_code`) and provenance
  (`src_region_id`) are stamped at **load time**, so region/country-scoped
  queries never test containment at request time. `src_region_id` is the
  per-region swap key (the load's previous-count, `DELETE` and membership
  backfills all filter on it).
- `pg_trgm` is required for name search. It joins the PostGIS extensions in the
  out-of-migration bootstrap: `developers/docker/db/init/` (dev), the Makefile
  `test-db-reset` target (test DB), and the prod bootstrap notes. In PHP tests
  the schema is mirrored by a test trait (`web/tests/Coverage/CoverageSchema.php`)
  inside the DAMA transaction, because migrations never create the table.

### 2.1 What `tags` holds — the serve-set

**The rule: we store only what we serve.** `coverage_poi.tags` holds exactly the
keys listed as `storedTagKeys` in the shared contract (§7), and nothing else.
The trim happens at parse time (`pipeline/coverage/parse.py`), so unwanted keys
never reach the database at all.

**Why this needs stating.** §5 catalogues which OSM *objects* we cache. That is a
different question from which *keys* we keep on them, and the two are easy to
conflate: `osmium tags-filter` selects **objects, not keys**, so every matching
object arrives carrying its full tag set. Untrimmed, the cache held **4,220
distinct keys** (a single memorial contributing 20 of them), far beyond
anything the code reads. That is the bulk-OSM duplication
[osm-data-architecture.md §1](osm-data-architecture.md) principle 1 forbids,
arrived at by omission rather than by decision.

The serve-set is five groups:

| Group | Count | Keys | Read by |
|---|---|---|---|
| **Selectors** | 11 | `amenity`, `drinking_water`, `historic`, `man_made`, `natural`, `railway`, `route`, `shelter_type`, `shop`, `tourism`, `waterway` | Classification (letter, `serviceKind`, `placeKind`); `tiles.py::_label_case` re-reads them at tile-build time |
| **Rules** | 2 | `memorial`, `usage` | `load.py` `excludeTagValues`: Q leaves out small memorials, F leaves out heritage railways (§7). `bicycle` and `cc:bicycle_from_route` are counted in their own groups: F also leaves out a `no` in either |
| **Display** | 22 | `opening_hours`, `website`, `contact:website`, `url`, `phone`, `contact:phone`, `addr:city`, `addr:street`, `addr:housenumber`, `operator`, `description`, `wheelchair`, `fee`, `capacity`, `ele`, `direction`, `height`, `bicycle`, `bicycle:fee`, `duration`, `seasonal`, `toll` | `CoverageRepository::TAG_WHITELIST`: exactly what the drawer renders (§5). `wheelchair`/`drinking_water` also feed tile props (§4) |
| **Media/reference** | 4 | `wikidata`, `wikipedia`, `image`, `wikimedia_commons` | `image`, `wikimedia_commons` and `wikidata` are in `TAG_WHITELIST`: the drawer links the Commons photo and the Wikidata item, and the media pipeline caches a copy `PhotoValidator` accepts (`FetchCommonsPhotoHandler`, photo-uploads.md §5h). `wikipedia` is stored, not served. Cost: 19 B/row, about 89 MB planet-wide |
| **Inherited** | 3 | `cc:bicycle_from_route`, `cc:bicycle:fee_from_route`, `cc:ferry_route` | Written by `parse.py`, never read from OSM: what a ferry dock takes from the ferry routes that end at it (§3). In `TAG_WHITELIST`; the drawer says where the answer came from (§5) |

`TAG_WHITELIST` has **32** entries: the 22 display keys, four selectors the
drawer also reads (`amenity`, `shop`, `drinking_water`, and `route`, which tells
a ferry route from a station), three media keys (`image`, `wikimedia_commons`,
`wikidata`) and the three inherited keys. Those are counted in their own rows,
so these groups and `check_date` (§4, the `cd` tile prop) sum to
11 + 2 + 22 + 4 + 3 + 1 = **43** distinct keys.

`ele`, `direction` and `height` serve the scenic-view letter (P): a place
carries its altitude, a viewpoint the compass bearing it faces, a waterfall
the metres it drops. The drawer reads all three for letter P only (`covProps`
in `assets/map/coverage.js`), but the whitelist is per-tag rather than
per-letter, so the same facts appear wherever else they are tagged. Values are
free text in OSM, so `assets/map/osm-tags.js` refuses anything that is not
plainly metres instead of guessing: "1200 ft" renders verbatim, never as 1200
metres.

`bicycle` and `bicycle:fee` are there for Getting there (F): whether a bike may
come aboard a ferry or train, and whether it costs extra. The drawer reads them
for F only and words them in its Bikes on board row (§5). OSM carries them on
ferries far more than on stations: in the Netherlands extract 368 of 580 ferry
routes and 28 of 884 ferry terminals carry `bicycle`, and no station does.

`duration`, `seasonal` and `toll` are a ferry route's crossing time, season and
fare, which the drawer shows beside its `opening_hours`, `fee` and website
(§5). In the Netherlands extract (2026-09-15) 125 of 575 ferry routes carry
`duration`, 110 `seasonal` and 199 `toll`.

Measured effect of the trim across BE + NL + DE (375,078 rows): tags payload
**73 MB → 35 MB (-51.7 %, 203 → 98 B/row)**, whole row **517 → 412 B/row**,
≈ 493 MB at the ≈ 4.7 M planet subset.

**What we deliberately do not store.** These are decisions, not oversights, and
tests enforce them:

- **`email` / `contact:email` — never.** 98.6 % of the rows carrying one also
  carry a website or phone, so a rider loses no way to reach a business; and
  12.6 % of the addresses sit on free consumer providers, i.e. **private
  mailboxes**. Storing personal data that no view ever renders is liability
  without benefit, and it would undercut the platform's position that the
  Commons *dataset* is non-personal. A website plus a phone number is the
  contact surface; anyone needing an email can find it on the website.
- **`name`** — promoted to the dedicated `coverage_poi.name` column, so keeping
  it in `tags` duplicated the authoritative value on every named row.
- **`addr:postcode`** — same store-only-what-we-serve rule: a rider has the pin.
- Everything else OSM happens to attach to a matching object: `inscription`,
  `memorial:*`, `person:date_of_birth`, `object:*`, `building`, `source`,
  `material`, and ~4,200 more.

**Why the trim is safe — and why it must be guarded.** The drawer has **no
live-OSM fallback**: `/map/coverage/poi/{ref}` is served purely from
`coverage_poi` joined to `item` (§5), and nothing in the request path calls
Overpass or the OSM API. A key that is not stored can therefore never be
displayed, so the keep-set and the display whitelist are one contract, enforced
from both sides:

- `load_contract()` rejects a `storedTagKeys` that drops a selector key, drops a
  key `tiles.py::_EXTRA_SQL` reads, drops an inherited `cc:` key, or lists
  `name`.
- `pipeline/tests/test_tiles.py` pins `contract.py::TILE_DERIVED_TAG_KEYS` to the
  keys that SQL actually reads.
- `web/tests/Catalog/CoverageContractTest.php` asserts
  **`TAG_WHITELIST ⊆ storedTagKeys`** — a display key the pipeline trims away
  would be a permanently blank drawer row.

**Changing the serve-set requires a re-harvest** to affect existing rows. The
batch runs weekly so this is cheap, but decide once rather than re-harvesting
twice. Nothing breaks in the interim: rows harvested under an older, wider set
simply carry keys nothing reads.

Materialize-on-edit ([osm-data-architecture.md §6](osm-data-architecture.md))
needs no full-tag snapshot either: it copies `{osm_ref, edit}` into the
canonical store and merges the OSM side from this cache at read time.

### 2.2 Backup posture: don't back this table up

`coverage_poi` is **derived data**. It is rebuilt from Geofabrik extracts on the
weekly cadence, holds nothing a human authored, and a rebuild produces *fresher*
rows than any restore would. Its recovery path is therefore **re-harvest, not
restore**, and routine backups should skip its contents:

```
pg_dump --exclude-table-data=coverage_poi …
```

(`--exclude-table-data`, not `--exclude-table`: the schema stays in the dump so a
restored database comes up structurally intact, ready for the next harvest.)

What genuinely needs backing up is the irreplaceable half — `item` (the curated
additions of [osm-data-architecture.md §6](osm-data-architecture.md)), the route
tables, submissions, and accounts. Those have no upstream to regenerate from.

**The risk to manage instead is rebuild time.** "We'll just re-harvest" is only a
credible DR story once the full planet rebuild has been measured end to end
(download → `osmium tags-filter` → parse → load → tiles, across every configured
extract). Until that number exists, treat it as unknown. If it proves too slow to
sit inside an acceptable outage, the cheap warm-start is **not** a table dump —
it is retaining the filtered PBFs and the built `coverage.pmtiles`, both already
produced by the batch and far smaller than the table plus its indexes.

**Why not partition by continent for backup granularity.** Continents are close
to disconnected — cross-border entities that appear in two Geofabrik extracts
(§3) almost always sit inside one continent — so a per-continent restore would be
nearly self-consistent in a way a per-country restore is not. It still is not
worth the structure:

- Per-unit dumps already work without partitioning (`pg_dump -t coverage_poi`, or
  `\copy (SELECT … WHERE country_code = ANY(…)) TO` for a slice).
- "Almost always" is not an invariant: Turkey, Russia, Egypt/Sinai, Kazakhstan
  and the Caucasus straddle the continental line and Geofabrik's extracts do not
  split there.
- The unit you would actually want to restore is the **refresh** unit (one
  extract), because the natural repair is re-harvesting it — not a continent.
- At ≈ 2.4 GB planet-wide (§2 sizing) the whole table dumps in minutes, so the
  problem partitioning would solve is not one this table has.

If `coverage_poi` is ever partitioned, the driver is the refresh/bloat story and
the key is `src_region_id` — per-partition dumps fall out as a side effect.

## 3. The weekly batch job

Per region in `COVERAGE_REGIONS`, independently:

1. **Download** the Geofabrik PBF (curl + md5; skip if unchanged).
   `COVERAGE_PBF_PATH` optionally points at a local PBF instead (fixture/dev
   runs — never a network dependency in tests).
2. **Filter** to the [osm-data-architecture.md §5](osm-data-architecture.md)
   selectors with `osmium tags-filter` (a few-MB PBF remains).
3. **Parse** with pyosmium into rows: letter(s), kind, name, centroid, and the
   tags **trimmed to the contract's `storedTagKeys`** (§2.1) — step 2 filtered
   *objects*, this step filters *keys*, and it is the only place that happens.
   `serviceKind` derives from the shared contract file
   (coverage-provider.md §7) — the same mapping as
   `App\Catalog\ServiceKind::fromOsmTags()`
   (`web/src/Catalog/ServiceKind.php`). A P or Q row's kind comes from the
   contract's `placeKind` rules (`Contract.kind_for_letter()`), the same mapping
   as `App\Catalog\PlaceKind::harvestRules()`.
   **A ferry dock links its ferry routes, and without a `bicycle` tag of its
   own inherits their bike answer, by OSM topology, never by position.** For
   each F `amenity=ferry_terminal` node, `parse.py` looks at the `route=ferry`
   ways whose first or last node it is (the filtered extract keeps way node
   refs). A dock with no `bicycle` tag takes their answer: any route with
   `bicycle` = `yes`, `designated` or `permissive` gives that value; otherwise
   any with `dismount` gives `dismount`; otherwise, when every route that has a
   `bicycle` tag says `no`, the dock gets `no` and the F `excludeTagValues`
   rule leaves it out (§7). A route with no `bicycle` tag adds nothing to the
   answer, and a value the drawer cannot word keeps the dock from a `no`. The
   answer is stored under keys OSM never uses, so nobody mistakes it for the
   dock's own tag: `cc:bicycle_from_route` (the winning route's value, lowest
   way id first), `cc:ferry_route` (the refs of the routes that gave it,
   `;`-separated, as `way/<id>`), and `cc:bicycle:fee_from_route=yes` when
   every one of those routes says `bicycle:fee=yes`. A dock that takes no
   answer, because it has its own `bicycle` tag or because no route gives one,
   still gets `cc:ferry_route`, naming every route that ends at it: its own tag
   answers Bikes on board, and its one route's crossing facts still reach the
   drawer (§5). A dock with no route gets nothing. An OSM tag under the `cc:`
   prefix is never stored. The parse logs how many docks it linked and how many
   inherited each answer. Measured on the Netherlands (2026-09-15): 793 docks
   linked, 547 of them with an inherited answer (`yes` 455, `permissive` 2,
   `dismount` 2, `no` 88). After the load rules, the cache holds 688 linked
   docks: 447 with an inherited answer, 18 with their own `bicycle` tag and 223
   with a route link only; 636 of them link one route that is in the cache and
   so show its facts.
4. **Load — per-region atomic swap with an ownership filter and a drift
   guard.** `COPY` into a staging table, then **before** the drift count: an
   ownership filter deletes staged rows this extract does not own — nearest-region-wins,
   across every onboarded country, so a border row picks exactly one owner
   regardless of which extract's cut also carries it; a row with no region
   within `BOUNDARY_SNAP_DEG` of *any* onboarded country is dropped outright
   (not staged at all). The contract rules (`nameOrTags`, then
   `excludeTagValues`, then `nearWay`) then remove the staged points they refuse. Abort if the row count drops more than
   the drift ratio below the previous run for the same region
   (`pipeline/coverage/load.py::DRIFT_ABORT_RATIO`, value `0.4`), **counted over
   the letters no contract rule filters**: a rule may shrink its own letter as
   far as the rule takes it, even to nothing, while a broken extract shrinks the
   unfiltered letters too and is still caught (2026-09-14: Chile, Slovenia, New
   Zealand and British Columbia each lost more than 40% of their rows to the
   scenic rules, with every other letter steady). A truncated
   download must never wipe a region, and the filter itself needs its own
   guard: an unseeded `region` table for the extract's country raises rather
   than silently staging zero rows. Then one transaction:
   resolve the extract slug to its `coverage_source` id (get-or-create), then a
   **diff-merge** swap (upsert only changed or new rows, unchanged rows skip
   with no write, and delete the disappeared), followed by a
   **delta-scoped** `region_id` backfill (`ST_Contains` over `region` polygons
   where they exist; `COVERAGE_FULL_MEMBERSHIP=1` recomputes the whole slice
   after a `region` change). Readers never see a half-loaded region; an abort
   keeps last week's slice serving. The run's connection sets a session-scoped
   resource budget from env (`load.py::apply_session_budget`, never global
   cluster config) and holds a session advisory lock (`run.py`,
   `COVERAGE_ADVISORY_LOCK_KEY`), so a second run exits 2 rather than loading
   alongside it.

After all regions, once per run:

5. **Export** per-letter newline-delimited GeoJSON from the full index.
6. **Build tiles** with tippecanoe: one layer per letter, `--minimum-zoom 6 /
   --maximum-zoom 14`, direct `.pmtiles` output
   (`pipeline/coverage/tiles.py::build_pmtiles`). Tiles carry **individual
   points only, z6–14, no clustering**. `-r1` keeps EVERY point at z11–14 (no
   rate-based thinning), so those tiles stay **complete** for the individual
   icons: a z11 tile is small enough that `--drop-densest-as-needed` never
   fires there. At **z6–10**, `--drop-densest-as-needed` does fire: where a
   tile would exceed the size budget it drops the densest overflow
   proportionally, leaving a **thinned density sample** rather than every
   point. It never merges points into a `point_count` feature; each kept
   feature stays an individual point. That z6–10 sample is what the client's
   overview heatmap consumes. There is no `--cluster-distance`,
   `--cluster-maxzoom` or `--accumulate-attribute` flag: each feature keeps its
   own single `ridtok`/`cctok` token, so the scope filter (§4, §6) is **exact
   per point** at any zoom, worldwide. A rider sees a **coverage-density
   heatmap** at overview zoom (z6 up to the icons), built from that thinned sample and
   filtered to the same scope tokens as the icons, plus the rail's exact
   `/map/coverage/counts` alongside it as the precise "how much"; individual icons render from z11 for water and z12 for every other category
   (`COV_ICON_MIN_ZOOM`, `iconMinZoom()`), where the tiles are complete;
   each category's heatmap holds full until its own icons start and fades
   under them over 0.75 of a zoom step. The heatmap is a
   light lavender ramp at 0.5 opacity, so the base map stays readable under it
   (`coverage.js`, owner 2026-09-30). Clustering is ruled out on purpose: a
   cluster is drawn at the *centroid* of its members, which can sit outside
   the scoped region and mixes members across a border. The heatmap bins the
   points themselves, so nothing is ever drawn where no POI is.
7. **Verify** with go-pmtiles (`verify_pmtiles`): header bounds, addressed tile
   count, expected layers, and a sample tile decode — a broken build never
   ships.
8. **Publish, per country** (`pipeline/coverage/publish.py`): each country
   whose export fingerprint has changed uploads its points archive under a
   **versioned key** `coverage/<cc>/<YYYYMMDD-HHMMSS>/points.pmtiles`
   (`Cache-Control: public, max-age=31536000, immutable`, `publish.publish_countries`),
   merged into the **stable key** `coverage/manifest.json`
   (`publish.MANIFEST_KEY`, `max-age=300`) alongside every country left
   unchanged, then old builds are pruned per country keeping the last 4
   (`publish.prune_family("coverage", ..., keep=4)`). Versioned keys mean an
   open reader mid-pan never has bytes change underneath it; a country whose
   fingerprint matches the live manifest is skipped rather than rebuilt.

**Failure mode:** any step aborts that region's transaction or the artifact
step; last good data keeps serving; a non-zero exit surfaces through the
scheduler's mail. **v1 extracts nodes + ways-as-centroid**; multipolygon
relations (~1–3 % of objects) are a fast-follow (see Open questions).

**Lines (surface, routes) own a border the same way points do.** The surface
and routes builds (coverage-provider.md §4) never touch PostGIS, but a border way still needs
exactly one owner so it is drawn once. A line feature's anchor is its
midpoint vertex (`coords[len(coords) // 2]`, the same vertex `GapGrid.add`
bins on); a knooppunt's anchor is its own point. The owner is the
`country_code` of the nearest **operational region** (the rows
`_materialize_operational_regions` selects: one row per country at that
country's deepest onboarded `admin_level`) within `BOUNDARY_SNAP_DEG`
(0.01 degrees) of the anchor - ties broken by distance, then smaller
`area_km2` (NULL last), then lower `id`: `load_region`'s point rule, restated
in Python (`pipeline/coverage/ownership.py::Owners`). No operational region
within the snap distance means the feature is foreign to every onboarded
country, and it is dropped rather than assigned. A `dev/`-prefixed region
(country `None`) skips the rule, as it does for points. Both line builds
snapshot the operational regions once per run to
`<workdir>/ownership-regions.json` (`ownership.snapshot_outlines`); the file
is rewritten only when its bytes change. Each region's extract stamp carries
the fingerprint of the outlines that can own a feature inside that region
(`ownership.region_fingerprint`): the outlines whose bounding box meets the
region PBF's header box (`ownership.pbf_header_box`) grown by
`BOUNDARY_SNAP_DEG`. A boundary edit re-extracts the regions it can affect
without anyone having to remember to force one, and onboarding a country
elsewhere re-extracts nothing. A PBF without a header box falls back to the
fingerprint of every outline (`ownership.outlines_fingerprint`).

**A country rebuilds only when its own inputs changed.** For every family
(coverage points, surface classified/todo, routes), a country's build is
compared by `publish.inputs_fingerprint(its input files, the contract
fingerprint, for the line families each member region's extract stamp, that
family's tile profile)` against `inputs` in the live manifest
(coverage-provider.md §4); equal means skipped, not rebuilt. The
fingerprint hashes file **content**, sorted by path, never file names or
mtimes - a nightly-rewritten export and a workdir wiped clean both hash
correctly, and a file renamed with identical bytes is not new data.

**A country builds only from a complete region set.** Three onboarded
countries span more than one Geofabrik extract (US = california + colorado,
CA = british-columbia + quebec, GB = great-britain + ireland-and-northern-ireland).
When a run's `--regions` does not include every one of a country's onboarded
regions, that country is skipped with a log line rather than published from a
partial extract - a run over `north-america/us/california` alone must not
take Colorado off the map. A region outside the onboarded list (a `dev/`
region, or a sub-country extract such as `europe/germany/bayern`) is
extracted but never tiled or published per country, and the run log says so.

**One country failing costs that country only.** A surface or routes
country whose tiling or bounds read fails is logged
(`[surface] <CC>: build FAILED: ...`), leaves its live entry serving, and
makes the run exit 1; every country that built is still published. A gap-grid
build failure is handled the same way. A surface run and a routes run each
hold their own session advisory lock for the whole run
(`run.LINE_RUN_LOCK_KEYS`, distinct from the coverage run lock and the
manifest locks), because both write per-region scratch files in the workdir;
a second run of the same family exits 2 without touching them.

**Entry points:** CLI `python -m coverage.run` in the pipeline container;
`make coverage-refresh` runs the whole chain against the dev DB + MinIO;
prod runs it as a scheduled job on the worker server (topology owned by
`operations.md` §6, see [README.md](README.md)). Runbook:
`developers/coverage-batch.md`. Pipeline env contract (set in
`developers/docker/compose.yaml` / `.env.example`): `COVERAGE_REGIONS`
(empty by default: every onboarded country's Geofabrik extract, read from
`country_extract`; set it to run a subset), `COVERAGE_WORKDIR` (`/data/work`, named scratch
volume), `COVERAGE_PBF_PATH` (optional local override), `COVERAGE_S3_ENDPOINT`,
`COVERAGE_S3_BUCKET` (dev: `cc-maps`; prod name is deployment config), `COVERAGE_S3_KEY`, `COVERAGE_S3_SECRET`,
`COVERAGE_S3_REGION` (signing only, default `us-east-1`),
`COVERAGE_PUBLIC_BASE_URL`.

**Republish without a harvest: `python -m coverage.run --tiles-only`**
(dev: `make coverage-tiles`). The flag skips the per-region Geofabrik harvest
and runs only the tail of the chain - export, build, verify and publish the
coverage PMTiles from the `coverage_poi` rows already in PostGIS, per country,
each one skipped when its export fingerprint already matches the live
manifest. This is the way to republish after a change that rewrote the
index without new OSM data, such as a migration that rewrites
`coverage_poi.letter` (`Version20260825120000`): source-layers are named
`<letter>_<cc>` (§4), so the tiles must be rebuilt from the rewritten rows.
`make coverage-tiles` brings up MinIO and publishes there, no Geofabrik
involved.

**Nightly dispatch: `python -m coverage.dispatch`** (`pipeline/coverage/dispatch.py`)
orders the onboarded regions by staleness and loads the stalest ones inside a
time budget and a region cap, then runs `--tiles-only` once if anything
loaded. Each loaded region also refreshes its routes, surface and road-piece
line extracts, and one offline pass per family (`--routes`, `--surface`,
`--roadpieces`; traffic-measurements.md section 2) then
republishes only the countries whose inputs changed - an unchanged country
costs a fingerprint comparison and nothing else. A pass that raises, or exits
non-zero (2 when another run of its family holds the run lock), is logged and
marks the night's publish failed; the remaining passes still run and the
dispatcher's run row is always finished.

## 4. Tile artifact contract

Thin tiles: enough to draw markers and run map-side filters; everything else
comes from the detail endpoint on click. Flat scalars only (MVT rule).
Feature id = numeric OSM id.

**Per-country keys, one manifest per family.** Every artifact family
(coverage points, surface classified/todo, cycle routes) publishes one file
per country under a versioned key `<family>/<cc>/<stamp>/<arm>.pmtiles`
(`cc` lowercase, `stamp` `YYYYMMDD-HHMMSS`; builds published with the older
minute form `YYYYMMDD-HHMM` still parse and sort by time) -
`coverage/be/20260924-031205/points.pmtiles`,
`surface/be/.../classified.pmtiles`, `surface/be/.../todo.pmtiles`,
`routes/be/.../routes.pmtiles`. The surface build additionally publishes one
world file with no country split, `surface/gaps/<stamp>/gaps.pmtiles`. The
grid merges the per-region cell sums (`surface_<slug>_gapcells.tsv`) of every
onboarded region whose cell file is current, that is whose surface extract
stamp is the one this run wants for it, whether or not the region is in this
run; while any onboarded region has no current cell file the grid is not
rebuilt and the live one keeps serving. `dev/` regions never feed it. Unstamped coverage rows
(no owning region) live under the fixed `zz` bucket, never a real country
code. Layer names inside a file are unaffected by any of this (`b_be`,
`surface_be`, `routes_be`, `knoop_be`, `roadpieces_be`, `gaps`).

Each family's stable key (`coverage/manifest.json`, `surface/manifest.json`,
`routes/manifest.json`, `roadpieces/manifest.json`) is a **manifest v2**:
```json
{"version": 2, "updated_at": "2026-09-24T03:12:05+00:00",
 "countries": {"be": {"stamp": "20260924-031205", "built_at": "2026-09-24T03:12:05+00:00",
                      "inputs": "3f9a0c1d2e4b5a69", "bounds": [2.54, 49.49, 6.41, 51.51],
                      "counts": {"classified": 391245, "todo": 204113},
                      "tiles": {"classified": "https://.../surface/be/20260924-031205/classified.pmtiles",
                                "todo": "https://.../surface/be/20260924-031205/todo.pmtiles"}}},
 "gaps": {"stamp": "20260924-031205", "built_at": "...", "inputs": "...", "url": "https://.../surface/gaps/20260924-031205/gaps.pmtiles"}}
```
`gaps` exists only in `surface/manifest.json`; `coverage/manifest.json`,
`routes/manifest.json` and `roadpieces/manifest.json` carry one tile arm each
(`points`, `routes`, `roadpieces`). The road-piece family (`run.py --roadpieces`,
`make roadpieces-tiles`) holds every way a bike may ride at z14, labelled for
traffic matching in Scout's ride review ([traffic-measurements.md](traffic-measurements.md) §2). A publish
(`pipeline/coverage/publish.py::publish_countries`) reads the live manifest,
replaces the entries it built, and writes it back under a Postgres advisory
lock per family (`publish.manifest_lock`); it never drops a country except
when told to with `--retire <cc>`, and a country cannot be both built and
retired in one publish. Publishing only ever replaces the countries it built,
so a narrow run (a subset of regions) leaves a wide manifest's other countries
serving. The read (`publish.read_live_manifest`) takes only "no manifest yet"
(`NoSuchKey`/404) and a v1 or version-less document as the empty v2
skeleton; any other failure (an S3 error, a body that is not JSON, a v2
document whose `countries` is not an object) raises, the run publishes
nothing for that family, and the last manifest keeps serving. A country's
`bounds` are the union of its archives' header bounds (both surface arms).

**Rollout of the per-country manifests.** Four rules, in order:

1. Deploy the web tier first. An app that reads only v1 shows nothing for a
   family once its manifest is v2, so the reader of both shapes
   (`BucketManifest`) must be live before any v2 publish.
2. The first v2 publish of each family is a full-universe run: every
   onboarded region. Until then the app serves the v1 world archive, and the
   first v2 manifest replaces it, so a first publish of a subset would take
   every other country off the map. The surface and routes runs enforce this:
   while the live manifest is v1 or absent, a run whose complete countries
   are fewer than every onboarded country refuses to publish, unless
   `--retire` is given or `COVERAGE_FIRST_PUBLISH_PARTIAL=1` is set. The
   coverage points build always exports the whole `coverage_poi` index, so
   its first v2 publish covers every loaded country by construction.
3. An app rollback after that first v2 publish needs a v1 republish from the
   previous release, or env pins (`ROAD_SURFACE_TILES_URL`,
   `ROUTES_TILES_URL`) to v1 archives: the previous app cannot read v2.
4. Delete the v1 keys (`developers/coverage-batch.md`) only after the
   app-rollback window has closed.

Before the nightly timer is enabled on a workdir whose extracts predate the
per-region stamps, pre-warm it: run `--routes --extract-only` and then
`--surface --extract-only` for each region, so the first night does not
re-extract every region inside the dispatcher's budget.

**Server: one entry per country, or one `*` world entry.**
`App\Coverage\CoverageManifest`, `SurfaceManifest`, `RoutesManifest` and
`RoadPiecesManifest` (all `App\Coverage\BucketManifest`, below) expose
`countryTiles(): array<cc, {tiles, bounds, stamp}>`. A v2 manifest yields one
entry per country (`BucketManifest::countryEntries()`). A v1 manifest, a
manifest with no `countries`, or an env pin (`ROAD_SURFACE_TILES_URL`,
`ROUTES_TILES_URL`) yields exactly one entry under the key `*` with world
bounds (`BucketManifest::worldEntry()`, `WORLD_BOUNDS`) - so a rollback to a
v1 artifact, or a pin, still serves every country from one archive. When a
surface pin is set the surface family serves only that pinned `*` entry; the
per-country manifest is not read.

**Client: one source per country in view, mounted once, never removed.**
`web/assets/map/tile-sources.js` owns the pmtiles protocol registration
(`ensureProtocol()`, added once for the page), the source id convention
(`<family>-<arm>-<cc>`, or `<family>-<arm>-all` for the `*` entry), and
`mountInView(map, family, arm, minzoom, onAdd)`: below `minzoom - 1` it does
nothing; at or above it, it adds a `pmtiles://` vector source for every
country entry whose bounds meet the current viewport padded 25%
(`keysInView`), calling `onAdd(key, sourceId)` for each newly-added one.
Sources are never removed once added - panning back out keeps them mounted,
panning to a fresh region adds more. `window.CC_TILES` (`MapController`,
`web/templates/map/index.html.twig`) is the nonce'd JSON of the coverage, surface and routes
families' `countryTiles()` (plus `roadpieces` for a curator or the Scout ride
review), prefetched together (`BucketManifest::prefetch()`,
coverage-provider.md §4 "The four readers share one base") so the page pays one
`FETCH_TIMEOUT` rather than one per manifest.
Only the tile *source* a layer reads from is per-country; layer ids keep
whatever per-`(letter, country)` or per-country convention their family
already used (below). The surface skin (classified/todo/gaps) and the routes
layer mount on first toggle, never at boot (`map.js`), so a rider who never
opens that panel never triggers a bucket fetch for it.

**Tuning note: border sharing and rebuild cost.** Border overlap before the
owner rule, measured over the dev workdir's same-day extracts: Belgium and
the Netherlands share a 3,301-way surface border (germany/france: 5,700;
netherlands/germany: 4,180; germany/switzerland: 5,088); the
belgium/netherlands route-way overlap is 1,745 (germany/france: 1,631;
netherlands/germany: 2,207). Without the owner rule, each of those ways is
drawn twice, once per country's archive. Tiling Belgium and Luxembourg
together took 16 s; tiling them apart took 14 s + 2 s; `tile-join` of the two
combined files gave the same 44.3 MB and 5,076 tiles in 4 s - per-country
builds lose nothing to the split.

After the owner rule, on real Belgium/Netherlands/Luxembourg extract-and-tile
runs: **zero** shared refs for every pair, on every arm measured:
surface classified (be 388,631 / nl 703,734 / lu 49,737), surface to-do
(be 225,795 / nl 235,986 / lu 19,514) and routes ways (be 164,463 /
nl 199,485 / lu 10,464) - belgium/netherlands, belgium/luxembourg and
netherlands/luxembourg all shared 0. On the same real dev publish:
`--routes --regions europe/belgium,europe/netherlands,europe/luxembourg`
took 5m23s and rebuilt all three; `--surface` over the same three regions
took 11m41s and rebuilt all three; a `--tiles-only` coverage republish over
all 19 onboarded countries (from `coverage_poi` already in PostGIS, no
harvest) took 7m30s and rebuilt all 19; a second `--surface` run over the
same three regions, immediately after the first with no data changed, took
13s and printed `unchanged, not rebuilt` for all three, with no `.pmtiles`
uploaded - the rebuild rule's fingerprint comparison costs a hash, not a
tile build.

**Source-layers are per-country: `<letter>_<cc>`** (lowercase), one
tippecanoe layer per `(letter, country_code)` pair, e.g. `b_be`, `b_nl`. A row
with a NULL `country_code` (the rare unstamped boundary-miss) buckets under
`<letter>_zz`, so no POI is ever silently dropped. The split keeps a letter's
icons filterable per country and drives the client's per-country layer wiring
from `CoverageManifest::countryCodes()` (below).

**What a rider sees as they zoom:** at overview zoom (z6–10) the tiles carry
the thinned, density-preserving sample of points (`--drop-densest-as-needed`,
§3 step 6), and the client draws it as a **coverage-density heatmap** instead
of individual icons, with the rail's exact `/map/coverage/counts` carrying the
precise "how much" alongside it. Individual icons render **from z11 for
water and z12 for every other category** (`COV_ICON_MIN_ZOOM`,
`iconMinZoom()` in `icons.js`), where `-r1` keeps every point, so a
category's icons arrive complete. At z9 every category popped in at once and
buried the map, and between the fading haze and the icons the map showed
nothing (owner 2026-10-08). Each category's heatmap now holds at full
strength until its own icons start and fades under them (heat layer `maxzoom`
= icon `minzoom` + 0.75), so the map is never empty. Our own places are DOM
pins and show at every zoom: below z12 they are the individual spots, and the
haze says how much more OpenStreetMap holds.

**Below z6 the map says so.** The tileset is built z6-14, so at z5 and wider
there is no coverage data to draw at any scope, and a silent map is
indistinguishable from a country whose harvest failed. The rail foot carries a
one-line hint under the count (`render.js updateZoomHint`, fired on
`zoomend`): below z6 "Zoom in to see the full-coverage layers", and nothing
from z6 up, where the density heatmap and then the icons are the answer. It is
suppressed when the rider has turned every coverage layer off, so it never
nags about layers nobody asked for. The hint box sits beside the zoom column
and above the attribution strip (`.zoom-hint`, map.css), so it never prints
across the credits.

| Property | Layers | Why in the tile |
|---|---|---|
| `ref` | all | join key: drawer detail fetch, curated dedupe, deep links |
| `n` | all (when named) | labels, search-pick highlight |
| `t` | all | type label (existing marker/drawer vocabulary) |
| `ridtok` | all (always; `""` when unstamped) | region scope filter: `"|<region_id>|"` (map-and-search.md §4.5) |
| `cctok` | all (always; `""` when unstamped) | country scope filter: `"|<cc>|"` (map-and-search.md §4.5) |
| `kind` | D, P, Q | D: shop/station/pump icon match. P and Q: the kind (`waterfall`, `castle`), one glyph each (osm-data-architecture.md §5a) |
| `potable` | B | water kind glyph: `yes` / `no` / absent (nobody said), derived from OSM `drinking_water` and `amenity` tags (data-provider-hierarchy.md §6.3a) |
| `food` | B | `true` for the shop and eatery half of letter B, absent for the water half; picks the food kind glyph |
| `acc` | O | stays accessibility filter |
| `cd` | all (when dated) | OSM `check_date`, only when it is a full `YYYY-MM-DD`, truncated to the day. A witness inside the freshness window drops the `?` badge on the coverage disc (data-provider-hierarchy.md §6.7.7, rung 8); the map compares it as a string to `window.CC_WITNESS_CUTOFF` |

`ref`/`n`/`t`/`ridtok`/`cctok` are the **universal** props (every layer, declared
as `universalTileProps` in `coverage-contract.json`, consumed by
`tiles.py::_universal_props` and pinned by both language contract suites);
`kind`/`potable`/`food`/`acc`/`cd` are per-letter extras (`tileProps`). **No cluster props
exist:** tippecanoe never injects `point_count`/`clustered`/`sqrt_point_count`/
`point_count_abbreviated` because nothing clusters. There is a single icon
layer per `(letter, country)`, `{key}-{cc}-cov`, beside its heatmap layer
`{key}-{cc}-heat`. Icons compose the dedupe and region-scope arms
(`covIconFilter`); its `!has point_count` arm is always true, since no feature
carries `point_count`.

`ridtok`/`cctok` are region-scoping TOKENS, not scalars: pipe-delimited so
`'|<id>|' in ridtok` is a delimiter-safe set test, and ALWAYS emitted (empty
string when unstamped, never NULL-stripped). Each feature carries **its own
single token**: there is no `--accumulate-attribute` and no cross-feature
union, so the scope filter tests one point's own region/country, never a
merged member set. "Prop-less" is the
explicit both-tokens-empty state (a row outside every region with no cc, or a
tile built without the tokens): the client renders it **unfiltered**
(map-and-search.md §4.5: the artifact lags the DB by up to a weekly rebuild,
so hiding-all would blank the map), the inverse of the
leak-safe default for served data. A cc-bearing rid-less row (cctok
non-empty) is NOT prop-less — it hides under a region scope (matching
`/counts`) and reappears under its country scope.

- **A · road surface stays out** of the coverage points artifact: it is
  corridor line data, orders of magnitude larger. The curated segments serve
  via the catalog region slices. N/E/R are category-3 (our own data) and are
  never in the extract
  ([osm-data-architecture.md §5](osm-data-architecture.md)).
  **Road surface has its own artifacts and its own manifest**: three of
  them (classified skin, "still to record" arm, gap grid), built by the same
  `coverage.run --surface` command from the same Geofabrik extracts but never
  touching PostGIS. **Publishing is per country:** each onboarded country's
  classified and to-do arms are built and published under
  `surface/<cc>/<stamp>/classified.pmtiles` and `surface/<cc>/<stamp>/todo.pmtiles`
  only when the fingerprint of its own extracts has changed, alongside one
  world `surface/gaps/<stamp>/gaps.pmtiles`, all pointed at by the stable
  `surface/manifest.json`
  (`{"version":2, "countries":{"<cc>":{"stamp","built_at","inputs","bounds",
  "counts","tiles":{"classified":url,"todo":url}}}, "gaps":{"stamp","built_at",
  "inputs","url"}}`). Read server-side by `App\Coverage\SurfaceManifest`, which
  shares `CoverageManifest`'s TTLs and its tolerate-everything failure policy
  (`BucketManifest`). The build and its editorial decisions (the seven surface
  classes, the to-do arm, the gap grid) live in the contract's `surface` key
  and in the wiki's *Building road-surface tiles* chapter
  (`wiki/developers/data-ops/surface-tiles.md`). A country whose
  onboarded regions are not all present in the run is skipped rather than
  half-published, and `--retire <cc>` is the only way to drop a country from
  the manifest.
  The classified arm additionally carries the **quality channel**: `sm` (raw
  OSM `smoothness`, gated on the contract's
  `surface.quality.values` list — an unlisted value is dropped at extract
  time, never guessed) and `mtb` (`mtb:scale`, `0`–`6` with optional `+`/`-`),
  both omitted when absent so absence stays absent.

  **Absent in the tile, named in the drawer.** Omitting the prop is
  right for the artifact and wrong for the rider on its own: the ticks draw only
  where `sm` exists, so a road nobody has assessed looks exactly like any other
  road without ticks. `openSurfaceDrawer` therefore always renders the
  Smoothness row, and when the prop is missing renders it in the `empty` style
  with a link into the wizard for that way (`/improve?ref=…&field=smoothness`),
  the same affordance the catalog drawer uses for an unset field. Same principle
  as the Traffic row, which says when it inferred rather than measured, and as
  the `legend_unverified` class: name the gap, and make naming it the way to
  close it. Whether "unknown" also earns a mark on the MAP is deliberately
  unanswered until the tag's coverage is measured.
- **The cycle-route network has its own artifact and manifest**:
  `route=bicycle`/`route=mtb` relations extracted per region by
  `coverage.run --routes` (`pipeline/coverage/routes.py`, a two-pass walk —
  relations first for membership, then ways with locations — over the same
  Geofabrik extracts, zero PostGIS), one line feature per **member way**
  (props `net`/`rr`/`rk`/`refs` + the element `ref`, contract `routes` key)
  plus knooppunt nodes as points (`nr`; in-artifact from
  `routes.nodes.minZoom` via a per-feature tippecanoe floor).
  **The archive carries TWO line floors, on the same per-feature trick the
  nodes use** (owner 2026-08-17). It builds from `routes.minZoom` = 5, and
  every way is stamped `routes.planningMinZoom` (5) when its strongest
  membership is in `routes.planningNetworks` (`icn`, `ncn`) and
  `routes.localMinZoom` (8) otherwise. An international route is what a rider
  plans with at the zoom where a country fits on the screen, and a layer that
  is absent below z8 reads as broken rather than as out of range. Shipping
  every local connector from z5 instead would put millions of lines into a
  handful of tiles for a picture nobody can read. **The client needs no zoom
  rule of its own**: a network with no features in a z6 tile draws nothing, so
  `routes-tiles.js` carries no minzoom and cannot drift from the build. Judged
  on the way's BEST membership, so a lane carrying both EuroVelo 12 and a
  village loop stays part of EuroVelo 12 at planning zoom. Cost, measured: a z5
  tile is ~1.0 MB and a z6 tile ~0.6 MB (`--no-tile-size-limit` is deliberate
  here — a dropped line is a route that vanishes), which is why the layer stays
  opt-in and off by default. **Publishing is per country**, same as the
  surface build: each onboarded country's `routes/<cc>/<stamp>/routes.pmtiles`
  rebuilds only when the fingerprint of its own extracts changes, pointed at
  by a stable `routes/manifest.json`
  (`{"version":2, "countries":{"<cc>":{"stamp","built_at","inputs","bounds",
  "counts","tiles":{"routes":url}}}}`), read server-side by
  `App\Coverage\RoutesManifest` (env pin `ROUTES_TILES_URL` wins; manifest
  `ROUTES_MANIFEST_URL`) and emitted as the `routes` family of `window.CC_TILES`
  (`cc`, or `*` for one archive serving every country, to
  `{tiles:{routes:url}, bounds, stamp}`). Its **own**
  manifest rather than a fourth surface arm: the two builds are separate
  invocations, and a shared manifest would let whichever ran last publish
  half-updated URLs for the other's arms. `--retire <cc>` is the only way to
  drop a country from it. The routes run also
  drops per-region `routes_<slug>_wayids.txt` **way-id sets** into the
  workdir: the surface pass reads them (`surface.extract_region
  route_way_ids`) so an untagged way carrying a signed route is to-do-arm
  homework whatever its highway class — which is why `--routes` runs before
  `--surface` when rebuilding both (the way-id file is named as an extract
  input, so a fresh routes run invalidates the surface extracts it would
  change).
- **Manifest** (stable key `coverage/manifest.json`, manifest v2 - the shape
  every family shares, above):
  `{"version":2, "updated_at":"<ISO>",
  "countries":{"<cc>":{"stamp","built_at","inputs","bounds",
  "counts":{"<letter>":n,…}, "tiles":{"points":url}}}}`. `App\Coverage\CoverageManifest`
  reads it (coverage-provider.md §4 "Server-side manifest read", below) and exposes `countryCodes()`:
  the sorted list of real onboarded countries the artifact was built for
  (`["BE","NL"]`; `zz`, the unstamped bucket, is never in it, because it is a
  fixed client-side fallback, not a country a rider can scope to) - it tells
  the client which per-`(letter, country)` **layers** to build, one time, at
  boot, before any tile source for that country exists.
- **Client per-country layer wiring** (`web/assets/map/coverage.js`
  `addCoverage`/`addCoverageLayers`): `window.CC_COVERAGE_COUNTRIES`
  (`MapController` injects `CoverageManifest::countryCodes()`) plus the fixed
  `zz` bucket is the list of source-layers a coverage letter can have
  (`COVERAGE_CCS`); a manifest with none (unset, or a v1/pinned archive) falls
  back to `[null]`, the single unsplit `<letter>-cov` layer against the plain
  `<letter>` source-layer, so an old or pinned artifact still renders (degrade,
  don't blank). Building the actual layers is lazy and viewport-driven, same
  as every family (coverage-provider.md §4 "Client: one source per country in view", above):
  `addCoverage()` calls `tile-sources.js`'s `mountInView(map, 'coverage',
  'points', COVERAGE_MIN_ZOOM, ...)`, and only when a country's (or the `*`
  world entry's) tile source is newly mounted does `addCoverageLayers(cc, src)`
  build that country's icon (`<key>-<cc>-cov`, `minzoom: iconMinZoom(key)`)
  and heatmap (`<key>-<cc>-heat`, `maxzoom` that plus 0.75) layer pair against
  it, then re-stacks the map (`liftInfoLayersAboveRoutes()`), so a country
  mounted after the first render has its haze under the lines and its icons
  over them - there is no
  `-cov-cl` cluster-bubble sublayer to wire. `updateCoverageScopeFilter`
  guards every `map.setFilter` call on `map.getLayer(id)`, so it is safe to
  call before every country's layers exist yet: the `ridtok`/`cctok` scope
  filter (this section, above) applies to whichever per-country layers are
  mounted so far, and to the rest as `mountInView` adds them. Each call also
  runs `applyStaysAccessFilter()` once, so the stays access facet reaches every
  mounted country's stays layer, including one mounted after the facet was
  set. Every coverage layer is added under the selected-POI overlay
  (`cov-sel-icon`, created by `addCoverage()` before the first mount), so a
  late-mounted country sits where the boot-time layers do. One
  click/hover handler set, bound once, hit-tests every mounted icon layer
  (`queryRenderedFeatures`) and acts on the top-most hit, so overlapping icons
  from two countries open one drawer.
- **The per-country POI counts are cached for ten minutes.**
  `CoverageStatsProvider::poisByCountry()` feeds both the `/coverage` headline
  total and its table, and each count is an index-only scan over every row in
  `coverage_poi` (167 ms against two million rows). It is memoised within the
  request and cached in `cache.app` between requests for `POIS_TTL` (600 s),
  because the number changes only when the pipeline harvests. A cache backend
  that cannot answer falls through to the query rather than to a wrong page.
- **Server-side manifest read.** `App\Coverage\CoverageManifest`
  (`web/src/Coverage/CoverageManifest.php`) fetches the manifest server-side,
  caches the per-country tiles for `BucketManifest::CACHE_TTL` (value `3600` s,
  `cache.app`), and degrades to an empty array on *every* failure path (flag
  off, empty URL, HTTP/transport error, malformed shape: logged, never
  thrown). `MapController` injects the result as the `coverage` family of the
  nonce'd global `window.CC_TILES` (`cc`, or `*` for one archive serving every
  country, to `{tiles:{points:url}, bounds, stamp}`), an empty object when no
  entry resolved. Result: a new artifact goes live within an hour of upload
  with no deploy and no client manifest fetch on boot, and the map always
  renders, tiles or not.
- **The four readers share one base, and one page starts them together.**
  `CoverageManifest`, `SurfaceManifest`, `RoutesManifest` and
  `RoadPiecesManifest` all extend
  `App\Coverage\BucketManifest` (`web/src/Coverage/BucketManifest.php`), which
  owns the fetch, the positive and negative cache, and the degrade-never-throw
  rule. Each subclass supplies only what differs: whether a pin or a flag makes
  the bucket moot (`needsManifest()`), the shape check (`validate()`), the
  cache key, and its two log lines.

  `BucketManifest::prefetch()` starts a fetch and returns without waiting.
  A page that reads more than one manifest must prefetch them all before
  reading any: `/map` reads three (four for a curator or the Scout ride
  review, which add the road pieces) and `/v1/map-config` reads two, and read in
  sequence their `FETCH_TIMEOUT` (value `5` s) would apply per fetch (staging
  measured 10.6 s on a cold sequential `/v1/map-config`). Started together the
  page's worst case is one `FETCH_TIMEOUT`, not one per manifest. A prefetch is
  skipped when the value is already cached and cancelled when it turns out
  unnecessary, so a warm cache still costs no round trip. Prefetching is an
  optimisation only: every reader still fetches inline when nobody prefetched,
  which is what a page that needs a single manifest keeps doing.
- **CSP:** the tile host is appended to `connect-src` (host enumeration owned
  by [security-architecture.md §2](security-architecture.md)) by
  `App\EventSubscriber\CspSubscriber` from the dedicated `COVERAGE_CSP_HOST`
  env var (container param `coverage.csp_host`) — a separate param rather than
  parsing the manifest URL, because the server fetches the manifest from a
  different origin than the browser range-reads tiles from (dev:
  `http://minio:9000` vs `http://localhost:9100`). The `pmtiles` protocol
  library is vendored same-origin at `web/assets/lib/pmtiles-4.4.1.js`
  (security-architecture.md §2.5). It is a classic script that publishes
  `window.pmtiles`, so it is untouched by MapLibre's move to ES modules; the
  map code still registers it with `maplibregl.addProtocol('pmtiles', …)`.

## 5. Symfony query plane: `/map/coverage/*`

Implemented by `App\Coverage\CoverageRepository` +
`App\Controller\CoverageController` (raw DBAL over `coverage_poi ⋈ item`).
`entry` below = `{ref, letter, n?, kind?, region?, rid?, ll: [lat, lng],
curated: bool, itemId?}`. A search entry names its region (`region.name`), because several
places can share one name. Our own item also carries its region id (`rid`), so
the map can load that region and open the item itself. A P or Q row the pipeline has not stamped with a
kind yet gets it from its OSM tags (`PlaceKind::fromOsmTags()`), in search and
in the drawer detail alike (osm-data-architecture.md §5a).

| Endpoint | Replaces | Response / caching |
|---|---|---|
| `GET /map/coverage/search?q=` | in-memory `ITEM_INDEX` sidebar search (coverage part) | `{"results": entry[], "attribution"}` — ranked curated first, then community; trgm-backed; default limit `CoverageRepository::SEARCH_LIMIT` (value `12`); ETag + `max-age=300` |
| `GET /map/coverage/nearby?lat=&lng=&km=` | town-card 5 km client-side haversine scan | `{"groups": [{letter, total, items: entry[]}], "attribution"}` — `ST_DWithin`, grouped by letter, community capped per group (`CoverageRepository::NEARBY_COMMUNITY_CAP`, value `3`) behind a "show all" expander; 422 on bad coords; `max-age=300` |
| `GET /map/coverage/counts` | rail totals | `{"counts": {"B": n, …}, "attribution"}`; **scope-aware**; summed from `coverage_count` (§11); `max-age=3600` |
| `GET /map/coverage/poi/{osmType}/{osmId}` | new: drawer detail for tile POIs | `{ref, letter, name, kind, ll, tags, photo, ferryRoute, curated, attribution}`: `tags` filtered to `CoverageRepository::TAG_WHITELIST` (store rich, serve trimmed); `ferryRoute` = `{ref, name, tags}` for the one ferry route a dock links, else `null` (§5); `curated` = `{itemId, state, fields, confirmations}` or `null`; `osmType ∈ {node, way}`; 404 when the ref is not cached; ETag + `max-age=300` |

- **A gone row lists nowhere (catalog-data-model.md §7).** The
  curated arm of `search` and `nearby` carries `GoneRows::notGoneSql('i')`,
  the predicate the payload and the public API use, so a row
  approved as "Not there anymore" is neither a curated entry nor, through
  its still-claimed OSM ref, a community one. Pinned by `CoverageQueryTest::test{Search,Nearby}GoneRowListsNowhereAndStillShadowsTwin`.
- **Region scope params (map-and-search.md §4.5).** `search`,
  `nearby` and `counts` accept optional `rids` (csv region ids →
  `region_id IN (…)`) and `cc` (2-letter country → `country_code = :cc`). The
  **community (`coverage_poi`) arm** takes both, ORed, so an unsplit-country row
  (region_id NULL, cc set) still matches. The **curated (`item`) arm is rid-ONLY**
  (drops `cc`): the map's served-data gate is rid-only and the catalog payload
  carries no `cc`, so a `cc`-OR arm here would list a curated POI whose pin the
  map hides — a served item gets its region stamped on write (`SpatialResolver`)
  or by the importer's membership recompute, so rid-only is complete. Counts
  become scope-aware and drive BOTH sides of a coverage layer's rail badge: the
  in-scope count is the `total`, and also the `shown` (every in-scope POI is on
  the map, revealed progressively as you zoom — the client does NOT count
  viewport-rendered tiles, which would read a confusing near-zero at overview
  zooms; `coverage.js covShownCount`, which follows the layer's mode rule
  while none of its per-country layers is mounted yet). So a coverage layer reads N/N when on,
  0/N when toggled off or mode-hidden — matching the served layers. **At an
  overview zoom the layer reads its full N/N even though no individual icons
  render**, the icons appear from z11 (water) or z12 (the rest); below that,
  e.g. Germany fitted at ~z5.8, shows the density **heatmap** instead; the count is
  honest (every POI IS in scope), the icon pixels arrive on zoom-in.
  Params are client-sent only (the plane is anonymous + cacheable — never
  server-resolved from a user); `rids` is de-duped by numeric value (zero-padded
  duplicates collapse), sorted, overflow/garbage rejected to the empty scope, and
  capped at `CoverageController::MAX_SCOPE_REGIONS` (value `24`) to bound the SQL
  IN-list (map-and-search.md §4.5); the cap is safe because a
  country scope's `cc` arm is the complete fallback (guaranteed by the pipeline's
  region ⇒ cc invariant), and it logs when it actually truncates. The HTTP-cache
  key is the raw client query string, which `covScopeQuery` already canonicalises
  (sorted, deduped) — the server sort/dedupe only keeps the SQL binding stable.
  Absent = current behaviour (backward compatible). The deep-link resolver
  (`openCoverageFeatureByName`, and `openCoverageByOsmRef` for `?ref=`)
  deliberately sends **no** scope params, so a deep link finds its target
  regardless of the saved scope, then widens.

Rules:

- **Server dedupe** ([osm-data-architecture.md §8](osm-data-architecture.md)):
  a coverage row is suppressed wherever a *served* `item` shares its
  `source_ref` — the object appears once, as curated. "Served" is
  `App\Catalog\ItemState::servedSqlTuple()`
  (`web/src/Catalog/ItemState.php`; the served-state set is owned by
  [catalog-data-model.md §4](catalog-data-model.md)).
- **The dedupe's letter guard is deliberately uneven.** A served `item` only
  cancels a coverage row if it is more than an untouched OSM import — a row
  still matching the retirement predicate
  (`App\Catalog\CoverageRetirement::untouchedOsmSql()`) counts as community,
  not curated (coverage-provider.md §9). Two shapes of that test coexist:

  | Endpoint | Test on the joined `item` |
  |---|---|
  | `poi/{osmType}/{osmId}` (`detail()`) | `NOT (i.letter IN (B,C,D,F,G,O,P,Q) AND untouched)` — letter-guarded |
  | `search`, `nearby`, `counts` | `NOT (untouched)` — **no letter guard** |

  The two diverge only for an untouched OSM `item` whose letter is outside
  `CoverageRetirement::LETTERS` (so A, N, E or R) that nonetheless shares a
  `source_ref` with a cached POI: `poi` would report it `curated`, while
  `search`/`counts` would still show the place as community. This is accepted,
  not overlooked. It cannot arise from the current pipeline, which writes only
  those same eight letters into `coverage_poi`, and A never enters the artifact
  while N is wikidata-sourced (coverage-provider.md §4). **Before letting any
  other letter share a `source_ref` with a coverage row, add the letter guard
  to the three `NOT EXISTS` clauses too.**
- Every response carries `"attribution": "© OpenStreetMap contributors (ODbL)"`
  (`CoverageRepository::ATTRIBUTION`), per
  [osm-data-architecture.md §3–4](osm-data-architecture.md).
- Every endpoint gets an **exact-path `PUBLIC_ACCESS`** entry in
  `web/config/packages/security.yaml` — the established pattern for cacheable
  map endpoints (scheb lazy-firewall gotcha; see the existing
  `^/map/catalog\.json$` entry and its sibling exact-path rules).
- **Rate limiting:** all four actions share `coverage_read` — the app's first
  anonymous-read limiter, inventoried in
  [security-architecture.md §7](security-architecture.md) and consistent with
  the API-only/no-scraping access terms of
  [osm-data-architecture.md §7](osm-data-architecture.md). Config key
  `coverage_read` in `web/config/packages/rate_limiter.yaml`: sliding window,
  limit 120 per 1 minute, per-IP key, dedicated pool
  `cache.coverage_read_limiter` (array adapter under `when@test`, following
  the `ride_check` precedent). Over the limit: 429 JSON
  `{"error":"rate_limited"}`.
- **Photos on a scenic point follow the camera rule
  ([scenic-views.md §8](scenic-views.md)).** `GET /map/coverage/photo/{osmType}/{osmId}`
  resolves the point's Commons file (a `wikimedia_commons`/`image` tag, else the
  Wikidata P18 cached in `wikidata_image`) and answers `{state: ready|pending|none}`
  from `CommonsPhotoAdmission`; a ready photo carries `cameraAt` when Commons
  records where the camera stood (`commons_photo.camera_lat`, `camera_lng`,
  `camera_checked_at`, [photo-uploads.md §5g](photo-uploads.md)). Every answer
  is `PhotoValidator::verdict()` for one place
  ([photo-uploads.md §5h](photo-uploads.md)): the served item standing for the
  point when there is one (`cp.ref IN (item.source_ref, item.osm_ref)`, the
  `poi` join), with that item's letter and pin, because the map draws that pin
  and the drawer opens that item; otherwise the point's own letter and position
  (`CoverageRepository::photoSubject()`). Commons' licence, author and
  non-free flags are judged before anything is downloaded, and for a P place a
  file whose camera is unknown or more than
  `PhotoValidator::MAX_CAMERA_DISTANCE_M` (250 m) from its pin is not
  downloaded for it (`commons_photo.state = declined`) and answers
  `{"state": "none"}`. The fetch message (`FetchCommonsPhoto`,
  `ResolveWikidataImage`) carries that place's letter and position.
  The curated overlay of `poi/{osmType}/{osmId}` filters an item's `photo` and
  `photos` the same way, against the item's own pin.
- **Getting there shows whether a bike may come aboard.** For letter F the
  drawer turns `bicycle` and `bicycle:fee` into a Bikes on board row
  (`bikesOnBoard` in `assets/map/osm-tags.js`, the one place that holds the
  vocabulary): `yes`, `designated` and `permissive` read "Allowed", `no` reads
  "Not allowed" (a coverage point with `bicycle=no` is not loaded at all, §7, so
  only a curated item shows it), `dismount` reads "Walk your bike", and `bicycle:fee=yes` adds
  "with a fee" to the first and the last. Any other value, or no `bicycle` tag,
  gives no row. The row carries the F field label "Bikes on board"
  (`CatalogFormRegistry`), so a curated item's stored value takes its place and
  an empty "+ add" prompt gives way to it.
- **A dock's inherited answer says where it came from.** A ferry dock with no
  `bicycle` tag may carry the answer of its ferry routes (§3); a dock with its
  own tag shows that tag's answer with no source words. `bikeAccess` in
  `osm-tags.js` reads the dock's own tag first and the `cc:` keys only without
  one; the row then reads "Allowed · from the ferry Enkhuizen - Stavoren" when
  one named route gave the answer, and "via its ferry route" or "via its ferry
  routes" otherwise (`bikeSourceText`). The route's name comes from the
  `poi` response: `ferryRoute` is `{ref, name, tags}` (tags whitelisted) for
  the one route in a dock's `cc:ferry_route`, whether or not the dock has its
  own `bicycle` tag, and `null` for a dock with several routes, a route not in
  the cache, or any other point.
- **A ferry route shows its crossing facts.** For a `route=ferry` point, and for
  a dock through `ferryRoute`, the drawer adds rows from `ferryFacts` in
  `osm-tags.js`: Crossing time from `duration` ("HH:MM", "HH:MM:SS" or ISO 8601
  such as `PT85M`, written "1 h 25 min"; a bare number, which could be minutes
  or hours, gives no row), Season from `seasonal` (`yes` reads "Seasonal", `no`
  "All year", free text verbatim), Service hours from `opening_hours`
  verbatim, Fare from `toll` or `fee` (`yes` in either reads "Paid", otherwise
  `no` reads "Free", a price gives no row), and the website (`website`,
  `contact:website`, `url`). A route's own website fills the drawer's usual
  Website row. Facts a dock borrows carry a "Ferry" badge, and the route's
  website gets its own badged row unless it is the dock's. Only a ferry route
  has these rows; a station's `fee` is not a fare.
- These are **site-internal map endpoints**, not the future public API.
  [osm-data-architecture.md §7](osm-data-architecture.md)'s reference-only
  rule governs the public API; the serving cache may serve OSM fields with
  attribution ([osm-data-architecture.md §4](osm-data-architecture.md)).

## 6. Map consumer and client-side dedupe

The full presentation contract lives in [map-and-search.md](map-and-search.md);
the data-plane facts it consumes:

- One `pmtiles://` vector source per published country (or `*`, from
  `window.CC_TILES.coverage`) with per-letter `<key>-<cc>-cov` symbol layers
  draws uncurated coverage. The per-letter `*_OSM` globals the catalog region
  slices fill (`web/assets/map/catalog-load.js` `CC_WATER_OSM` …
  `CC_HISTORY_OSM`) carry served items only; curated pins, climbs, routes and
  surface serve from the region slices, heat from `/map/heat.json`.
- **Client dedupe + the refs-mirror rule.** `CatalogProvider::payload()`
  (`web/src/Catalog/CatalogProvider.php`) gains a top-level
  `refs: list<string>` — the DISTINCT `source_ref`s of the `source='osm'`
  item rows **the payload itself serves**, plus the `osm_ref` twin of every
  served row that has one (an authority row attached to a tap,
  data-provider-hierarchy.md §4.1; without it an authority harvest draws each
  tap twice). Tile layers filter these refs out
  (`window.CC_CURATED_REFS`). The mirror rule is the invariant:
  `curatedRefs()` applies *exactly* the same exclusion as the payload's item
  collections, so `refs` excludes exactly what the payload excludes: a tile
  twin is **never suppressed for a row that is no longer served**. Untouched
  legacy OSM rows that are excluded from the payload but not yet deleted
  (§9) therefore render from tiles as community instead of vanishing.
  The claim rule itself lives in one place, `App\Catalog\ClaimedOsmRefs`:
  `curatedRefs()` reads its SELECT, and the ride check's coverage arm
  (map-and-search.md §9) excludes the same refs, so a point hidden on the map
  is never listed along a ride either.
- **A catalog item borrows its OSM point's photo.** A served item with no
  `photo`/`photos` of its own (after `PhotoValidator::sift()`) that stands for
  an OSM point, through `source_ref` or `osm_ref`, carries
  `photoRef: "<osm ref>"` in its catalog feature when that point's
  `coverage_poi` row (lowest letter, the row `poi` reads) passes
  `CoverageRepository::photoPossible()`, the same test behind `poi`'s `photo`
  (`CatalogProvider::osmPhotoRefs()`). The key is absent everywhere else, so
  other features stay byte-identical, and absent when `coverage_poi` does not
  exist. The drawer (`photoWaitRef()` in `commons-photo.js`) waits on
  `photoRef`, or on a coverage point's own `ref` when `poi` said `photo`, and
  polls `/map/coverage/photo/{ref}` for it, which judges the file against the
  item's pin (§5). Every way into an item drawer (pin click, `?item=`, a
  search or best-of hit with an item id, a live insert through
  `featureForItem()`) builds from these properties. An item's own photo always
  wins: `photoRef` is not emitted beside one, and the drawer shows a photo it
  holds before it waits on one. `photoRef` follows the payload's `?v=` tag, so
  a coverage harvest that adds a `wikidata` tag reaches an unchanged item's
  feature on the next catalog mutation or deploy.
- `?feature=` deep links resolve against the local (curated) index first, then
  fall back to one `/map/coverage/search` lookup, so coverage POIs stay
  linkable.
- `?ref=<osm ref>` deep links ([map-and-search.md §8](map-and-search.md)) skip
  the index entirely: one `/map/coverage/poi/{osmType}/{osmId}` call returns the
  letter, the coordinates and the tags, which is everything the drawer needs.
  This is what the share button emits for an uncurated coverage POI, because
  most of them have no `name`, and every unnamed scenic view is drawn as
  "Viewpoint", so `?feature=` could not tell 1,743 Belgian viewpoints apart.
  The accepted shape (`OSM_REF` in `osm-tags.js`) is pinned to the `node|way`
  the route requires and to the two prefixes `coverage_poi.ref` actually holds,
  so a share link is never mintable for something the endpoint would refuse.
  A POI whose OSM `name` tag is set also carries a readable slug after the ref
  (`?ref=node/462149319/roche-aux-faucons`); the slug is discarded on read, and
  a POI with no `name` gets none rather than being slugged with our own
  category word.
- **Degradation:** `catalog-load.js` keeps defining all `CC_*` globals (empty
  pools degrade gracefully); tile-source failure degrades to basemap +
  curated data — the same silent-degradation convention as the Photon
  geocoder.
- The community-tier decisions of [map-and-search.md §12](map-and-search.md)
  are implemented on this data source; their contract stays owned there.

## 7. Cross-language contract: `coverage-contract.json`

One committed file, `pipeline/contract/coverage-contract.json`, is the single
source of truth for the mapping both languages need:

```
{"version": 1,
 "letters": {"B": {"selectors": [{"tag": "amenity=drinking_water", "label": "Drinking water"}, …],
             "tileProps": […]}, …},
 "serviceKind": {"shop=bicycle": "shop", "amenity=bicycle_repair_station": "station",
                 "amenity=compressed_air": "pump"},
 "placeKind": {"P": {"tourism=viewpoint": "viewpoint", …}, "Q": {"historic=castle": "castle", …}},
 "universalTileProps": ["ref", "n", "t", "ridtok", "cctok"],
 "storedTagKeys": ["addr:city", "amenity", …],
 "surface": {…}, "routes": {…}}
```

`surface` and `routes` configure the line builds of §4 (classes, zooms, tile
props); the rest of this section is about the point catalogue.

- `letters` keys are exactly `B C D F G O P Q` — the
  [osm-data-architecture.md §5](osm-data-architecture.md) point catalogue.
- A letter may carry **`nearWay`** (`withinM`, `highways`, `bicycleTags`): its
  points load only within `withinM` metres of a way a bike may ride, a highway
  named in `highways` or any highway tagged `bicycle=` one of `bicycleTags`, and
  never one tagged `bicycle=no`. Only P (scenic views) carries it: 250 m, roads
  including service and gravel, cycleways, and bike-tagged tracks and paths. P
  selects viewpoints, waterfalls, rapids, cliffs, cave entrances, rock arches,
  rocks and boulders, never peaks (osm-data-architecture.md §5a). `run.py` filters each region's
  ways with a third osmium pass (`<region>-bikeways.osm.pbf`), and
  `load.py::_apply_near_ways` copies the ways near staged P points into a temp
  table and deletes the staged points with none in range, before the drift
  guard. Why and the measurements: [scenic-views.md](scenic-views.md).
- A letter may carry **`nameOrTags`** (a list of tag keys): its points load
  only with a non-empty `name` or one of those keys in `tags`. Every key must be
  in `storedTagKeys`, or `load_contract()` refuses the file, because an
  unstored key could never be present. P (scenic views) and Q (history and
  culture) carry it: `image`, `wikidata`, `wikimedia_commons`. `load_region`
  applies it to the staged rows before the near-way filter. See
  [scenic-views.md §2](scenic-views.md), rule 3.
- A letter may carry **`excludeTagValues`** (a map of tag key to values): a
  point does not load when that tag is set and **every** one of its
  `;`-separated values is in the list, so `memorial=plaque;statue` stays for its
  statue. Every key must be in `storedTagKeys`. Q and F carry it. Q:
  `memorial` = `bench`, `blue_plaque`, `ghost_bike`, `grave`, `plaque`,
  `stolperstein`, `tomb`. A Stolperstein or a plaque is a memorial, not a place
  to ride to (owner 2026-09-15). F: `usage` = `leisure`, `tourism`, and
  `bicycle` = `no`, and `cc:bicycle_from_route` = `no`. A heritage railway such
  as the Museumstoomtram Hoorn-Medemblik (node/521261183, `railway=station`,
  `usage=tourism`) is a day out, not a way to get somewhere, and a ferry that
  takes no bikes gets no cyclist anywhere, whether the ferry route says so or
  its dock inherited it from every tagged route that ends there, such as the
  Veerdienst Zuiderzeemuseum (node/47228717, §3) (owner 2026-09-15). `load_region` applies it after `nameOrTags`,
  and a letter it filters is left out of the drift guard like the other rules.
  Measured on the Netherlands (2026-09-15), History and culture went from 9,056
  points to 3,400: 4,033 had no name and no photo link (mostly unnamed
  memorials and burial mounds), and 1,623 named small memorials followed
  (1,254 Stolpersteine, 350 plaques). Castles, forts, manors and monasteries
  hardly change; Veteranenmonument (`memorial=war_memorial`) stays.
- The bike-way pass keeps memory flat: `extract.py::export_lines` runs `osmium
  export` with a disk-based node location index (`sparse_file_array` in a
  temporary directory), because the in-memory index for Germany's bike ways
  takes several GB. On a machine short of memory, load countries one at a time
  with `python -m coverage.run --load-only --regions <region>` (no export, no
  tiles, no publish) and build the artifact once with `--tiles-only` over the
  full region list.
- `storedTagKeys` is the **serve-set**: the only tag keys `parse.py` writes into
  `coverage_poi.tags` (§2 storage policy). Sorted + unique, and validated on
  load — `load_contract()` raises if it drops a selector key, drops a key
  `tiles.py::_EXTRA_SQL` reads (`contract.py::TILE_DERIVED_TAG_KEYS`, itself
  pinned to that SQL by `pipeline/tests/test_tiles.py`), drops a key `parse.py`
  writes on a ferry dock (`contract.py::ROUTE_INHERITED_TAG_KEYS`), or lists
  `name`.
- The **Python job consumes it** (`pipeline/coverage/contract.py::load_contract`,
  dataclasses `Selector`/`LetterSpec`/`Contract` with `letters_for(tags)`,
  `kind_for(tags)` for the D `serviceKind` and `kind_for_letter(letter, tags)`
  for D, P and Q); PHP never reads it at runtime.
- **PHP tests pin it** (`web/tests/Catalog/CoverageContractTest.php`): the
  `serviceKind` rules must resolve identically through
  `App\Catalog\ServiceKind::fromOsmTags()`, every PHP kind must be reachable,
  the D selectors must be exactly the `serviceKind` rule set, the P and Q
  `placeKind` rules must equal `App\Catalog\PlaceKind::harvestRules()` and be
  exactly those letters' selectors, and
  **`CoverageRepository::TAG_WHITELIST ⊆ storedTagKeys`** — the drawer can only
  render what the pipeline stored, and there is no live-OSM fallback to cover a
  gap. The test skips (not fails) when the file is absent so `web/` stays
  runnable alone. That skip means the guard only fires where the whole repo is
  checked out, never in the dev container, which mounts `web/` alone, so
  `ci-app.yml` lists `pipeline/contract/**` in its trigger paths and a
  contract-only edit re-runs the PHP pin.
  `.github/workflows/ci-pipeline.yml` runs the whole pipeline pytest suite on
  `pipeline/**`, which covers `load_contract()`'s validation and the
  `test_tiles.py` drift pin. It runs natively rather than building
  `pipeline/Dockerfile`: it installs `osmium-tool`, builds the pinned
  tippecanoe and downloads go-pmtiles (both checksum-verified), and uses a
  PostGIS service for the `db` fixture.
- Result: the Python extractor and the Symfony serving plane cannot drift —
  one mapping, asserted from both sides.

## 8. Rollout: `COVERAGE_TILES`

- Env flag `COVERAGE_TILES` (0|1, container param `coverage.tiles_enabled`,
  wired in `web/config/packages/coverage.yaml`; **defaults `1`** in `web/.env`).
  Off disables the tile *display* plane: no manifest fetch, no `coverage`
  entries in `CC_TILES`, no coverage CSP host; the map degrades to basemap +
  curated data.
- There is no second display path for uncurated OSM: the tiles are it. The
  retirement exclusion in `CatalogProvider` (coverage-provider.md §9) is
  **unconditional** (not flag-gated), the `refs` list mirrors it
  (coverage-provider.md §6), and the `verified` property stays. A and N never
  pass through the exclusion.
- Enabling the flag in prod is gated on the checklist in
  `developers/coverage-batch.md` (bucket + manifest published, and client-IP
  propagation verified for the per-IP `coverage_read` limiter).

## 9. Legacy retirement (owner-gated)

Harvested OSM rows that duplicate the cache leave the canonical store
([osm-data-architecture.md §9](osm-data-architecture.md)):

- **Retirement predicate** (identical in `CatalogProvider` and the command):
  `source='osm' AND state='unverified'` AND zero `change_history` AND zero
  `item_confirmation` AND zero `submission` rows for the item — exactly what
  the cache serves. **Anything a human ever touched stays canonical.**
  The command additionally letter-guards `IN ('B','C','D','F','G','O','P','Q')`:
  A (not in the coverage artifact) and N (own data) must never be deleted.
- Console command `app:coverage:retire-legacy`
  (`web/src/Command/Coverage/RetireLegacyOsmCommand.php`): the **bare
  invocation IS the dry-run**: per-letter counts, zero writes; there is no
  `--dry-run` option. `--force` performs the transactional DELETE and prints
  the deleted count.
- **The `--force` run is owner-gated**: explicit owner approval plus a dev-DB
  `pg_dump` backup into `developers/docker/backups/` first.
  Display is already correct either way: untouched legacy rows are out of
  the payload *and* out of `refs` (mirror rule, coverage-provider.md §6), so
  the destructive run is pure database cleanup with zero display change.
- **PIVOT rows stay canonical** (`source=authority`, Tourisme Wallonie CC-BY
  attribution). `tools/wallonia` does no OSM POI work; it is kept for the
  atlas demo and the canonical seeds (climbs, routes, surface, PIVOT). The
  coverage path never touches Overpass.

## 9.1 The public /coverage page

`/coverage` is a DB-driven page served by `App\Catalog\CoverageStatsProvider`
(raw DBAL; only the per-country POI counts are cached, §4): live KPIs
(POI/item/route/country COUNTs), a per-country volume table, and "biggest
gaps" cards naming the three catalog categories with the fewest publicly
served items (`thinnestCategories()`). Because `coverage_poi` is
pipeline-owned DDL (§2) and absent on a fresh contributor stack, every read
of it in the provider is guarded by `to_regclass()` and degrades to zero
rather than failing the page. Coverage *percentages* are deliberately absent:
there is no honest denominator for "how complete is a country", so the page
shows real volumes instead.

**The table.** Operational countries only, with the same L2-exclusion
predicate as the region pages. The bar compares POI **density**, reference
items per km² of onboarded area scaled to the densest country, because an
absolute-volume bar would dwarf small countries under the biggest one forever
(owner 2026-07-30). Rows sort by that density, highest first, country code as
tiebreaker (owner 2026-08-30). The reference-items column header offers the
other order, absolute total, as a **link** to `?sort=total` rather than a
client-side toggle: a toggle needs an inline script, an inline script needs a
CSP nonce, and a nonce is the one thing a shared cache cannot hold
([page-caching.md §3.2](page-caching.md)). As two URLs both orders are cached
and both work with no JavaScript. `CoverageStatsProvider::countries()` takes
the sort key; the controller validates it against `SORT_DENSITY` and
`SORT_TOTAL` and falls back to density rather than 404ing. The cell is two
lines, the bar with the absolute total at its end and the density under it,
and whichever metric is the active sort is ink while the other is grey (owner
2026-09-08).

The density is **printed as a number** beside the bar, not left implicit in
its length: a bar with no scale can only say one country is longer than
another, which is the opposite of what a density bar exists to correct. The
explanatory list sits **below** the table: the numbers are what a reader came
for.

**Provenance is shown on the catalog column, not the reference one**, because
that is where the mix is. `coverage_poi` is OSM top to bottom (every row
carries an `osm_version` and a node/way `ref`, and the table has no source
column, §2), so a "breakdown" there would be one bar wearing a chart's
clothes. Partner data does not land in that layer: it is imported as catalog
`item` rows keeping their own `ItemSource`, which is how the Wallonia PIVOT
stays and drinking-water taps sit alongside rider contributions.

The `ItemSource` values are bucketed to four the page can say out loud:
`osm` (mirrored) · `partner` (`authority`, `wikidata`: an open dataset
somebody else maintains) · `riders` (`user` and `manual`, which the enum
defines as a hand-added row treated like a contribution) · `derived` (`auto`,
the pipeline). An unrecognised source falls into `derived` rather than
vanishing, so a new importer shows up as an unexplained number instead of
silently shrinking the total. Zero buckets are dropped, so an OSM-only country
shows one word rather than four with three noughts.

**The globe.** The second view (owner 2026-09-08: "a cool way to visualize
the coverage besides a list") shows the same countries on the globe the
regions page uses (`assets/pages/country-globe.js`, one shape per country
from `/regions/outlines.json`). The globe is plain: one-colour land, a sea,
admin-2 borders and nothing else (owner: "more simple, rest of the world one
colour"). Each country is filled by a **density class**:
`CoverageStatsProvider::densityClasses()` ranks the countries with any
reference items by POIs per km² and cuts them into `DENSITY_STEPS` (five)
equal-count steps, one hue from pale to spruce. Equal-count and not
equal-width, because density spans three orders of magnitude and a linear
scale would paint every country but one the palest step. A country with
nothing on file is class 0, a wash of ink outside the ramp, so the legend never
claims a range that starts at nothing; ties rank by country code so a cached
page and its next render agree. The classes are a sort of the country list
already in memory, computed for every response. The legend under the globe
prints each step's density range from the same bounds the paint uses, and the
five colours are one Twig list handed to the script on `data-ramp`, so paint
and legend cannot drift. Hover names the country and its density; a click
shows a card with the figures of that country's table row (regions, density
and total, catalog items with provenance, routes) and a link to its regions.
Every card is rendered by Twig and waits hidden, so the script holds no string
and the view needs no nonce. The ramp (`#84AC98 #63937C #46785F #2D5A44
#1C3A2A` on the paper surface) passed the ordinal checks, lightness monotone
and a 2:1 light-end contrast.

**Both views are in one response, and a class decides which one is on
screen.** `/coverage` is one URL (one cached body per sort order). The table
is what the markup shows on its own, because it is the view that works with no
JavaScript; a head script, `assets/pages/coverage-view.js`, adds `cc-globe` to
`<html>` before paint and CSS swaps the two. A screen 900px or wider opens on
the globe (owner 2026-09-08: "should open on the globe page if not mobile"),
and a `?view=table` or `?view=globe` in the URL wins over the width, so a
shared link opens on the view it names. Switching needs no navigation: the
chips are buttons that toggle the class.

**MapLibre is only loaded when the globe is shown.** `assets/pages/coverage-globe.js`
builds the globe on its first showing, so a reader who stays on the table
(every phone, by default) never fetches the library. The chips are hidden
until that script runs, so a reader with no JavaScript is never offered a
switch that cannot move.

## 10. Relationship to other documents

- [osm-data-architecture.md](osm-data-architecture.md) owns the policy this
  provider implements (tag catalogue, data categories, licensing,
  materialize-on-edit, API policy).
- [map-and-search.md](map-and-search.md) owns how coverage is presented
  (markers, ordering, community tier, reveal behaviour).
- [catalog-data-model.md](catalog-data-model.md) owns canonical item identity
  (`source`, `source_ref`, states) that the dedupe and retirement predicates
  join against.
- [security-architecture.md](security-architecture.md) owns the CSP host
  enumeration and the rate-limiter inventory that this provider extends:
  `COVERAGE_CSP_HOST` joins its §2 connect-src table, and `coverage_read` is
  the anonymous-read row in its §7 inventory.
- [dev-environment.md](dev-environment.md) owns the container stack the
  pipeline runs in (compose profiles, MinIO, worker scheduling, prod
  topology).
- Contribution on uncurated coverage POIs is materialize-on-edit,
  owned by [osm-data-architecture.md §6](osm-data-architecture.md): a coverage
  drawer's edit link carries the point's OSM ref (`/improve?ref=…`), and its
  one-tap confirm posts to `/osm/confirm`.

## 11. Kept counts: `coverage_count`

`/map/coverage/counts` is a sum over `coverage_count`, one row per
(country_code, region_id, letter) with the number of coverage rows still
shown. A live count walks every coverage row and asks whether one of our items
claims it: unscoped that measured **26.6 s** on dev (2.06 M rows), against
0.06 s for the sum. The database keeps the table right on its own, because the
coverage rows are written by the Python pipeline and the claims by PHP, and
neither side can see the other's writes.

`web/migrations/Version20260906180000.php` owns every piece:

- `coverage_poi_shown(ref)`: the predicate, in SQL, that mirrors
  `CoverageRepository::liveCounts()` and `CoverageRetirement::untouchedOsmSql()`:
  shown unless a served item claims the ref through `source_ref` or `osm_ref`
  and that item is more than an untouched OSM import.
  `CoverageCountTest::testTheCountTableAgreesWithALiveCount` keeps the two
  languages equal.
- `coverage_count_refresh(cc, rid, letter)` recounts one bucket;
  `coverage_count_rebuild()` recounts everything (the slow walk, once);
  `coverage_count_refresh_refs(text[])` recounts the buckets a set of refs
  lives in.
- **Statement-level triggers with transition tables**, so a 300 000-row
  harvest recounts each touched bucket once rather than once per row: on
  `coverage_poi` (insert, update, delete: the buckets old and new), on
  `item` (the refs old and new), and on `change_history`,
  `item_confirmation` and `submission` (the first touch on an OSM import is
  what hides its twin, so those tables move counts too).
- `coverage_count_install()` creates the `coverage_poi` triggers and the
  bucket index if that table exists, and rebuilds when the table is empty.
  The migration calls it; the pipeline's `ensure_schema()` calls it after
  creating the table (guarded on the function existing, so pipeline and app
  can deploy in either order); the test trait `CoverageSchema` calls it after
  its own CREATE. Nothing else may create `coverage_poi` without calling it.
  `catalog_change_install()` follows the same three call sites: it creates
  the `coverage_poi` trigger that moves every catalog region's stamp when a
  point's photo tags may have changed (catalog-data-model.md §9.1).
- `app:coverage:recount` runs install and rebuild by hand, for the day rows
  were loaded with triggers off, or for proof.

The read side sums buckets under the scope arms of §5
(`region_id IN (:rids) OR country_code = :cc`), `HAVING SUM(n) > 0` so an
emptied bucket does not surface as a zero. A full rebuild took 51 s on dev.

**The recount reaches its bucket through an index (`Version20260910150000`).**
`coverage_count_refresh()` addresses a bucket as `COALESCE(country_code, '')`
and `COALESCE(region_id, 0)`, so a NULL country or region is a bucket of its
own; a plain index on the columns cannot serve that. Without an index on the
expressions the planner walks every row of the letter (476 000 for history)
and calls `coverage_poi_shown()` on the way: 350 ms a recount, and a
curator's one-tap confirm fires six. `coverage_poi_bucket_key_idx` on the
same two COALESCE expressions plus `letter` makes it 29 ms with the predicate
untouched, so the rule stays defined once; `coverage_count_install()` creates
it, and `item.source_ref` has `idx_item_source_ref` for the claim lookup
inside the predicate. One tap still fires six recounts; a per-transaction
dedupe is the next step if a heavier write path ever needs it.

## Open questions

- **Multipolygon relations** (~1–3 % of objects, e.g. some castles) are not
  harvested (nodes + way centroids only); pyosmium area assembly is an approved
  fast-follow with no scheduled plan yet.
- **Planet scale** is unmeasured: the worldwide flip is gated on a documented
  dry-run (disk, RAM, wall-clock on worker-class hardware, procedure in
  `developers/coverage-batch.md`); no numbers exist yet. That includes tile
  count and size at z11–14 across a planet-wide extract, the full rebuild time
  (§2.2), and worldwide search latency (trgm + bbox bias).
- **Tile density tuning** at z14 may need per-layer minzoom adjustment beyond
  `--drop-densest-as-needed`; to be observed on real artifacts.
