<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# OSM Data Architecture

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

This document defines how the Cycling Commons relates to OpenStreetMap (OSM):
what we cache, what we never republish, how OSM objects enter our database, how
we serve OSM-backed coverage cheaply, exactly which OSM data we use, and how OSM
tags map onto our item catalogue. It is the source of truth for anyone working
on data ingestion, the map, the contribution flow, or the public API.

---

## 1. Principles

1. **Store only what we add value to.** Our canonical database holds our own
   generated data and the curated *additions* we make to OSM objects — never a
   bulk copy of OSM as our records.
2. **Reference OSM objects; don't republish their data.** We identify OSM objects
   by their stable ref (`type/id`, e.g. `node/61146471`) and attach our
   enrichment to that ref. We *do* hold a serving cache of a defined OSM subset
   for our own display and search (§5) — but our **canonical dataset and public
   API** never republish OSM's tags or geometry as ours; they carry `osm_ref`s +
   our additions.
3. **Worldwide from day one.** The platform is global at launch. We do not
   pre-harvest the planet's POIs into our canonical store; coverage comes from
   the cache/OSM. Administrative *regions* (spatial buckets for queries) are
   generated ahead of go-live.
4. **Coverage is a narrow, well-defined subset — on both axes.** *Which objects*
   we cache is the fixed catalogue in §5. *Which tag keys we keep on those
   objects* is a separate, equally fixed list — the serve-set
   ([coverage-provider.md §2.1](coverage-provider.md)) — applied at parse time.
   Keeping both narrow is what makes ingestion, caching, and serving cheap; a
   cache that stores every tag of every catalogued object is a bulk OSM copy by
   omission, which principle 1 forbids.
   **We do not cache contact email addresses** (see §5).
5. **An OSM object only enters our canonical store when a human curates it** (§6).

## 2. The three data categories

| # | Category | Example | Where it lives |
|---|----------|---------|----------------|
| 1 | **Pure uncurated OSM** | A bike shop nobody has touched | Not in the canonical store. Served from our OSM serving cache (§5), which mirrors the OSM subset. |
| 2 | **OSM object + our curation** | Top Cycle, verified with a work-stand note | Canonical store holds `{osm_ref, our added fields, provenance, verification state}` — **not** OSM's tags/geometry. Merged with the cache at read time. |
| 3 | **Our own generated data** | Riders' routes, climbs, hazard reports, photos | Fully ours. Structured data in our DB; media in S3. |

Categories 2 and 3 are "curated" for display; category 1 is "community"
coverage. The join key between our data and OSM is always `osm_ref`.

How the source families flow into the two data planes as built
(provenance values: catalog-data-model.md §5; tiers: map-and-search.md §12):

```
 OpenStreetMap (ODbL)          official registries:         our own inputs:
   │                           Tourisme Wallonie PIVOT      riders (user) ·
   │ weekly per-region         (CC-BY) · Wikidata           curators (manual) ·
   │ Geofabrik extract                  │                   our tooling (auto)
   ▼                                    ▼                            │
 ┌──────────────────────────┐   ┌──────────────────────────────────────────┐
 │ COVERAGE PLANE — cache   │   │ CANONICAL STORE — the `item` table       │
 │ coverage_poi + PMTiles   │   │ source = pivot | wikidata | user |       │
 │ pure OSM subset (§5);    │   │          manual | auto | osm             │
 │ rebuilt weekly, never    │──▶│ `osm` rows = ref + OUR additions only    │
 │ edited, never canonical  │§6 │ (category 2), created the moment a       │
 └──────────────────────────┘   │ human curates a coverage object          │
   │      materialize-on-edit   └──────────────────────────────────────────┘
   │ PMTiles + /map/coverage/*     │ catalog.json + item endpoints
   ▼                               ▼
 community tier on the map      curated/verified tiers on the map
 (map-and-search.md §12)        (PIVOT = verified by registry provenance)
```

## 3. Licensing posture

Cycling Commons publishes under:

- **Data:** Open Database License 1.0 (ODbL) + Database Contents License 1.0 (DbCL).
- **Media (photos):** CC BY-SA 4.0.
- **Wiki prose** (`wiki/`, wiki.cyclingcommons.org): CC BY-SA 4.0 — per-page
  SPDX headers, pre-commit enforced.
- **Code:** PolyForm Shield 1.0.0 (source-available, non-compete).

Consequences for OSM handling:

- OSM is ODbL. **Because our data is also ODbL, ODbL's share-alike obligation
  costs us nothing** — we are ODbL on both sides, so there is no risk of
  "accidentally" relicensing our enrichment by touching OSM data.
- **We may serve OSM data** (ODbL permits redistribution) provided we attribute
  `© OpenStreetMap contributors` and keep it under ODbL. Serving OSM data is
  therefore *allowed*; whether the public API does it is an operational choice
  (§7), not a legal constraint.
- **Third-party media stays reference-not-ingest** where relevant: e.g. Mapillary
  imagery is CC BY-SA 4.0 (same as our photo pool) and may be embedded with
  attribution, but is never pushed into OSM (ODbL) — the incompatibility runs one
  way, toward OSM's database, not toward us.

## 4. What we cache vs. what we never republish

**Serving cache (our own use):** an ODbL-attributed copy of the OSM *subset* in
§5, held only to power map display and search. This is the one place we hold
OSM's data.

**Canonical dataset / public API:** `osm_ref` (for objects we have curated), our
added fields, provenance and verification state, and everything in category 3.
These **never** contain OSM's tags/geometry as ours.

**Never:** a bulk regional or global copy of OSM in the canonical store; OSM data
surfaced through the public API as our own.

## 5. Coverage provider and the OSM data we use

We must show OSM coverage (uncurated category-1 objects) on the map and in
search without hammering public infrastructure. The rule: **never call the
public Overpass API on a user request.**

**Approach — pre-extract, then serve from our own artefacts:**

1. On a schedule (weekly is sufficient — POIs change slowly), extract the tag
   subset below from an OSM planet/region PBF (`osmium tags-filter`, or one
   batched Overpass pull) in the `pipeline` service.
2. Load the result into a **POI index (PostGIS)** for spatial queries ("show all
   bike shops in town X") and **vector tiles (S3/CDN)** for map display.
3. User requests hit our tiles/index only — zero live Overpass load. The
   planet-wide subset measures ≈ 4.7 M points
   ([coverage-provider.md](coverage-provider.md) §10); per-region extracts
   stay small, so ingestion and serving remain cheap.

**Concrete implementation (shipped 2026-07-16).** The `pipeline` container runs
a weekly per-region batch (`pipeline/coverage/`, regions from
`COVERAGE_REGIONS`, v1 `europe/belgium`): Geofabrik PBF → `osmium tags-filter`
→ pyosmium → the **`coverage_poi`** PostGIS table (atomic per-region swap),
then tippecanoe builds **`coverage.pmtiles`** from the full index, go-pmtiles
verifies it, and the artifact + manifest upload to the Cycling Commons' **own
Hetzner Object Storage bucket** (`cc-maps`) — deliberately separate from any
shared basemap bucket so coverage cost stays observable
(coverage-provider.md §1). Symfony serves search / nearby /
counts / drawer detail from `coverage_poi` (`/map/coverage/*`); the map reads
the PMTiles by byte range. Selectors, letters, and the D `serviceKind` mapping
live in the shared contract file `pipeline/contract/coverage-contract.json`,
held in sync with `App\Catalog\ServiceKind` by cross-language tests. Full
design: [coverage-provider.md](coverage-provider.md).

### The complete OSM item catalogue

This is the authoritative, exhaustive list of OSM data we cache. Extending it is
a deliberate decision — every addition widens ingestion and the cache.

| Commons item | Kind | OSM selector | Notes |
|--------------|------|--------------|-------|
| A · Road surface | line | `highway=*` with `surface=*` | Corridor data, not POIs |
| C · Water & food | point | `amenity=drinking_water`, `drinking_water=yes`, `amenity=water_point`, `man_made=water_tap`, `shop=bakery` | Water sources + the bakery (the classic resupply stop; fills the "food" half of the letter). Cafés/restaurants deliberately excluded — too dense, low per-item signal |
| **D · Bike shop** | point | `shop=bicycle` | Staffed; real opening hours apply |
| **D · Self-service station** | point | `amenity=bicycle_repair_station` | Unmanned; **inherently 24/7** |
| **D · Public pump** | point | `amenity=compressed_air` | Unmanned; 24/7 |
| E · Where to sleep | point | `tourism=hotel/hostel/guest_house/chalet/camp_site/…` | |
| G · Getting there | point | `railway=station`, `railway=halt`, `amenity=ferry_terminal`, `route=ferry` | Ferries are route-critical crossings in this region. `route=ferry` ways reduce to the crossing midpoint (legitimately over water); the terminal is the land-side dock |
| H · Shelter | point | `shelter_type=picnic_shelter/weather_shelter/field_shelter/lean_to/basic_hut/gazebo/pavilion/rock_shelter/sun_shelter/wildlife_hide/dugout` (typed shelters only — bare `amenity=shelter` and `shelter_type=public_transport` bus stops stay out) | |
| I · Scenic views | point | `tourism=viewpoint`, `natural=peak`, `waterway=waterfall` | |
| J · History & culture | point | `historic=castle/fort/ruins/monument/memorial/…` | |

Item types **B · Climbs**, **F · Hazards**, and **K · Recommended routes** are
category-3 (our own data) and are **not** part of the OSM extract.

### What we keep *on* each cached object

The table above says which OSM **objects** we cache. It does not say which of
their **tags** we keep — a separate and equally deliberate list, because
`osmium tags-filter` selects objects, not keys, so a matching object arrives
carrying everything OSM has attached to it.

We store **29 tag keys**: the 11 selector keys above, the 14 keys the POI drawer
displays, and 4 provisional media/reference keys. Everything else is dropped
before it reaches our database. The full list, the rationale, and the tests that
enforce it are in
[coverage-provider.md §2.1](coverage-provider.md). Two consequences worth
knowing here:

- **We do not store contact email addresses** (`email`, `contact:email`). They
  are ~99 % redundant against the website and phone we do keep, and a sizeable
  share of them are private mailboxes rather than business role addresses.
  Keeping personal data that no view renders is liability without benefit, and
  the Commons dataset is meant to be non-personal. Riders reach a business via
  its website or phone.
- **The cache is the only OSM source at serve time.** No request path calls
  Overpass or the OSM API — including the POI drawer, which reads
  `coverage_poi` alone. So an untrimmed key is not "extra safety"; it is dead
  weight, and a trimmed one cannot be recovered without a re-harvest.

### Bike services — one type, three OSM kinds

Item type **D · Bike services** carries a `serviceKind ∈ {shop, station, pump}`
discriminator, derived from the OSM tag on ingest (and selectable on manual add
— deferred until an add-new-bike-service flow exists; today items enter only
via ingest or edits to existing items, which always carry a kind). The
distinction is a data fact, not just presentation:

- `shop` (`shop=bicycle`) — staffed; opening hours are meaningful; the
  `24/7 / See website / Unknown` field defaults to `Unknown`.
- `station` (`amenity=bicycle_repair_station`) / `pump`
  (`amenity=compressed_air`) — unmanned; **24/7 is the default assumption**.
  The edit form shows the same opening-hours field with `24/7` preselected —
  overridable, because some stations follow a host building's hours (e.g. a
  repair station inside a library). When no value is stored, the item card
  shows the assumed default as a read-only "Opening hours · 24/7" row; a
  stored value wins.

Each kind gets a distinct marker so riders see the difference; the
opening-hours default is kind-specific (`Unknown` for shops, `24/7` for
stations/pumps). Edit-flow consequences live in
[edit-items/D-bike-services.md](edit-items/D-bike-services.md).

## 6. Materialize-on-edit lifecycle

An OSM object crosses the coverage→canonical boundary **only when a human
curates it.** Until then it exists for us purely as cached coverage (category 1).

```
        (OSM coverage, cache only — not in canonical store)
                 │
   user edits via the improve form
                 │
                 ▼
        materialize into canonical store
     { osm_ref + submitted edit }   ── policy violation ──▶  trashed immediately
                 │                                            (no retention)
          moderation review
        (optional double-check)
             ┌───┴───┐
        approve     reject
             │         │
             ▼         ▼
      curated item   retained 3 months
      (category 2)   (dispute window)
                         │
                    GC deletes
```

Rules:

- **Materialize on first edit.** Submitting an edit for an uncurated OSM object
  copies the referenced object into the canonical store as `{osm_ref, edit}` and
  opens a submission. Nothing is materialized by mere viewing.
- **A confirmation is an edit** (owner decision 2026-08-12). A rider standing at
  an OSM tap answers one question - "drinkable?", "still here?" - and that answer
  is a claim about the place, so it materializes it exactly as the wizard would.
  `POST /osm/confirm` fills the letter's own form from the coverage row and the
  rider's stance, and posts it for them: same submission, same queue, same
  moderators, one tap instead of a form. The endpoint is the only shortcut - it
  approves nothing, and the item lands `submitted` like any other proposal.
  - The name comes from OSM when there is one, and from the layer's own label
    when there is not ("Water & food"), because the title is what a curator
    reads first.
  - **One item per `{osm_ref, letter}`.** A place already awaiting review is
    invisible from the drawer, so a second tap is answered `409 pending_review`
    and the rider is told what happened rather than invited to try again.
  - Confirmation tallies still live on the item (`item_confirmation`), never on
    an OSM ref. On approval the submitter's own stance is recorded form-sourced
    and uncounted, so the map never asks them the same question twice.
  - **Yes is not the only answer** (owner decision 2026-08-12). Alongside the
    letter's own question - drinkable / still here - the drawer offers *Out of
    order*, *Closed* and *Not there anymore*. Each writes the `condition` field
    the edit form itself offers, so a rider who wants to say more opens the form
    and finds their own answer already chosen rather than a second, contradictory
    record of it. *Out of order* appears only where something can break (water,
    bike services, toilets); a viewpoint cannot.
  - **`condition = 'Not there anymore'` removes a place from the map, without
    handing it back to OSM.** `CatalogProvider::itemRows()` stops drawing the
    item; `curatedRefs()` still claims its ref, so the coverage POI it was
    materialized from stays hidden. Both halves are the behaviour: without the
    first the report changes nothing, without the second the reference point
    reappears in the hole the item left. The row itself stays - a curator may
    disagree, and the report is a record either way.
- **Approve → stays** as a curated category-2 item.
- **Reject → retained 3 months**, then the garbage collector deletes it. The
  window exists to handle disputes: we keep the record long enough to review a
  challenge.
- **Policy-violating content is trashed immediately** — no retention window.
- **Optional double-moderator check** for safety-sensitive or contested items.

This lifecycle rides the existing submission / moderation / retention (Trash)
machinery; materialize-on-edit is only the trigger that pulls an object across
the boundary.

## 7. Public API and access terms

The public API defaults to **reference-only**:

- For a query range it returns the **`osm_ref`s** we hold coverage for, plus our
  **enriched fields** where we have them (category 2), and our own data
  (category 3) in full.
- It does **not** republish OSM's tags/geometry as ours. Consumers hydrate OSM
  detail from the `osm_ref` against OSM directly.

Reference-only is an **operational** default — freshness (OSM is more current
than any cache of ours), cost/bandwidth, and clean provenance — not a licence
requirement. Since we are ODbL, an **optional hydrated/merged endpoint** (our
data + the cached OSM subset, `© OpenStreetMap contributors`, ODbL) is permitted
and may be offered as a developer convenience. All OSM-derived responses carry
OSM attribution.

**Access terms.** Programmatic access to Commons data is **only** via the public
API. **Scraping** the site, tiles, or endpoints outside the API is **prohibited**
by the user terms. This governs the presentation layer, not the openness of the
data: our own data (ODbL) is provided openly *through the API* — that is the
sanctioned channel — and OSM-derived detail is obtained from OSM, so the
no-scraping rule does not restrict any ODbL right over the underlying open data.
The user terms must state both the API-only access rule and the scraping
prohibition.

## 8. Surfacing curated vs community to users

The map and search reach the **full Commons**: our curated/own data (categories
2–3) and OSM coverage (category 1), deduplicated by `osm_ref` so a curated OSM
object appears once, as curated. Presentation ranks **curated/verified first**
and marks category-1 records as **community** (lighter markers, a "community"
tag). The display toggle governs ambient map density, not what search can find.
The detailed presentation contract (ordering, markers, reveal behaviour) is
delegated to [map-and-search.md](map-and-search.md), which consumes this
document's data model unchanged, whether community records arrive from the
cache or from OSM.

## 9. Relationship to the current implementation

The initial Wallonia dataset harvested uncurated OSM into the `item` table and
inlined it for the map. That single-region harvest is **superseded** by this
architecture: uncurated OSM is cached coverage (§5), not canonical rows, and
enters the canonical store only via materialize-on-edit (§6). The interim
clause that let the harvest stand in for the coverage provider is **retired** — the pre-extract pipeline, the `coverage_poi` index, and the
PMTiles artifact are live and are the only serving path for uncurated OSM.
Harvested `item` rows no human ever touched are removed by
`app:coverage:retire-legacy` (dry-run report first; the destructive run is
owner-gated); anything with an edit, confirmation, or submission stays
canonical. `tools/wallonia` remains for the retired atlas demo and the
canonical seeds (climbs, routes, surface, PIVOT stays) — the coverage path
never touches Overpass again.

## 10. Before go-live

- Build the administrative **regions** (spatial buckets) worldwide.
- ~~Stand up the **coverage provider**~~ — done 2026-07-16: weekly per-region
  extract in the `pipeline` service → `coverage_poi` (PostGIS) +
  `coverage.pmtiles` on the CC bucket
  ([coverage-provider.md](coverage-provider.md)).
- ~~Implement **materialize-on-edit** against the cached coverage.~~ Done
  2026-07-30 (edit arm): the coverage drawer's edit link opens
  `/improve?ref=<node|way/id>&type=<slug>` — the same add wizard, name +
  location prefilled from the cached POI — and submit mints the item with
  `source_ref = <osm ref>` / `source = osm` (one item per ref, enforced at
  intake), so the §6 lifecycle and the coverage dedupe both engage.
  Confirm arm done 2026-08-12: `POST /osm/confirm` mints the same item from a
  single tap (§6), so a rider no longer has to fill a form to say the tap
  works. Confirmations still count only on the materialized item once it is
  approved and served - the tap proposes, a curator decides.
- ~~Split **D · Bike services** into `shop / station / pump` kinds.~~ Done —
  kind-selectable manual add remains deferred until an add-new flow exists (§5).
- Add the **API-only access** rule and **scraping prohibition** to the user terms.
- Commission a **licence review** confirming §3.
