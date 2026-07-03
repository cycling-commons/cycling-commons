# Spec — Catalog data model, Wallonia import, DB-served map (data-API phase A)

- **Status:** Draft for review
- **Date:** 2026-07-03
- **Scope:** The first data-API phase: a durable domain model for the map catalog (`item` + `recommended_route` + `heat_point`), an idempotent Wallonia import with source provenance, and `/map` served from the database with the static catalog fixtures retired. **One spec → two implementation plans** (§12).
- **Surfaces:**
  - `web/src/Catalog/*` (new: entities, enums, geometry DBAL type, `CatalogProvider`, import command), `web/migrations/*`
  - `web/src/Controller/MapController.php`, `web/assets/map/map.js`, `web/templates/map/index.html.twig`, `web/assets/data/*` (retired)
  - `tools/wallonia/*` (additive `--export` output; atlas fixtures unchanged)
- **Depends on:** PostGIS (enabled in the dev stack, unused until now), the World reference bundle (`web/src/World` — subdivision FK), the catalog field registry (`CatalogField`/`FieldKind`, already driving the improve wizard).
- **Related:** [`edit-items/README.md`](edit-items/README.md) (lifecycle/votability funnel, provenance tags, change-history principle), [`2026-07-02-map-based-moderation-design.md`](2026-07-02-map-based-moderation-design.md) §13 (hardening bundle that fires when real submissions arrive), [`2026-06-26-html-to-symfony-migration-design.md`](2026-06-26-html-to-symfony-migration-design.md) (explicitly deferred the data API — this spec begins it).

---

## 1. Problem

The catalog exists only as static JavaScript fixtures, three times over: `tools/wallonia` harvests into `atlas/demo/*.js`, which are manually copied (undocumented, drift-prone) to `web/assets/data/*.js`, which `/map` loads as `<script>` globals. The fixtures **strip source ids** (`to_fixture_js` drops `_id` and all `_`-prefixed keys), so nothing can be traced back to OSM/Wikidata/PIVOT, re-harvests can only wholesale-replace, and nothing can ever be edited, moderated, or voted on durably. Every contribution surface built so far (improve wizard, moderation queue, votes) dead-ends in `ContributionStubService` because there is nothing to persist against.

## 2. Goals / Non-goals

**Goals**

- A **durable catalog model** with per-row provenance (`source` + `source_ref`), lifecycle state, and geometry — designed for tens of millions of rows even though it starts with ~1,600 across the three tables (~1,560 items + 11 routes + the heat set).
- An **idempotent import** from a new harvest export that preserves source ids (run twice → identical rows).
- `/map` **reads the database** (cacheable catalog endpoint); all eleven catalog fixture files retire. Acceptance is byte-level parity, not vibes.
- The model is **forward-compatible** with phases B (submissions/moderation on real data) and C (edit application + field-level change history + re-harvest conflict queue) without schema rework.

**Non-goals (deferred, deliberately)**

- `Submission`/decision persistence and `SampleQueue` replacement — **phase B** (triggers the moderation spec §13 hardening bundle).
- Applying approved edits, append-only per-field change history, and the re-harvest **`osm_sync` conflict queue** — **phase C** (§11 sketches the flow so the schema anticipates it).
- Vector-tile serving (`ST_AsMVT`) — the catalog endpoint is the named interim (§7).
- The ride-ingestion → heat-aggregation pipeline (Python side, per the geo/compute boundary). Phase A stores today's aggregate points; it does not compute them.
- Stays dedupe-at-import (OSM vs PIVOT stays stay separate rows; the client-side `stays-merge.js` keeps merging).
- Auto-retirement of items missing from a re-harvest (§6, rationale there).
- Upstreaming local fixes to OSM (much later; fork-with-provenance works without it and it would make forks temporary when it lands).

## 3. Settled decisions (with rationale)

1. **Fork with provenance** — OSM-derived items are imported as our rows, carrying `source`/`source_ref`; local edits (phase C) are allowed and recorded per-field. Field-level change history is what makes safe re-harvest merges possible: the importer updates any field *without* local edit history and routes genuine conflicts (locally-edited field whose OSM value also changed) to the moderation queue as an **`osm_sync`** item — drawer shows *ours → OSM's* in the existing was→now diff; curator decides **load OSM / keep ours**.
2. **One generic `item` entity** (letters A–J), not per-type entities or Doctrine inheritance: common/filterable fields are real columns, type-specific detail lives in registry-validated `jsonb`. This is the planet-scale feature-store shape (osm2pgsql, Overture); per-type tables would fragment every cross-cutting concern (queue, history, votes) into polymorphic references.
3. **The boundary is product-semantic, not geometric**: *atomic editable catalog feature* → `item` (points **and** lines — surface segments are letter-A items); *curated composition* → `recommended_route`; *computed aggregate* → `heat_point`. Only L (heatmap) is non-editable catalog; it is not an item.
4. **Rename:** layer K "Quality rides" becomes **"Recommended routes"** (map label + legend + i18n where applicable).
5. **Scale commitments** (§10) are part of the contract, not advice.

## 4. Data model

### 4.1 `item` — letters A–J (~1,560 rows at import)

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint` generated identity | index-friendly at scale; no random UUIDs |
| `letter` | `char(1)` | catalog type A–J; registry-validated |
| `name` | `text` | |
| `geom` | `geometry(Geometry, 4326)` | Point for most letters, LineString for A (surface); expected geometry kind per letter is declared by the catalog registry and validated at import/edit time |
| `country_code` | `char(2)` | denormalized filter column (moderation world overview, future queries) |
| `subdivision_id` | `bigint` FK → `world_subdivision`, nullable | resolved from the harvest's per-province tag |
| `state` | enum `submitted / unverified / verified / rejected / retired` | imported rows enter **`unverified`** (on the map, but not past the community verification gate); user submissions (phase B) enter `submitted`; `retired` is curator-decided, never automatic |
| `source` | enum `osm / pivot / wikidata / user / auto` | the provenance tags from edit-items |
| `source_ref` | `text`, nullable | `node/123`, `way/456`, `Q2093`, PIVOT id; **unique `(source, source_ref)`** (Postgres treats NULL refs as distinct — fine for `auto`) |
| `attributes` | `jsonb` | **only registry-declared keys** — unknown keys are an import error, not a passthrough; today's fixture fields (`t, prov, c, sim, r, desc, photo`, per-letter form fields) map here |
| `created_at` / `updated_at` / `imported_at` | `timestamptz` | `imported_at` = last harvest touch (staleness signal, replaces auto-retire) |

Indexes: GiST(`geom`) — *the* map read path is viewport bbox + filter; btree(`letter`, `state`); btree(`country_code`); unique(`source`, `source_ref`).

### 4.2 `recommended_route` — letter K (11 rows at import)

`id` bigint identity · `name` · `geom geometry(LineString, 4326)` + GiST · `distance_m` / `ascent_m` (real columns — they are display/sort fields, not jsonb) · `state`, `source`, `source_ref`, `attributes`, timestamps as in `item`.

### 4.3 `heat_point` — layer L (seeded from today's `CC_ROUTES.heat`)

`id` bigint identity · `geom geometry(Point, 4326)` + GiST · `weight` real · `source` (`auto` for now) · `computed_at`. **Not** an item: no name, no lifecycle, no attributes, never editable, never in the moderation queue. This is the table most likely to explode when real rides feed it — first candidate for partitioning and tile/aggregation serving.

### 4.4 Geometry in Doctrine

One small custom DBAL type for `geometry` (write `ST_GeomFromGeoJSON`/WKT, read `ST_AsGeoJSON`) rather than a full spatial ORM package — matches the project's plain-SQL migration style; complex spatial queries stay raw SQL/DBAL where they belong.

## 5. Harvest export (Python, `tools/wallonia`)

- Additive `--export` output alongside the existing fixture writing: **one GeoJSON/JSONL file per layer** that *keeps* what `to_fixture_js` strips — `_id` (OSM type+id), source metadata, raw per-feature fields. `atlas/demo/*.js` keep being written unchanged (`main`'s HTML demo consumes them); the export is a second artifact, not a replacement.
- All sources export: 7 OSM POI layers, PIVOT stays (ArcGIS id as ref), Wikidata climbs (`Q…` as ref), surface segments (OSM way refs if `route_surfaces.py` has them stable, else `source='auto'`, NULL ref), routes, and the heat point set.
- Geo harvesting stays Python (project boundary); export files are the handoff artifact.

## 6. Importer (PHP, `app:catalog:import <path>`)

- Modeled on `app:world:import`: **idempotent upsert by `(source, source_ref)`** — insert ⇒ `state='unverified'`; update ⇒ refresh fields + `imported_at`. Acceptance: run twice, row counts and content identical.
- Letter mapping from the layer file; `country_code='BE'`; subdivision resolved from the harvest province tag (nullable fallback).
- Attributes validated through the catalog registry (unknown key ⇒ error; wrong geometry kind for letter ⇒ error).
- **Batched DBAL writes**, not ORM-per-entity — the pattern that survives world scale; Doctrine is the application's path, never the import loop.
- **No auto-retire on absence**: the harvest is capped/ranked (`rank_and_cap`), so absence from a re-harvest can mean "fell below cap", not "deleted upstream". `imported_at` records staleness; retirement is a curator decision until per-feature deletion signals exist.
- **No conflict machinery in phase A**: local edits don't exist until phase C, so the importer is a plain upsert. Forward design (§11) is documented so the phase-C importer slot-in is a filter + queue-write, not a redesign.

## 7. Serving flip (`/map` reads the DB)

- **`GET /map/catalog.json`** — public, cacheable (`ETag` + `max-age`), all layers keyed by letter. Chosen over inline injection because the catalog is the whole map payload (~0.5–1 MB): inlining it into every `/map` response would discard the HTTP caching the static files get today. This endpoint is the **named interim** until vector tiles.
- `CatalogProvider` serializes DB rows to the **existing fixture shapes** (`{t,n,prov,c,sim,r,desc,photo}` GeoJSON per POI layer, the `CC_CLIMBS` array shape, `CC_STAYS_PIVOT`, `CC_ROUTES.routes` + `.heat`) — the data changes address, not shape; `map.js`'s layer-merge logic is untouched.
- `map.js` wraps init in a `fetch('/map/catalog.json').then(...)` — a contained, browser-verifiable refactor of the load sequence.
- **Retired:** all 11 catalog fixture files in `web/assets/data/` + their `<script>` tags (POI layers, climbs, stays-pivot, routes/heat, surface). **Stay static:** `regions-data.js`, `races.js`, `profiles-data.js` (navigation/content fixtures, not catalog). `atlas/demo/*` untouched.
- Curator pending-layer injection (`CC_PENDING`) is unaffected — separate, role-gated path.

## 8. Lifecycle & provenance semantics

- `unverified` — on the map, pre-verification-gate (all imported rows start here). `verified` — passed the tiered verification gate (phase C+ mechanics). `submitted` — awaiting moderation (phase B; never set by import). `rejected` — moderation outcome (phase B). `retired` — removed from serving; curator-decided.
- `source` answers "where did this row come from"; per-field edit provenance (`[edit]`/`[tap]`) arrives with change history in phase C.
- The serving endpoint emits `unverified` + `verified` items (parity with today: everything imported renders), never `submitted/rejected/retired`.

## 9. Acceptance & testing

- **Parity is the end-to-end test of the import**: per-layer feature counts from `/map/catalog.json` equal the retired fixtures' counts exactly; sampled features byte-match after shape serialization; Playwright pass as anonymous rider shows identical rendering, 0 console errors.
- Import idempotency test (run twice, diff row set); registry-validation tests (unknown key, wrong geometry kind); geometry round-trip unit test for the DBAL type; endpoint tests (shape, cache headers, state filtering).
- Standard gates: phpunit, phpstan, psalm, cs-fixer, SPDX, translations (K-rename keys ×4 locales), `node --check`.

## 10. Scale commitments (contract, not advice)

1. Reads are **viewport-bbox + filter** on indexed columns; `jsonb` is fetched per-row (drawer), never scanned.
2. **Hot-field promotion doctrine**: any attribute appearing in `WHERE`/`ORDER BY` gets a `GENERATED ALWAYS AS (…) STORED` column + index; the registry records which fields are promoted.
3. **Partition-ready**: bigint identity keys; `country_code`/`letter` are the natural partition keys, activatable without redesign; `heat_point` and (phase C) the change-history table are first in line.
4. Serving moves to **vector tiles** when scale demands; `/map/catalog.json` is explicitly interim.
5. World-scale re-harvest is a **Python ETL with set-based SQL upserts** — never an ORM loop.

## 11. Forward design (phases B/C — documented, not built)

- **Phase B:** `Submission` entity replaces `SampleQueue` (its shape is the de-facto schema: `{id, type: new|edit|hazard|photo, letter, country, region, title, lat, lng, who, when, body, was, now}` + the four stub payload kinds); decisions persist with an audit trail; moderation spec §13 hardening fires (drawer HTML-escape, filter-context redirect, TYPES relocation, `moderationToken` reject-on-miss).
- **Phase C:** append-only per-field change history (time-partitionable from its first migration); approved edits mutate `item.attributes`/columns; the importer gains the conflict filter — skip locally-edited fields, and where OSM's value *also* changed, write an **`osm_sync`** submission to the queue (*was* = our value, *now* = OSM's; decisions mean **load OSM / keep ours**).
- **Later:** ride ingestion → heat aggregation (Python); vector tiles; upstreaming to OSM (dissolves forks when it lands).

## 12. Plan split

- **Plan 1 — model + import:** migrations (3 tables + enums + indexes), geometry DBAL type, entities, harvest `--export`, `app:catalog:import`, seed import of Wallonia data, idempotency + validation tests. DB is a (temporarily) shadow source of truth.
- **Plan 2 — serving flip:** `CatalogProvider` + `/map/catalog.json`, `map.js` fetch-init refactor, K-rename, fixture retirement, parity acceptance (counts + Playwright), i18n keys.
