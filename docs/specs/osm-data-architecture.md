<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# OSM Data Architecture

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

This document defines how the Cycling Commons relates to OpenStreetMap (OSM):
what we store, what we never store, how OSM objects enter our database, how we
serve OSM-backed coverage cheaply, and how OSM tags map onto our item catalogue.
It is the source of truth for anyone working on data ingestion, the map, the
contribution flow, or the public API.

---

## 1. Principles

1. **Store only what we add value to.** Our database holds our own generated data
   and the curated *additions* we make to OSM objects — never a bulk copy of OSM.
2. **Reference OSM objects; do not copy their data.** We identify OSM objects by
   their stable ref (`type/id`, e.g. `node/61146471`). We attach our enrichment
   to that ref. OSM's own tags and geometry are fetched from OSM (live or from a
   serving cache), not treated as ours.
3. **Worldwide from day one.** The platform is global at launch. We do not
   pre-harvest the planet's POIs into our store; coverage comes from OSM on
   demand. Administrative *regions* (spatial buckets for queries) are generated
   ahead of go-live.
4. **Coverage is a narrow, well-defined subset.** We consume a small fixed set of
   OSM tags (§5). This keeps ingestion, caching, and serving cheap.
5. **An OSM object only enters our store when a human curates it** (§6).

## 2. The three data categories

| # | Category | Example | Where it lives |
|---|----------|---------|----------------|
| 1 | **Pure uncurated OSM** | A bike shop nobody has touched | **Not stored.** Served live from OSM, or from our OSM serving cache (§5). |
| 2 | **OSM object + our curation** | Top Cycle, verified with a work-stand note | We store `{osm_ref, our added fields, provenance, verification state}` — **not** OSM's tags/geometry. Merged with OSM at read time. |
| 3 | **Our own generated data** | Riders' routes, climbs, hazard reports, photos | **Fully ours.** Structured data in our DB; media in S3. |

Categories 2 and 3 are "curated" for display purposes; category 1 is "community"
coverage. The join key between our data and OSM is always `osm_ref`.

## 3. Licensing posture

Cycling Commons publishes under:

- **Data:** Open Database License 1.0 (ODbL) + Database Contents License 1.0 (DbCL).
- **Media (photos):** CC BY-SA 4.0.
- **Code:** PolyForm Shield 1.0.0 (source-available, non-compete).

Consequences for OSM handling:

- OSM is ODbL. **Because our data is also ODbL, ODbL's share-alike obligation
  costs us nothing** — we are ODbL on both sides, so there is no risk of
  "accidentally" relicensing our enrichment by touching OSM data.
- **We may serve OSM data** (ODbL permits redistribution) provided we attribute
  `© OpenStreetMap contributors` and keep it under ODbL. Serving OSM data is
  therefore *allowed*; whether we do it is an operational choice (§8), not a
  legal constraint.
- **Media stays reference-not-ingest** where third-party: e.g. Mapillary imagery
  is CC BY-SA 4.0 (same as our photo pool) and may be embedded with attribution,
  but is never pushed into OSM (ODbL) — the incompatibility runs one way, toward
  OSM's database, not toward us.
- A full licence review is due before go-live; this section is the working
  answer, not legal advice.

## 4. What we never store vs. always store

**Never store:** OSM tags, names, geometry, or attributes as our own records; a
bulk regional or global copy of OSM POIs.

**Always store:** `osm_ref` (for objects we have curated), our added fields,
provenance and verification state, and everything in category 3 (our own data +
media in S3).

## 5. Coverage provider (serving OSM cheaply)

We must show OSM coverage (uncurated category-1 objects) on the map and in
search without hammering public infrastructure. The rule: **never call the
public Overpass API on a user request.**

**Approach — pre-extract, then serve from our own artefacts:**

1. On a schedule (weekly is sufficient — POIs change slowly), extract our fixed
   tag subset from an OSM planet/region PBF (`osmium tags-filter`, or one batched
   Overpass pull) in the `pipeline` service.
2. Load the result into:
   - a **POI index (PostGIS)** for spatial queries ("show all bike shops in town
     X"), and
   - **vector tiles (S3/CDN)** for map display.
3. User requests hit our tiles/index only — zero live Overpass load, and it
   scales because the subset is tiny (a whole province returns tens of objects,
   not thousands).

The serving cache is an ODbL-attributed copy of a *subset* of OSM, held for
serving. It is distinct from our **canonical dataset / public API** (§8), which
never republishes OSM's data as ours.

**The tag subset** (extend deliberately; every addition widens ingestion):

| Item type | OSM selectors |
|-----------|---------------|
| C · Water & food | `amenity=drinking_water`, `drinking_water=yes` |
| D · Bike services | `shop=bicycle`, `amenity=bicycle_repair_station`, `amenity=compressed_air` (see §7) |
| E · Where to sleep | `tourism=hotel/hostel/guest_house/chalet/camp_site/…` |
| G · Getting there | `railway=station`, `railway=halt` |
| H · Shelter & emergency | `amenity=shelter`, `emergency=phone/defibrillator` |
| I · Scenic views | `tourism=viewpoint`, `natural=peak`, `waterway=waterfall` |
| J · History & culture | `historic=castle/fort/ruins/monument/memorial/…` |
| A · Road surface | `highway=*` with `surface=*` (corridor/line data, not POIs) |

Item types **B · Climbs**, **F · Hazards**, and **K · Recommended routes** are
category-3 (our own data) and are not part of the OSM extract.

## 6. Materialize-on-edit lifecycle

An OSM object crosses the live→stored boundary **only when a human curates it.**
Until then it exists for us purely as coverage (category 1).

```
        (live OSM, not stored)
                 │
   user edits via the improve form
                 │
                 ▼
        materialize locally
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
  copies the referenced object into our store as `{osm_ref, edit}` and opens a
  submission. Nothing is materialized by mere viewing.
- **Approve → stays** as a curated category-2 item.
- **Reject → retained 3 months**, then the garbage collector deletes it. The
  retention window exists to handle disputes: we keep the record long enough to
  review a challenge.
- **Policy-violating content is trashed immediately** — no retention window.
- **Optional double-moderator check** for safety-sensitive or contested items.

This lifecycle rides the existing submission / moderation / retention (Trash)
machinery; materialize-on-edit is only the trigger that pulls an object across
the boundary.

## 7. Bike services: shop vs self-service station

OSM cleanly distinguishes staffed shops from unmanned self-service infrastructure,
and the Commons must reflect that difference — it changes which fields make sense
and what "opening hours" even means.

| Kind | OSM selector | Staffed? | Opening hours | Notes |
|------|-------------|----------|---------------|-------|
| **Bike shop** | `shop=bicycle` | Yes | Real, variable → the `24/7 / See website / Unknown` field applies | Website, tools, work stand, chain tool, e-bike charging, pump valve |
| **Self-service station** | `amenity=bicycle_repair_station` | No | **Inherently 24/7** — no opening-hours field | Pump valve, work stand, chain tool, tools available |
| **Public pump** | `amenity=compressed_air` | No | 24/7 | Pump valve only |

Design:

- Item type **D · Bike services** carries a `serviceKind ∈ {shop, station, pump}`
  discriminator, derived from the OSM tag on ingest and selectable on manual add.
- Each kind gets a **distinct marker** so the difference is visible on the map,
  and **kind-specific form fields**: only `shop` shows the opening-hours control;
  `station`/`pump` omit it (24/7 is implied by the type, not a chosen value).
- This supersedes treating "unmanned / 24-7" as a dropdown option — it becomes
  the *type*, which is the correct model.

## 8. Public API

The API defaults to **reference-only**:

- For a query range it returns the **`osm_ref`s** we hold coverage for, plus our
  **enriched fields** where we have them (category 2), and our own data
  (category 3) in full.
- It does **not** republish OSM's tags/geometry as ours. Consumers hydrate OSM
  detail from the `osm_ref` against OSM directly.

Reference-only is an **operational** default, chosen for freshness (OSM is more
current than any cache of ours), cost/bandwidth, and clean provenance — not
because licensing forbids serving OSM data. Since we are ODbL, an **optional
hydrated/merged endpoint** (our data + the cached OSM subset, `© OpenStreetMap
contributors`, ODbL) is permitted and may be offered as a developer convenience
if consumers find the two-call pattern too much friction.

All OSM-derived responses carry OSM attribution.

## 9. Surfacing curated vs community to users

The map and search reach the **full Commons**: our curated/own data (categories
2–3) and OSM coverage (category 1), deduplicated by `osm_ref` so a curated OSM
object appears once, as curated. Presentation ranks **curated/verified first**
and marks category-1 records as **community** (lighter markers, a "community"
tag). The display toggle governs ambient map density, not what search can find.
The detailed presentation contract (ordering, markers, reveal behaviour) lives in
the map/search UX spec and consumes this document's data model unchanged,
regardless of whether community records arrive live or from the serving cache.

## 10. Relationship to the current implementation

The initial Wallonia dataset harvests uncurated OSM into the `item` table and
inlines it for the map. That single-region harvest is **superseded** by this
architecture: going forward, uncurated OSM is coverage (live or cached, §5), not
stored rows, and enters the store only via materialize-on-edit (§6). The harvest
may remain as the first concrete implementation of the coverage provider until
the pre-extract pipeline and tiles exist, behind the same interface, so the swap
does not touch the map or contribution UX.

## 11. Before go-live

- Build the administrative **regions** (spatial buckets) worldwide.
- Stand up the **coverage provider**: the scheduled OSM subset extract → PostGIS
  POI index + vector tiles in the `pipeline` service.
- Implement **materialize-on-edit** against the live/cached coverage.
- Split **D · Bike services** into `shop / station / pump` kinds.
- Commission a **licence review** confirming §3.
