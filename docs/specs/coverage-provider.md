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
- **Measured sizing.** At 377,558 rows (BE + NL + DE + LU; 0 unstamped
  after the ownership fix), compacted steady-state:
  **341 B/row heap + 176 B/row indexes = 517 B/row** — the compacted per-row cost.
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
| **Display** | 14 | `opening_hours`, `website`, `contact:website`, `url`, `phone`, `contact:phone`, `addr:city`, `addr:street`, `addr:housenumber`, `operator`, `description`, `wheelchair`, `fee`, `capacity` | `CoverageRepository::TAG_WHITELIST` — exactly what the drawer renders (§5). `wheelchair`/`drinking_water` also feed tile props (§4) |

`TAG_WHITELIST` has **15** entries; `drinking_water` is counted in the selector
row above, so the three groups sum to 11 + 14 + 4 = **29** distinct keys.
| **Media/reference** | 4 | `wikidata`, `wikipedia`, `image`, `wikimedia_commons` | **Nothing yet — provisional.** Kept only because re-adding them later costs a full re-harvest; pending a decision on whether we build the drawer photo / deep-link features. Cost: 19 B/row, ≈ 89 MB planet-wide |

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
   (not staged at all). Abort if the post-filter row count drops more than the
   drift ratio below the previous run for the same region
   (`pipeline/coverage/load.py::DRIFT_ABORT_RATIO`, value `0.4`) — a truncated
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
| `potable` | C | water marker variant, derived from OSM `drinking_water` tags |
| `acc` | E | stays accessibility filter |

`ref`/`n`/`t`/`ridtok`/`cctok` are the **universal** props (every layer, declared
as `universalTileProps` in `coverage-contract.json`, consumed by
`tiles.py::_universal_props` and pinned by both language contract suites);
`kind`/`potable`/`acc` are per-letter extras (`tileProps`). **No cluster props
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
  segments keep serving via `catalog.json`. B/F/K are category-3 (our own
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
- **The cycle-route network has its own artifact and manifest since
  2026-08-13** (plan:
  `docs/plans/handoffs/2026-08-12-routes-layer-and-surface-quality.md`):
  `route=bicycle`/`route=mtb` relations extracted per region by
  `coverage.run --routes` (`pipeline/coverage/routes.py`, a two-pass walk —
  relations first for membership, then ways with locations — over the same
  Geofabrik extracts, zero PostGIS), one line feature per **member way**
  (props `net`/`rr`/`rk`/`refs` + the element `ref`, contract `routes` key)
  plus knooppunt nodes as points (`nr`; in-artifact from
  `routes.nodes.minZoom` via a per-feature tippecanoe floor). Published as
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
  "built_at":"<ISO>", "counts":{"C":n,…}, "regions":[…], "country_codes":[…]}`.
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
  `pipeline/contract/**` in its trigger paths so a
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

## 9.1 The public /coverage page

The marketing-era `/coverage` page (hardcoded demo KPIs and an invented
per-country percentage table) was replaced by a DB-driven page:
`App\Catalog\CoverageStatsProvider` (raw DBAL, no cache — the
RegionDirectoryProvider posture) serves live KPIs (POI/item/route/country
COUNTs), a per-country volume table (operational countries only, same
L2-exclusion predicate as the region pages; the bar compares POI **density**
— places per km² of onboarded area, scaled to the densest country — because
an absolute-volume bar would dwarf small countries under the biggest one
forever, owner correction 2026-07-30), and "biggest gaps" cards computed as the three catalog
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
