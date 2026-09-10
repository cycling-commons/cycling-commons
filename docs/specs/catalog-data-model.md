<!-- SPDX-License-Identifier: AGPL-3.0-only -->

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
moderation machinery in moderation-and-contribution.md; the R (routes) domain
in route-domain.md; the map/search UX in map-and-search.md.

---

## 1. The four-table model

The whole map catalog lives in four tables (entities in
`web/src/Catalog/Entity/`; `region` created by `Version20260703152605`, the
other three by `Version20260703153611`):

| Table | Holds | Letters |
|---|---|---|
| `item` | Every atomic editable catalog feature | A–G, N–Q |
| `recommended_route` | Curated route compositions | R |
| `heat_point` | The computed ride-heat aggregate | none (derived layer) |
| `region` | Operational spatial buckets (moderation, voting, caps) | — |

**Letters renumbered 2026-08-25:** practical A–M, experiential N–Z. Old -> new:
B->N, C->B, E->O, F->E, G->F, H->G, I->P, J->Q, K->R, M->C; A and D unchanged.
Migration `Version20260825120000` rewrites `item`, `coverage_poi` and
`submission`; the coverage tile artifact must be republished after it (layer
names are `<letter>_<cc>`). The ride heatmap no longer has a letter (it was L).

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
- **Computed aggregate → `heat_point`** — the heat aggregate is *never* an item: no name, no
  lifecycle, no attributes, no region, never editable, never moderated.

The letter → geometry-kind mapping is enforced at import
(`ImportCatalogCommand::GEOMETRY_KIND`): `A` = LineString, every other letter = Point.
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
| `letter` | varchar(1) | catalog type A–G, N–Q (uppercased by the setter) |
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
| `imported_at` | timestamp nullable | last seen in an upstream export: every harvest that carries the row stamps it, on insert, update and the no-change pass (data-provider-hierarchy.md §6.7.6). A staleness signal only; there is **no auto-retire** (catalog-data-model.md §4) |
| `custody_reclaimed_at` | timestamp nullable | the provider's survey date that took custody of a verified row back from the riders, written only by the harvest (data-provider-hierarchy.md §6.7.2); NULL = never reclaimed. Custody is ours again from the first confirmation newer than it |

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
map-and-search.md §4.5 Phase-2 review finding 5) · `computed_at`.
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

**Invariants (map-and-search.md §4.5, review-enforced):**

- **Never delete a region row.** `moderator_area.region_id` is
  `ON DELETE CASCADE` (`Version20260714210000`), so deleting a region silently
  drops curator jurisdictions. Region lifecycle is **upsert-by-slug only**;
  geometry changes only via an explicit versioned re-import. Slug and ISO code
  are the stable identity across re-imports — boundaries may shift, the row
  endures.
- **`country_code` is required at import.** A region with no country is
  invisible to country-scoped curators (`ModerationScope` matches on
  `region.country_code`) — a silent jurisdiction hole. `importRegions` rejects
  an artifact that lacks it (map-and-search.md §4.5 risk 1).
- **Operating-level regions tessellate, never overlap.** The importer rejects
  an `ST_Overlaps` pair within one country; membership additionally resolves
  any overlap smallest-area-wins, so a bad row that slips through is still
  deterministic (catalog-data-model.md §6).

**Seeding playbook (map-and-search.md §4.5a — curator-demand-driven):**
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

The shield has exactly one escape hatch, for the case where a *corrected seed
definition* should beat the edit pinning the row - a re-measured geometry, say.
`app:catalog:seed-manual --overwrite-ref=<source_ref>` (repeatable) swaps in
`ItemUpsert::SQL_OVERWRITE_EDITED`, the same statement without the guard, for
the named refs only. It errors on a ref that matches no pin, so a typo can not
silently widen the blast radius, and it prints a warning naming every ref it is
about to overwrite. The `change_history` rows are left intact: the edit stays in
the audit trail, only the current content is replaced. No other seeding command
has this option, and there is no all-refs form.

4. **Measured climb values survive a re-seed while the line stands**
   (added 2026-08-16). `attributes` is also where `app:climbs:recompute
   --write` stores a climb's measurements, and the seeds deliberately type no
   numbers - so a plain attribute replacement un-measures every unshielded
   climb, which is exactly what a re-seed did on 2026-08-09 (four of the six
   Swiss passes, and once before that). Both SQL statements now carry the
   recompute-owned keys (`ItemUpsert::MEASURED_KEYS`: length, gain, footEle,
   summitEle, avgGradient, maxGradient, grad, lineGrad, demSource, binM,
   steepWindowM, steep) over from the existing row, but ONLY when the row is
   letter N and its stored `route` is byte-identical to the seeded one: a
   measurement is a claim about one specific line, and keeping it across a
   redraw is the Roche-aux-Faucons failure (stored numbers from a line that
   moved). A changed line drops the measurements, and `seed-manual` ends by
   naming every manual climb left without `lineGrad` and the recompute
   command to run - with the reminder that the recompute needs the elevation
   source up, because it writes zeros rather than erroring when Valhalla
   answers nulls. `MeasuredKeysTest` pins the key list against both SQL
   constants and against what the recompute actually writes;
   `SeedManualCatalogCommandTest` pins both halves of the route rule.

## 4. Lifecycle states (`App\Catalog\ItemState`)

| State | Meaning | Set by |
|---|---|---|
| `submitted` | Awaiting moderation | User contribution intake — **never** by import |
| `unverified` | Public but unconfirmed (pre-verification-gate) | Every imported/seeded row enters here |
| `verified` | Passed the community verification gate | Verification mechanics (edit-items/README.md funnel) |
| `rejected` | Moderation outcome | Moderation |
| `retired` | Removed from serving | **Curator decision only — never automatic** (a curator running `app:catalog:dedupe --write` after reading its dry run is that decision; see §5a) |

`ItemState::SERVED = [Unverified, Verified]` is the single constant defining
what the public ever sees; every serving query filters through
`ItemState::servedSqlTuple()`. `submitted`/`rejected`/`retired` are never
served.

**No auto-retire on re-harvest absence.** The harvest is capped/ranked, so
absence from a re-run can mean "fell below cap", not "deleted upstream".
`imported_at` records staleness; retirement is a curator decision. (The one
deliberate exception: `heat_point` rows with `source='auto'` are
delete-and-replaced wholesale each import — the heat aggregate has no lifecycle to protect.)

The same state enum is shared by `recommended_route`, but K's transitions run
a route-specific machine (Routes queue, ride-verification, retire-to-admit
cap) — route-domain.md owns it.

## 5. Provenance sources (`App\Catalog\ItemSource`)

How these families flow into the coverage cache vs the canonical store is
diagrammed in [osm-data-architecture.md §2](osm-data-architecture.md).

| Value | Meaning |
|---|---|
| `osm` | Harvested from OpenStreetMap (`source_ref` = `node/…` / `way/…`) |
| `authority` | A publisher of record for the thing mapped. Which one is `item.provider`, a row in `data_provider` ([data-provider-hierarchy.md](data-provider-hierarchy.md) §3). Today the only one is Géoportail Wallonie PIVOT, official Tourisme Wallonie accommodation |
| `wikidata` | Wikidata-anchored rows (`source_ref` = `Q…`) |
| `user` | Rider contribution through the app |
| `scout` | Rider ride-trace intake ([moderation-and-contribution.md](moderation-and-contribution.md), Scout intake). How it arrived, not verification: the server never saw the ride file |
| `manual` | Hand-authored/seeded row — see below |
| `auto` | Pipeline-derived (synthetic refs, e.g. `fx:surface:…`) |

`manual`, `user` and `scout` are the three the map treats as **rider** sources
(`RIDER_SOURCES` in `web/assets/map/i18n.js`); the rest keep their upstream
citation in the drawer.

!!! note "`pivot` became `authority` on 2026-09-04; the registry rank is still to come"
    The value was a bucket named after its first member. It is now
    `authority`, and every row in it points at a `data_provider` row
    ([data-provider-hierarchy.md](data-provider-hierarchy.md) §3, §10).
    The ladder below is unchanged: every authority still sits on the one
    rung `pivot` sat on. Ranking each provider from its own registry row
    (that document's §4) is specified and not built, and lands with the
    generic harvester.

**Keeper order**, used by the duplicate guard alone
(catalog-data-model.md §5a) and by nothing else:
`manual` > `user` > `scout` > `authority` > `wikidata` > `osm` > `auto`. The
authority is `App\Catalog\ItemSource::dedupeRank()`, which carries the reason
for each position; do not restate the numbers here, they would drift. It is not
a quality score and says nothing about a row's lifecycle state.

**Provenance is not media.** These values answer where a *place record* came
from. A photograph of that place is not a place, and third-party media (a
Wikimedia Commons file reached through an OSM `image` / `wikimedia_commons` tag
or a Wikidata `P18`) therefore takes none of these values. It is cached
media carrying its own per-file credit and licence, hung off the row it
illustrates. Filing it as a source value would mean materialising every
illustrated coverage POI into an `item` purely to hold a picture, against
[osm-data-architecture.md §6](osm-data-architecture.md); filing it as a rider
upload would restate its licence as CC BY-SA and push it through a moderation
queue with no submitter ([photo-uploads.md §6](photo-uploads.md)). Owner
question, 2026-08-24.

**The `manual` seeding rule** (`SeedManualCatalogCommand`,
`app:catalog:seed-manual`): hand-authored demo/hero content is seeded as real
`source='manual'` item rows — treated exactly like rider contributions (never
touched by the harvest importer, served source-agnostically, editable and
moderatable), permanently distinguishable from harvested sources. Every seeded
row enters `state='unverified'` — never `verified`; verification is only ever
earned through the funnel. Seeding is idempotent (`source_ref =
manual:<stable-slug>`, same upsert as the importer) and collision-safe through
the shared duplicate guard below. Corollary: **everything on the map is a real DB row** — no decorative
constants in templates or `map.js` (the single letter-E hazard pin is the
documented standing exception, since E has no serving path yet).

### 5a. One place, one row (2026-08-24)

**Why a guard is needed at all.** Read-time dedupe matches **by ref**
([coverage-provider.md §5](coverage-provider.md)). A PIVOT hotel row and an OSM
hotel row for one building have different refs by construction, so a ref-based
dedupe is *structurally* blind to them. Five such pairs were served as two pins
on one building, plus two `wikidata`/`osm` pairs and six same-source OSM twins.
The older guard could not have caught any of them: it lived in
`SeedManualCatalogCommand` alone, compared names as exact strings, and ignored
distance, so a namesake 8,000 km away blocked a legitimate pin while
"Saint-Roch" and "Saint Roch" passed each other unnoticed.

**The rule.** Two rows are the same place when all three hold:

1. same `letter`;
2. same **name key** — `App\Catalog\Import\NameKey::of()`;
3. within **250 m**, measured geometry to geometry (`DuplicateGuard::RADIUS_M`),
   so a climb's LINE is compared as a line rather than as its midpoint.

All three are load-bearing. Name alone merges three real "St Mary's Cathedral"
buildings on three continents; distance alone merges a cafe and the bike shop
next door; letter alone merges a climb with the viewpoint on its summit.

**The name key** reduces a name to what makes two rows the same dedication:
accents fold to ASCII, a trailing place qualifier is dropped at the first comma,
**separators** (hyphens, slashes, every width of dash) become a space and
**joiners** (apostrophes, abbreviation points) come off with nothing. So
`Saint-Roch` and `Saint Roch` agree while `Mary's` becomes `marys` and not
`mary s`. It is a comparison key and nothing else: never stored, never
displayed, never written back. `Ferme de l'Espinette` stays spelled exactly
that way.

It exists twice, in two languages — `NameKey::of()` and `normalise_name()` in
`tools/wikimedia/prescreen_seeded.py` — and both are pinned to
`tools/wikimedia/name_key_cases.json`, asserted from each side
(`NameKeyContractTest`, `test_name_key_contract.py`). Same arrangement, and the
same reason, as `coverage-contract.json`: two implementations of one rule drift,
and drift here means the import guard and the pre-screen report disagree about
what a duplicate is.

**Which row wins.** `ItemSource::dedupeRank()`:
`manual` > `user` > `scout` > `authority` > `wikidata` > `osm` > `auto`. It is not a
quality score and says nothing about lifecycle state; it answers one question,
"which of these two records of one place is ours to keep". Without it the winner
is whichever harvest happened to import first, which is how a canonical PIVOT
row loses to an OSM row.

**Two halves, and the split is deliberate.**

- `App\Catalog\Import\DuplicateGuard` runs inside every import path
  (`app:catalog:import`, `seed-manual`, `seed-climbs`, `seed-wikidata`; the
  one way OUT is `app:items:purge`, 2026-09-06: an item with its photos,
  submissions, checks and history, dry run by default, for test rows that
  reached the catalogue, never a moderation act) and
  **never writes**. It declines to ADD a second row, and names every skip with
  the id in the way, the distance, and — when the row being held out comes from
  a better source — the command that resolves it. A count alone would let a
  canonical row stay locked out for weeks while the import reported success.
- `app:catalog:dedupe` retires the losers among rows already in the database.
  Dry run by default; `--write` acts. **A row carrying curator edits is never
  retired**: that human work cannot be weighed against a source ranking, so the
  whole group is reported and left alone (the same shield `ItemUpsert` already
  applies against re-import overwrites, §3).

Only **served** rows count as a collision, which is what lets the two halves
compose: once a curator retires a weaker row it stops blocking, and the next
import admits the better one.

**On the no-auto-retire rule (§4).** Retirement stays a curator decision. A
curator reading a dry run and then passing `--write` *is* that decision; an
import running unattended at 04:00 is not, which is exactly why the guard can
only refuse to add and never remove. The §4 rule guards against inferring
deletion from *absence* in a capped harvest; a positively identified duplicate
is a different question, and it is still a human who answers it.

### 5b. OSM is the identity spine (2026-08-25)

Three columns in this codebase look like they answer "which real object is
this". Only one of them does, and confusing them has now produced the same
class of bug three times: a hotel served twice, a viewpoint served twice, and a
resolved duplicate that came straight back as a raw pin.

| Column | Owner | What it is | What it is NOT |
|---|---|---|---|
| `coverage_poi.ref` | the harvest | The OSM object itself, mirrored into our cache. `node/930340800` | ours to edit; the pipeline rewrites this table |
| `item.source_ref` | whichever pipeline wrote the row | That pipeline's **upsert key**, so a re-run updates instead of duplicating | an identity. A PIVOT row carries `fx:pivot:hotel-koru|ramillies` and a Wikidata row `wikidata:Q322824` |
| `item.osm_ref` | us | **The join key back to OSM**, whatever our own source is | a guess. NULL means "no OSM counterpart", never "not checked" |

**A coverage POI is the reference every higher layer points at.** It is not a
lesser copy of an item; it is the object. An item that records that same place
points at it, and that pointer is `osm_ref`.

**An item upgraded from a coverage POI always carries the ref it came from.**
Materialize-on-edit ([osm-data-architecture.md §6](osm-data-architecture.md))
copies the OSM object into the canonical store, so the resulting row records a
known OSM object by construction and there is nothing to infer. An `osm`-sourced
item whose `source_ref` is a `node/…` or `way/…` and whose `osm_ref` is NULL is
an unfinished row, not a row with no counterpart.

**Every other source has to be linked, and most of them cannot be inferred.**
`source_ref` cannot stand in: it is the writing pipeline's key and shares no
alphabet with an OSM ref. So the link is made three ways, in this order:

1. **Imports link as they write**, when they know the object.
2. **`app:catalog:link-osm`** backfills existing rows from `coverage_poi`, the
   mirror we already hold. Nothing is fetched from OSM. Three outcomes, and only
   the first writes:
   - **within `OsmLinker::TIGHT_M` (100 m; 50 until 2026-09-06, owner) with an
     identical name key**: linked automatically. Close enough and named the
     same is not a judgement call.
   - **100 m to `OsmLinker::LOOSE_M` (250 m)**: never written here. It becomes
     an `OsmLink` finding on the curator data desk.
   - **the OSM object is already claimed by another served row**: that is a
     duplicate wearing a link, so it goes to the desk as a duplicate rather than
     being welded together by a command.
3. **A curator decides the rest.** This is the one that needs saying out loud:
   **when a rider adds a place and does not link it to OSM, checking for a
   nearby OSM object and making the link is curator work, not an accident of
   geometry.** The desk exists so that question is asked about every unlinked
   row rather than only the convenient ones. Accepting an `OsmLink` finding
   writes `osm_ref` and nothing else (`ModerateDataController::apply()`).

**Read-time dedupe joins on identity, not on the upsert key.** A coverage row is
suppressed when a *served* item IS that object:

```sql
cp.ref IN (i.source_ref, i.osm_ref) AND i.state IN ('unverified','verified')
```

`source_ref` alone was the original rule and could never have worked for a
non-OSM source, since neither `fx:pivot:…` nor `wikidata:Q…` can equal
`node/…`. That mismatch is why one hotel appeared twice, once from the catalog
and once from the coverage cache.

**Resolving a duplicate transfers the identity.** The keeper is the highest
`ItemSource::dedupeRank()`, so the row retired is the lowest order, the one
closest to the primary source. The survivor **inherits the retired row's
`osm_ref`** (falling back to its `source_ref` when the retired row is
OSM-sourced), unless it already has one of its own.

Without that inheritance the operation is not deduplication. Retiring the OSM
twin makes it unserved, the join above stops suppressing its coverage POI, and
the raw OSM pin reappears beside the row the curator just kept: one duplicate
traded for another, and the place quietly loses its link to OpenStreetMap.
Owner, 2026-08-25: *"else it is not deduplication what we are doing"*.

#### The question has three answers, not two

`osm_ref IS NULL` cannot mean both "this place has no OSM counterpart" and
"nobody has looked yet", and today it means the second for every row. So the
answer is recorded separately:

| `osm_checked_at` | `osm_ref` | Meaning |
|---|---|---|
| NULL | NULL | **Nobody has asked.** The row is unfinished |
| set | `node/…` | Linked, by construction, by the linker, or by a curator |
| set | NULL | **Asked and answered: this place is not in OSM.** A real, deliberate answer |

It is written whenever the question is genuinely answered, and never as a side
effect of anything else:

- **materialize-on-edit**, answered by construction: the row exists *because* a
  rider edited a known OSM object, so both columns are set at creation;
- **`app:catalog:link-osm`** inside the tight band, answered by the machine;
- **a curator settling an `OsmLink` finding**: accepted sets the ref, dismissed
  records "no counterpart". Both are answers;
- **a curator approving a new rider place**: see the gate below.

#### The candidate list is stored, not asked (owner decision, 2026-08-25)

The chip on a queue card offers `OsmLinker::nearby()`: the coverage objects of
that letter within 250 m. Until 2026-08-25 the desk ran that query for every
open card on every list view, against two million coverage rows: the same
question with the same answer, 2.8 s per card before the geography index and
one spatial query per card after it ("that doesn't sound well engineered").
The answer changes only when the place moves or the country's coverage is
re-harvested, so it is computed once and stored on the row:

| Column | Meaning |
|---|---|
| `item.osm_candidates` (jsonb) | the list `{ref, name, distanceM}[]` the chip shows |
| `item.osm_candidates_at` | when it was computed; NULL with a NULL list = compute on next read |

`App\Catalog\Import\OsmCandidates` owns it: intake computes it when the row
is created (`refresh()`), an approved pin move recomputes it at the new point
(`refreshAt()`, ModerationService, answered or not: the old answer was about
the old spot) and, when the new point lies within `OsmLinker::ON_TOP_M` (15 m)
of an OSM object of its letter that no other served row claims, links the row
to it (`onTopOf()`, `answerOsm()`, a `change_history` row on `osmRef`). Owner
2026-09-05, after moving a nameless RIVM tap onto the spot of a deleted CC-row
and seeing the raw OSM drop come back beside it: "when location is changed it
must also look if it is now on top of an OSM spot. Not only for water but for
other types." The desk reads the list with (`forItems()`),
computing only where the column is NULL. The pipeline's per-region swap
(`load.py`, in the swap transaction, right after the delete-disappeared arm)
sets both columns back to NULL for every **open** row whose `country_code` is
among the slice's countries; answered rows keep whatever they hold, and no
harvest depends on the app's schema (`to_regclass('item')` guard). Net: zero
spatial queries per list view; one per row per harvest, on first read.

#### Approving a new place requires answering it (owner decision, 2026-08-25)

**A `NewItem` submission cannot be approved while `osm_checked_at` is NULL.**
The curator either links the OSM object or states there is none. Both are one
click; neither is a default.

This is a gate rather than a nudge because the alternative is what we have now.
The linker and the desk exist, they work, and **0 of 1,525 rows are linked**,
because every path to them is an operator command somebody has to remember to
run. A scan that fills a queue nobody is required to empty produces exactly this
outcome. Putting the question in the one flow a curator cannot skip is the only
placement that makes the answer certain rather than likely.

It is also the cheapest moment to ask. The curator is already looking at the
place, on the map, with its coordinates in front of them, and
`OsmLinker::candidateFor()` can offer the nearby objects without a single
outbound request, because `coverage_poi` is already the mirror. Asked later it
is archaeology; asked here it is a glance.

A rider is never asked. They are describing a place they stood next to, not
reconciling two databases.

**Scheduled, not remembered.** `app:catalog:link-osm` and
`app:catalog:findings` run on a schedule and their output is what the curator
data desk shows. The desk is the queue for everything the gate does not catch:
rows that existed before the gate, rows whose OSM counterpart appeared later,
and links that need a human because they fall in the 100 m to 250 m band.

**Built 2026-08-25** (plan `docs/plans/2026-08-25-osm-identity-spine.md`):
materialisation answers by construction, `Version20260825110000` added
`osm_checked_at` and backfilled the 775 by-construction rows, the approval gate
refuses an unanswered new place (`OsmUnansweredException`; the queue row carries
an OSM chip: `OSM?` pops the `OsmLinker::nearby()` candidates and "Not in OSM",
and once answered the chip reads the linked ref or "not in OSM", so an answered
row no longer looks like an unasked one, 2026-08-25), and every writer
(`app:catalog:link-osm`, the data desk's accept AND dismiss, duplicate
inheritance) records the answer. Still an operator's job: installing the two
timers `operations.md` §1 now specifies, and running the first
`app:catalog:link-osm --write` over the pre-gate backlog.

**`osm_ref` is deliberately not UNIQUE.** The duplicates have to be cleared
first, or the constraint is a migration that cannot run. Two served rows sharing
one `osm_ref` is a duplicate by definition and is the desk's business.

## 6. Region membership mechanics

Assigned by a deterministic containment rule, recomputed **from scratch on
every import run** (`ImportCatalogCommand::recomputeMembership()`): null out
`region_id` on `item` and `recommended_route`, then assign the containing
region — **smallest by `area_km2` first when regions overlap**, so membership
never depends on row order once regions multiply past the Wallonia seed
(map-and-search.md §4.5):

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
identically-located imported item (map-and-search.md §4.5). `ST_PointOnSurface` is guaranteed
on-geometry for points *and* lines, so a border-crossing segment gets exactly
one home region (the map still finds it from neighboring viewports via the GiST
index). Because membership is a recompute, regions can split/merge later without
touching item schema. Rider route proposals never pass the importer; intake
resolves `region_id` with the same rule (route-domain.md). `heat_point` carries
`region_id` too — never for moderation or voting (heat stays unmoderated), but
because the ride-heat layer scope-filters client-side like every served layer
(map-and-search.md §4.5, Phase-2 review finding 5); points are stamped in
the same `recomputeMembership` pass, `ST_Contains` on the point directly.

Ad-hoc spatial queries ("all items in an arbitrary polygon") need no region
row — GiST + `ST_Intersects` works day one.

**Rider base-area re-derivation rides the same import transaction
(map-and-search.md §4.5 Phase 4).** `ImportCatalogCommand` calls
`App\Service\BaseLocationService::rederiveAll()` immediately after
`recomputeMembership()`, inside the same transaction: every rider with a
stored base point (`users.base_point`) gets a fresh `base_region_ids`/
`base_country_codes` from `App\Service\BaseAreaResolver`, so a My-area scope
never drifts stale against region geometry that just changed in this same
import run. There is no queue in this app, so re-derivation is
transactional-inline by design, not deferred to a worker — the per-rider loop
runs at import time, not on read.

## 7. `attributes`: registry-validated jsonb

**Only registry-declared keys may enter `attributes` — an unknown key is an
error at the front door, never a passthrough.** The allowlist per letter is
`App\Catalog\Import\AttributeVocabulary`:

- the letter's editable field names from `CatalogFormRegistry` (the same
  registry that derives forms, drawers, and review steps — edit-items/README.md);
- shared display keys (`AttributeVocabulary::COMMON`: `t`, `town`, `web`, `c`,
  `sim`, `r`, `desc`, `descTr`, `photo`, `photos`, `links`);
- a few per-letter fixture extras (`AttributeVocabulary::EXTRAS`), notably
  `N`'s `attribution` (the fixture's free-text citation — renamed because
  `source` is reserved for provenance; `CatalogProvider::climbs()` renames it
  back on serving) and `D`'s `serviceKind` (`shop`/`station`/`pump`,
  harvester/import-stamped, never a form field — see
  [osm-data-architecture.md](osm-data-architecture.md) §5).

`AttributeVocabulary::assertValid()` throws listing every unknown key; the
import transaction rolls back.

### `links` — outbound pointers to the pages that describe a place (2026-08-16)

A castle, a hotel, a city: the item is a short entry about a place other
people have written whole pages about, and `links` points OUT at them. Two
levels, deliberately (owner scope 2026-08-14: several different SITES, each
possibly in several languages):

```
links: [ { label?, urls: [ { url, locale? }, … ] }, … ]
```

One entry per DESTINATION in display order; the urls inside an entry are the
same page in different languages; a locale-less url is the entry's default.
`App\Catalog\Import\OutboundLinks::assertValid()` gates every write path:
https only (these values end in `<a href>` on the public map), at most 4
destinations, 6 language variants each, and the same host may hold at most 2
entries - the caps ARE the anti-spam design, cheaper than moderating an
advert afterwards. The drawer half is `web/assets/map/links.js`
(`itemLinks()`): every entry renders, resolved to the reader's locale
(exact → locale-less default → `en` → first), showing the bare domain beside
the label, with `rel="noopener noreferrer nofollow"` on every record-row
link (nofollow also kills the SEO incentive for submitting links at all).

**ONE storage slot per fact (owner 2026-08-16).** The official website is
NOT a links entry: it lives in the editable `web` attribute - the same slot
the OSM harvest and the wizard's Website/Official-site field use - and
`OutboundLinks` REFUSES an entry labelled "Official site", because two
fields for one fact means the rider can only edit one of them. `links`
carries the OTHER destinations.

**The free first fill**: `tools/wikimedia/item_links.py` reads every
wikidata-seeded row's Q-id and writes the reviewable
`tools/wikimedia/out/item-links.json` as `{ref: {web?, links?}}`: the
official website (P856) destined for `web`, the Wikipedia sitelinks as one
links entry. `app:items:import-links` loads it, matched by the STORED
`source_ref` (two shapes exist in the wild): `links` replaces, `web` fills
ONLY when empty (a harvested or rider-typed value wins over Wikidata's
claim), and rows with an approved curator edit are skipped entirely.

Pinned by `OutboundLinksTest`, `ImportItemLinksCommandTest` and
`web/tests/js/links.test.mjs`.

#### The rest of it, specified (2026-08-16)

The shipped layers are the cheap ones - https-only, caps, same-host rule,
bare-domain display, nofollow - applied first as the item designed. Three
pieces remain, and this is what each has to be. **They are independent: the
editor can ship without the reputation layers and vice versa**, which is the
reason to write them separately rather than as one "finish links" task.

**1. The wizard's repeatable multi-locale editor — BUILT 2026-08-16.** What
follows was the design; it shipped as written, on I and J. `FieldKind::Links`
renders one hidden JSON field marked `data-links-editor`, `links-editor.js`
builds the control beside it, and `CatalogContributionService::decodeLinks()`
parses it **before the change diff** - after it, a save that touched nothing
would compare a JSON string to a stored array, report a change every time, and
send a curator work that does not exist. `OutboundLinks::assertValid()` gates
it, because the browser's caps are a courtesy and the wizard must not be the
one door that skips the rule. An emptied editor posts `''` and normalises to
null, so taking a link down is a real edit. The validator's message is NOT
echoed back: every one of its rules is already enforced in the browser, so a
rider can only reach it by posting by hand, and that is the case where naming
internals is a favour to the wrong person. Pinned by
`OutboundLinksEditorTest`. Design as written:

Today `links` can only arrive from the importer. The wizard needs a field that
matches the two-level shape without teaching a rider the words "entry" and
"variant".

- **A new `FieldKind::Links`**, not a reuse. Every existing kind renders one
  input for one scalar (`MultiSelect` is the closest and it is still one
  control over a fixed vocabulary); this is a nested repeatable over free text,
  and the registry's per-kind `switch` in `ImproveType` is where the difference
  belongs rather than inside a `Text` field that behaves unlike every other
  `Text` field.
- **Shape on the wire: one hidden JSON input**, written by the editor script
  and parsed server-side, exactly as the climb `route` and the segment shapes
  already travel. A `links[0][urls][1][url]` name grid would put the nesting in
  the HTTP layer where PHP's array parsing, not `OutboundLinks`, would decide
  what a malformed post means.
- **The server never trusts it.** `OutboundLinks::assertValid()` already gates
  every write path and MUST gate this one - the caps are the anti-spam design,
  and a client-side cap is a suggestion. A post that fails validation returns
  the form with the rider's input intact and the specific rule named ("at most
  4 places to link to"), never a silently truncated list.
- **What the rider sees**: a list of *places to link to*, each with a label and
  one url; "add another language" reveals a per-locale row inside that entry.
  Locale defaults to the wizard's own locale, and the locale-less slot is
  offered as "any language", which is what it means at render time.
- **Counts come from the constants**, not from copy: `MAX_ENTRIES`,
  `MAX_URLS_PER_ENTRY` and `MAX_LABEL_LENGTH` render into the field's help text
  and its `maxlength`, so raising a cap is one edit rather than three.
- **"Official site" stays refused** at the field as well as at the gate, with
  the reason said once ("the official site has its own field above"), or the
  rider meets a validation error for obeying the form.

**2. Google Safe Browsing - BUILT, all four call sites (2026-08-16).**
`App\Catalog\Links\SafeBrowsing` is the reusable core: batched Lookup-API
calls, a three-state verdict (`safe`/`unsafe`/`unknown`) so an outage can never
read as clean, a suffix-matched host allowlist that a look-alike domain cannot
spoof, `worst()` for the queue card, and OFF with an empty `SAFE_BROWSING_KEY`
rather than quietly passing everything. Pinned by `SafeBrowsingTest`.

**The verdict lives in its own table, keyed by url** (`link_verdict`,
`App\Catalog\Links\LinkVerdictStore`, `Version20260816220000`), and the
alternative was refused for a concrete reason: a verdict written inside the
`links` attribute would flow through the wizard's change diff and surface on a
moderation card as a rider-made edit, manufacturing curator work out of a
background check. Keying by url also makes the fact shared by every item
pointing at the same page, which is what turns the scheduled re-check into ONE
sweep over distinct urls rather than one per item. The primary key is a sha256
of the url, because a btree key over unbounded TEXT has a size limit a long url
could cross - and a rider's save must never be lost to an index detail.

The four call sites:

| where | direction | what it does |
|---|---|---|
| **submit** (`CatalogContributionService::checkLinks`) | fail OPEN, never blocks | asks, records whatever comes back, and swallows every error: nothing about a background check may cost a rider their save |
| **the queue card** (`SubmissionQueue::linkFlags`, `linkFlag` on the row) | flag, never reject | shows the worst verdict for the links THIS submission proposes, `unsafe` and `unknown` differently, on both the desk template and the map drawer |
| **the map payload** (`CatalogProvider::decode()`) | fail CLOSED | strips a url whose last verdict is `unsafe` out of `links` before it can reach an `<a href>`; an entry left with no urls disappears. One chokepoint, because a withhold any forgotten path could skip is not a withhold |
| **the sweep** (`app:links:recheck`, beside the GC timers) | - | re-asks about verdicts older than a week AND about urls in served items that were never checked at all; a no-op when the key is empty, so nothing is ever stamped as if it had been checked |

`SAFE_BROWSING_KEY` is on the deploy-prerequisites list (operations.md §3).

Design as written:

Today `links` can only arrive from the importer. The wizard needs a field that
matches the two-level shape without teaching a rider the words "entry" and
"variant".

- **A new `FieldKind::Links`**, not a reuse. Every existing kind renders one
  input for one scalar (`MultiSelect` is the closest and it is still one
  control over a fixed vocabulary); this is a nested repeatable over free text,
  and the registry's per-kind `switch` in `ImproveType` is where the difference
  belongs rather than inside a `Text` field that behaves unlike every other
  `Text` field.
- **Shape on the wire: one hidden JSON input**, written by the editor script
  and parsed server-side, exactly as the climb `route` and the segment shapes
  already travel. A `links[0][urls][1][url]` name grid would put the nesting in
  the HTTP layer where PHP's array parsing, not `OutboundLinks`, would decide
  what a malformed post means.
- **The server never trusts it.** `OutboundLinks::assertValid()` already gates
  every write path and MUST gate this one - the caps are the anti-spam design,
  and a client-side cap is a suggestion. A post that fails validation returns
  the form with the rider's input intact and the specific rule named ("at most
  4 places to link to"), never a silently truncated list.
- **What the rider sees**: a list of *places to link to*, each with a label and
  one url; "add another language" reveals a per-locale row inside that entry.
  Locale defaults to the wizard's own locale, and the locale-less slot is
  offered as "any language", which is what it means at render time.
- **Counts come from the constants**, not from copy: `MAX_ENTRIES`,
  `MAX_URLS_PER_ENTRY` and `MAX_LABEL_LENGTH` render into the field's help text
  and its `maxlength`, so raising a cap is one edit rather than three.
- **"Official site" stays refused** at the field as well as at the gate, with
  the reason said once ("the official site has its own field above"), or the
  rider meets a validation error for obeying the form.

**2. Google Safe Browsing — THE CLIENT IS BUILT (2026-08-16), the two call
sites are not.** `App\Catalog\Links\SafeBrowsing` is the reusable core:
batched Lookup-API calls, a three-state verdict (`safe`/`unsafe`/`unknown`) so
an outage can never read as clean, a suffix-matched host allowlist that a
look-alike domain cannot spoof, `worst()` for the queue card, and OFF with an
empty `SAFE_BROWSING_KEY` rather than quietly passing everything. Pinned by
`SafeBrowsingTest`.

**What is left is a STORAGE decision, and building it wrongly would be worse
than not having it.** The verdict has to be cached, and it must NOT live inside
the `links` attribute: `links` flows through the wizard's change diff, so a
verdict written there would surface as a rider-made edit on a moderation card
and manufacture curator work out of a background check. The two candidates,
both real:

  - a `link_verdict` table keyed by url, with its timestamp - shared across
    every item that points at the same page, which is also what makes the
    scheduled re-check one sweep rather than one per item; or
  - a sibling attribute (`linksChecked`) excluded from the diff by name - fewer
    moving parts, but it re-checks the same Wikipedia url once per item and
    puts an exclusion rule inside a generic loop.

The first is recommended. Either way the call sites are then: **submit** writes
the verdict and never blocks (fail open), the **moderation card** reads it, the
**map payload** withholds a url whose last verdict is `unsafe` (fail closed,
`CatalogProvider::feature()`), and a scheduled sweep beside the GC timers is
what makes "again at render" true over time.

Design as written:

**2. Google Safe Browsing, at submit AND at render.**

Both, and the pair is the point: a URL that was clean when it was submitted is
exactly how a link farm gets past a one-time check, and a check only at render
lets a hostile URL sit in the moderation queue where a curator clicks it first.

- **Flag, never silently reject** (the item's own rule). A flagged submission
  still reaches the queue, carrying the verdict on the card, because a false
  positive that vanishes is indistinguishable from a bug.
- **Fail OPEN at submit, fail CLOSED at render.** They are different questions.
  A rider must not lose their contribution because a Google endpoint is down,
  so an unreachable lookup at submit stores the link and marks the verdict
  unknown for the curator. On the public map the same unknown must not become a
  clean bill of health - a link whose last verdict was "unsafe" is withheld
  until a later check clears it. This asymmetry is deliberate and is the one
  thing to get right.
- **Cache the verdict**, with its timestamp, so the render check is a stored
  read rather than an outbound call in a rider's hot path. Built as a table
  keyed by url, for the reasons above. A scheduled re-check (operations.md §1,
  beside the GC timers) is what makes "again at render" true over time; the
  render path itself never blocks on Google.
- **An allowlist skips the friction** for wikipedia.org and the official
  tourism domains, because those are the links the importer produces in bulk
  and re-checking them is spending a quota on a known answer.
- **The API key is an env var with no safe default** and belongs in the
  deploy-prerequisites list; absent, the layer is OFF and says so at boot
  rather than silently passing everything.

**3. urlscan.io preview on the queue card.**

The look-without-visiting option, and it is worth building only after (2): a
screenshot is a moderator convenience, whereas the reputation list is the thing
that stops a bad click.

- **A stored screenshot reference on the submission**, fetched by the worker
  tier (media-storage-architecture.md) when a submission carrying links is
  queued - never fetched by the moderation page, or the desk's render time
  depends on a third party.
- **The image is proxied, never hot-linked**: an `<img>` pointing at urlscan
  would tell them which of our moderators is looking at what, and it is one
  more CSP host.
- **Local antivirus is the wrong tool here** and is not part of this: it scans
  files, not pages. The reputation-list + sandboxed-preview pair is the
  applicable one, which is why `ClamAvScanner` (photos) and this share nothing.

### `condition` — the one field that removes a place

Every confirmable point type carries `condition`
(`CatalogFormRegistry::CONDITION`: *As mapped · Out of order · Closed · Not
there anymore*), added 2026-08-12 so a rider can say a place has stopped being
what the map says it is. The map's one-tap answers and the edit form write the
same key with the same vocabulary, which is what keeps a tap and a form edit
from becoming two different records of one fact
([moderation-and-contribution.md](moderation-and-contribution.md) §10.4).

**One vocabulary, two menu lengths.** *Out of order* needs working parts. A
tap, a pump and a toilet have them; a viewpoint, a shelter, a monument and a
station platform do not, so those four types offer
`CatalogFormRegistry::CONDITION_NO_PARTS` (*As mapped · Closed · Not there
anymore*) instead. This is a narrower menu, never a second vocabulary: the
values are identical, so a tap and a form edit still cannot disagree about what
"gone" is called. `ItemType::canBreak()` is the server's single source of
truth, used both to pick the menu and to refuse the stance at the confirm
endpoint; the client mirrors it as `CC_BREAKABLE` (community.js). The client
had drawn this line since 2026-08-12 while the wizard had not, so the form
offered a rider standing at a viewpoint the chance to call the view broken
(owner-reported 2026-08-14). Keep the three in step.

**No default.** A default would make every untouched edit form assert "as
mapped" about a place its editor never looked at, and turn a no-op edit into a
change the intake refuses. Silence means nobody has said.

`condition = 'Not there anymore'` is the only attribute value that changes what
is served. One predicate, `App\Catalog\GoneRows::notGoneSql()`, is shared by
every served read: `CatalogProvider::itemRows()` drops the row from the payload,
`PublicItemsProvider` from the API, and `CoverageRepository::search()` and
`nearby()` from their curated arm (coverage-provider.md §5), while
`curatedRefs()` still claims its `source_ref`, so the coverage POI it was
materialized from stays hidden too. A place reported gone leaves the map without
handing itself back to the reference layer, and the row stays in the table
because a curator may disagree (the curator ghost layer, map-and-search.md §5).

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
- **O/G de-overlap**: the stays (O) and shelter (G) layers use disjoint OSM
  selectors (`build_all.py` `LAYERS`; the tag families are catalogued in
  osm-data-architecture.md §5). Features are
  deduped by OSM id within a layer and across provinces
  (`harvest_poi.dedupe()`); the identity triple deliberately *permits* one OSM
  entity to classify into two letters.
- **Per-province caps** (`cap_per_province` per layer in `build_all.py
  LAYERS`) rank-and-cap each layer — the reason absence from a re-harvest is
  not a deletion signal (catalog-data-model.md §4).
- **The heat layer is synthetic and illustrative**: heat points are sampled from the
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
  `source='auto'` rows only. Items carrying `condition = 'Not there anymore'`
  are dropped from the feature collections while keeping their ref in `refs`
  (§7).
- **One item per `(source, source_ref, letter)`, including rejected rows.** The
  unique key does not care about state, so a rejected materialization still
  holds its OSM ref: proposing that place again REVIVES the row (back to
  `submitted`, carrying the new proposal) rather than minting a twin, which used
  to raise a constraint violation in the rider's face
  (`CatalogContributionService::submitDraft()`, owner-reported 2026-08-12). A
  rejection is a decision about one report, not a life sentence on a place — and
  the surviving row is what lets a curator see the earlier decision they are
  overturning.
- **One payload, layers keyed by letter**, in the historical fixture shapes
  (`web/assets/map/catalog-load.js` assigns them to the legacy `window.CC_*`
  globals and injects `map.js`; on fetch failure the map still boots empty):

| Key | Shape | Notes |
|---|---|---|
| `A` | surface-segment list | `path` = [[lat,lng]…]; `wayId` only for `way/…` refs |
| `N` | climbs list | `geom.ll` = [lat,lng]; stored `attribution` served as `source` (citation) |
| `B`,`C`,`D`,`E`,`F`,`G`,`P`,`Q` | GeoJSON FeatureCollection | properties = attributes + `n` (name) + `prov` (subdivision name) + `id` |
| `O` | `{osm, authority}` | the only source-split letter: an authority's rows are their own bucket, because they carry their publisher's citation and licence; every other source lands in `osm` |
| `R` | routes list | includes raw `state` (map badges "proposed"), canonicalized `difficulty` and `bikeTypes` |
| `refs` | `["node/123", …]` | source_ref of every served `source='osm'` item, so the client can drop the coverage-tile twin (osm-data-architecture.md §8) |
| heat | **absent** | the ride heatmap (no letter) moved to its own endpoint on 2026-08-09 (below) |

`E` (hazards) joined the payload on 2026-07-21 and `C` (public toilets) on
2026-07-30; both are ordinary served-item collections, region-stamped like every
other letter.

**The heatmap is not in this payload.** The ~6,600 heat points serve from
`GET /map/heat.json` (`MapController::heat()`), on the same public/ETag/max-age
discipline, fetched only when a rider first switches the heatmap on. The layer
is off by default, so on the catalog path every visitor was paying its bytes for
something most of them never turn on (frontend review 2026-08-09).

- Every served feature carries `srcType` (the raw `ItemSource` value) and
  `id` (the DB row id — the map edit-bridge's `?item=` target), so provenance
  renders uniformly across all layers and rider/manual contributions are
  legibly attributed.
- Explicitly the **named interim until vector tiles** (catalog-data-model.md
  §11).

### No server-side memo, and why (decided 2026-08-09)

`CatalogProvider::json()` rebuilds the payload on every request. The body is
user-independent, so it is the same answer every time and an obvious memo
candidate. It was attempted and reverted, and the decision is **not to memoise
it yet**. Measured on the dev catalog (1,768 items): **58 ms median** — 34 ms
building, 25 ms encoding, 1.3 ms hashing — for 958 kB raw / 223 kB transferred.
With `max-age=3600` and a content ETag in front of it, that is once per visitor
per hour.

Two things a future attempt must know, because both cost a session to find:

- **A `MAX(updated_at)` content stamp does not work, and raising the column to
  `timestamp(6)` does not fix it.** Doctrine writes `'Y-m-d H:i:s'` whatever the
  column can hold (`AbstractPlatform::getDateTimeFormatString()`, not overridden
  by PostgreSQL), so two edits in the same second share a stamp and the memo
  serves the older payload — silently. No row in the three tables carries a
  sub-second component today.
- **The mechanism that works is a counter the database bumps itself**: a
  one-row `catalog_stamp` table with `AFTER INSERT OR UPDATE OR DELETE … FOR
  EACH STATEMENT` triggers on every table `payload()` reads. Collision-proof,
  fires for raw SQL as well as the ORM, one primary-key read. Read the stamp
  *before* building the payload, and keep a source-reading guard test asserting
  each table `payload()` queries carries a trigger.

Revisit when `/map/catalog.json` is a measurable share of request time under
real traffic, or the payload grows well past ~1 MB. Try `proxy_cache` on the
frontends before application code.

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
| Géoportail Wallonie PIVOT | open data (CC-BY-compatible) | `authority` source + own `O` bucket, driving the Tourisme-Wallonie attribution branch in the stays merge |

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

- **O/G `basic_hut` carve.** The original design routed
  `amenity=shelter` + `shelter_type=basic_hut` to O (sleepable); the current
  harvest selectors (`tools/wallonia/build_all.py`) implement de-overlap
  purely by disjoint selectors, with *all* `amenity=shelter` going to G. Is
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
- **E (hazards) serving path.** Letter E has intake designed but no serving
  path in `CatalogProvider`; the one demo hazard pin remains hardcoded in
  `map.js` (deferred, tracked in `docs/TODO.md`).
- ~~**Known data issue**: a pre-existing OSM-vs-OSM duplicate ("Signal de
  Botrange", two `osm`-sourced rows) — a harvest-side dedupe gap within a
  single source, outside the manual-seeding collision guard.~~ **Answered
  2026-08-24 (§5a).** A full scan found it was not one case but 14 groups, and
  not only same-source: seven were cross-source, which the old guard could not
  have seen at all. `DuplicateGuard` stops new ones and `app:catalog:dedupe`
  clears the existing ones.
