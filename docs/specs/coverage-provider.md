<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

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
  separated, `europe/belgium,europe/netherlands` since 2026-07-22 —
  `europe/netherlands` added with the NL onboarding) refreshes as a whole on its
  own run; regions can stagger across the week. *Border caveat:* Geofabrik
  extracts overlap in a border buffer, so one OSM entity can arrive staged in
  two adjacent extracts with the same `(ref, letter)`. Ownership is decided at
  staging, by geometry, not by write order
  (`2026-07-23-border-overlap-ownership-design.md §3`): an extract keeps only
  the staged rows that fall inside — or within `BOUNDARY_SNAP_DEG` of — a
  region of its own configured country, so exactly one extract ever inserts a
  given `(ref, letter)`, and a shared border entity never collides with the
  global `UNIQUE(ref, letter)`. `load_region`'s `INSERT … ON CONFLICT (ref,
  letter) DO UPDATE` still runs after that filter and still derives
  `country_code` from the geometric region — it is no longer how ownership is
  decided, only the safety net for a stale row a former owner hasn't deleted
  yet. A staged row that falls in no onboarded region at all is dropped rather
  than kept with a NULL region. Planet scale is config + disk, gated on a
  measured dry-run (see Open questions).
- **Own object-storage bucket** (`cc-maps`) from day one, separate from any
  shared basemap bucket, so coverage cost stays observable. Dev mirrors it with
  the existing MinIO compose profile (`developers/docker/compose.yaml`,
  profile `storage`).

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
    letter        char(1)      NOT NULL,  -- C D E G H I J (osm-data-architecture.md §5 catalogue)
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
- **Measured sizing (2026-07-23).** At 375,078 rows (BE + NL + DE, compacted):
  **341 B/row heap + 176 B/row indexes = 517 B/row**, and the per-row figure is
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
object arrives carrying its full tag set. Before the trim (2026-07-23) the cache
therefore stored **4,220 distinct keys** — a single memorial contributing 20 of
them — while nothing in the codebase read more than **27**. That is the bulk-OSM
duplication [osm-data-architecture.md §1](osm-data-architecture.md) principle 1
forbids, arrived at by omission rather than by decision.

The serve-set is three groups:

| Group | Count | Keys | Read by |
|---|---|---|---|
| **Selectors** | 9 | `amenity`, `drinking_water`, `historic`, `natural`, `railway`, `shelter_type`, `shop`, `tourism`, `waterway` | Classification (letter + `serviceKind`); `tiles.py::_label_case` re-reads them at tile-build time |
| **Display** | 14 | `opening_hours`, `website`, `contact:website`, `url`, `phone`, `contact:phone`, `addr:city`, `addr:street`, `addr:housenumber`, `operator`, `description`, `wheelchair`, `fee`, `capacity` | `CoverageRepository::TAG_WHITELIST` — exactly what the drawer renders (§5). `wheelchair`/`drinking_water` also feed tile props (§4) |

`TAG_WHITELIST` has **15** entries; `drinking_water` is counted in the selector
row above, so the three groups sum to 9 + 14 + 4 = **27** distinct keys.
| **Media/reference** | 4 | `wikidata`, `wikipedia`, `image`, `wikimedia_commons` | **Nothing yet — provisional.** Kept only because re-adding them later costs a full re-harvest; pending a decision on whether we build the drawer photo / deep-link features. Cost: 19 B/row, ≈ 89 MB planet-wide |

Measured impact of the trim across BE + NL + DE (375,078 rows): tags payload
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
   ownership filter deletes staged rows this extract does not own
   (`2026-07-23-border-overlap-ownership-design.md §3`) — nearest-region-wins,
   across every onboarded country, so a border row picks exactly one owner
   regardless of which extract's cut also carries it; a row with no region
   within `BOUNDARY_SNAP_DEG` of *any* onboarded country is dropped outright
   (not staged at all). Abort if the post-filter row count drops more than the
   drift ratio below the previous run for the same region
   (`pipeline/coverage/load.py::DRIFT_ABORT_RATIO`, value `0.4`) — a truncated
   download must never wipe a region, and the filter itself needs its own
   guard: an unseeded `region` table for the extract's country raises rather
   than silently staging zero rows. Then one transaction:
   resolve the extract slug to its `coverage_source` id (get-or-create), then
   `DELETE FROM coverage_poi WHERE src_region_id = :sid` + insert + `region_id`
   backfill (`ST_Contains` over `region` polygons where they exist). Readers
   never see a half-loaded region; an abort keeps last week's slice serving.

After all regions, once per run:

5. **Export** per-letter newline-delimited GeoJSON from the full index.
6. **Build tiles** with tippecanoe: one layer per letter, minzoom 6 / maxzoom
   14, direct `.pmtiles` output (`pipeline/coverage/tiles.py::build_pmtiles`).
   minzoom 6 (not 8) keeps coverage visible at the region/country overview
   zooms the scope selector fits to (All Belgium ~z7; region-scoping-design.md
   §7). **Low-zoom clustering** (`--cluster-distance=20 --cluster-maxzoom=11
   -r1 --cluster-densest-as-needed`): tippecanoe's default point-thinning
   dropped ~99% of the points at overview zooms (23 of 2015 D-services survived
   at z8), so the map looked empty while the rail said 2015/2015. Instead `-r1`
   keeps EVERY point and clustering merges nearby ones (z6–11) into one feature
   carrying `point_count` (`point_count` summed **within a single tile** = that
   tile's total); above the `--cluster-maxzoom` cap (z12+) points render
   individually so a rider zoomed into a town sees the actual POIs.
   `--cluster-densest-as-needed` merges (never drops) if a tile still exceeds the
   size limit. The scope tokens `ridtok`/`cctok` are unioned across a cluster's
   members via `--accumulate-attribute=ridtok:concat`/`cctok:concat` (§4), so a
   bubble is scoped by its full member set. **Conservation is per-tile, not
   global:** tippecanoe's default 5/256 feature buffer duplicates seam-strip
   points into both adjacent tiles' clusters, so a cross-tile `point_count` sum
   overshoots (+~9% at z6 for Belgium) — the authoritative total is the
   buffer-free SQL `/map/coverage/counts`, never a bubble sum.
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
(default `europe/belgium,europe/netherlands,europe/germany`), `COVERAGE_WORKDIR` (`/data/work`, named scratch
volume), `COVERAGE_PBF_PATH` (optional local override), `COVERAGE_S3_ENDPOINT`,
`COVERAGE_S3_BUCKET` (`cc-maps`), `COVERAGE_S3_KEY`, `COVERAGE_S3_SECRET`,
`COVERAGE_S3_REGION` (signing only, default `us-east-1`),
`COVERAGE_PUBLIC_BASE_URL`.

## 4. Tile artifact contract

Thin tiles: enough to draw markers and run map-side filters; everything else
comes from the detail endpoint on click. Flat scalars only (MVT rule).
Feature id = numeric OSM id.

**Source-layers are per-country: `<letter>_<cc>`** (lowercase; `cc` is the
country code lowercased), one tippecanoe layer per `(letter, country_code)`
pair — e.g. `c_be`, `c_nl` — rather than one layer per letter. Onboarding a
second bordering country (the Netherlands, 2026-07-22) surfaced a
client-rendering-only defect Belgium-alone couldn't show: tippecanoe clusters
*within a layer*, so a single per-letter layer let a low-zoom bubble merge POIs
across a border, and the bubble's unioned `ridtok`/`cctok` (the scoping tokens
documented later in this section) then matched a scope even though its
`point_count` and map anchor mixed both countries (measured under
`country:NL` before the fix: 42 pure-NL, 31 mixed, 0 pure-BE rendered).
Partitioning by country makes clustering — and therefore
`point_count` and the anchor position — country-pure by construction; the
`ridtok`/`cctok` token filter itself needed no change. A row with a NULL
`country_code` (the rare unstamped boundary-miss) buckets under `<letter>_zz`
so no POI is ever silently dropped. Full design + verified browser results
(0 mixed clusters under both `country:NL` and `country:BE`):
[2026-07-22-coverage-scope-rendering-design.md](2026-07-22-coverage-scope-rendering-design.md).

**What a rider actually sees as they zoom** (measured 2026-07-23 by decoding the
live artifact over one spot — Schwaan, DE — at every zoom; DE layers only):

| zoom | features drawn in the tile | POIs they represent |
|---|---|---|
| 6 | 81 | 26,429 |
| 8 | 165 | 3,403 |
| 10 | 53 | 148 |
| 11 | 11 | 17 |
| 12 | 11 | 11 |
| 14 | 5 | 5 |

Three behaviours combine here, and they are frequently mistaken for POIs
disappearing:

1. **A zoom step quarters the ground area a tile covers**, so most of a bubble's
   members leave the viewport rather than the map. Two zoom steps ≈ 1/16 of the
   area — a "36" bubble legitimately resolving to a handful of small bubbles on
   screen, with the rest off to the sides.
2. **`--cluster-distance` is in screen pixels, not metres.** 20 px is several km
   of merging at z8 and roughly 200 m at z11, so bubbles dissolve far faster than
   POI density changes.
3. **Clustering stops at z11.** From z12 features carry no `point_count` at all
   and render as individual pins with **no count badge** — the numbers do not
   count down to 1, they stop existing. This is the step most often read as
   "everything vanished".

**Bubbles are per (letter, country), never unified.** Because each
`<letter>_<cc>` layer clusters independently, several bubbles with different
counts can sit almost on top of each other — they are different *categories*, not
one aggregate, and each splits on its own schedule as you zoom. A rider looking
at "36 / 27 / 16" in one spot is seeing three letters, not 79 POIs of one kind.

| Property | Layers | Why in the tile |
|---|---|---|
| `ref` | all | join key: drawer detail fetch, curated dedupe, deep links |
| `n` | all (when named) | labels, search-pick highlight |
| `t` | all | type label (existing marker/drawer vocabulary) |
| `ridtok` | all (always; `""` when unstamped) | region scope filter — `"|<region_id>|"` (region-scoping-design.md §6, Phase 3) |
| `cctok` | all (always; `""` when unstamped) | country scope filter — `"|<cc>|"` (region-scoping-design.md §6, Phase 3) |
| `kind` | D | shop/station/pump icon match |
| `potable` | C | water marker variant, derived from OSM `drinking_water` tags |
| `acc` | E | stays accessibility filter |

`ref`/`n`/`t`/`ridtok`/`cctok` are the **universal** props (every layer, declared
as `universalTileProps` in `coverage-contract.json`, consumed by
`tiles.py::_universal_props` and pinned by both language contract suites);
`kind`/`potable`/`acc` are per-letter extras (`tileProps`).
**`point_count`/`clustered`/`sqrt_point_count`/`point_count_abbreviated`** are all
injected by tippecanoe on cluster features at z6–11 (§3 step 6; the last two are
tippecanoe conveniences the client does not read): the client draws a clustered
feature (`has point_count`) as a count bubble (`{key}-cov-cl` symbol layer — a
colour disc + the count) that, on click, zooms in until it splits into individual
icons; the `{key}-cov` icon layer filters to unclustered features
(`!has point_count`). Icons compose the dedupe + region-scope arms
(`covIconFilter`); bubbles take scope only (`covClusterFilter` — a bubble is
never an exact curated twin, so the dedupe arm could only mis-hide it).

`ridtok`/`cctok` are region-scoping TOKENS, not scalars: pipe-delimited so
`'|<id>|' in ridtok` is a delimiter-safe set test, and ALWAYS emitted (empty
string when unstamped, never NULL-stripped) so `--accumulate-attribute=concat`
can UNION them across a cluster's members — a bubble's `ridtok` then holds every
member's region and is scoped by set membership, not one member's lottery-inherited
`rid`. "Prop-less" is the explicit both-tokens-empty state (a row outside every
region with no cc, or a tile built before this change): the client renders it
**unfiltered** (region-scoping-design.md §8 risk 2 fallback — the artifact lags
the DB by up to a weekly rebuild, so hiding-all would blank the map), the inverse
of the leak-safe default for served data. A cc-bearing rid-less row (cctok
non-empty) is NOT prop-less — it hides under a region scope (matching `/counts`)
and reappears under its country scope.

- **A · road surface stays out** of the coverage artifact: corridor line data,
  orders of magnitude larger, its own future decision. The existing curated
  segments keep serving via `catalog.json`. B/F/K are category-3 (our own
  data) and are never in the extract
  ([osm-data-architecture.md §5](osm-data-architecture.md)).
- **Manifest** (stable key `coverage/manifest.json`):
  `{"version":1, "url":"<COVERAGE_PUBLIC_BASE_URL>/coverage/<YYYYMMDD-HHMM>.pmtiles",
  "built_at":"<ISO>", "counts":{"C":n,…}, "regions":[…], "country_codes":[…]}`.
  `country_codes` is the sorted list of real onboarded countries resolved via
  `COUNTRY_BY_REGION` (`["BE","NL"]`; the `zz` bucket is excluded — it is a
  fixed client-side fallback, not a real country) — it tells the client which
  per-country layers to wire without probing the tile itself.
- **Client per-country layer wiring** (`web/assets/map/map.js` `addCoverage`):
  iterates every coverage letter × the manifest's `country_codes` plus a fixed
  `zz` bucket, building layer ids `<key>-<cc>-cov` (unclustered icons) and
  `<key>-<cc>-cov-cl` (cluster bubbles) bound to the matching `<letter>_<cc>`
  source-layer; `updateCoverageScopeFilter` iterates the same product so the
  `ridtok`/`cctok` scope filter (this section, above) applies to every
  per-country layer pair. **`[null]` fallback:** a manifest with no
  `country_codes` (a pre-split artifact, published before this change) falls
  back to iterating `[null]` instead — one unsplit `<letter>-cov`/
  `<letter>-cov-cl` layer pair per letter against the plain `<letter>`
  source-layer, exactly the pre-split shape — so an old artifact still
  renders (degrade, don't blank), matching this document's manifest-failure
  convention (coverage-provider.md §4 below: `CoverageManifest` returns
  `null` on every failure path).
- **Server-side manifest read.** `App\Coverage\CoverageManifest`
  (`web/src/Coverage/CoverageManifest.php`) fetches the manifest server-side,
  caches the versioned URL for `CoverageManifest::CACHE_TTL` (value `3600` s,
  `cache.app`), and returns `null` on *every* failure path (flag off, empty
  URL, HTTP/transport error, malformed shape — logged, never thrown).
  `MapController` injects the URL as the
  nonce'd global `window.CC_COVERAGE_URL`, emitted only when non-null. Result:
  a new artifact goes live within an hour of upload with no deploy and no
  client manifest fetch on boot, and the map always renders, tiles or not.
- **CSP:** the tile host is appended to `connect-src` (host enumeration owned
  by [security-architecture.md §2](security-architecture.md)) by
  `App\EventSubscriber\CspSubscriber` from the dedicated `COVERAGE_CSP_HOST`
  env var (container param `coverage.csp_host`) — a separate param rather than
  parsing the manifest URL, because the server fetches the manifest from a
  different origin than the browser range-reads tiles from (dev:
  `http://minio:9000` vs `http://localhost:9100`). The `pmtiles` protocol
  library is SRI-pinned from unpkg exactly like maplibre-gl.

## 5. Symfony query plane: `/map/coverage/*`

Implemented by `App\Coverage\CoverageRepository` +
`App\Controller\CoverageController` (raw DBAL over `coverage_poi ⋈ item`).
`entry` below = `{ref, letter, n?, kind?, ll: [lat, lng], curated: bool,
itemId?}`.

| Endpoint | Replaces | Response / caching |
|---|---|---|
| `GET /map/coverage/search?q=` | in-memory `ITEM_INDEX` sidebar search (coverage part) | `{"results": entry[], "attribution"}` — ranked curated first, then community; trgm-backed; default limit `CoverageRepository::SEARCH_LIMIT` (value `12`); ETag + `max-age=300` |
| `GET /map/coverage/nearby?lat=&lng=&km=` | town-card 5 km client-side haversine scan | `{"groups": [{letter, total, items: entry[]}], "attribution"}` — `ST_DWithin`, grouped by letter, community capped per group (`CoverageRepository::NEARBY_COMMUNITY_CAP`, value `3`) behind a "show all" expander; 422 on bad coords; `max-age=300` |
| `GET /map/coverage/counts` | rail totals | `{"counts": {"C": n, …}, "attribution"}`; **scope-aware** (Phase 3); `max-age=3600` |
| `GET /map/coverage/poi/{osmType}/{osmId}` | new: drawer detail for tile POIs | `{ref, letter, name, kind, ll, tags, curated, attribution}` — `tags` filtered to `CoverageRepository::TAG_WHITELIST` (store rich, serve trimmed); `curated` = `{itemId, state, fields, confirmations}` or `null`; `osmType ∈ {node, way}`; 404 when the ref is not cached; ETag + `max-age=300` |

- **Region scope params (Phase 3, region-scoping-design.md §6).** `search`,
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
  viewport-rendered tiles, which before clustering read a confusing near-zero at
  overview zooms; `map.js covShownCount`). So a coverage layer reads N/N when on,
  0/N when toggled off or mode-hidden — matching the served layers. **Below the
  coverage minzoom (z6) the layer reads its full N/N while nothing renders** — a
  large country fitted below z6 (e.g. Germany ~z5.8) has no coverage geometry
  yet; the count is honest (every POI IS in scope), the pixels arrive on zoom-in.
  Params are client-sent only (the plane is anonymous + cacheable — never
  server-resolved from a user); `rids` is de-duped by numeric value (zero-padded
  duplicates collapse), sorted, overflow/garbage rejected to the empty scope, and
  capped at `CoverageController::MAX_SCOPE_REGIONS` (value `24`) to bound the SQL
  IN-list (region-scoping-design.md §8 risk 10) — the cap is safe because a
  country scope's `cc` arm is the complete fallback (guaranteed by the pipeline's
  region ⇒ cc invariant), and it logs when it actually truncates. The HTTP-cache
  key is the raw client query string, which `covScopeQuery` already canonicalises
  (sorted, deduped) — the server sort/dedupe only keeps the SQL binding stable.
  Absent = current behaviour (backward compatible). The deep-link resolver
  (`openCoverageFeatureByName`) deliberately sends **no** scope params, so a
  deep link finds its target regardless of the saved scope, then widens.

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
  | `poi/{osmType}/{osmId}` (`detail()`) | `NOT (i.letter IN (C,D,E,G,H,I,J) AND untouched)` — letter-guarded |
  | `search`, `nearby`, `counts` | `NOT (untouched)` — **no letter guard** |

  The two diverge only for an untouched OSM `item` whose letter is outside
  `CoverageRetirement::LETTERS` (so A, B, F or K) that nonetheless shares a
  `source_ref` with a cached POI: `poi` would report it `curated`, while
  `search`/`counts` would still show the place as community. This is accepted,
  not overlooked. It cannot arise from the current pipeline, which writes only
  those same seven letters into `coverage_poi`, and A never enters the artifact
  while B is wikidata-sourced (coverage-provider.md §4). **Before letting any
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
  item rows **the payload itself serves**. Tile layers filter these refs out
  (`window.CC_CURATED_REFS`). The mirror rule is the invariant:
  `curatedRefs()` applies *exactly* the same exclusion as the payload's item
  collections, so `refs` excludes exactly what the payload excludes — a tile
  twin is **never suppressed for a row that is no longer served**. During the
  pre-retirement window (flag on, legacy rows excluded from the payload but
  not yet deleted), those objects therefore render from tiles as community
  instead of vanishing entirely. This refinement supersedes the design's
  looser "ships the set of curated refs" wording.
- `?feature=` deep links resolve against the local (curated) index first, then
  fall back to one `/map/coverage/search` lookup, so coverage POIs stay
  linkable.
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
 "letters": {"C": {"selectors": [{"tag": "amenity=drinking_water", "label": "Drinking water"}, …],
             "tileProps": […]}, …},
 "serviceKind": {"shop=bicycle": "shop", "amenity=bicycle_repair_station": "station",
                 "amenity=compressed_air": "pump"},
 "universalTileProps": ["ref", "n", "t", "ridtok", "cctok"],
 "storedTagKeys": ["addr:city", "amenity", …]}
```

- `letters` keys are exactly `C D E G H I J` — the
  [osm-data-architecture.md §5](osm-data-architecture.md) point catalogue.
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
  `pipeline/contract/**` in its trigger paths (added 2026-07-23) so a
  contract-only edit re-runs the PHP pin; before that it fired on nothing.
  **Still open:** the pipeline's own pytest suite runs in no workflow at all
  (`ci-tools.yml` covers `tools/**` only), so `load_contract()`'s validation and
  the `test_tiles.py` drift pin are dev-machine-only. Tracked in the storage
  backlog.
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
  property stays. A and B never pass through the exclusion.
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
  The command additionally letter-guards `IN ('C','D','E','G','H','I','J')`:
  A (not in the coverage artifact) and B (own data) must never be deleted.
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
- **PIVOT rows stay canonical** (`source=pivot`, Tourisme Wallonie CC-BY
  attribution). `tools/wallonia` is relieved of OSM POI duty but kept for the
  retired atlas demo and the canonical seeds (climbs, routes, surface, PIVOT);
  the coverage path never touches Overpass again.

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
  adjustment beyond `--cluster-densest-as-needed`; to be observed on real
  artifacts.

### Planet-flip dry-run checklist (pipeline, not reachable with Belgium data)

Two clustering behaviours are safe for Belgium but must be verified before the
worldwide flip (region-scoping-design.md §7 Phase 5):

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
