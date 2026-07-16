<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->
> **Consolidated into** coverage-provider.md (the canonical implementation contract; implementation plan pending execution), osm-data-architecture.md (§11 corrections), map-and-search.md (consumer behaviour summary) and dev-environment.md (prod topology) **(2026-07-16).** This dated working doc is sweepable; the canonical docs above are the source of truth.

# Coverage Provider (Plan 2) — Design

**Status:** approved design, ready for implementation planning · **Date:** 2026-07-16
**Supersedes:** the data-sourcing assumptions of
`docs/specs/2026-07-15-full-commons-search-and-community-tier-design.md` and its
plan (their UX decisions are carried in, rebased onto this design — see §8).
**Implements:** the coverage provider of `docs/specs/osm-data-architecture.md` §5
(referred to below as *OSM-arch §N*; section references in this repo are always
doc-qualified).

## 1. Goal and context

Show every OSM POI of the OSM-arch §5 catalogue on /map — worldwide-ready,
searchable, without ever calling Overpass on a user request — and retire the
capped Wallonia demo harvest as the serving path for uncurated OSM.

Current state (measured 2026-07-16): /map boots from one monolithic
`/map/catalog.json` (1,176,284 B raw / 252,945 B gzipped) served by
`CatalogProvider` from the `item` table; the browser holds every POI in memory
(`ITEM_INDEX`), which powers sidebar search, the town card, ride-check linking,
and rail counts. All 1,580 item rows are `state='unverified'`, harvested capped
(20–30 per province per layer) with simulated confirmation flags. The planet-wide
§5 subset is ≈ 4.7 M points (taginfo 2026-07-16: peaks 1.09 M, shelter 670 k,
memorials 498 k, bike services 92 k, …) — the in-memory model cannot scale to it.

## 2. Decisions (all confirmed by the owner, 2026-07-16)

| # | Decision |
|---|----------|
| D1 | **Architecture: pipeline builds, Symfony serves, PMTiles display.** The Python pipeline container is an internal batch worker producing a PostGIS index + a PMTiles artifact; Symfony serves all coverage queries; the map displays coverage from static vector tiles. (FastAPI-serves and no-tiles variants evaluated and rejected.) |
| D2 | **Belgium first, worldwide-ready.** v1 ships the Geofabrik `europe/belgium` extract end to end; planet scale is config + disk, sized by a measured dry-run before the flip. |
| D3 | **Own CC bucket from day one** (cost observability). Hetzner Object Storage, separate from `upstream-maps`; dev mirrors with the existing MinIO compose profile. The existing 150 GB world.pmtiles is a Protomaps basemap build and cannot carry coverage (no refs, no §5 attributes, not weekly-refreshable); only the serving *pattern* (nginx range proxy-cache) is reused. |
| D4 | **Full map swap in this plan.** Tiles + query endpoints + map.js consumers land together; the 2026-07-15 community-tier UX decisions are implemented here on the new data source. |
| D5 | **Weekly cadence, per-region runs.** Each Geofabrik region updates as a whole, independently (owner request); regions can stagger across the week. |
| D6 | **Coverage rows carry region membership** (`region_id`, `country_code`, `src_region`) computed at load time (owner request) so region/country-scoped queries never test containment at request time. |
| B1 | v1 extracts **nodes + ways** (ways reduced to centroid). Multipolygon relations (~1–3 % of objects, e.g. some castles) are a fast-follow needing pyosmium area assembly. |
| E1 | Rail counts: **totals** from a counts endpoint + **shown** computed client-side from rendered tile features in the viewport. |

Prod topology (corrected during this design): CC rides the upstream platform Hetzner
cluster — LB → 2 nginx/PHP frontends (CC is one more vhost + cert; TLS either
TCP-passthrough to nginx or LB-terminated via SNI certs), 1 DB server shared with
division, 1 worker server (Valhalla + messenger workers) that hosts the weekly
batch. The earlier "single VPS" framing came from the deploy workflows and is
wrong. The shared DB is one more reason display must not query per pan.

## 3. Component overview

```
Weekly, per region (worker server, pipeline container):
  Geofabrik PBF ─ osmium tags-filter ─ pyosmium ─▶ coverage_poi (PostGIS, per-region atomic swap)
                                                        │
                                     full-index export ─▶ tippecanoe ─▶ coverage.pmtiles ─▶ CC bucket

Request time:
  pan/zoom ─▶ byte-range reads of coverage.pmtiles (bucket + nginx range proxy; zero PHP)
  search / nearby / counts / drawer detail ─▶ Symfony /map/coverage/* ─▶ coverage_poi ⋈ item
```

- **One extract, two artifacts.** The index is the source of truth; tiles are
  generated *from* it, so display and search cannot disagree.
- **The pipeline never serves requests** — it is a scheduled job with DB and
  bucket credentials, identical in dev (`make coverage-refresh` → MinIO) and prod
  (systemd timer/cron on the worker server).
- **Curated data stays canonical** in `item` (+ climbs, routes, PIVOT stays),
  merged at read time and deduped by ref (OSM-arch §8).

## 4. Coverage cache schema

Pipeline-owned DDL, idempotent `CREATE` at run start. **Not** a Doctrine
migration; Doctrine `schema_filter` gets a `coverage_` exclusion (same pattern as
the existing `topology.` exclusion). The table is a disposable cache — never
edited by hand or by the app.

```sql
CREATE TABLE IF NOT EXISTS coverage_poi (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ref          varchar(160) NOT NULL,   -- 'node/61146471' | 'way/…' = item.source_ref format
    letter       char(1)      NOT NULL,   -- C D E G H I J (OSM-arch §5 catalogue)
    kind         varchar(16),             -- serviceKind for D (shop|station|pump), NULL otherwise
    name         varchar(255),            -- OSM name tag, NULL when unnamed
    geom         geometry(Point, 4326) NOT NULL, -- nodes as-is; ways centroid at load
    tags         jsonb        NOT NULL,   -- full filtered tag subset (drawer + Plan 3/4 source)
    osm_version  int,                     -- upstream version (Plan 3 materialization snapshot)
    osm_ts       timestamptz,             -- upstream last-edit timestamp
    src_region   varchar(64)  NOT NULL,   -- Geofabrik extract ('europe/belgium')
    country_code char(2),                 -- stamped from extract config
    region_id    bigint,                  -- ST_Contains(region.geom, geom) at load; NULL until polygons exist
    UNIQUE (ref, letter)                  -- one entity may carry two letters (matches item rule)
);
-- GIST(geom), (letter), (region_id), GIN(name gin_trgm_ops)
```

Notes:

- `ref` matches `item.source_ref` — the OSM-arch §8 dedupe join key.
- `tags` stores the **full filtered tag set** + `osm_version`/`osm_ts`: Plan 3's
  materialize-on-edit snapshots an object without re-fetching OSM, and Plan 4's
  optional hydrated endpoint has everything. Store rich, serve trimmed.
- `pg_trgm` extension is required for name search; it joins the postgis
  extensions in the out-of-migration bootstrap (`developers/docker/db/init/`,
  Makefile `test-db-reset`, prod bootstrap notes).

## 5. The weekly batch job (pipeline service)

Per region in the config list (v1: `['europe/belgium']`), independently:

1. **Download** the Geofabrik PBF (curl + md5; skip if unchanged).
2. **Filter** to the OSM-arch §5 selectors (`osmium tags-filter` → few-MB PBF).
3. **Parse** with pyosmium → rows: letter(s), kind, name, centroid, tag subset.
   serviceKind derives from the shared contract file (§9), the same mapping as
   `App\Catalog\ServiceKind::fromOsmTags()`.
4. **Load**: `COPY` into a staging table, count-drift sanity check vs the
   previous run, then one transaction: `DELETE FROM coverage_poi WHERE
   src_region = :r` + insert + `region_id` backfill (`ST_Contains` over `region`
   polygons where they exist). Readers never see a half-loaded region; an abort
   keeps last week's slice.

After the region passes, once per run:

5. **Export** per-letter newline GeoJSON from the full index.
6. **Build tiles**: tippecanoe, one layer per letter, minzoom 8 / maxzoom 14,
   `--drop-densest-as-needed`, direct `.pmtiles` output.
7. **Verify** with go-pmtiles (bounds, tile count, sample decode) — a broken
   build never ships.
8. **Upload** versioned key `coverage/<date>.pmtiles` + manifest to the CC
   bucket; prune, keeping the last 4. Versioned keys mean an open reader mid-pan
   never has bytes change underneath it.

Operational notes:

- **Failure mode:** any step aborts that region's transaction / the artifact
  step; last good data keeps serving; non-zero exit surfaces via the worker
  server's timer/cron mail.
- **Container changes:** pipeline image gains `osmium-tool`, `tippecanoe`,
  `pyosmium`, an S3 client, and a writable scratch volume (it currently has
  none). The FastAPI stub remains for health probes; the job is a CLI entrypoint.
- **Planet spike (in scope, measurement only):** one documented dry-run of the
  planet path recording disk, RAM, and wall-clock on worker-class hardware, so
  the worldwide flip is sized before Plan 5 needs it.
- Dev/tests: `make coverage-refresh` runs the whole chain against the dev DB +
  MinIO; pytest uses a tiny committed fixture PBF — CI never touches the network.

## 6. Tile artifact contract

Thin tiles: enough to draw markers and run map-side filters; everything else
comes from the detail endpoint on click. Flat scalars only (MVT rule).

| Property | Layers | Why in the tile |
|---|---|---|
| `ref` | all | join key: drawer detail fetch, curated-dedupe, deep links |
| `n` | all (when named) | labels, search-pick highlight |
| `t` | all | type label (existing marker/drawer vocabulary) |
| `kind` | D | shop/station/pump icon match (existing expression) |
| `potable` | C | water marker variant, derived from OSM `drinking_water` tags |
| `acc` | E | stays accessibility filter (existing `setFilter` narrowing) |

- One tile layer per letter (`c,d,e,g,h,i,j`); feature id = numeric osm id.
- **A · road surface stays out** of the coverage artifact: corridor line data,
  orders of magnitude larger, its own future decision. The current 351 curated
  segments keep serving via catalog.json.
- The upload step writes a manifest at the stable key `coverage/manifest.json`
  (current versioned URL, built_at, per-letter counts). Symfony reads it
  server-side (cached 3600 s) and injects the versioned tile URL as
  `CC_COVERAGE_URL` into the /map template — no client manifest fetch on boot,
  and a new artifact goes live within an hour without a deploy.
- CSP: bucket/cache host added to `connect-src`; the `pmtiles` protocol lib is
  SRI-pinned from unpkg exactly like maplibre-gl.

## 7. Symfony query plane

| Endpoint | Replaces | Shape / caching |
|---|---|---|
| `GET /map/coverage/search?q=` | ITEM_INDEX sidebar search (coverage part) | ranked: curated first, then community; dedupe by ref; trgm-backed; ETag + max-age 300 |
| `GET /map/coverage/nearby?lat=&lng=&km=` | town-card 5 km haversine scan | `ST_DWithin` over `coverage_poi ⋈ item`, grouped by letter; community capped ~3/group behind a "show all" expander (07-15 decision A); max-age 300 |
| `GET /map/coverage/counts` | rail totals | per-letter totals; max-age 3600 |
| `GET /map/coverage/poi/{type}/{id}` | new: drawer for tile POIs | display-whitelisted cached OSM tags + curated overlay `{itemId, state, fields, confirmations}` + `© OpenStreetMap contributors (ODbL)`; max-age 300 |

Rules:

- **Dedupe (OSM-arch §8):** a coverage row is suppressed wherever a served
  `item` with the same `source_ref` exists — the object appears once, as curated.
- Every endpoint gets an exact-path `PUBLIC_ACCESS` entry in `security.yaml`
  (scheb lazy-firewall caching gotcha), ETag handling, and a new anonymous
  sliding-window rate limiter `coverage_read` (120/min per IP) — the app's
  first anonymous-read limiter, consistent with the no-scraping access terms.
- These are **site-internal map endpoints**, not the Plan 4 public API:
  OSM-arch §7's reference-only rule governs the public API; the serving cache
  may serve OSM fields with attribution (OSM-arch §4).

## 8. map.js swap and community tier (2026-07-15 rebase)

- **Display:** the seven `*-osm` GeoJSON pools are replaced by one `pmtiles://`
  vector source with per-letter symbol layers reusing the existing canvas-minted
  icons and the D `kind` icon expression. Curated/confirmed pins, climbs,
  routes, surface, heat are unchanged (slimmed catalog.json keeps serving them).
- **Community tier:** the `verified` flag derives from real canonical state
  (curated item / confirmations) — the simulated `c` attribute dies. Utility
  community POIs draw in Curated mode with a lighter marker (07-15 decision B);
  search picks of non-drawn experiential items drop a reveal pin instead of
  force-switching mode (decision C, also fixes `openRouteById`); Photon results
  filter to `countrycode === 'BE'` (decision E).
- **Client dedupe:** the slimmed catalog ships the set of curated refs; tile
  layers filter those out.
- **Search:** the local index shrinks to the curated pool; coverage matches come
  from `/map/coverage/search` (debounced, merged under the existing
  Places/letters grouping); the town card uses `nearby`; ride-check keeps its
  existing ll-fallback. `?feature=<name>` deep links resolve against the local
  index first, then fall back to one search-endpoint lookup, so coverage POIs
  stay linkable.
- **Drawers:** click reads tile properties for the header, then hydrates via the
  detail endpoint — same pattern as the existing history/confirmations fetches.
  OSM attribution as today; the PIVOT attribution branch is untouched.
- **Boot contract:** catalog-load.js still defines all `CC_*` globals (empty
  pools degrade gracefully — verified in map.js); tile-source failure degrades
  to basemap + curated data, matching the Photon silent-degradation convention.

## 9. Contracts, testing, rollout

- **Shared contract file `coverage-contract.json`** (committed): OSM-arch §5
  selectors, letter mapping, serviceKind tag rules, tile property list. The
  Python job consumes it; PHP tests assert `ServiceKind`/letters stay in sync —
  one source of truth instead of two parallel mappings.
- **Tests:** pytest over the fixture PBF (selectors, centroid, kind, per-region
  swap); Symfony endpoint tests (dedupe, ETag, PUBLIC_ACCESS, limiter); the
  cross-language contract test; one end-to-end dev run + one browser
  verification pass at the end (repo convention: verify lightly).
- **Rollout:** `COVERAGE_TILES` env flag — off keeps today's behaviour; dev
  flips first; ships defaulted on once verified and the legacy pool path is
  deleted in the same plan (no long-lived dual path; the repo is not publicly
  deployed yet).

## 10. Canonical-data migration

- **Retire redundant rows:** after tiles + endpoints are verified, delete `item`
  rows with `source='osm'`, `state='unverified'` and **zero** change_history,
  confirmations, or submissions — exactly what the cache now serves. Anything a
  human ever touched stays. Console command with `--dry-run` count report first;
  the destructive run requires explicit owner approval + a dev-DB backup
  (standing rule).
- **Simulated flags die** (`c`/`sim`/`r`); confirmed-pin display keys on real
  confirmations/curation.
- **PIVOT stays canonical** (source=pivot, Tourisme Wallonie CC-BY attribution).
- **tools/wallonia is relieved of OSM POI duty** — kept for the retired atlas
  demo and canonical seeds (climbs, routes, surface, PIVOT); the coverage path
  never touches Overpass again. OSM-arch §9's interim clause gets retired in the
  spec update.
- **Scoped out (Plan 3):** contributions/confirmations on uncurated coverage
  POIs (the materialize-on-edit trigger). Coverage drawers show contribution
  CTAs only where an `item` exists, as today.

## 11. Documentation updates carried by this plan

- `docs/specs/osm-data-architecture.md`: concrete §5 implementation (per-region
  weekly extract, index + PMTiles on the CC bucket), correct the "tens of
  objects per province" scale claim with real numbers, retire the §9 interim
  clause, note the own-bucket decision.
- Mark the 2026-07-15 spec + plan superseded-into-Plan-2 (UX decisions carried).
- Dev docs: `make coverage-refresh`, MinIO profile, pipeline container tooling,
  `.env` examples for bucket credentials, CSP note.

## 12. Risks

| Risk | Mitigation |
|---|---|
| Planet-scale disk/time unknown | measured dry-run (§5) before any worldwide flip |
| Tile density at z14 (peaks, memorials) | `--drop-densest-as-needed`; per-layer minzoom tuning |
| Bucket CORS + Range behaviour | curl verification matrix in the implementation plan; nginx range proxy-cache pattern already proven on division |
| Worldwide search latency | trgm + bbox bias; Belgium-first hides this until the planet flip; measured then |
| Python/PHP mapping drift | single `coverage-contract.json` + cross-language contract tests |
| Weekly refresh vs open readers | versioned object keys; old artifacts retained (4) |
