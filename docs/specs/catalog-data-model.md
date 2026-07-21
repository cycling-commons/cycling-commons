<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Catalog Data Model, Import & Serving

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

This document defines the running catalog data model: the four catalog tables
and the one-generic-entity decision, row identity and the idempotent upsert,
lifecycle states, provenance sources, region membership, the registry-validated
`attributes` jsonb, the import pipeline, the `/map/catalog.json` serving
contract, per-source attribution rules, and the scale doctrine. It documents
**what is built** — where a forward-looking architecture differs, the bridge
section (catalog-data-model.md §12) says exactly which parts
[osm-data-architecture.md](osm-data-architecture.md) supersedes going forward
and which parts are permanent.

Sibling ownership: the OSM relationship, licensing posture, and coverage/API
policy live in [osm-data-architecture.md](osm-data-architecture.md); per-type
edit contracts in [edit-items/](edit-items/README.md); the submission →
moderation machinery in moderation-and-contribution.md; the K (routes) domain
in route-domain.md; the map/search UX in map-and-search.md.

---

## 1. The four-table model

The whole map catalog lives in four tables (entities in
`web/src/Catalog/Entity/`; `region` created by `Version20260703152605`, the
other three by `Version20260703153611`):

| Table | Holds | Letters |
|---|---|---|
| `item` | Every atomic editable catalog feature | A–J |
| `recommended_route` | Curated route compositions | K |
| `heat_point` | The computed ride-heat aggregate | L |
| `region` | Operational spatial buckets (moderation, voting, caps) | — |

**One generic `item` entity — not per-type entities, not Doctrine
inheritance.** Common/filterable fields are real columns; type-specific detail
lives in registry-validated jsonb (catalog-data-model.md §7). This is the
planet-scale feature-store shape (osm2pgsql, Overture); per-type tables would
fragment every cross-cutting concern (moderation queue, change history,
confirmations, votes) into polymorphic references.

### The boundary rule (product-semantic, not geometric)

What lands in which table is decided by product semantics, never by geometry
kind:

- **Atomic editable catalog feature → `item`** — points *and* lines (road-surface
  segments are letter-A items with LineString geometry).
- **Curated composition → `recommended_route`** — riders propose, curators own
  edits; see route-domain.md.
- **Computed aggregate → `heat_point`** — L is *never* an item: no name, no
  lifecycle, no attributes, no region, never editable, never moderated.

The letter → geometry-kind mapping is enforced at import
(`ImportCatalogCommand::GEOMETRY_KIND`): `A` = LineString, `B`–`J` = Point.
Wrong geometry kind for a letter is an import error.

## 2. Schemas

All geometry columns are PostGIS `geometry(Geometry, 4326)` with GiST indexes,
exchanged as GeoJSON strings through one small custom DBAL type
(`App\Catalog\Doctrine\GeometryType`: `ST_GeomFromGeoJSON` on write,
`ST_AsGeoJSON` on read). No spatial ORM package; complex spatial queries stay
raw SQL/DBAL. Timestamps are `TIMESTAMP(0) WITHOUT TIME ZONE`
(`datetime_immutable` on the PHP side).

### 2.1 `item` (`App\Catalog\Entity\Item`)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint generated identity | partition-friendly; no random UUIDs |
| `letter` | varchar(1) | catalog type A–J (uppercased by the setter) |
| `name` | varchar(200) | the one pseudo-field outside `attributes` — `Item::NAME_FIELD` keeps that rule in one place |
| `geom` | geometry, GiST `idx_item_geom` | Point, or LineString for A |
| `country_code` | varchar(2), btree `idx_item_country` | denormalized filter column |
| `subdivision_id` | bigint nullable | plain column, **no FK constraint** → `world_subdivision.id` |
| `region_id` | bigint nullable, btree `idx_item_region` | plain column, **no FK constraint**; recomputed every import (catalog-data-model.md §6) |
| `state` | varchar(12) ← `ItemState` | catalog-data-model.md §4 |
| `source` | varchar(10) ← `ItemSource` | catalog-data-model.md §5 |
| `source_ref` | varchar(160) | upstream id (`node/…`, `way/…`, `Q…`, PIVOT id) or stable synthetic ref (`fx:…`, `manual:…`); never NULL in practice |
| `attributes` | jsonb | registry-validated only (catalog-data-model.md §7) |
| `created_at` / `updated_at` | timestamp | every content setter (name/geom/state/attributes) touches `updated_at` |
| `imported_at` | timestamp nullable | last harvest touch — the staleness signal (there is **no auto-retire**, catalog-data-model.md §4) |

Composite indexes: btree `idx_item_letter_state (letter, state)` — the serving
filter; unique `uniq_item_source_ref_letter (source, source_ref, letter)` —
the identity (catalog-data-model.md §3).

### 2.2 `recommended_route` (`App\Catalog\Entity\RecommendedRoute`)

`id` bigint identity · `name` varchar(200) · `geom` (LineString) + GiST ·
`distance_m` / `ascent_m` integer nullable (display/sort fields, so real
columns, not jsonb) · `region_id` (as in `item`) · `state` / `source` /
`source_ref` / `attributes` / timestamps as in `item` · unique
`uniq_route_source_ref (source, source_ref)` (no letter — the table *is* the
letter). Route-domain extensions (`proposed_by`, suitability vocabulary, the
`route_vote` / `route_ride` / `route_suggestion` / `route_change_history`
tables) are owned by route-domain.md.

### 2.3 `heat_point` (`App\Catalog\Entity\HeatPoint`)

`id` bigint identity · `geom` (Point) + GiST · `weight` float · `source`
(`ItemSource`; only `auto` exists and only `auto` is served) · `season`
varchar(8) nullable (the ride-heat layer's filter facet) · `region_id` bigint
nullable (region membership for the map's scope filter — stamped by
`recomputeMembership` on every import, backfilled by `Version20260721120000`;
region-scoping-design.md §7 Phase-2 review finding 5) · `computed_at`.
First candidate for partitioning and tile/aggregation serving when real rides
feed it.

### 2.4 `region` (`App\Catalog\Entity\Region`)

`id` bigint identity · `slug` varchar(80) unique (the upsert key; seed:
`wallonia`) · `name` varchar(160) · `geom` (MultiPolygon) nullable + GiST ·
`area_km2` float nullable (informational **and the overlap tie-break** —
catalog-data-model.md §6) · `country_code` varchar(2) (added by
`Version20260704222148` for moderation filtering) · `iso_code` varchar(10)
nullable (ISO 3166-2, joins World `Subdivision.code`, e.g. `BE-WAL`) ·
`admin_level` smallint nullable · `source` varchar(32) nullable (polygon
provenance: `osm` | `overture`) · `active_cap` smallint nullable (per-region
override of `route.region_active_cap`; NULL = the global default) · timestamps.
The last four columns were added by `Version20260719140000` (region-scoping
Phase 1); `importRegions` stamps `country_code`/`iso_code`/`admin_level`/
`source` from the artifact and **requires `country_code`** (invariants below).
Regions are the day-one operational unit: moderator areas, region-scoped
voting/rankings, and the per-region route cap all anchor to `region.id`
(owned by moderation-and-contribution.md and route-domain.md respectively).

**Invariants (region-scoping-design.md §3, review-enforced):**

- **Never delete a region row.** `moderator_area.region_id` is
  `ON DELETE CASCADE` (`Version20260714210000`), so deleting a region silently
  drops curator jurisdictions. Region lifecycle is **upsert-by-slug only**;
  geometry changes only via an explicit versioned re-import. Slug and ISO code
  are the stable identity across re-imports — boundaries may shift, the row
  endures.
- **`country_code` is required at import.** A region with no country is
  invisible to country-scoped curators (`ModerationScope` matches on
  `region.country_code`) — a silent jurisdiction hole. `importRegions` rejects
  an artifact that lacks it (region-scoping-design.md §8 risk 1).
- **Operating-level regions tessellate, never overlap.** The importer rejects
  an `ST_Overlaps` pair within one country; membership additionally resolves
  any overlap smallest-area-wins, so a bad row that slips through is still
  deterministic (catalog-data-model.md §6).

**Seeding playbook (region-scoping-design.md §5a — curator-demand-driven):**
countries start **unsplit** (zero region rows; rider scope still works via
My-area / country / Everywhere). When a curator volunteers, seed that country's
subdivisions at the granularity of the smallest jurisdiction anyone there wants
— one operating level per country. Mechanically: add a `region-<slug>.geojson`
artifact carrying `slug`/`name`/`area_km2`/`country_code` (plus `iso_code`/
`admin_level`/`source`), drop it in the export dir, and run `app:catalog:import`
— the same glob, more files; membership recomputes from scratch. A jurisdiction
is a **set of region rows** on `moderator_area`, never a drawn polygon.

### 2.5 `change_history` (referenced, owned elsewhere)

The append-only per-field edit log (`App\Catalog\Entity\ChangeHistory`;
schema and the append-only invariant are owned by
moderation-and-contribution.md §4.1). Its write path
(`ModerationService::applyEdit()`) and semantics belong to
moderation-and-contribution.md — it appears here because its mere **existence
per item is load-bearing for the import upsert** (catalog-data-model.md §3).

## 3. Identity and the idempotent upsert

**Item identity is `(source, source_ref, letter)`** — entity × classification.
One upstream entity may legitimately hold rows in two layers (e.g. a site that
is both history and scenic). Routes upsert by `(source, source_ref)`.

The single item-upsert statement is `App\Catalog\Import\ItemUpsert::SQL`,
shared by every seeding command so the rules below can never diverge:

1. **Idempotent** — run the import twice, get identical rows (`updated_at`
   only bumps when content is `IS DISTINCT FROM` the incoming values;
   `imported_at` always refreshes).
2. **`state` is set only on INSERT, never on update** — a re-import can never
   knock a row back down (or up) the lifecycle.
3. **Curator-edit shield** — if *any* `change_history` row exists for the
   item (the marker that `ModerationService::applyEdit()` applied an approved
   edit), the upsert keeps the DB's `name`/`geom`/`country_code`/
   `subdivision_id`/`attributes` untouched and only refreshes `imported_at`.
   A re-harvest never clobbers a moderation-approved edit.

## 4. Lifecycle states (`App\Catalog\ItemState`)

| State | Meaning | Set by |
|---|---|---|
| `submitted` | Awaiting moderation | User contribution intake — **never** by import |
| `unverified` | Public but unconfirmed (pre-verification-gate) | Every imported/seeded row enters here |
| `verified` | Passed the community verification gate | Verification mechanics (edit-items/README.md funnel) |
| `rejected` | Moderation outcome | Moderation |
| `retired` | Removed from serving | **Curator decision only — never automatic** |

`ItemState::SERVED = [Unverified, Verified]` is the single constant defining
what the public ever sees; every serving query filters through
`ItemState::servedSqlTuple()`. `submitted`/`rejected`/`retired` are never
served.

**No auto-retire on re-harvest absence.** The harvest is capped/ranked, so
absence from a re-run can mean "fell below cap", not "deleted upstream".
`imported_at` records staleness; retirement is a curator decision. (The one
deliberate exception: `heat_point` rows with `source='auto'` are
delete-and-replaced wholesale each import — L has no lifecycle to protect.)

The same state enum is shared by `recommended_route`, but K's transitions run
a route-specific machine (Routes queue, ride-verification, retire-to-admit
cap) — route-domain.md owns it.

## 5. Provenance sources (`App\Catalog\ItemSource`)

How these families flow into the coverage cache vs the canonical store is
diagrammed in [osm-data-architecture.md §2](osm-data-architecture.md).

| Value | Meaning |
|---|---|
| `osm` | Harvested from OpenStreetMap (`source_ref` = `node/…` / `way/…`) |
| `pivot` | Géoportail Wallonie PIVOT (official Tourisme Wallonie accommodation) |
| `wikidata` | Wikidata-anchored rows (`source_ref` = `Q…`) |
| `user` | Rider contribution through the app |
| `manual` | Hand-authored/seeded row — see below |
| `auto` | Pipeline-derived (synthetic refs, e.g. `fx:surface:…`) |

**The `manual` seeding rule** (`SeedManualCatalogCommand`,
`app:catalog:seed-manual`): hand-authored demo/hero content is seeded as real
`source='manual'` item rows — treated exactly like rider contributions (never
touched by the harvest importer, served source-agnostically, editable and
moderatable), permanently distinguishable from harvested sources. Every seeded
row enters `state='unverified'` — never `verified`; verification is only ever
earned through the funnel. Seeding is idempotent (`source_ref =
manual:<stable-slug>`, same upsert as the importer) and collision-safe: a pin
is skipped when a non-manual row with the same `(name, letter)` already
exists. Corollary: **everything on the map is a real DB row** — no decorative
constants in templates or `map.js` (the single letter-F hazard pin is the
documented standing exception, since F has no serving path yet).

## 6. Region membership mechanics

Assigned by a deterministic containment rule, recomputed **from scratch on
every import run** (`ImportCatalogCommand::recomputeMembership()`): null out
`region_id` on `item` and `recommended_route`, then assign the containing
region — **smallest by `area_km2` first when regions overlap**, so membership
never depends on row order once regions multiply past the Wallonia seed
(region-scoping-design.md §3):

```sql
UPDATE item SET region_id = m.region_id FROM (
  SELECT DISTINCT ON (i.id) i.id AS item_id, r.id AS region_id
  FROM item i JOIN region r ON ST_Contains(r.geom, ST_PointOnSurface(i.geom))
  ORDER BY i.id, r.area_km2 ASC NULLS LAST, r.id ASC
) m WHERE item.id = m.item_id
```

The **same smallest-area-wins rule is shared by all four membership writers** —
`recomputeMembership`, `RegionResolver` (route intake),
`SeedManualCatalogCommand::recomputeMembership` (the `manual` hero pins,
catalog-data-model.md §5), and `pipeline/coverage/load.py` (the `coverage_poi`
stamp) — so a manual pin can never land in a different region than an
identically-located imported item (region-scoping-design.md §3). `ST_PointOnSurface` is guaranteed
on-geometry for points *and* lines, so a border-crossing segment gets exactly
one home region (the map still finds it from neighboring viewports via the GiST
index). Because membership is a recompute, regions can split/merge later without
touching item schema. Rider route proposals never pass the importer; intake
resolves `region_id` with the same rule (route-domain.md). `heat_point` carries
`region_id` too — never for moderation or voting (heat stays unmoderated), but
because the ride-heat layer scope-filters client-side like every served layer
(region-scoping-design.md §7, Phase-2 review finding 5); points are stamped in
the same `recomputeMembership` pass, `ST_Contains` on the point directly.

Ad-hoc spatial queries ("all items in an arbitrary polygon") need no region
row — GiST + `ST_Intersects` works day one.

## 7. `attributes`: registry-validated jsonb

**Only registry-declared keys may enter `attributes` — an unknown key is an
error at the front door, never a passthrough.** The allowlist per letter is
`App\Catalog\Import\AttributeVocabulary`:

- the letter's editable field names from `CatalogFormRegistry` (the same
  registry that derives forms, drawers, and review steps — edit-items/README.md);
- shared display keys (`AttributeVocabulary::COMMON`: `t`, `town`, `web`, `c`,
  `sim`, `r`, `desc`, `descTr`, `photo`, `photos`);
- a few per-letter fixture extras (`AttributeVocabulary::EXTRAS`), notably
  `B`'s `attribution` (the fixture's free-text citation — renamed because
  `source` is reserved for provenance; `CatalogProvider::climbs()` renames it
  back on serving) and `D`'s `serviceKind` (`shop`/`station`/`pump`,
  harvester/import-stamped, never a form field — see
  [osm-data-architecture.md](osm-data-architecture.md) §5).

`AttributeVocabulary::assertValid()` throws listing every unknown key; the
import transaction rolls back.

### The `JSON_PRESERVE_ZERO_FRACTION` gotcha

PHP's `json_encode()` drops the fraction from whole-number floats
(`87.0` → `87`), but the fixture/serving contract keeps whole-number floats as
floats. **Every code path that encodes catalog jsonb or the serving payload
must pass `JSON_PRESERVE_ZERO_FRACTION`** — currently
`ImportCatalogCommand`, `SeedManualCatalogCommand`, `BackfillAttributesCommand`,
`SurfaceProfiler`, `RouteProposalService`, `RideCheckService`, and
`CatalogProvider::json()`. A write path missing the flag silently corrupts
numeric attributes on the next re-encode.

## 8. Import pipeline

`app:catalog:import <dir>` (`App\Catalog\Command\ImportCatalogCommand`)
consumes the export artifacts produced by `tools/wallonia`
(`make wallonia-export`, strict-cache replay into `tools/wallonia/out/`).
Everything runs in **one transaction**, in this order:

1. **Regions first** (`region-*.geojson`, upsert by `slug`) — membership
   depends on them.
2. **Item layers** (`*.json` with `letter` + `features`): geometry-kind check,
   `serviceKind` fallback derivation for D, vocabulary validation, then the
   shared `ItemUpsert::SQL` with `state='unverified'`. Records missing or
   carrying a non-`ItemSource` `source`/`ref` fail the import
   (`resolveSourceRef()`).
3. **Routes** (`routes.json`): upsert by `(source, source_ref)`; state only on
   insert.
4. **Heat** (`heat.json`): `DELETE FROM heat_point WHERE source='auto'`, then
   bulk insert (chunks of 500, `ImportCatalogCommand::importHeat()`).
5. **Membership recompute** (catalog-data-model.md §6), then the derived
   route-surface profiles (`SurfaceProfiler::recomputeAll()`).

Batched raw DBAL throughout — the import loop never hydrates ORM entities.

Harvest-side rules that shape what arrives (toolchain:
`tools/wallonia/build_all.py`, `export.py`, `enrich.py`):

- **Harvested outputs are regenerated, never hand-edited.** Curated editorial
  choices (climb list, route seeds) are version-controlled *seed inputs*;
  geometry is always fetched by script.
- **E/H de-overlap**: the stays (E) and shelter (H) layers use disjoint OSM
  selectors (`build_all.py` `LAYERS`; the tag families are catalogued in
  osm-data-architecture.md §5). Features are
  deduped by OSM id within a layer and across provinces
  (`harvest_poi.dedupe()`); the identity triple deliberately *permits* one OSM
  entity to classify into two letters.
- **Per-province caps** (`cap_per_province` per layer in `build_all.py
  LAYERS`) rank-and-cap each layer — the reason absence from a re-harvest is
  not a deletion signal (catalog-data-model.md §4).
- **L is synthetic and illustrative**: heat points are sampled from the
  curated routes' own geometry (`routes.py`), never real ride data, never
  third-party ride platforms — and every UI surface labels it so
  (`d_faked_src` / `heatmap_hint` keys in `web/translations/messages.en.yaml`).

## 9. Serving: `GET /map/catalog.json`

`MapController::catalog()` (`web/src/Controller/MapController.php`) +
`App\Catalog\CatalogProvider`. The contract:

- **Public and cacheable**: `ETag` (md5 of the exact encoded bytes — the
  provider encodes once), `Cache-Control: public, max-age=3600`
  (`setMaxAge(3600)` in `MapController::catalog()`), conditional-request 304s.
- **States served: `unverified` + `verified` only** (via
  `ItemState::servedSqlTuple()`), for items and routes alike. Heat serves
  `source='auto'` rows only.
- **One payload, layers keyed by letter**, in the historical fixture shapes
  (`web/assets/map/catalog-load.js` assigns them to the legacy `window.CC_*`
  globals and injects `map.js`; on fetch failure the map still boots empty):

| Key | Shape | Notes |
|---|---|---|
| `A` | surface-segment list | `path` = [[lat,lng]…]; `wayId` only for `way/…` refs |
| `B` | climbs list | `geom.ll` = [lat,lng]; stored `attribution` served as `source` (citation) |
| `C`,`D`,`G`,`H`,`I`,`J` | GeoJSON FeatureCollection | properties = attributes + `n` (name) + `prov` (subdivision name) + `id` |
| `E` | `{osm, pivot}` | the only source-split letter: `pivot` rows are their own bucket; every other source lands in `osm` |
| `K` | routes list | includes raw `state` (map badges "proposed"), canonicalized `difficulty` and `bikeTypes` |
| `L` | `[[lat, lng, season], …]` | |
| `F` | **absent** | hazards have no serving path yet |

- Every served feature carries `srcType` (the raw `ItemSource` value) and
  `id` (the DB row id — the map edit-bridge's `?item=` target), so provenance
  renders uniformly across all layers and rider/manual contributions are
  legibly attributed.
- Explicitly the **named interim until vector tiles** (catalog-data-model.md
  §11).

The per-item public change log (`GET /map/item/{id}/history`, max-age 60) is
served read-only from `change_history` via `ChangeHistoryView`; its content
contract belongs to moderation-and-contribution.md.

### The exact-path PUBLIC_ACCESS 2FA-firewall gotcha

Every cacheable anonymous endpoint needs an **explicit exact-path
`PUBLIC_ACCESS` entry** in `web/config/packages/security.yaml`
`access_control` (e.g. `^/map/catalog\.json$`). "No rule matches" is not
enough: the scheb 2FA bundle's lazy-firewall `TwoFactorAccessListener` would
otherwise call `tokenStorage->getToken()`, forcing a session read on every
request, which makes `AbstractSessionListener` downgrade `Cache-Control` to
`private, must-revalidate` (see `TwoFactorAccessDecider::isPubliclyAccessible()`).
This repeats for **any** new cacheable endpoint — `/map/best-of`,
`/map/item/\d+/history`, `/routes/\d+.gpx`, and `/items/\d+/confirmations`
already carry the same entry for the same reason.

## 10. Per-source attribution rules

The platform-level licensing posture is owned by
[osm-data-architecture.md](osm-data-architecture.md) §3. The per-source rules
the catalog pipeline enforces:

| Source | Licence | Rule (as enforced) |
|---|---|---|
| OpenStreetMap | ODbL 1.0 | `© OpenStreetMap contributors · ODbL` on the map (`AttributionControl`, `web/assets/map/map.js`) and in every harvested `atlas/demo/*-osm.js` artifact header (the `tools/wallonia/out/*.json` import artifacts are headerless JSON and carry no attribution) |
| Wikidata | CC0 | used for notability ranking/identifiers; no attribution required (credited anyway) |
| Wikipedia | CC BY-SA 4.0 | short descriptions → `desc` (truncated at 260 chars, `enrich.py`); machine-translated ones flagged `descTr` |
| Wikimedia Commons | per-file | **every photo attribute structurally carries `credit` + `license` + `source`** (`enrich._photo()` always stamps all three); only free licences are ever attached (the `FREE` allowlist in `tools/wallonia/enrich.py`); the drawer renders credit (linked to the author's profile) + licence deed link + Commons file-page link |
| Géoportail Wallonie PIVOT | open data (CC-BY-compatible) | own `pivot` source + own `E` bucket, driving the Tourisme-Wallonie attribution branch in the stays merge |

Photos are the one real licence trap: a photo that cannot be licensed cleanly
is dropped, never guessed.

## 11. Scale commitments (contract, not advice)

1. **Reads are viewport-bbox + filter on indexed columns**; `attributes`
   jsonb is fetched per-row (drawer), never scanned in a WHERE.
2. **Hot-field promotion doctrine**: any attribute that ends up in
   `WHERE`/`ORDER BY` gets a `GENERATED ALWAYS AS (…) STORED` column + index,
   and the registry records the promotion. *No promotions exist yet* — the
   doctrine is the trigger condition.
3. **Partition-ready**: bigint identity keys everywhere; `country_code` and
   `letter` are the natural partition keys, activatable without redesign;
   `heat_point` and `change_history` are first in line. **Specified, pending
   implementation** (nothing is partitioned today).
4. **`/map/catalog.json` is interim**: serving moves to vector tiles when
   scale demands. **Specified, pending implementation.**
5. **World-scale ingestion is a Python ETL with set-based SQL upserts** —
   never an ORM loop (see the Python-vs-PHP boundary in dev-environment.md).

## 12. Bridge: this model vs. the OSM data architecture

[osm-data-architecture.md](osm-data-architecture.md) defines the target OSM
relationship (reference-don't-copy, materialize-on-edit, coverage provider).
The two documents coexist deliberately; here is the exact split.

**Superseded going forward** (do not extend these patterns):

- **Bulk harvest-and-copy of uncurated OSM into `item` rows.** The Wallonia
  import filled the canonical store with category-1 OSM objects; going
  forward, uncurated OSM is *cached coverage* (osm-data-architecture.md §5,
  coverage-provider.md) and enters the canonical store only via
  materialize-on-edit (osm-data-architecture.md §6). The existing Wallonia
  rows stay as the first concrete coverage-provider implementation until the
  pre-extract pipeline exists (osm-data-architecture.md §9).
- **The planned `osm_sync` re-harvest conflict queue** (curator resolves
  ours-vs-OSM field conflicts on re-import). With materialize-on-edit,
  uncurated objects are never our rows, so there is no re-harvest merge to
  arbitrate. Never built; do not build it.
- **Region-capped/ranked harvesting as a coverage strategy** — coverage
  becomes the fixed tag subset of osm-data-architecture.md §5, worldwide.

**Permanent** (survives the target architecture unchanged):

- The four-table schema, the one-generic-entity decision, and the
  product-semantic boundary rule (catalog-data-model.md §1–§2) — a
  materialized category-2 object is simply an `item` with `source='osm'` and
  `source_ref` as its `osm_ref`.
- The `(source, source_ref, letter)` identity, the idempotent upsert, and the
  curator-edit shield (catalog-data-model.md §3).
- The state enum and its semantics, including no-auto-retire
  (catalog-data-model.md §4), and the provenance enum (catalog-data-model.md §5).
- Region membership mechanics (catalog-data-model.md §6) — regions go
  worldwide before go-live, the mechanics don't change.
- Registry-validated `attributes` (catalog-data-model.md §7).
- The serving contract and its gotchas (catalog-data-model.md §9) — until
  vector tiles replace the endpoint, per the scale doctrine.
- The scale doctrine itself (catalog-data-model.md §11).
- Per-source attribution rules (catalog-data-model.md §10).

## Open questions

- **E/H `basic_hut` carve.** The original design routed
  `amenity=shelter` + `shelter_type=basic_hut` to E (sleepable); the current
  harvest selectors (`tools/wallonia/build_all.py`) implement de-overlap
  purely by disjoint selectors, with *all* `amenity=shelter` going to H. Is
  the basic-hut carve still intended for the coverage-provider tag subset?
- **Per-feature description source link.** The Wallonia design specified a
  per-feature `descSource` + link for Wikipedia (CC BY-SA) summaries; the
  implementation carries `desc`/`descTr` but no per-feature source link.
  CC BY-SA attribution for descriptions currently rests on the data-catalog
  level, not per feature.
- **Standalone licence lint.** "Fail the build if any photo lacks
  credit+license" was specified as an explicit lint step; today the invariant
  is enforced structurally (free-licence gate + always-stamped
  `credit`/`license`/`source` in `enrich.py`) with no independent lint pass
  that would catch a future regression in that structure.
- **F (hazards) serving path.** Letter F has intake designed but no serving
  path in `CatalogProvider`; the one demo hazard pin remains hardcoded in
  `map.js` (deferred, tracked in `docs/TODO.md`).
- **Known data issue**: a pre-existing OSM-vs-OSM duplicate ("Signal de
  Botrange", two `osm`-sourced rows) — a harvest-side dedupe gap within a
  single source, outside the manual-seeding collision guard.
