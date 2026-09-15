<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Coverage Provider

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

> **Implemented** (2026-07-17). This is the shipped contract for the coverage
> provider — the batch job, serving cache, tiles, and query plane described
> below are built, and every file path is the real location in the tree.
> Where the design and the implementation plan refined a detail differently,
> the plan's refinement is recorded here.

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
  own run; regions can stagger across the week. **Every onboarded country must
  appear in that list**, and a country can be onboarded without appearing in it:
  Spain was added to `COUNTRY_BY_REGION` on 2026-08-08 and not to
  `COVERAGE_REGIONS`, so from then until 2026-08-14 nothing following the
  committed config ever refreshed its 234,958 POIs. The two lists are the same
  fact written twice and drift silently — `COUNTRY_BY_REGION` hard-fails on an
  unknown region, but neither errors on a *missing* one. *Border caveat:* Geofabrik
  extracts overlap in a border buffer, so one OSM entity can arrive staged in
  two adjacent extracts with the same `(ref, letter)`. Ownership is decided at
  staging, by geometry, not by write order: each staged row is
  resolved to the **single nearest region** within `BOUNDARY_SNAP_DEG`, ordered
  by distance then area then id, and the extract keeps the row only if that
  region's country is its own. Because that lookup ignores which extract is
  asking, every extract computes the same answer, so exactly one ever inserts a
  given `(ref, letter)` and a shared border entity never collides with the
  global `UNIQUE(ref, letter)`. *"Within `BOUNDARY_SNAP_DEG` of a region of my
  own country" is NOT sufficient* — Geofabrik's overlap reaches ~0.10°, ten
  times the snap, so both neighbours satisfy that weaker test and ownership
  falls back to write order for ~1,691 rows. Nearest-wins is what makes it
  exclusive; containment still beats proximity automatically at distance 0. `load_region`'s `INSERT … ON CONFLICT (ref,
  letter) DO UPDATE` still runs after that filter and still derives
  `country_code` from the geometric region — it is no longer how ownership is
  decided, only the safety net for a stale row a former owner hasn't deleted
  yet. A staged row that falls in no onboarded region at all is dropped rather
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
Doctrine's `schema_filter` (`web/config/packages/doctrine.yaml`, currently
`~^(?!topology\.)~`) gains a `coverage_` exclusion on the same pattern, so
migrations and `schema:validate` never touch it. The table is a **disposable
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
    kind          varchar(16),            -- serviceKind for D (shop|station|pump), NULL otherwise
    name          varchar(255),           -- OSM name tag, NULL when unnamed
    geom          geometry(Point, 4326) NOT NULL, -- nodes as-is; ways centroid at load
    tags          jsonb        NOT NULL,  -- full filtered tag subset (drawer + Plans 3/4 source)
    osm_version   int,                    -- upstream version (materialization snapshot)
    osm_ts        timestamptz,            -- upstream last-edit timestamp
    src_region_id smallint     NOT NULL REFERENCES coverage_source(id), -- harvest extract (normalized: 2 bytes/row, not a repeated ~16-byte string)
    country_code  char(2),                -- stamped from extract config
    region_id     int,                    -- ST_Contains(region.geom, geom) at load; NULL until polygons exist. Soft ref to region.id (4 bytes: region count never nears int4)
    UNIQUE (ref, letter)                  -- one entity may carry two letters (item's uniq_item_source_ref_letter, source-scoped: catalog-data-model.md §3)
);
-- Indexes: GIST(geom), (letter), (region_id), (country_code), (src_region_id), GIN(name gin_trgm_ops)
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
- **Measured sizing.** At 377,558 rows (BE + NL + DE + LU; 0 unstamped
  after the ownership fix), compacted steady-state:
  **341 B/row heap + 176 B/row indexes = 517 B/row** — the compacted per-row cost.
  Since 2026-08-25 there is one more index, `coverage_poi_geog_idx`, a
  functional GiST on `(geom::geography)` (about +60 B/row). Every radius
  query the app runs casts to geography (`ST_DWithin(cp.geom::geography, …)`
  in `OsmLinker` and `/map/coverage/nearby`), and the geometry GiST cannot
  serve that predicate: without it the planner walked the letter index and
  measured 375k rows per lookup, 2.8 s per queue card on `/moderate`
  (owner-reported). With it: 2 ms. Pipeline-owned like the rest
  (`load.py` `_INDEX_DDL`, created CONCURRENTLY by `ensure_schema`); prod
  gets it on the next harvest or by hand before go-live.
  **Superseded:** the earlier `DELETE-all-then-INSERT-all` per-region swap doubled the row
  count mid-swap and left a large reusable-free-space high-water mark (measured then:
  258 MB heap, 67 % reusable free space), which needed a `VACUUM FULL`/`pg_repack` to
  return to the OS. The load is now a **diff-merge** (upsert-changed + delete-disappeared),
  so a weekly harvest rewrites only the OSM delta — no mid-swap doubling, dead tuples are
  bounded by the (small) churn, and **`VACUUM FULL` is no longer needed** (it is
  prod-unsafe on the shared host anyway; `pg_repack` remains the option if a one-off file
  shrink is ever wanted). The per-row figure is
  stable across countries (tags average 205 B/row in DE, 199 in BE, 197 in NL —
  Germany is the most exhaustively tagged country on Earth, so the worldwide
  average should drift down, not up; the one item that grows is the `name`
  trigram index once names are multibyte CJK/Cyrillic/Arabic, ≈ +20 B/row).
  Against the ≈ 4.7 M planet-wide subset (§10) that projects to **≈ 2.4 GB
  all-in** for full world coverage (≈ 1.8 GB at 75 %), with a credible band of
  1.3-2.7 GB driven entirely by the row-count estimate, not by per-row cost.
  Two consequences: the whole coverage index fits in page cache on the existing
  DB host, and any earlier "100M+ rows / tens of GB" framing (naive area
  extrapolation from German POI density) is wrong and has been removed from
  `pipeline/coverage/load.py`.
- Region membership (`region_id`, `country_code`) and provenance
  (`src_region_id`) are stamped at **load time**, so region/country-scoped
  queries never test containment at request time. `src_region_id` is the
  per-region atomic-swap key (the load's previous-count / `DELETE` / membership
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
object arrives carrying its full tag set. Before the trim the cache
therefore stored **4,220 distinct keys** — a single memorial contributing 20 of
them — while nothing in the codebase read more than **29**. That is the bulk-OSM
duplication [osm-data-architecture.md §1](osm-data-architecture.md) principle 1
forbids, arrived at by omission rather than by decision.

The serve-set is three groups:

| Group | Count | Keys | Read by |
|---|---|---|---|
| **Selectors** | 11 | `amenity`, `drinking_water`, `historic`, `man_made`, `natural`, `railway`, `route`, `shelter_type`, `shop`, `tourism`, `waterway` | Classification (letter + `serviceKind`); `tiles.py::_label_case` re-reads them at tile-build time |
| **Rules** | 2 | `memorial`, `usage` | `load.py` `excludeTagValues`: Q leaves out small memorials, F leaves out heritage railways (§7) |
| **Display** | 19 | `opening_hours`, `website`, `contact:website`, `url`, `phone`, `contact:phone`, `addr:city`, `addr:street`, `addr:housenumber`, `operator`, `description`, `wheelchair`, `fee`, `capacity`, `ele`, `direction`, `height`, `bicycle`, `bicycle:fee` | `CoverageRepository::TAG_WHITELIST`: exactly what the drawer renders (§5). `wheelchair`/`drinking_water` also feed tile props (§4) |

`TAG_WHITELIST` has **25** entries: the 19 display keys, three selectors the
drawer also renders (`amenity`, `shop`, `drinking_water`), and three media keys
(`image`, `wikimedia_commons`, `wikidata`). Those six are counted in their own
rows, so these groups and `check_date` (§4, the `cd` tile prop) sum to
11 + 2 + 19 + 4 + 1 = **37** distinct keys.

`ele`, `direction` and `height` were added on 2026-08-21 for the scenic-view
letter (P), where OSM's own record is often richer than what the drawer showed:
a peak carries its altitude, a viewpoint the compass bearing it faces, a
waterfall the metres it drops. The drawer reads all three for letter P only
(`covProps` in `assets/map/coverage.js`), but the whitelist is per-tag rather
than per-letter, so the same facts appear wherever else they are tagged. Values
are free text in OSM, so `assets/map/osm-tags.js` refuses anything that is not
plainly metres instead of guessing — "1200 ft" renders verbatim, never as 1200
metres. **Existing rows do not gain the tags until the country is re-harvested**
(§3); this is the first contract change to prove that path.

`bicycle` and `bicycle:fee` are there for Getting there (F): whether a bike may
come aboard a ferry or train, and whether it costs extra. The drawer reads them
for F only and words them in its Bikes on board row (§5). OSM carries them on
ferries far more than on stations: in the Netherlands extract 368 of 580 ferry
routes and 28 of 884 ferry terminals carry `bicycle`, and no station does.
| **Media/reference** | 4 | `wikidata`, `wikipedia`, `image`, `wikimedia_commons` | `image`, `wikimedia_commons` and `wikidata` are in `TAG_WHITELIST`: the drawer links the Commons photo and the Wikidata item, and the media pipeline caches a copy `PhotoValidator` accepts (`FetchCommonsPhotoHandler`, photo-uploads.md §5h). `wikipedia` is stored, not served. Cost: 19 B/row, about 89 MB planet-wide |

Measured impact of the trim across BE + NL + DE (then 375,078 rows; 377,558 after
the Luxembourg onboarding): tags payload
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
  key `tiles.py::_EXTRA_SQL` reads, or lists `name`.
- `pipeline/tests/test_tiles.py` pins `contract.py::TILE_DERIVED_TAG_KEYS` to the
  keys that SQL actually reads.
- `web/tests/Catalog/CoverageContractTest.php` asserts
  **`TAG_WHITELIST ⊆ storedTagKeys`** — a display key the pipeline trims away
  would be a permanently blank drawer row.

**Changing the serve-set requires a re-harvest** to affect existing rows. The
batch runs weekly so this is cheap, but decide once rather than re-harvesting
twice. Nothing breaks in the interim: rows harvested under an older, wider set
simply carry keys nothing reads.

*History: an earlier revision of this section justified storing the full tag set
so materialize-on-edit could snapshot without re-fetching OSM. That was wrong —
materialize-on-edit ([osm-data-architecture.md §6](osm-data-architecture.md))
copies `{osm_ref, edit}` into the canonical store and merges the OSM side from
this cache at read time, so it needs no per-row full-tag snapshot.*

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
   (`web/src/Catalog/ServiceKind.php`).
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
   **diff-merge** swap — upsert only changed/new rows (unchanged rows skip, no
   write) and delete the disappeared, replacing the earlier
   DELETE-all + INSERT-all — followed by a
   **delta-scoped** `region_id` backfill (`ST_Contains` over `region` polygons
   where they exist; `COVERAGE_FULL_MEMBERSHIP=1` recomputes the whole slice
   after a `region` change). Readers never see a half-loaded region; an abort
   keeps last week's slice serving. The batch runs under a per-session resource
   budget + an advisory single-run lock on CC's own DB cluster (same design doc
   §3.1–§3.2).

After all regions, once per run:

5. **Export** per-letter newline-delimited GeoJSON from the full index.
6. **Build tiles** with tippecanoe: one layer per letter, `--minimum-zoom 6 /
   --maximum-zoom 14`, direct `.pmtiles` output
   (`pipeline/coverage/tiles.py::build_pmtiles`). Tiles carry **individual
   points only, z6–14 — no clustering** (this supersedes the old `minzoom 6`
   low-zoom cluster-bubble build this section used to describe; the `minzoom 6`
   floor came back afterwards for an unrelated reason — the overview density
   heatmap of §4, not clusters). `-r1` keeps EVERY point at z11–14 (no
   rate-based thinning), so those tiles stay **complete** for the individual
   icons — a z11 tile is small enough that `--drop-densest-as-needed` never
   fires there, so nothing drops at z11 or above. At **z6–10**,
   `--drop-densest-as-needed` **does** fire, now as more than a safety valve:
   where a whole-region tile would exceed the size budget it drops the
   densest overflow proportionally, leaving a **thinned density sample**
   rather than every point — it still never merges points into a
   `point_count` feature, each dropped-or-kept feature stays an individual
   point. That z6–10 sample is exactly what the client's overview heatmap
   consumes. There is no `--cluster-distance`, `--cluster-maxzoom`, or
   `--accumulate-attribute` flag: each feature keeps its own single
   `ridtok`/`cctok` token (no cross-cluster union), so the scope filter
   (§4/§6) is **exact per point** at any zoom, worldwide — a single point's
   token is its own region, so no tile attribute can render it outside its
   scope. A rider now sees a **coverage-density heatmap** at overview zoom
   (z6–~9), built from that thinned sample and filtered to the same scope
   tokens as the icons, plus the rail's exact `/map/coverage/counts`
   alongside it as the precise "how much"; individual dots render from z9
   upward (the z9–10 dots are the thinned sample, complete by z11),
   cross-fading with the heatmap at the ~z9 handoff. (The prior
   cluster-bubble design rendered a cluster at the *centroid* of its
   members, which could sit outside the scoped region — the phantom-bubble
   class that removing clustering eliminates. The heatmap carries the
   same per-point phantom-free guarantee, not a centroid: it bins the points
   themselves, so nothing is ever drawn where no POI is.)
7. **Verify** with go-pmtiles (`verify_pmtiles`): header bounds, addressed tile
   count, expected layers, and a sample tile decode — a broken build never
   ships.
8. **Publish** (`pipeline/coverage/publish.py`): upload the artifact under a
   **versioned key** `coverage/<YYYYMMDD-HHMM>.pmtiles`
   (`Cache-Control: public, max-age=31536000, immutable`), then repoint the
   manifest at the **stable key** `coverage/manifest.json`
   (`publish.MANIFEST_KEY`, `max-age=300`), then prune old artifacts keeping
   the last 4 (`publish.prune(keep=4)`). Versioned keys mean an open reader
   mid-pan never has bytes change underneath it.

**Failure mode:** any step aborts that region's transaction or the artifact
step; last good data keeps serving; a non-zero exit surfaces through the
scheduler's mail. **v1 extracts nodes + ways-as-centroid**; multipolygon
relations (~1–3 % of objects) are a fast-follow (see Open questions).

**Entry points:** CLI `python -m coverage.run` in the pipeline container;
`make coverage-refresh` runs the whole chain against the dev DB + MinIO;
prod runs it as a scheduled job on the worker server (topology owned by
[dev-environment.md §9](dev-environment.md)). Runbook:
`developers/coverage-batch.md`. Pipeline env contract (set in
`developers/docker/compose.yaml` / `.env.example`): `COVERAGE_REGIONS`
(the committed default names **every onboarded country's Geofabrik extract**,
21 of them as of 2026-08-14), `COVERAGE_WORKDIR` (`/data/work`, named scratch
volume), `COVERAGE_PBF_PATH` (optional local override), `COVERAGE_S3_ENDPOINT`,
`COVERAGE_S3_BUCKET` (dev: `cc-maps`; prod name is deployment config), `COVERAGE_S3_KEY`, `COVERAGE_S3_SECRET`,
`COVERAGE_S3_REGION` (signing only, default `us-east-1`),
`COVERAGE_PUBLIC_BASE_URL`.

**Republish without a harvest: `python -m coverage.run --tiles-only`**
(dev: `make coverage-tiles`). The flag skips the per-region Geofabrik harvest
and runs only the tail of the chain - export, build, verify and publish the
coverage PMTiles from the `coverage_poi` rows already in PostGIS; the
manifest's `regions` is the region list given (`COVERAGE_REGIONS` /
`regions=`). This is the way to republish after a change that rewrote the
index without new OSM data - the 2026-08-25 letter renumbering is the
exemplar: migration `Version20260825120000` rewrote `coverage_poi.letter`, and
since source-layers are named `<letter>_<cc>` (§4) the tile artifact had to be
rebuilt from the rewritten rows. `make coverage-tiles` brings up MinIO and
publishes there, no Geofabrik involved.

## 4. Tile artifact contract

Thin tiles: enough to draw markers and run map-side filters; everything else
comes from the detail endpoint on click. Flat scalars only (MVT rule).
Feature id = numeric OSM id.

**Source-layers are per-country: `<letter>_<cc>`** (lowercase; `cc` is the
country code lowercased), one tippecanoe layer per `(letter, country_code)`
pair — e.g. `b_be`, `b_nl` — rather than one layer per letter. Onboarding a
second bordering country (the Netherlands, 2026-07-22) surfaced a
client-rendering-only defect Belgium-alone couldn't show: tippecanoe clusters
*within a layer*, so a single per-letter layer let a low-zoom bubble merge POIs
across a border, and the bubble's unioned `ridtok`/`cctok` (the scoping tokens
documented later in this section) then matched a scope even though its
`point_count` and map anchor mixed both countries (measured under
`country:NL` before the fix: 42 pure-NL, 31 mixed, 0 pure-BE rendered).
Partitioning by country made clustering — and therefore `point_count` and the
anchor position — country-pure by construction; the `ridtok`/`cctok` token
filter itself needed no change. A row with a NULL `country_code` (the rare
unstamped boundary-miss) buckets under `<letter>_zz` so no POI is ever
silently dropped. Verified in the browser at the time: 0 mixed clusters under
both `country:NL` and `country:BE`.
This border-mixing defect was one instance of the broader phantom-bubble class
that clustering could not be made safe against at any tuning — removing
clustering entirely supersedes
this fix rather than building on it; the per-country layer split itself is
kept (above) for reasons unrelated to clustering.

**What a rider actually sees as they zoom:** at overview zoom
(z6–~11) the coverage source's tiles carry a thinned, density-preserving
sample of points (`--drop-densest-as-needed`, above), and the client draws
that sample as a **coverage-density heatmap** instead of individual icons —
a smooth surface fills the overview zoom that the earlier z11-floor build had
left empty, with the rail's exact `/map/coverage/counts` still carrying the
precise "how much" alongside it. Individual icons render **from z9 up** (owner
tuning 2026-07-24 lowered the handoff from z11 so the spots show at the
region-fit landing zoom): the z9–10 icons come from the **thinned** z6–10 tile
sample and densify to **complete** at z11–14, where `-r1` keeps every point —
there is no bubble step and no "features stop counting down" transition to
explain. The heatmap and the icons **cross-fade at ~z9** (heat layer `maxzoom
9`, icon layer `minzoom 9`). This replaces an
earlier measured cluster zoom-table for one spot (Schwaan, DE) that
characterised bubble-dissolution behaviour which no longer exists.

**Below z6 the map says so.** The tileset is built z6-14, so at z5 and wider
there is no coverage data to draw at any scope, and the map drew nothing and
explained nothing. That silence is indistinguishable from a country whose
harvest failed, and it cost two separate owner bug reports ("no POI in South
Africa", then the same for Northern Cape at z5.5) that each ran all the way
through the pipeline, the tiles and the scope filter before landing on the
zoom. The rail foot now carries a one-line hint under the count
(`render.js updateZoomHint`, fired on `zoomend`): below z6 "zoom in to see the
full-coverage layers", between z6 and z9 "shown as density here, zoom in for
individual places", and nothing from z9 up where the icons themselves are the
answer. It is suppressed when the rider has turned every coverage layer off,
so it never nags about layers nobody asked for.

**Layers stay per (letter, country)** (`<letter>_<cc>`, unstamped rows bucket
under `<letter>_zz`) — this split is now unrelated to clustering (there is
none); it keeps a letter's icons filterable per country and drives the
manifest's `country_codes`-based client wiring (below).

| Property | Layers | Why in the tile |
|---|---|---|
| `ref` | all | join key: drawer detail fetch, curated dedupe, deep links |
| `n` | all (when named) | labels, search-pick highlight |
| `t` | all | type label (existing marker/drawer vocabulary) |
| `ridtok` | all (always; `""` when unstamped) | region scope filter — `"|<region_id>|"` (map-and-search.md §4.5, Phase 3) |
| `cctok` | all (always; `""` when unstamped) | country scope filter — `"|<cc>|"` (map-and-search.md §4.5, Phase 3) |
| `kind` | D | shop/station/pump icon match |
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
layer per `(letter, country)`, `{key}-{cc}-cov`; the former `{key}-{cc}-cov-cl`
cluster-bubble sublayer and `covClusterFilter()` are retired. Icons compose the
dedupe + region-scope arms (`covIconFilter`) — the `!has point_count` arm in
that filter is a harmless carry-over (always true now that no feature ever
carries `point_count`; left in place as optional cleanup, not a bug).

`ridtok`/`cctok` are region-scoping TOKENS, not scalars: pipe-delimited so
`'|<id>|' in ridtok` is a delimiter-safe set test, and ALWAYS emitted (empty
string when unstamped, never NULL-stripped). Each feature carries **its own
single token** — there is no `--accumulate-attribute` and no cross-feature
union now that tiles carry individual points only; the scope filter tests one
point's own region/country, never a merged member set. "Prop-less" is the
explicit both-tokens-empty state (a row outside every region with no cc, or a
tile built before this change): the client renders it **unfiltered**
(map-and-search.md §4.5 risk 2 fallback — the artifact lags the DB by up
to a weekly rebuild, so hiding-all would blank the map), the inverse of the
leak-safe default for served data. A cc-bearing rid-less row (cctok
non-empty) is NOT prop-less — it hides under a region scope (matching
`/counts`) and reappears under its country scope.

- **A · road surface stays out** of the coverage artifact: corridor line data,
  orders of magnitude larger, its own future decision. The existing curated
  segments keep serving via `catalog.json`. N/E/R are category-3 (our own
  data) and are never in the extract
  ([osm-data-architecture.md §5](osm-data-architecture.md)).
  **It has its own artifacts and its own manifest since 2026-08-12** — three of
  them (classified skin, "still to record" arm, gap grid), built by the same
  `coverage.run --surface` command from the same Geofabrik extracts but never
  touching PostGIS, and published under `surface/<stamp>/<arm>.pmtiles` with a
  stable `surface/manifest.json`. Read server-side by
  `App\Coverage\SurfaceManifest`, which mirrors `CoverageManifest`'s TTLs and
  its tolerate-everything failure policy. Twelve countries, ~15.0M classified
  ways / ~12.2M to record / ~102k grid cells. The build and its editorial
  decisions live in
  [Dated/2026-08-09-surface-line-tiles-design.md](Dated/2026-08-09-surface-line-tiles-design.md)
  §10 and in the wiki's *Building road-surface tiles* chapter. Two rails the
  point job does not have, both from the first real publish: a publish is
  **refused** when its country set is a strict subset of the live manifest's
  (a one-region rebuild would otherwise take eleven countries off the map with
  a zero exit), and pruning removes whole build prefixes rather than individual
  arms.
  The classified arm additionally carries the **quality channel** since
  2026-08-13: `sm` (raw OSM `smoothness`, gated on the contract's
  `surface.quality.values` list — an unlisted value is dropped at extract
  time, never guessed) and `mtb` (`mtb:scale`, `0`–`6` with optional `+`/`-`),
  both omitted when absent so absence stays absent.

  **Absent in the tile, named in the drawer (2026-08-31).** Omitting the prop is
  right for the artifact and wrong for the rider on its own: the ticks draw only
  where `sm` exists, so a road nobody has assessed looks exactly like any other
  road without ticks. `openSurfaceDrawer` therefore always renders the
  Smoothness row, and when the prop is missing renders it in the `empty` style
  with a link into the wizard for that way (`/improve?ref=…&field=smoothness`),
  the same affordance the catalog drawer uses for an unset field. Same principle
  as the Traffic row, which says when it inferred rather than measured, and as
  the `legend_unverified` class: name the gap, and make naming it the way to
  close it. Whether "unknown" also earns a mark on the MAP is deliberately
  unanswered until the tag's coverage is measured (docs/TODO.md, "Surface
  quality").
- **The cycle-route network has its own artifact and manifest since
  2026-08-13** (plan:
  `docs/plans/handoffs/2026-08-12-routes-layer-and-surface-quality.md`):
  `route=bicycle`/`route=mtb` relations extracted per region by
  `coverage.run --routes` (`pipeline/coverage/routes.py`, a two-pass walk —
  relations first for membership, then ways with locations — over the same
  Geofabrik extracts, zero PostGIS), one line feature per **member way**
  (props `net`/`rr`/`rk`/`refs` + the element `ref`, contract `routes` key)
  plus knooppunt nodes as points (`nr`; in-artifact from
  `routes.nodes.minZoom` via a per-feature tippecanoe floor).
  **The archive carries TWO line floors (2026-08-17), on the same per-feature
  trick the nodes use.** It builds from `routes.minZoom` = 5, and every way is
  stamped `routes.planningMinZoom` (5) when its strongest membership is in
  `routes.planningNetworks` (`icn`, `ncn`) and `routes.localMinZoom` (8)
  otherwise. An international route is what a rider plans with at the zoom
  where a country fits on the screen, and below 8 the whole layer used to be
  simply absent — which reads as broken rather than as out of range. Shipping
  every local connector from z5 instead would put millions of lines into a
  handful of tiles for a picture nobody can read. **The client needs no zoom
  rule of its own**: a network with no features in a z6 tile draws nothing, so
  `routes-tiles.js` carries no minzoom and cannot drift from the build. Judged
  on the way's BEST membership, so a lane carrying both EuroVelo 12 and a
  village loop stays part of EuroVelo 12 at planning zoom. Cost, measured: a z5
  tile is ~1.0 MB and a z6 tile ~0.6 MB (`--no-tile-size-limit` is deliberate
  here — a dropped line is a route that vanishes), which is why the layer stays
  opt-in and off by default. Published as
  `routes/<stamp>/routes.pmtiles` with a stable `routes/manifest.json`
  (`{"tiles":{"routes":url}, "counts":{"ways":n,"nodes":n},
  "country_codes":[…]}`), read server-side by `App\Coverage\RoutesManifest`
  (env pin `ROUTES_TILES_URL` wins; manifest `ROUTES_MANIFEST_URL`) and
  emitted as `window.CC_ROUTES_URL`. Its **own** manifest rather than a fourth
  surface arm: the two builds are separate invocations, and a shared manifest
  would let whichever ran last publish half-updated URLs for the other's arms.
  Same shrink guard, same whole-prefix pruning (`keep=3`). The routes run also
  drops per-region `routes_<slug>_wayids.txt` **way-id sets** into the
  workdir: the surface pass reads them (`surface.extract_region
  route_way_ids`) so an untagged way carrying a signed route is to-do-arm
  homework whatever its highway class — which is why `--routes` runs before
  `--surface` when rebuilding both (the way-id file is named as an extract
  input, so a fresh routes run invalidates the surface extracts it would
  change).
- **Manifest** (stable key `coverage/manifest.json`):
  `{"version":1, "url":"<COVERAGE_PUBLIC_BASE_URL>/coverage/<YYYYMMDD-HHMM>.pmtiles",
  "built_at":"<ISO>", "counts":{"B":n,…}, "regions":[…], "country_codes":[…]}`.
  `country_codes` is the sorted list of real onboarded countries resolved via
  `COUNTRY_BY_REGION` (`["BE","NL"]`; the `zz` bucket is excluded — it is a
  fixed client-side fallback, not a real country) — it tells the client which
  per-country layers to wire without probing the tile itself.
- **Client per-country layer wiring** (`web/assets/map/map.js` `addCoverage`):
  iterates every coverage letter × the manifest's `country_codes` plus a fixed
  `zz` bucket, building one icon layer `<key>-<cc>-cov` (`minzoom: 9`, so the
  spots show from the region-fit landing zoom; the tiles themselves build from
  `--minimum-zoom 6`) plus a `<key>-<cc>-heat` heatmap layer (`maxzoom: 9`) on
  the same `<letter>_<cc>` source-layer — there is no `-cov-cl` cluster-bubble
  sublayer to wire.
  `updateCoverageScopeFilter` iterates the same product so the `ridtok`/`cctok`
  scope filter (this section, above) applies to every per-country layer.
  **`[null]` fallback:** a manifest with no `country_codes` (a pre-split
  artifact, published before this change) falls back to iterating `[null]`
  instead — one unsplit `<letter>-cov` layer per letter against the plain
  `<letter>` source-layer, exactly the pre-split shape — so an old artifact
  still renders (degrade, don't blank), matching this document's
  manifest-failure convention (coverage-provider.md §4 below:
  `CoverageManifest` returns `null` on every failure path).
- **The per-country POI counts are cached for ten minutes.**
  `CoverageStatsProvider::poisByCountry()` was called twice per render, once for
  the headline total and once for the table, and each pass was an index-only
  scan over every row in `coverage_poi`: 167 ms against two million rows
  (measured 2026-08-31), so `/coverage` spent most of its time counting the same
  thing twice. It is now memoised within the request and cached in `cache.app`
  between them, which took the page from ~300 ms to ~75 ms. Ten minutes because
  the number changes only when the pipeline harvests. A cache backend that
  cannot answer falls through to the query rather than to a wrong page.
- **Server-side manifest read.** `App\Coverage\CoverageManifest`
  (`web/src/Coverage/CoverageManifest.php`) fetches the manifest server-side,
  caches the versioned URL for `CoverageManifest::CACHE_TTL` (value `3600` s,
  `cache.app`), and returns `null` on *every* failure path (flag off, empty
  URL, HTTP/transport error, malformed shape — logged, never thrown).
  `MapController` injects the URL as the
  nonce'd global `window.CC_COVERAGE_URL`, emitted only when non-null. Result:
  a new artifact goes live within an hour of upload with no deploy and no
  client manifest fetch on boot, and the map always renders, tiles or not.
- **The three readers share one base, and one page starts them together.**
  `CoverageManifest`, `SurfaceManifest` and `RoutesManifest` all extend
  `App\Coverage\BucketManifest` (`web/src/Coverage/BucketManifest.php`), which
  owns the fetch, the positive and negative cache, and the degrade-never-throw
  rule. Each subclass supplies only what differs: whether a pin or a flag makes
  the bucket moot (`needsManifest()`), the shape check (`validate()`), the
  cache key, and its two log lines.

  `BucketManifest::prefetch()` starts a fetch and returns without waiting.
  A page that reads more than one manifest must prefetch them all before
  reading any: `/map` reads three and `/v1/map-config` reads two, and read in
  sequence their `FETCH_TIMEOUT` (value `5` s) applies per fetch, so the page
  itself had no upper bound. Staging measured 10.6 s on a cold
  `/v1/map-config` (2026-08-30) for exactly this reason. Started together the
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
`entry` below = `{ref, letter, n?, kind?, ll: [lat, lng], curated: bool,
itemId?}`.

| Endpoint | Replaces | Response / caching |
|---|---|---|
| `GET /map/coverage/search?q=` | in-memory `ITEM_INDEX` sidebar search (coverage part) | `{"results": entry[], "attribution"}` — ranked curated first, then community; trgm-backed; default limit `CoverageRepository::SEARCH_LIMIT` (value `12`); ETag + `max-age=300` |
| `GET /map/coverage/nearby?lat=&lng=&km=` | town-card 5 km client-side haversine scan | `{"groups": [{letter, total, items: entry[]}], "attribution"}` — `ST_DWithin`, grouped by letter, community capped per group (`CoverageRepository::NEARBY_COMMUNITY_CAP`, value `3`) behind a "show all" expander; 422 on bad coords; `max-age=300` |
| `GET /map/coverage/counts` | rail totals | `{"counts": {"B": n, …}, "attribution"}`; **scope-aware** (Phase 3); `max-age=3600` |
| `GET /map/coverage/poi/{osmType}/{osmId}` | new: drawer detail for tile POIs | `{ref, letter, name, kind, ll, tags, curated, attribution}` — `tags` filtered to `CoverageRepository::TAG_WHITELIST` (store rich, serve trimmed); `curated` = `{itemId, state, fields, confirmations}` or `null`; `osmType ∈ {node, way}`; 404 when the ref is not cached; ETag + `max-age=300` |

- **A gone row lists nowhere (catalog-data-model.md §7; 2026-09-07).** The
  curated arm of `search` and `nearby` carries `GoneRows::notGoneSql('i')`,
  the predicate the payload and the public API already use, so a row
  approved as "Not there anymore" is neither a curated entry nor, through
  its still-claimed OSM ref, a community one. Before this the nearby list
  named a "Scenic views" the map did not draw (owner, Atomium 2026-09-07).
  Pinned by `CoverageQueryTest::test{Search,Nearby}GoneRowListsNowhereAndStillShadowsTwin`.
- **Region scope params (Phase 3, map-and-search.md §4.5).** `search`,
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
  zooms; `map.js covShownCount`). So a coverage layer reads N/N when on,
  0/N when toggled off or mode-hidden — matching the served layers. **At an
  overview zoom the layer reads its full N/N even though no individual icons
  render** — the icons appear from z9 (a large country fitted below that, e.g.
  Germany ~z5.8, shows the density **heatmap** instead, z6–~9); the count is
  honest (every POI IS in scope), the icon pixels arrive on zoom-in
  (superseding the earlier z6/z11 minzoom this line documented).
  Params are client-sent only (the plane is anonymous + cacheable — never
  server-resolved from a user); `rids` is de-duped by numeric value (zero-padded
  duplicates collapse), sorted, overflow/garbage rejected to the empty scope, and
  capped at `CoverageController::MAX_SCOPE_REGIONS` (value `24`) to bound the SQL
  IN-list (map-and-search.md §4.5 risk 10) — the cap is safe because a
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
  "Not allowed", `dismount` reads "Walk your bike", and `bicycle:fee=yes` adds
  "with a fee" to the first and the last. Any other value, or no `bicycle` tag,
  gives no row. The row carries the F field label "Bikes on board"
  (`CatalogFormRegistry`), so a curated item's stored value takes its place and
  an empty "+ add" prompt gives way to it.
- These are **site-internal map endpoints**, not the future public API.
  [osm-data-architecture.md §7](osm-data-architecture.md)'s reference-only
  rule governs the public API; the serving cache may serve OSM fields with
  attribution ([osm-data-architecture.md §4](osm-data-architecture.md)).

## 6. Map consumer and client-side dedupe

The full presentation contract lives in [map-and-search.md](map-and-search.md);
the data-plane facts it consumes:

- One `pmtiles://` vector source (from `window.CC_COVERAGE_URL`) with
  per-letter `<key>-cov` symbol layers replaces the seven per-letter `*-osm`
  GeoJSON pools that `catalog.json` currently ships
  (`web/assets/map/catalog-load.js` `CC_WATER_OSM` … `CC_HISTORY_OSM`).
  Curated pins, climbs, routes, surface, and heat keep serving from the
  slimmed `catalog.json`.
- **Client dedupe + the refs-mirror rule.** `CatalogProvider::payload()`
  (`web/src/Catalog/CatalogProvider.php`) gains a top-level
  `refs: list<string>` — the DISTINCT `source_ref`s of the `source='osm'`
  item rows **the payload itself serves**, plus the `osm_ref` twin of every
  served row that has one (an authority row attached to a tap,
  data-provider-hierarchy.md §4.1; added 2026-09-05 when the first RIVM
  harvest drew 2429 taps twice). Tile layers filter these refs out
  (`window.CC_CURATED_REFS`). The mirror rule is the invariant:
  `curatedRefs()` applies *exactly* the same exclusion as the payload's item
  collections, so `refs` excludes exactly what the payload excludes — a tile
  twin is **never suppressed for a row that is no longer served**. During the
  pre-retirement window (flag on, legacy rows excluded from the payload but
  not yet deleted), those objects therefore render from tiles as community
  instead of vanishing entirely. This refinement supersedes the design's
  looser "ships the set of curated refs" wording.
  The claim rule itself lives in one place, `App\Catalog\ClaimedOsmRefs`:
  `curatedRefs()` reads its SELECT, and the ride check's coverage arm
  (map-and-search.md §9) excludes the same refs, so a point hidden on the map
  is never listed along a ride either.
- **A catalog item borrows its OSM point's photo.** A served item with no
  `photo`/`photos` of its own (after `PhotoValidator::sift()`) that stands for
  an OSM point, through `source_ref` or `osm_ref`, carries
  `photoRef: "<osm ref>"` in its `catalog.json` feature when that point's
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
 "universalTileProps": ["ref", "n", "t", "ridtok", "cctok"],
 "storedTagKeys": ["addr:city", "amenity", …]}
```

- `letters` keys are exactly `B C D F G O P Q` — the
  [osm-data-architecture.md §5](osm-data-architecture.md) point catalogue.
- A letter may carry **`nearWay`** (`withinM`, `highways`, `bicycleTags`): its
  points load only within `withinM` metres of a way a bike may ride, a highway
  named in `highways` or any highway tagged `bicycle=` one of `bicycleTags`, and
  never one tagged `bicycle=no`. Only P (scenic views) carries it: 250 m, roads
  including service and gravel, cycleways, and bike-tagged tracks and paths. P
  selects viewpoints and waterfalls, not peaks. `run.py` filters each region's
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
  to ride to (owner 2026-09-15). F: `usage` = `leisure`, `tourism`. A heritage
  railway such as the Museumstoomtram Hoorn-Medemblik (node/521261183,
  `railway=station`, `usage=tourism`) is a day out, not a way to get somewhere
  (owner 2026-09-15). `load_region` applies it after `nameOrTags`,
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
  pinned to that SQL by `pipeline/tests/test_tiles.py`), or lists `name`.
- The **Python job consumes it** (`pipeline/coverage/contract.py::load_contract`,
  dataclasses `Selector`/`LetterSpec`/`Contract` with `letters_for(tags)` and
  `kind_for(tags)`); PHP never reads it at runtime.
- **PHP tests pin it** (`web/tests/Catalog/CoverageContractTest.php`): the
  `serviceKind` rules must resolve identically through
  `App\Catalog\ServiceKind::fromOsmTags()`, every PHP kind must be reachable,
  the D selectors must be exactly the `serviceKind` rule set, and
  **`CoverageRepository::TAG_WHITELIST ⊆ storedTagKeys`** — the drawer can only
  render what the pipeline stored, and there is no live-OSM fallback to cover a
  gap. The test skips (not fails) when the file is absent so `web/` stays
  runnable alone.
  ⚠️ That skip means the guard only fires where the whole repo is checked out —
  never in the dev container, which mounts `web/` alone. `ci-app.yml` now lists
  `pipeline/contract/**` in its trigger paths so a
  contract-only edit re-runs the PHP pin; before that it fired on nothing.
  **Closed 2026-08-24:** the pipeline's own pytest suite used to run in no
  workflow at all (`ci-tools.yml` covers `tools/**` only), so
  `load_contract()`'s validation and the `test_tiles.py` drift pin were
  dev-machine-only. `.github/workflows/ci-pipeline.yml` now runs the whole
  suite on `pipeline/**`. It runs natively rather than building
  `pipeline/Dockerfile`, because no test needs tippecanoe or go-pmtiles (the
  tile and publish steps are monkeypatched or botocore-stubbed); the two real
  dependencies are `osmium-tool` and a PostGIS service for the `db` fixture.
- Result: the Python extractor and the Symfony serving plane cannot drift —
  one mapping, asserted from both sides.

## 8. Rollout: `COVERAGE_TILES`

- Env flag `COVERAGE_TILES` (0|1, container param `coverage.tiles_enabled`,
  wired in `web/config/packages/coverage.yaml`; **defaults `1`** since the
  default-on flip). Off disables the tile *display* plane: no manifest
  fetch, no `CC_COVERAGE_URL`, no coverage CSP host — the map degrades to
  basemap + curated data.
- The rollout sequence this section originally planned has been **executed**:
  dev flipped first, the flag defaulted on after end-to-end verification, and
  the legacy `*-osm` display path was deleted in the same plan — no
  long-lived dual path. The retirement exclusion in `CatalogProvider`
  (coverage-provider.md §9) is now **unconditional** (not flag-gated), the
  `refs` list mirrors it (coverage-provider.md §6), and the `verified`
  property stays. A and N never pass through the exclusion.
- Enabling the flag in prod is gated on the checklist in
  `developers/coverage-batch.md` (bucket + manifest published, and client-IP
  propagation verified for the per-IP `coverage_read` limiter).

## 9. Legacy retirement (owner-gated)

After tiles + endpoints are verified, the redundant harvested rows leave the
canonical store ([osm-data-architecture.md §9](osm-data-architecture.md)'s
interim clause retires):

- **Retirement predicate** (identical in `CatalogProvider` and the command):
  `source='osm' AND state='unverified'` AND zero `change_history` AND zero
  `item_confirmation` AND zero `submission` rows for the item — exactly what
  the cache now serves. **Anything a human ever touched stays canonical.**
  The command additionally letter-guards `IN ('B','C','D','F','G','O','P','Q')`:
  A (not in the coverage artifact) and N (own data) must never be deleted.
- Console command `app:coverage:retire-legacy`
  (`web/src/Command/Coverage/RetireLegacyOsmCommand.php`): the **bare
  invocation IS the dry-run** — per-letter counts, zero writes; there is no
  `--dry-run` option. `--force` performs the transactional DELETE and prints
  the deleted count. This refinement supersedes the design's
  "`--dry-run` report first" wording.
- **The `--force` run is owner-gated**: explicit owner approval plus a dev-DB
  `pg_dump` backup into `developers/docker/backups/` first (standing rules).
  The implementation plan only ever executes the bare dry-run against a real
  database; `--force` runs solely inside phpunit against the throwaway test
  DB. Display is already correct either way — untouched legacy rows are out of
  the payload *and* out of `refs` (mirror rule, coverage-provider.md §6), so
  the destructive run is pure database cleanup with zero display change.
- **PIVOT rows stay canonical** (`source=authority`, Tourisme Wallonie CC-BY
  attribution). `tools/wallonia` is relieved of OSM POI duty but kept for the
  retired atlas demo and the canonical seeds (climbs, routes, surface, PIVOT);
  the coverage path never touches Overpass again.

## 9.1 The public /coverage page

The marketing-era `/coverage` page (hardcoded demo KPIs and an invented
per-country percentage table) was replaced by a DB-driven page:
`App\Catalog\CoverageStatsProvider` (raw DBAL, no cache — the
RegionDirectoryProvider posture) serves live KPIs (POI/item/route/country
COUNTs), a per-country volume table (operational countries only, same
L2-exclusion predicate as the region pages; the bar compares POI **density**
— reference items per km² of onboarded area, scaled to the densest country —
because an absolute-volume bar would dwarf small countries under the biggest
one forever, owner correction 2026-07-30; rows sort by that same density,
highest first, country code as tiebreaker — owner correction 2026-08-30,
previously absolute POI count. The reference-items column header offers the
other order, absolute total, as a **link** to `?sort=total` rather than a
client-side toggle: a toggle needs an inline script, an inline script needs a
CSP nonce, and a nonce is the one thing a shared cache cannot hold
([page-caching.md §3.2](page-caching.md)). As two URLs both orders are cached
and both work with no JavaScript. `CoverageStatsProvider::countries()` takes the
sort key, the controller validates it against the two constants and falls back
to density rather than 404ing, the cell is two lines, the bar with the
absolute total at its end and the density under it, and whichever metric is
the active sort is ink while the other is grey, owner 2026-09-08), and
"biggest gaps" cards computed as the three catalog
categories with the fewest publicly-served items. Because `coverage_poi` is
pipeline-owned DDL (§2) and absent on a fresh contributor stack, every read
of it in the provider is guarded by `to_regclass()` and degrades to zero
rather than failing the page. Coverage *percentages* are deliberately gone:
there is no honest denominator for "how complete is a country", so the page
shows real volumes instead.

The density is **printed as a number** beside the bar, not left implicit in
its length. A bar with no scale can only say "Germany is longer than
Luxembourg", which is the opposite of what a density bar exists to correct —
and Luxembourg is in fact the densest of the four. The explanatory list sits
**below** the table: the numbers are what a reader came for, and three
paragraphs of vocabulary in front of them is a toll gate.

**Provenance is shown on the catalog column, not the reference one**, because
that is where the mix actually is. `coverage_poi` is OSM top to bottom — every
row carries an `osm_version` and a node/way `ref`, and the table has no source
column at all (§2) — so a "breakdown" there would be one bar wearing a chart's
clothes. Partner data does not land in that layer: it is imported as catalog
`item` rows keeping their own `ItemSource`, which is how the Wallonia PIVOT
stays and drinking-water taps already sit alongside rider contributions.

The six `ItemSource` values are bucketed to four the page can say out loud:
`osm` (mirrored) · `partner` (PIVOT, Wikidata — an open dataset somebody else
maintains) · `riders` (`user` and `manual`, which the enum already defines as a
hand-added row treated like a contribution) · `derived` (pipeline). An
unrecognised source falls into `derived` rather than vanishing, so a new
importer shows up as an unexplained number instead of silently shrinking the
total. Zero buckets are dropped, so an OSM-only country shows one word rather
than four with three noughts.

**A second view, the globe** (owner 2026-09-08: "a cool way to visualize the
coverage besides a list"). The globe shows the same countries on
the globe the regions page uses (`assets/pages/country-globe.js`, one shape per
country from `/regions/outlines.json`), each filled by a **density class**:
`CoverageStatsProvider::densityClasses()` ranks the countries with any
reference items by POIs per km² and cuts them into `DENSITY_STEPS` (five)
equal-count steps, one hue from pale to spruce. Equal-count and not
equal-width, because density spans three orders of magnitude and a linear
scale would paint every country but one the palest step. A country with
nothing on file is class 0, a wash of ink outside the ramp, so the legend never
claims a range that starts at nothing; ties rank by country code so a cached
page and its next render agree. The controller computes the classes only for
the globe view. The legend under the globe prints each step's density range
from the same bounds the paint uses, and the five colours are one Twig list
handed to the script on `data-ramp`, so paint and legend cannot drift. Hover
names the country and its density; a click shows a card with the figures of
that country's table row (regions, density and total, catalog items with
provenance, routes) and a link to its regions. Every card is rendered by Twig
and waits hidden, so the script holds no string and the view needs no nonce.
The two views are two URLs with two chip links, for the same reason the sort
is ([page-caching.md §3.2](page-caching.md)); an unknown `view` falls back to
the table. The ramp (`#84AC98 #63937C #46785F #2D5A44 #1C3A2A` on the paper
surface) passed the ordinal checks, lightness monotone and a 2:1 light-end
contrast, on 2026-09-08.

## 10. Relationship to other documents

- [osm-data-architecture.md](osm-data-architecture.md) owns the policy this
  provider implements (tag catalogue, data categories, licensing,
  materialize-on-edit, API policy). Its §5 scale claim now carries the real
  measure (planet-wide subset ≈ 4.7 M points; per-region extracts stay
  small); its §9 interim-harvest clause was retired by the
  implementation's documentation sweep, not by this document.
- [map-and-search.md](map-and-search.md) owns how coverage is presented
  (markers, ordering, community tier, reveal behaviour).
- [catalog-data-model.md](catalog-data-model.md) owns canonical item identity
  (`source`, `source_ref`, states) that the dedupe and retirement predicates
  join against.
- [security-architecture.md](security-architecture.md) owns the CSP host
  enumeration and the rate-limiter inventory that this provider extends:
  `COVERAGE_CSP_HOST` joins its §2 connect-src table, and `coverage_read` is
  the anonymous-read row in its §7 inventory (which already
  cross-links back here).
- [dev-environment.md](dev-environment.md) owns the container stack the
  pipeline runs in (compose profiles, MinIO, worker scheduling, prod
  topology).
- Contribution on uncurated coverage POIs (the materialize-on-edit trigger) is
  **out of scope here** (Plan 3): coverage drawers show contribution CTAs only
  where an `item` exists.

## 11. Kept counts: `coverage_count` (2026-09-06)

`/map/coverage/counts` used to walk every coverage row and, for each, ask
whether one of our items claims it. Unscoped that took **26.6 s** on dev
(2.06 M rows) against 0.2 s for one region, and the first visitor each hour
paid it (owner: "Everywhere gets slow"). It is now a sum over
`coverage_count`, one row per (country_code, region_id, letter) with the
number of coverage rows still shown, and the database keeps that table
right on its own, because the coverage rows are written by the Python
pipeline and the claims by PHP, and neither side can see the other's writes.

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
- `app:coverage:recount` runs install and rebuild by hand, for the day rows
  were loaded with triggers off, or for proof.

The read side sums buckets under the same scope arms as before
(`region_id IN (:rids) OR country_code = :cc`), `HAVING SUM(n) > 0` so an
emptied bucket does not surface as a zero. Measured after: unscoped 0.06 s.
The migration's one full count took 51 s on dev; on prod expect minutes, in
the deploy window.

**The recount reaches its bucket through an index (2026-09-10,
`Version20260910150000`).** `coverage_count_refresh()` addresses a bucket as
`COALESCE(country_code, '')` and `COALESCE(region_id, 0)`, so a NULL country
or region is a bucket of its own; that is right, and it is why the plain
bucket index was never used. The planner walked every row of the letter
(476 000 for history) and called `coverage_poi_shown()` on the way: 350 ms a
recount, and a curator's one-tap confirm fires six (owner: "just takes a
long time", 5.5 s). `coverage_poi_bucket_key_idx` on the same two COALESCE
expressions plus `letter` makes it 29 ms with the predicate untouched, so
the rule stays defined once; `coverage_count_install()` creates it wherever
it creates the other, and `item.source_ref` gets `idx_item_source_ref` for
the claim lookup inside the predicate. The six recounts of one tap are still
six; a per-transaction dedupe is the next step if a heavier write path ever
needs it.

## Open questions

- **Multipolygon relations** (~1–3 % of objects, e.g. some castles) are not in
  v1 (nodes + way centroids only); pyosmium area assembly is an approved
  fast-follow with no scheduled plan yet.
- **`region_id` backfill** is inert until the worldwide administrative region
  polygons exist ([osm-data-architecture.md §10](osm-data-architecture.md));
  until then the column stays NULL and scoped queries fall back to
  `country_code`.
- **Planet scale** is unmeasured: the worldwide flip is gated on a documented
  dry-run (disk, RAM, wall-clock on worker-class hardware, procedure in
  `developers/coverage-batch.md`); no numbers exist yet. Related: worldwide
  search latency (trgm + bbox bias) is only measured at that flip.
- **A · road surface at coverage scale** (corridor line data in tiles) has no
  decision — deliberately excluded from this artifact.
- **Tile density tuning** at z14 (peaks, memorials) may need per-layer minzoom
  adjustment beyond `--drop-densest-as-needed`; to be observed on real
  artifacts.

### Planet-flip dry-run checklist (pipeline, not reachable with Belgium data)

**Superseded 2026-07-24:** coverage no longer clusters at all, so neither risk below is
reachable any more — clustering not scaling worldwide was itself one of the
two problems that motivated removing it (design doc §1). Retained as the
historical record of the clustering-era planet-flip risk, not a live gate;
worldwide-scale risk for the no-cluster tiles (tile count/size at z11–14
across a planet-wide extract) is unmeasured and has no checklist yet.

Two clustering behaviours were safe for Belgium but would have needed
verifying before a worldwide flip under the old clustered design
(map-and-search.md §4.5 Phase 5):

- **tippecanoe segfault on an oversized above-cap tile.** With the pinned combo
  (`--cluster-maxzoom=11 --cluster-densest-as-needed -r1`), a single z12–14 tile
  holding ~30 000 same-letter points (a megacity-density tile) crashes
  tippecanoe v2.79.0 with SIGSEGV instead of clustering — the as-needed cluster
  path on an above-cap tile. `run.py` aborts before publish, so a crash freezes
  the artifact at last-good permanently until the flags change. Belgium's densest
  real z12 letter tile is 65 features, so it is unreachable today; 20 000 points
  build fine, and removing either flag avoids the crash. File upstream at
  felt/tippecanoe (nearest existing: mapbox/tippecanoe#840) and re-test the pin.
- **Scope-token string size.** `ridtok`/`cctok` are concatenated (not deduped)
  across a cluster's members, so a giant z6 cluster carries one long string
  (Belgium worst case: a 1081-member bubble → ~4.3 KB; deduped it would be one
  token). Correct at any scale (the client's `in` test is dedup-agnostic), but a
  planet megacity cluster of tens of thousands compounds the tile-size pressure
  above. If it bloats tiles, dedup the tokens in a post-tile pass (needs an MVT
  round-trip lib the pipeline does not yet carry) rather than truncating (which
  would mis-hide regions).

## The globe is plain (2026-09-08)

Both globes come from `assets/pages/country-globe.js`: a one-colour land,
a sea, admin-2 borders and nothing else (owner: "more simple, rest of the
world one colour"). Raising countries by item count was tried and reverted
the same night ("too much"). The density paint stays as the coverage
globe's colour.

## Desktop opens on the globe, and it costs one request (2026-09-12)

**Both views are in one response, and a class decides which one is on
screen.** `/coverage` is one URL. The table is what the markup shows on its
own, because it is the view that works with no JavaScript; a head script,
`assets/pages/coverage-view.js`, adds `cc-globe` to `<html>` before paint and
CSS swaps the two. A screen 900px or wider opens on the globe (owner
2026-09-08: "should open on the globe page if not mobile"), and a `?view=` in
the URL wins over the width, so the `?view=globe` links already shared still
open on the globe.

**It used to be two URLs.** The table was `/coverage`, the globe
`/coverage?view=globe`, and a head script ran `location.replace()` to send a
wide screen from the first to the second. That cost every desktop reader a
second request for a page the browser had already been given, which is what
it looked like from outside (owner 2026-09-12: "loading coverage page
redirect to view=globe, that should be the default hit, not a redirect").
Setting a class does the same job with no navigation, so the chips became
buttons and `/regions`' shape, one page with an in-place switch, is now the
shape of both pages.

**MapLibre is still only loaded when the globe is shown.** That was the point
of keeping a phone on the table, and moving the views into one response does
not change it: `assets/pages/coverage-globe.js` builds the globe on its first
showing, so a reader who stays on the table never fetches the library. The
chips are hidden until that script runs, so a reader with no JavaScript is
never offered a switch that cannot move, which is also what retired the
page's old noscript paragraph.

