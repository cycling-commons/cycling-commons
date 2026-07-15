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
4. **Coverage is a narrow, well-defined subset.** We cache only the fixed set of
   OSM tags catalogued in §5. This keeps ingestion, caching, and serving cheap.
5. **An OSM object only enters our canonical store when a human curates it** (§6).

## 2. The three data categories

| # | Category | Example | Where it lives |
|---|----------|---------|----------------|
| 1 | **Pure uncurated OSM** | A bike shop nobody has touched | Not in the canonical store. Served from our OSM serving cache (§5), which mirrors the OSM subset. |
| 2 | **OSM object + our curation** | Top Cycle, verified with a work-stand note | Canonical store holds `{osm_ref, our added fields, provenance, verification state}` — **not** OSM's tags/geometry. Merged with the cache at read time. |
| 3 | **Our own generated data** | Riders' routes, climbs, hazard reports, photos | Fully ours. Structured data in our DB; media in S3. |

Categories 2 and 3 are "curated" for display; category 1 is "community"
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
3. User requests hit our tiles/index only — zero live Overpass load, and it
   scales because the subset is tiny (a whole province returns tens of objects).

### The complete OSM item catalogue

This is the authoritative, exhaustive list of OSM data we cache. Extending it is
a deliberate decision — every addition widens ingestion and the cache.

| Commons item | Kind | OSM selector | Notes |
|--------------|------|--------------|-------|
| A · Road surface | line | `highway=*` with `surface=*` | Corridor data, not POIs |
| C · Water & food | point | `amenity=drinking_water`, `drinking_water=yes` | |
| **D · Bike shop** | point | `shop=bicycle` | Staffed; real opening hours apply |
| **D · Self-service station** | point | `amenity=bicycle_repair_station` | Unmanned; **inherently 24/7** |
| **D · Public pump** | point | `amenity=compressed_air` | Unmanned; 24/7 |
| E · Where to sleep | point | `tourism=hotel/hostel/guest_house/chalet/camp_site/…` | |
| G · Getting there | point | `railway=station`, `railway=halt` | |
| H · Shelter & emergency | point | `amenity=shelter`, `emergency=phone/defibrillator` | |
| I · Scenic views | point | `tourism=viewpoint`, `natural=peak`, `waterway=waterfall` | |
| J · History & culture | point | `historic=castle/fort/ruins/monument/memorial/…` | |

Item types **B · Climbs**, **F · Hazards**, and **K · Recommended routes** are
category-3 (our own data) and are **not** part of the OSM extract.

### Bike services — one type, three OSM kinds

Item type **D · Bike services** carries a `serviceKind ∈ {shop, station, pump}`
discriminator, derived from the OSM tag on ingest (and selectable on manual add).
The distinction is a data fact, not just presentation:

- `shop` (`shop=bicycle`) — staffed; opening hours are meaningful (the
  `24/7 / See website / Unknown` field applies).
- `station` (`amenity=bicycle_repair_station`) / `pump`
  (`amenity=compressed_air`) — unmanned; **24/7 is implied by the type**, so
  there is no opening-hours field. "Unmanned / 24-7" is the *kind*, never a
  chosen value.

Each kind gets a distinct marker so riders see the difference; form fields are
kind-specific (only `shop` shows opening hours).

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
The detailed presentation contract (ordering, markers, reveal behaviour) lives in
the map/search UX spec and consumes this document's data model unchanged,
whether community records arrive from the cache or from OSM.

## 9. Relationship to the current implementation

The initial Wallonia dataset harvests uncurated OSM into the `item` table and
inlines it for the map. That single-region harvest is **superseded** by this
architecture: going forward, uncurated OSM is cached coverage (§5), not canonical
rows, and enters the canonical store only via materialize-on-edit (§6). The
harvest may remain as the first concrete implementation of the coverage provider
until the pre-extract pipeline and tiles exist, behind the same interface, so the
swap does not touch the map or contribution UX.

## 10. Before go-live

- Build the administrative **regions** (spatial buckets) worldwide.
- Stand up the **coverage provider**: the scheduled OSM subset extract → PostGIS
  POI index + vector tiles in the `pipeline` service.
- Implement **materialize-on-edit** against the cached coverage.
- Split **D · Bike services** into `shop / station / pump` kinds.
- Add the **API-only access** rule and **scraping prohibition** to the user terms.
- Commission a **licence review** confirming §3.
