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
  separated, v1 `europe/belgium`) refreshes as a whole on its own run; regions
  can stagger across the week. Planet scale is config + disk, gated on a
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
CREATE TABLE IF NOT EXISTS coverage_poi (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ref          varchar(160) NOT NULL,   -- 'node/61146471' | 'way/…' = item.source_ref format
    letter       char(1)      NOT NULL,   -- C D E G H I J (osm-data-architecture.md §5 catalogue)
    kind         varchar(16),             -- serviceKind for D (shop|station|pump), NULL otherwise
    name         varchar(255),            -- OSM name tag, NULL when unnamed
    geom         geometry(Point, 4326) NOT NULL, -- nodes as-is; ways centroid at load
    tags         jsonb        NOT NULL,   -- full filtered tag subset (drawer + Plans 3/4 source)
    osm_version  int,                     -- upstream version (materialization snapshot)
    osm_ts       timestamptz,             -- upstream last-edit timestamp
    src_region   varchar(64)  NOT NULL,   -- Geofabrik extract ('europe/belgium')
    country_code char(2),                 -- stamped from extract config
    region_id    bigint,                  -- ST_Contains(region.geom, geom) at load; NULL until polygons exist
    UNIQUE (ref, letter)                  -- one entity may carry two letters (item's uniq_item_source_ref_letter, source-scoped: catalog-data-model.md §3)
);
-- Indexes: GIST(geom), (letter), (region_id), GIN(name gin_trgm_ops)
```

- `ref` matches `item.source_ref` (`web/src/Catalog/Entity/Item.php`) — the
  dedupe join key of [osm-data-architecture.md §8](osm-data-architecture.md).
- **Store rich, serve trimmed.** `tags` holds the *full* filtered tag set plus
  `osm_version`/`osm_ts`, so materialize-on-edit
  ([osm-data-architecture.md §6](osm-data-architecture.md), Plan 3) can
  snapshot an object without re-fetching OSM, and the future optional hydrated
  endpoint ([osm-data-architecture.md §7](osm-data-architecture.md), Plan 4)
  has everything it needs. What *leaves* the server is trimmed: tiles carry
  the thin property set (coverage-provider.md §4), the detail endpoint
  whitelists display tags (coverage-provider.md §5).
- Region membership (`region_id`, `country_code`, `src_region`) is stamped at
  **load time**, so region/country-scoped queries never test containment at
  request time.
- `pg_trgm` is required for name search. It joins the PostGIS extensions in the
  out-of-migration bootstrap: `developers/docker/db/init/` (dev), the Makefile
  `test-db-reset` target (test DB), and the prod bootstrap notes. In PHP tests
  the schema is mirrored by a test trait (`web/tests/Coverage/CoverageSchema.php`)
  inside the DAMA transaction, because migrations never create the table.

## 3. The weekly batch job

Per region in `COVERAGE_REGIONS`, independently:

1. **Download** the Geofabrik PBF (curl + md5; skip if unchanged).
   `COVERAGE_PBF_PATH` optionally points at a local PBF instead (fixture/dev
   runs — never a network dependency in tests).
2. **Filter** to the [osm-data-architecture.md §5](osm-data-architecture.md)
   selectors with `osmium tags-filter` (a few-MB PBF remains).
3. **Parse** with pyosmium into rows: letter(s), kind, name, centroid, tag
   subset. `serviceKind` derives from the shared contract file
   (coverage-provider.md §7) — the same mapping as
   `App\Catalog\ServiceKind::fromOsmTags()`
   (`web/src/Catalog/ServiceKind.php`).
4. **Load — per-region atomic swap with drift guard.** `COPY` into a staging
   table; abort if the new row count drops more than the drift ratio below the
   previous run for the same region
   (`pipeline/coverage/load.py::DRIFT_ABORT_RATIO`, value `0.4`) — a truncated
   download must never wipe a region. Then one transaction:
   `DELETE FROM coverage_poi WHERE src_region = :r` + insert + `region_id`
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
   carrying `point_count` (sum over a zoom = the full total); above the
   `--cluster-maxzoom` cap (z12+) points render individually so a rider zoomed
   into a town sees the actual POIs. `--cluster-densest-as-needed` merges
   (never drops) if a tile still exceeds the size limit.
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
(default `europe/belgium`), `COVERAGE_WORKDIR` (`/data/work`, named scratch
volume), `COVERAGE_PBF_PATH` (optional local override), `COVERAGE_S3_ENDPOINT`,
`COVERAGE_S3_BUCKET` (`cc-maps`), `COVERAGE_S3_KEY`, `COVERAGE_S3_SECRET`,
`COVERAGE_S3_REGION` (signing only, default `us-east-1`),
`COVERAGE_PUBLIC_BASE_URL`.

## 4. Tile artifact contract

Thin tiles: enough to draw markers and run map-side filters; everything else
comes from the detail endpoint on click. Flat scalars only (MVT rule). One tile
layer per letter — `c d e g h i j` — feature id = numeric OSM id.

| Property | Layers | Why in the tile |
|---|---|---|
| `ref` | all | join key: drawer detail fetch, curated dedupe, deep links |
| `n` | all (when named) | labels, search-pick highlight |
| `t` | all | type label (existing marker/drawer vocabulary) |
| `rid` | all (when region-stamped) | region scope filter (region-scoping-design.md §6, Phase 3) |
| `cc` | all (when stamped) | country scope filter (region-scoping-design.md §6, Phase 3) |
| `kind` | D | shop/station/pump icon match |
| `potable` | C | water marker variant, derived from OSM `drinking_water` tags |
| `acc` | E | stays accessibility filter |

`ref`/`n`/`t`/`rid`/`cc` are the **universal** props (every layer, declared as
`universalTileProps` in `coverage-contract.json`, pinned by both language
contract suites); `kind`/`potable`/`acc` are per-letter extras (`tileProps`).
**`point_count`/`clustered`** are injected by tippecanoe on cluster features at
z6–11 (§3 step 6): the client draws a clustered feature (`has point_count`) as a
count bubble (`{key}-cov-cl` symbol layer — a colour disc + the count) that, on
click, zooms in until it splits into individual icons; the `{key}-cov` icon
layer filters to unclustered features (`!has point_count`). Both layers compose
the dedupe + region-scope arms (`covIconFilter`/`covClusterFilter` in map.js).
`rid`/`cc` are NULL-stripped, so a row outside every region (or a tile built
before Phase 3) is prop-less; the client renders a prop-less coverage feature
**unfiltered** (region-scoping-design.md §8 risk 2 fallback — the artifact lags
the DB by up to a weekly rebuild, so hiding-all would blank the map), the
inverse of the leak-safe default for served data.

- **A · road surface stays out** of the coverage artifact: corridor line data,
  orders of magnitude larger, its own future decision. The existing curated
  segments keep serving via `catalog.json`. B/F/K are category-3 (our own
  data) and are never in the extract
  ([osm-data-architecture.md §5](osm-data-architecture.md)).
- **Manifest** (stable key `coverage/manifest.json`):
  `{"version":1, "url":"<COVERAGE_PUBLIC_BASE_URL>/coverage/<YYYYMMDD-HHMM>.pmtiles",
  "built_at":"<ISO>", "counts":{"C":n,…}, "regions":[…]}`.
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
  `region_id IN (…)`) and `cc` (2-letter country → `country_code = :cc`),
  applied to both the curated (`item`) and community (`coverage_poi`) arms.
  A country scope sends both, ORed, so an unsplit-country row (region_id NULL,
  cc set) still matches. Counts become scope-aware and drive BOTH sides of a
  coverage layer's rail badge: the in-scope count is the `total`, and also the
  `shown` (every in-scope POI is on the map, revealed progressively as you
  zoom — the client does NOT count viewport-rendered tiles, which
  `--drop-densest-as-needed` thins to a confusing near-zero at overview zooms;
  `map.js covShownCount`). So a coverage layer reads N/N when on, 0/N when
  toggled off or mode-hidden — matching the served layers. Params are
  client-sent only (the plane is
  anonymous + cacheable — never server-resolved from a user), de-duped, sorted
  and capped at `CoverageController::MAX_SCOPE_REGIONS` (value `24`) to bound
  the shared HTTP-cache keyspace (region-scoping-design.md §8 risk 10); the cap
  is safe because a country scope's `cc` arm is the complete fallback. Absent =
  current behaviour (backward compatible). The deep-link resolver
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
                 "amenity=compressed_air": "pump"}}
```

- `letters` keys are exactly `C D E G H I J` — the
  [osm-data-architecture.md §5](osm-data-architecture.md) point catalogue.
- The **Python job consumes it** (`pipeline/coverage/contract.py::load_contract`,
  dataclasses `Selector`/`LetterSpec`/`Contract` with `letters_for(tags)` and
  `kind_for(tags)`); PHP never reads it at runtime.
- **PHP tests pin it** (`web/tests/Catalog/CoverageContractTest.php`): the
  `serviceKind` rules must resolve identically through
  `App\Catalog\ServiceKind::fromOsmTags()`, every PHP kind must be reachable,
  and the D selectors must be exactly the `serviceKind` rule set. The test
  skips (not fails) when the file is absent so `web/` stays runnable alone.
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
  `country_code`/`src_region`.
- **Planet scale** is unmeasured: the worldwide flip is gated on a documented
  dry-run (disk, RAM, wall-clock on worker-class hardware, procedure in
  `developers/coverage-batch.md`); no numbers exist yet. Related: worldwide
  search latency (trgm + bbox bias) is only measured at that flip.
- **A · road surface at coverage scale** (corridor line data in tiles) has no
  decision — deliberately excluded from this artifact.
- **Tile density tuning** at z14 (peaks, memorials) may need per-layer minzoom
  adjustment beyond `--drop-densest-as-needed`; to be observed on real
  artifacts.
