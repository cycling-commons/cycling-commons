<!-- SPDX-License-Identifier: AGPL-3.0-only -->

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
   generated data and the curated *additions* we make to OSM objects, never a
   bulk copy of OSM as our records.
2. **Reference OSM objects; don't republish their data.** We identify OSM objects
   by their stable ref (`type/id`, e.g. `node/61146471`) and attach our
   enrichment to that ref. We *do* hold a serving cache of a defined OSM subset
   for our own display and search (§5), but our **canonical dataset and public
   API** never republish OSM's tags or geometry as ours; they carry `osm_ref`s +
   our additions.
3. **Worldwide from day one.** The platform is global at launch. We do not
   pre-harvest the planet's POIs into our canonical store; coverage comes from
   the cache/OSM. Administrative *regions* (spatial buckets for queries) are
   generated ahead of go-live.
4. **Coverage is a narrow, well-defined subset, on both axes.** *Which objects*
   we cache is the fixed catalogue in §5. *Which tag keys we keep on those
   objects* is a separate, equally fixed list: the serve-set
   ([coverage-provider.md §2.1](coverage-provider.md)), applied at parse time.
   Keeping both narrow is what makes ingestion, caching, and serving cheap; a
   cache that stores every tag of every catalogued object is a bulk OSM copy by
   omission, which principle 1 forbids.
   **We do not cache contact email addresses** (see §5).
5. **An OSM object only enters our canonical store when a human curates it** (§6).

## 2. The three data categories

| # | Category | Example | Where it lives |
|---|----------|---------|----------------|
| 1 | **Pure uncurated OSM** | A bike shop nobody has touched | Not in the canonical store. Served from our OSM serving cache (§5), which mirrors the OSM subset. |
| 2 | **OSM object + our curation** | Top Cycle, verified with a work-stand note | Canonical store holds `{osm_ref, our added fields, provenance, verification state}`, **not** OSM's tags/geometry. Merged with the cache at read time. |
| 3 | **Our own generated data** | Riders' routes, climbs, hazard reports, photos | Fully ours. Structured data in our DB; media in S3. |

Categories 2 and 3 are "curated" for display; category 1 is "community"
coverage. The join key between our data and OSM is always `osm_ref`.

How the source families flow into the two data planes as built
(provenance values: catalog-data-model.md §5; tiers: map-and-search.md §12):

```
 OpenStreetMap (ODbL)          official registries:         our own inputs:
   │                           Tourisme Wallonie PIVOT      riders (user, scout) ·
   │ weekly per-region         (CC-BY) · Wikidata           curators (manual) ·
   │ Geofabrik extract                  │                   our tooling (auto)
   ▼                                    ▼                            │
 ┌──────────────────────────┐   ┌──────────────────────────────────────────┐
 │ COVERAGE PLANE - cache   │   │ CANONICAL STORE - the `item` table       │
 │ coverage_poi + PMTiles   │   │ source = authority | wikidata | user |   │
 │ pure OSM subset (§5);    │   │          scout | manual | auto | osm     │
 │ rebuilt weekly, never    │──▶│ `osm` rows = ref + OUR additions only    │
 │ edited, never canonical  │§6 │ (category 2), created the moment a       │
 └──────────────────────────┘   │ human curates a coverage object          │
   │      materialize-on-edit   └──────────────────────────────────────────┘
   │ PMTiles + /map/coverage/*     │ region slices + item endpoints
   ▼                               ▼
 community tier on the map      curated/verified tiers on the map
 (map-and-search.md §12)        (PIVOT = verified by registry provenance)
```

## 3. Licensing posture

This section is the canonical statement of what the Cycling Commons publishes
under. Every other spec that mentions a licence defers to it; if the two
disagree, this one is right and the other one is stale.

Cycling Commons publishes under five buckets of its own: code and UI, data,
standalone media, wiki prose and the reserved brand, plus the third-party files
it carries. UI translations get a row of their own below because they are the
one thing readers reliably put in the wrong bucket. They are part of the
software:

| Bucket | Licence | Where it lives |
|---|---|---|
| **Code and UI** | **AGPL-3.0-only** (GNU Affero General Public License v3) | the whole repository unless a row below overrides it |
| **UI translations** | **AGPL-3.0-only** | `web/translations/*.yaml` in git, and the `translation_overlay` / `translation_proposal` rows in the database |
| **Data** | ODbL-1.0 + DbCL-1.0 | the `item` table, the coverage plane, the public API, the exports |
| **Standalone media** (rider photos, the five Wikimedia photographs) | CC BY-SA 4.0, or each photograph's own licence | `web/public/media/`, uploaded rider photos |
| **Wiki prose** (`wiki/`, wiki.cyclingcommons.org) | CC BY-SA 4.0 | per-page SPDX headers, pre-commit enforced |
| **Logo and wordmark** | `LicenseRef-CyclingCommons-Brand`, **reserved** | `web/assets/brand/`, the favicons, `brand-src/` |
| **Country flags** | MIT, (c) Panayiotis Lipiridis | `web/assets/flags/` |

The third-party rows are examples, not the whole list: the vendored frontend
libraries (BSD-3-Clause, MIT), the three web fonts (OFL-1.1) and the harvested
Wikidata output (CC0-1.0) each keep their own terms, and single files inside a
bucket are sometimes carved out of it. The authority for all of it is
[`REUSE.toml`](../../REUSE.toml) plus the per-file SPDX headers, and
`reuse lint` fails the build when a file is uncovered.

### 3.1 What AGPL-3.0-only means here

The platform is **free software, and open source in the ordinary sense of the
word**. Anyone may read it, run it, modify it and redistribute it, including
commercially, and including as a rival to us. The licence sets no
field-of-use restriction and holds nothing back for us: AGPL has no such
clause to give.

What AGPL asks in return is reciprocity: anyone who conveys the software, or a
modified version of it, must pass on the complete corresponding source under the
same licence. Section 13 extends that to the case this project actually cares
about. **A user who interacts with a modified version over a network must be
offered its Corresponding Source**, whether or not a copy of the software ever
changes hands. Putting a fork on a public website therefore triggers the same
duty to publish source that shipping a binary would.

Two consequences the rest of the repository has to honour:

- **Section 13 is a live duty on us, not only on a fork.** We run this software
  as a network service. Every deployment must offer its own users the
  Corresponding Source of the version actually running, including any local
  modifications. A link to the public repository only discharges it while the
  running code and the public repository are the same code.
- **`AGPL-3.0-only`, never bare `AGPL-3.0` and never `AGPL-3.0-or-later`.**
  The bare form is deprecated in SPDX. "Or later" is refused deliberately: it
  would hand future licence policy over this codebase to a third party.

The AGPL section 14 proxy, the person who may decide that a later version of the
licence applies, is named as a person: **Xander Koevoet**. It is deliberately
not a URL, because anyone with write access to a page could then publish a proxy
acceptance that section 14 makes permanently binding.

Copyright is held by **BikeCoders** (https://bikecoders.life), the trading name
of the sole owner, and is expected to move to a Dutch foundation (stichting) within about a
year. Contributors are **not** asked to assign copyright: Dutch law needs a
signed deed for that, which a checkbox in a repository cannot be. They grant a
non-exclusive licence through a DCO sign-off (`git commit -s`), gated in CI.

### 3.2 The name is not part of the grant

The software is free. **The name, the logo and the wordmark are not.** AGPL v3
section 7(e) expressly permits declining to grant rights under trademark law,
and [`TRADEMARK.md`](../../TRADEMARK.md) is that declination. A fork gets the
whole codebase and must give itself its own name. Nothing in this document, and
nothing in the code licence, grants any right in the marks.

### 3.3 Consequences for OSM handling

- OSM is ODbL. **Because our data is also ODbL, ODbL's share-alike obligation
  costs us nothing.** We are ODbL on both sides, so there is no risk of
  "accidentally" relicensing our enrichment by touching OSM data.
- **We may serve OSM data** (ODbL permits redistribution) provided we attribute
  `© OpenStreetMap contributors` and keep it under ODbL. Serving OSM data is
  therefore *allowed*; whether the public API does it is an operational choice
  (§7), not a legal constraint.
- **The code licence and the data licence are independent.** AGPL governs the
  software; it says nothing about the database, and ODbL says nothing about the
  software. A consumer of the API takes on ODbL's share-alike over derived
  databases whether or not they ever touch our source.
- **Third-party media stays reference-not-ingest** where relevant. Mapillary
  imagery, for instance, is CC BY-SA 4.0 (the same as our photo pool) and may be
  embedded with attribution, but is never pushed into OSM (ODbL). The
  incompatibility runs one way, toward OSM's database, not toward us.

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

**The approach is to pre-extract, then serve from our own artefacts:**

1. On a schedule (weekly is sufficient, POIs change slowly), extract the tag
   subset below from an OSM planet/region PBF (`osmium tags-filter`, or one
   batched Overpass pull) in the `pipeline` service.
2. Load the result into a **POI index (PostGIS)** for spatial queries ("show all
   bike shops in town X") and **vector tiles (S3/CDN)** for map display.
3. User requests hit our tiles/index only, so there is zero live Overpass
   load. The planet-wide subset measures ≈ 4.7 M points
   (coverage-provider.md §2, sizing); per-region extracts
   stay small, so ingestion and serving remain cheap.

**Concrete implementation.** The `pipeline` container runs a per-region batch
(`pipeline/coverage/`; the regions are every onboarded country's Geofabrik
extracts, read from `country_extract`, or `COVERAGE_REGIONS` for a subset):
Geofabrik PBF → `osmium tags-filter` → pyosmium → the **`coverage_poi`**
PostGIS table (atomic per-region swap), then tippecanoe builds one
`points.pmtiles` per country from the full index, go-pmtiles verifies it, and
the archives and `coverage/manifest.json` upload to the Cycling Commons' **own
object-storage bucket** (name: deployment config, dev MinIO: `cc-maps`),
deliberately separate from any
shared basemap bucket so coverage cost stays observable
(coverage-provider.md §1). Symfony serves search / nearby /
counts / drawer detail from `coverage_poi` (`/map/coverage/*`); the map reads
the PMTiles by byte range. Selectors, letters, the D `serviceKind` mapping and
the P and Q `placeKind` mapping live in the shared contract file
`pipeline/contract/coverage-contract.json`, held in sync with
`App\Catalog\ServiceKind` and `App\Catalog\PlaceKind` by cross-language tests. Full
design: [coverage-provider.md](coverage-provider.md).

### The complete OSM item catalogue

This is the authoritative, exhaustive list of OSM data we cache. Extending it is
a deliberate decision: every addition widens ingestion and the cache.

| Commons item | Kind | OSM selector | Notes |
|--------------|------|--------------|-------|
| A · Road surface | line | `highway=*` with `surface=*` | Corridor data, not POIs |
| B · Water & food | point | `amenity=drinking_water`, `drinking_water=yes`, `amenity=water_point`, `man_made=water_tap`, `shop=bakery` | Water sources + the bakery (the classic resupply stop; fills the "food" half of the letter). Cafés/restaurants deliberately excluded: too dense, low per-item signal |
| C · Public toilets | point | `amenity=toilets` | [edit-items/C-public-toilets.md](edit-items/C-public-toilets.md) |
| **D · Bike shop** | point | `shop=bicycle` | Staffed; real opening hours apply |
| **D · Self-service station** | point | `amenity=bicycle_repair_station` | Unmanned; **inherently 24/7** |
| **D · Public pump** | point | `amenity=compressed_air` | Unmanned; 24/7 |
| F · Getting there | point | `railway=station`, `railway=halt`, `amenity=ferry_terminal`, `route=ferry` | Ferries are route-critical crossings in this region. `route=ferry` ways reduce to the crossing midpoint (legitimately over water); the terminal is the land-side dock. A point tagged `usage=tourism` or `usage=leisure` (a heritage railway), or `bicycle=no` (a ferry that takes no bikes), is left out ([coverage-provider.md §7](coverage-provider.md)). A dock with no `bicycle` tag inherits the answer of the ferry routes that end at it, by topology, under `cc:` keys of its own, and is left out when every tagged route says no ([coverage-provider.md §3](coverage-provider.md)). A ferry route's `duration`, `seasonal` and `toll` are stored for the drawer |
| G · Shelter | point | `shelter_type=picnic_shelter/weather_shelter/field_shelter/lean_to/basic_hut/gazebo/pavilion/rock_shelter/sun_shelter/wildlife_hide/dugout` (typed shelters only; bare `amenity=shelter` and `shelter_type=public_transport` bus stops stay out) | |
| O · Where to sleep | point | `tourism=hotel/hostel/guest_house/chalet/camp_site/…` | |
| P · Scenic views | point | `tourism=viewpoint`, `waterway=waterfall`, `waterway=rapids`, `natural=cliff`, `natural=cave_entrance`, `natural=arch`, `natural=rock`, `natural=stone` | One kind per tag (§5a). Within 250 m of a way a bike may ride, with a name or a photo link; never a peak ([scenic-views.md §2](scenic-views.md)) |
| Q · History & culture | point | `historic=castle/fort/ruins/monument/memorial/archaeological_site/manor/monastery` | One kind per tag (§5a) |

Letters are grouped practical A–M, experiential N–Z (catalog-data-model.md §1).

Item types **N · Climbs**, **E · Hazards**, and **R · Recommended routes** are
category-3 (our own data) and are **not** part of the OSM extract.

### What we keep *on* each cached object

The table above says which OSM **objects** we cache. It does not say which of
their **tags** we keep, which is a separate and equally deliberate list, because
`osmium tags-filter` selects objects, not keys, so a matching object arrives
carrying everything OSM has attached to it.

We store **43 tag keys**: the 11 selector keys above, 2 keys the load rules
read (`memorial`, `usage`), the 22 keys the POI drawer displays, 4
media/reference keys, the 3 `cc:` keys the harvest writes on a ferry dock, and
`check_date`. Everything else is dropped before it reaches our database. The full list, the rationale, and the tests that
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
  Overpass or the OSM API, including the POI drawer, which reads
  `coverage_poi` alone. So an untrimmed key is not "extra safety"; it is dead
  weight, and a trimmed one cannot be recovered without a re-harvest.

### Bike services: one type, three OSM kinds

Item type **D · Bike services** carries a `serviceKind ∈ {shop, station, pump}`
discriminator, derived from the OSM tag on ingest and chosen as the form's
Type field (`CatalogFormRegistry`) when a rider adds or edits a place. The
distinction is a data fact, not just presentation:

- `shop` (`shop=bicycle`): staffed; opening hours are meaningful; the
  `24/7 / See website / Unknown` field defaults to `Unknown`.
- `station` (`amenity=bicycle_repair_station`) / `pump`
  (`amenity=compressed_air`): unmanned; **24/7 is the default assumption**.
  The edit form shows the same opening-hours field with `24/7` preselected, and
  it is overridable, because some stations follow a host building's hours (e.g. a
  repair station inside a library). When no value is stored, the item card
  shows the assumed default as a read-only "Opening hours · 24/7" row; a
  stored value wins.

Each kind gets a distinct marker so riders see the difference; the
opening-hours default is kind-specific (`Unknown` for shops, `24/7` for
stations/pumps). Edit-flow consequences live in
[edit-items/D-bike-services.md](edit-items/D-bike-services.md).

### 5a. Shelters, scenic views and history: every OSM tag is one kind

Every Type of **P · Scenic views** and **Q · History & culture** is a kind.
**Every OSM tag we show converts to exactly one kind**, so what we add can go
back to OSM as that tag (owner 2026-10-07). A waterfall in OSM stays a
waterfall here, and comes back out as `waterway=waterfall`. The other direction
is optional: an own kind has no OSM tag, and a place of that kind stays ours.
`App\Catalog\PlaceKind` is the one table:

| Letter | Kind | OSM tag | Harvested |
|---|---|---|---|
| P | viewpoint | `tourism=viewpoint` | yes |
| P | peak | `natural=peak` | no: a summit is where no rider is ([scenic-views.md §2](scenic-views.md) rule 1) |
| P | waterfall | `waterway=waterfall` | yes |
| P | rapids | `waterway=rapids` | yes |
| P | cliff | `natural=cliff` | yes |
| P | cave | `natural=cave_entrance` | yes |
| P | arch | `natural=arch` | yes |
| P | rock | `natural=rock` | yes |
| P | stone | `natural=stone` | yes |
| P | nature | none: a beautiful stretch to ride, such as a road through a forest | own kind |
| Q | castle | `historic=castle` | yes |
| Q | fort | `historic=fort` | yes |
| Q | ruins | `historic=ruins` | yes |
| Q | monument | `historic=monument` | yes |
| Q | memorial | `historic=memorial` | yes |
| Q | archaeological | `historic=archaeological_site` | yes |
| Q | manor | `historic=manor` | yes |
| Q | monastery | `historic=monastery` | yes |
| Q | museum | `tourism=museum` | no: left out of the harvest ([edit-items/Q-history-culture.md](edit-items/Q-history-culture.md)) |
| Q | worship | `amenity=place_of_worship` | no: every church is too many pins |
| Q | heritage | none: a heritage site that is no single OSM kind | own kind |
| Q | architecture | none: a building worth the stop for its architecture | own kind |

A kind that is not harvested is still a Type for our own places.

**G · Shelter** has a Type by the same rule (owner 2026-10-08), one per OSM
`shelter_type`: basic hut, dugout, field shelter, gazebo, lean-to, pavilion,
picnic shelter, rock shelter, sun shelter, weather shelter and wildlife hide,
all harvested, plus bus shelter (`shelter_type=public_transport`) and
defibrillator (`emergency=defibrillator`), which are not. It replaces the
free `shelterType` list. G draws a glyph per kind and the contract's
`placeKind` holds its rules, like P and Q. Migration `Version20261008110000`
gave existing shelters their Type: a Type a shelter already had, else the
linked OSM point's `shelter_type` (one of the eleven harvested values), else
the old `shelterType` or `t` label; bus shelter and defibrillator come from
those labels only, since the harvest stores neither tag. It removed
`shelterType`. Submissions a curator can still approve (pending, needs info,
and those in Trash from either) propose the Type instead, in the payload and
in `changes` as `{was, now}`, which is what an approval applies.

**O · Where to sleep** has a Type by the same rule (owner 2026-10-08), every
one an OSM `tourism` tag, so every stay can go back to OSM: hotel, motel,
guest house / B&B (`tourism=guest_house`), hostel, campsite
(`tourism=camp_site`), chalet / gîte (`tourism=chalet`), mountain hut
(`tourism=alpine_hut`), wilderness hut and holiday rental (`tourism=apartment`,
not harvested). A label names both words riders know a type by: one type, not
two. The authority's B&B, Gîte and Budget stay map onto guest house, chalet and
hostel. It is a form field, filled on conversion and on import from the
row's `t` label; it has no map glyphs yet (`PlaceKind::TYPED_LETTERS` holds O,
`PlaceKind::LETTERS`, the glyph letters, does not), and the season ballot draws
its stay icon from it (`StayKind::fromType()`). Migration
`Version20261008090000` gave existing places their Type: the linked OSM point's
`tourism` tag, else the `t` label; `Version20261008100000` folds the three
types an earlier reading kept apart (bnb, gite, budget) into the OSM ones.
O had no Type field before, so no submission proposed one.

The G, P and Q kinds are used the same way everywhere:

- **Stored** as the item's `type` attribute: a key (`waterfall`), shown as its
  translated label (`CatalogField::selectKeyed`). On every form the Type is the
  second field, right after the name, and its list is in alphabetical order of
  the rider's language (`sortChoices`, `ImproveType::selectChoices()`); the
  stored order stays the match order. A harvested tag is never
  stored as an own kind: an OSM castle is `castle`, not `heritage`.
- **Harvested:** the pipeline stamps `coverage_poi.kind` from the contract's
  `placeKind` rules, and the tiles carry it as `kind`, the same way D carries
  `serviceKind`. A point with two matching tags is the first kind in the
  contract order, as in the tile's `t` label. `PlaceKind::fromOsmTags()` reads
  tags the same way: the harvested kinds first, in table order, and a kind the
  harvest leaves out only after them, so a summit tagged with its waterfall
  is a waterfall in PHP and in the pipeline alike.
- **Served:** the coverage search, nearby list and drawer
  (`CoverageRepository`) give every letter in `PlaceKind::TYPED_LETTERS`
  (G, O, P, Q) its `kind`: our item's Type, or the coverage point's stamped
  `kind`, else read from its tags. The tags are decoded only where the
  pipeline has not stamped the kind. The map draws a glyph where `KindIcons`
  has one; an O stay has none, and its kind starts the wizard on its type.
- **Drawn:** one glyph per kind on the category disc (`KindIcons`), on OSM
  points and our own places alike, in both legends and on search rows. A tile
  from before the stamp still names its kind in its `t` label (the contract's
  selector label is the kind label), and the map reads it
  (`window.CC_PLACE_KIND_LABELS`).
- **Converted:** materialize-on-edit (§6) and the one-tap OSM confirm fill the
  Type from the point's kind.
- **Seeded:** `app:catalog:seed-wikidata` reads an artifact's Type through
  `PlaceKind::fromWikidataLabel()`: a kind or an exact label as above, and
  Heritage site as a castle, the same reading `Version20261007140000` gives
  rows already seeded, so a re-seed keeps it.
- **Scout:** SCENERY · VIEW is a viewpoint. NATURE, HISTORY, CULTURE and
  ARCHITECT name several kinds, so the ride review asks the rider which one,
  with the own kind first where there is one (NATURE: Natural feature), and a
  P or Q tag is not sent without it
  ([moderation-and-contribution.md](moderation-and-contribution.md), Scout intake).

Migration `Version20261007140000` turned the older Types into kinds. Per item:
the linked OSM point's tag first (a harvested kind before one the harvest
leaves out), then the Type when it is a kind already or an old label that is
exactly one kind (Museum / culture is a museum; Natural feature, Heritage site
and Architecture keep their own kinds; Viewpoint / high point only on a Scout
row, where the device's VIEW wrote it), then an import's `t` label. Heritage
site on a `wikidata` row became a castle: Q23413 (castle) was the only
Wikidata class `tools/wikimedia/country_places.py` ever filed under it. A row
whose Type a person chose keeps Heritage site, and the name decides nothing
on any source. A label naming two OSM kinds (Viewpoint / high point, Religious
site) was cleared for a curator. Submissions a curator can still approve
(pending, needs info, and those in Trash from either) got the same reading of
their proposed Type, in the payload and in `changes`. Decided submissions and
change history keep the old labels, because they record what was said then.

All four Type migrations can run twice with the same result, and none goes
down: each `down()` throws `IrreversibleMigration`, because a many-to-one
reading and a removed field cannot be undone from the data. Going back means
restoring a backup taken before them.

## 6. Materialize-on-edit lifecycle

An OSM object crosses the coverage→canonical boundary **only when a human
curates it.** Until then it exists for us purely as cached coverage (category 1).

```
        (OSM coverage, cache only, not in canonical store)
                 │
   user edits via the improve form
                 │
                 ▼
        materialize into canonical store
     { osm_ref + submitted edit }   ── policy violation ──▶  trashed: hidden,
                 │                                            deleted after 30 days
          moderation review
        (optional double-check)
             ┌───┴───┐
        approve     reject
             │         │
             ▼         ▼
      curated item   kept while the
      (category 2)   rider's account is
                         │
                account deletion deletes
```

Rules:

- **Materialize on first edit.** Submitting an edit for an uncurated OSM object
  copies the referenced object into the canonical store as `{osm_ref, edit}` and
  opens a submission. Nothing is materialized by mere viewing. The coverage
  drawer's edit link opens `/improve?ref=<node|way/id>&type=<letter>`, the add
  wizard with name and location prefilled from the cached POI, and the submit
  mints the item with `source = osm`, `source_ref = <osm ref>`, one item per
  ref, so the coverage dedupe (§8) engages.
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
  - **The same answers survive materialization.** A place that became ours is
    not frozen: a water point added as existing and potable can be shut off,
    break, or be taken out next season (owner 2026-08-12), so
    `POST /items/{id}/condition` offers the identical three words on an item we
    already hold. It files an ordinary **edit** submission rather than a
    confirmation - a claim about the place, not a vote on it - so the queue,
    the diff and the approve button are the ones that already exist. A second
    report of the same thing answers `409 pending_review`.
  - **`condition = 'Not there anymore'` removes a place from the map, without
    handing it back to OSM.** `CatalogProvider::itemRows()` stops drawing the
    item; `curatedRefs()` still claims its ref, so the coverage POI it was
    materialized from stays hidden. Both halves are the behaviour: without the
    first the report changes nothing, without the second the reference point
    reappears in the hole the item left. The row itself stays - a curator may
    disagree, and the report is a record either way.
- **Approve → stays** as a curated category-2 item.
- **Reject → kept as long as the rider's account**, and deleted with it
  (moderation-and-contribution.md §8). The record stays to handle a dispute.
- **Policy-violating content is trashed**: hidden at once, kept 30 days in the
  curators' bin in case it was a mistake, then deleted for good
  (moderation-and-contribution.md §6).
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

Reference-only is an **operational** default, chosen for freshness (OSM is
more current than any cache of ours), cost and bandwidth, and clean provenance.
It is not a licence requirement. Since we are ODbL, an **optional
hydrated/merged endpoint** (our data + the cached OSM subset, `© OpenStreetMap
contributors`, ODbL) is permitted and may be offered as a developer
convenience. All OSM-derived responses carry OSM attribution.

**Access terms.** Programmatic access to Commons data is **only** via the public
API and the weekly bulk export ([api-strategy.md §3.1](api-strategy.md)), a
published file rather than live access. **Scraping** the site, tiles, or endpoints outside the API is **prohibited**
by the user terms. This governs the presentation layer, not the openness of the
data: our own data (ODbL) is provided openly *through the API and the export*,
which are the sanctioned channels, and OSM-derived detail is obtained from OSM (the developer
wiki's guide `wiki/developers/api/openstreetmap-data.md` gives the exact filters through
`pipeline/osm_filters.py`, held to the real passes by `pipeline/tests/test_osm_filters.py`), so the
no-scraping rule does not restrict any ODbL right over the underlying open data.
The user terms state both the API-only access rule and the scraping
prohibition (`pages/terms.html.twig`).

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

## 9. Harvested OSM rows in the canonical store

Uncurated OSM is cached coverage (§5), not canonical rows, and enters the
canonical store only via materialize-on-edit (§6). The pre-extract pipeline,
the `coverage_poi` index and the PMTiles archives are the only serving path for
uncurated OSM. Older `source = osm` rows harvested straight into `item` that no
human ever touched are left out of the catalog payload and removed by
`app:coverage:retire-legacy` (the bare command is the dry-run report; `--force`
deletes and is owner-gated, coverage-provider.md §9); anything with an edit,
confirmation, or submission stays canonical. `tools/wallonia` remains for the
atlas demo and the canonical seeds (climbs, routes, surface, PIVOT stays); the
coverage path never touches Overpass.

## 10. Before go-live

- Build the administrative **regions** (spatial buckets) worldwide.
- Commission a **licence review** confirming §3.
