# Spec — Wallonia data fill: making the Atlas "live-real"

- **Status:** Draft — awaiting user review (author stepped away; plan written for review on return)
- **Date:** 2026-06-24
- **Scope:** Demo data + a reusable harvest toolchain. Frontend demo + new `tools/wallonia/` Python scripts.
- **Goal:** Replace the thin hand-placed demo POIs with region-balanced, real, attributed data across
  **every** catalog layer (A–L) for Wallonia, so the map looks and behaves as it would once live.
- **Builds on:** [`2026-06-18-catalog-v2-and-per-type-forms.md`](2026-06-18-catalog-v2-and-per-type-forms.md)
  (canonical A–L catalog; L = derived ride heatmap), the existing `atlas/demo/water-osm.js` harvest
  (290 points, one-off), and `tools/regions/` (the existing Python geo-tooling pattern).

---

## 1. Goal & success criteria

"As real as if it were live." Concretely, when this lands the Wallonia map should:

1. **Be dense and balanced** — every layer carries real OSM/official data, spread across all five
   Walloon provinces, not clustered on Liège + the Hautes Fagnes (today's demo bias).
2. **Have rich cards** — names, short descriptions and (where licensed) a photo on scenic/history/stay
   features, not bare dots. Water carries a potability flag.
3. **Feel curated, not dumped** — capped, ranked per region so the map reads like an editor chose it.
4. **Tell stories** — ~10–14 named routes, each tying together the legendary climbs, viewpoints and
   the *history of the towns it passes through*.
5. **Show where people ride** — the L heatmap panel filled with a real, ODbL-clean cycling-density
   overlay (season-aware).
6. **Be reproducible** — one `make` target re-runs the whole harvest; no more frozen one-off `.js`.
7. **Be honestly attributed** — every source credited per its licence; nothing claimed that isn't real.

**Guiding principle — plausibility above all.** Everything on the map must read as *plausible*: real
data wherever we can harvest it (POIs, climbs, routes, water), real geometry for anything drawn, and
grounded-synthetic only where real data is impossible (the L heatmap, derived from OSM network density).
Nothing random or obviously fabricated. Synthetic content is always labelled illustrative — plausible,
never deceptive.

**Out of scope:** the production PostGIS/API pipeline (stays scaffolding); user-account/personal data;
disciplines beyond what the catalog already covers.

---

## 2. Decisions locked (from scoping)

| Axis | Decision |
|---|---|
| Output | **Reusable Python harvest scripts → static `.js` fixtures** (rerunnable, no backend) |
| Category scope | **All of A–L** — 6 bulk POI layers, the 3 curated geometry layers, water refresh, **and** the L heatmap |
| Depth | **Region-balanced** — cap per category, distributed across Walloon provinces/arrondissements |
| Sources | **OSM + Wikidata/Wikipedia + official (SWDE, SNCB/iRail)** |

---

## 3. Scope by layer

Three natures of work; each layer falls into one bucket.

### 3a. Bulk-harvestable POI layers (Overpass → fixture, region-balanced + enriched)

| Layer | OSM selectors (Wallonia bbox) | Target total | Region-balanced by | Enrichment |
|---|---|---|---|---|
| **C · Water & food** | `amenity=drinking_water/fountain/water_point`, `man_made=water_tap/spring`, `drinking_water=yes` | refresh ~290 | province | **SWDE** potability → `verified` flag |
| **D · Bike services** | `shop=bicycle`, `amenity=bicycle_repair_station`, `amenity=compressed_air`, `service:bicycle:*` | ~100 | arrondissement | opening hours from OSM |
| **E · Where to sleep** | `tourism=camp_site/hostel/guest_house/chalet/wilderness_hut/alpine_hut`, `amenity=shelter`+`shelter_type=basic_hut` | ~140 | province (≈28/prov) | Wikidata/Wikipedia blurb + photo |
| **G · Getting there** | `railway=station`+`usage=main/branch`, bike-on-train flag | ~90 | network (rail lines) | **SNCB/iRail** station list + bike rules |
| **H · Shelter & emergency** | `amenity=shelter`, `emergency=phone/defibrillator`, `tourism=picnic_site`+shelter | ~100 | province | — |
| **I · Scenic views** | `tourism=viewpoint`, `natural=peak/cliff/waterfall` w/ name | ~110 | province | Wikidata notability rank + photo |
| **J · History & culture** | `historic=*` (castle, monument, memorial, ruins, archaeological_site), `tourism=attraction`+heritage | ~150 | province | Wikidata/Wikipedia blurb + photo; WWII layer |

"Target total" is a cap, not a floor — exhaustive harvest, then rank-and-trim per region bucket (§5b).

**E vs H de-overlap:** both touch `amenity=shelter`. Split by sleepability — `shelter_type=basic_hut`
and `tourism=wilderness_hut/alpine_hut/camp_site/hostel/guest_house/chalet` → **E (sleep)**; all other
`amenity=shelter` (rain/picnic shelters) → **H (shelter)**. The harvester dedupes by OSM id across
layers so no feature appears in both.

### 3b. Curated geometry layers (real geometry, editorial selection)

| Layer | Method | Target |
|---|---|---|
| **A · Road surface** | Overpass `way[surface]` + `highway=cycleway` in RAVeL/`network=rcn/lcn` relations; classify into paved / smooth-cycleway / gravel / pavé / ground; trim to representative sectors per province | ~40 segments |
| **B · Climbs** | **Keep the 5 we already have** (Redoute, Mur de Huy, Stockeu, Roche-aux-Faucons, Hockai); add ~25 more legendary/regional climbs — geometry from OSM ways + BRouter, gradient profile from DEM | 5 kept + ~25 new ≈ 30 |
| **K · Quality rides** | ~10–14 themed named routes (extends today's 6); geometry via BRouter on real OSM; each linked to the climbs/views/history it threads | 10–14 routes |

### 3c. Derived layer

| Layer | Method | Output |
|---|---|---|
| **L · Ride heatmap** | **Illustrative (synthetic) demo heat**, grounded in OSM cycle-network density so it follows real cycleways/RAVeL/signed routes — weighted line-density of route relations `icn/ncn/rcn/lcn`, RAVeL, `highway=cycleway`, surfaced lanes, rasterised to a season-weighted grid; rendered in the existing L panel | `heatmap-data.js` (grid or weighted points for Leaflet.heat) |

**Honesty note on L (resolved):** we have no real ride GPS and won't use Strava (not redistributable).
The heatmap is **synthetic — an illustrative demo of what a usage heatmap would look like** — but
grounded in real OSM cycle-network density so it follows actual cycleways/RAVeL and reads as plausible,
not random noise. It rides under the site's existing "figures are illustrative" demo framing, and the L
panel label states it's illustrative, not real ride data. No false claim of usage data; no GPS-traces
path needed.

### 3d. Not collected

- **F · Hazards & conditions** — community-reported; not meaningfully in OSM. Keep the 1–2 demo
  examples; do **not** fabricate hazards (they'd read as real safety claims). Note this in the plan.

---

## 4. Sources & licensing

| Source | Licence | Use | Attribution handling |
|---|---|---|---|
| OpenStreetMap (Overpass) | **ODbL 1.0** | all coordinates + tags | already the dataset's base licence; "© OpenStreetMap contributors" |
| Wikidata | **CC0** | notability rank, identifiers, coords cross-check | no attribution required (credited anyway) |
| Wikipedia (REST summary) | **CC BY-SA 4.0** | short descriptions | per-feature `descSource` + link; SA noted in data-catalog |
| Wikimedia Commons | per-file (CC BY / BY-SA / PD) | photos | **per-photo** `credit`/`license`/`source` (schema already supports this) |
| SWDE | check open-data terms before use | water potability flag | credit; **open question — verify licence (see §12)** |
| SNCB / iRail (irail.be) | iRail open data | station list + bike-on-train | credit iRail/SNCB |
| BRouter | routing engine (OSM data) | route/climb geometry | "geometry via BRouter" tag (existing convention) |

All harvested data is **non-personal geodata** → consistent with the open-data stance. Code stays under
the repo's source-available licence; only data is open. Photos are the one real licence trap — only
include images whose Commons licence is CC/PD, and carry the credit string into the fixture.

---

## 5. Architecture

### 5a. Harvest toolchain (`tools/wallonia/`, Python)

Geo/raster/Overpass/elevation work → **Python** (per the project's Python-vs-PHP boundary). Mirrors the
existing `tools/regions/` pattern. Proposed layout:

```
tools/wallonia/
  bbox.py            # Wallonia bbox + province/arrondissement polygons (from OSM admin rels)
  overpass.py        # thin Overpass client w/ retry, cache to tools/wallonia/.cache/
  enrich_wikidata.py # Wikidata/Wikipedia lookup (descriptions, photos, notability)
  harvest_poi.py     # generic POI harvester: selectors → dedupe → region-bucket → rank → cap → fixture
  harvest_water.py   # C: POI + SWDE potability join
  harvest_transit.py # G: POI + iRail station/bike join
  harvest_surface.py # A: way[surface] → classify → trim
  heatmap.py         # L: cycle-network density → grid raster
  elevation.py       # B: DEM sampling for climb gradient profiles (uses pipeline /data/dem)
  build_all.py       # orchestrates; writes all atlas/demo/*-data.js fixtures
  README.md          # how to run, sources, licences
```

A `make wallonia-data` target runs `build_all.py`. Overpass responses are cached so reruns are cheap
and we're polite to the API. Curated layers (B climbs, K routes) are authored as **input seed files**
(`tools/wallonia/seeds/climbs.yaml`, `routes.yaml`) — names, endpoints, theme — and the script
fetches geometry/elevation for them; this keeps editorial choices in version control, not buried in JS.

### 5b. Region-balancing algorithm

1. Load Wallonia's **5 provinces** (Hainaut, Liège, Luxembourg, Namur, Walloon Brabant) and **20
   arrondissements** as polygons (OSM `admin_level=6/7`).
2. Point-in-polygon assign every harvested feature to its bucket.
3. **Rank** within each bucket (notability: Wikidata sitelinks / `historic` significance / name present
   / OSM completeness).
4. **Cap** each bucket at `target_total / n_buckets` (± a slack so sparse rural buckets aren't padded
   with junk and dense urban ones aren't starved). Log what was dropped (no silent truncation).
5. Emit fixture with a `prov` property so the map can later filter by province.

### 5c. Output fixtures

Compact-key GeoJSON-ish, matching `water-osm.js` (`t`=type, `n`=name, …) for the bulk layers; richer
objects for curated layers (existing climb/route schema). New/changed files in `atlas/demo/`:

```
services-osm.js  stays-osm.js  transit-osm.js  shelter-osm.js  scenic-osm.js  history-osm.js
water-osm.js (refreshed)   surface-data.js (expanded)   routes-data.js (expanded)
climbs-data.js (NEW — extract B out of map.html into its own fixture)
heatmap-data.js (NEW — L)
```

Each fixture starts with a header comment: source, licence, harvest date, record count, script that
produced it.

---

## 6. Curated content design

### 6a. Legendary climbs (B) — keep what we have, expand the rest

**Already live (keep as-is — these are done well):** Côte de la Redoute, Mur de Huy, Côte de Stockeu
(Merckx stele), Côte de la Roche-aux-Faucons, and Hockai via RAVeL L44a (Wallonia's longest climb).
Each already carries real geometry, a gradient profile, credited Commons photos and a race-heritage
record card. **B work is purely additive — we do not re-collect these.**

**Add (~25 we don't have yet),** matching the existing card quality:

- **More LBL:** Côte de Wanne, Côte de la Haute-Levée, Col du Rosier, Côte de Desnié, Côte de la Vecquée.
- **More Flèche Wallonne:** Côte de Cherave, Côte d'Ereffe, Côte de Bohissau.
- **Region-spread (so B isn't only eastern-Ardennes LBL country):** Thier de Coo, Côte de Saint-Roch
  (Houffalize), Côte de Trasenster, climbs above the Amblève/Semois, plus a few in Namur/Condroz and
  the Pays de Herve.

Target **~30 total (5 kept + ~25 new)**. Each new one: real geometry (OSM + BRouter), DEM gradient
profile, race/heritage record card, and a credited photo where licensable.

### 6b. Themed routes (K) — "nice routes"

~10–14 named loops, each with a theme, season, distance, and an explicit list of the climbs / views /
towns it threads. Candidate set:

1. **LBL Heritage Loop** — the classic climbs, Liège ring.
2. **Vennbahn RAVeL** — flagship rail-trail greenway (Eastern Belgium).
3. **Hautes Fagnes Gravel** — the existing one, kept/refined.
4. **Meuse Citadels** — Namur → Dinant, citadels + Adolphe Sax's Dinant.
5. **Semois Deep South** — Bouillon castle, Orval abbey.
6. **Pays de Herve** — farmland, cider/cheese country.
7. **Mur de Huy / Flèche** — the Flèche Wallonne finale.
8. **Eau d'Heure Lakes** — the big lakes, family/e-bike friendly.
9. **Famenne & Durbuy** — "smallest city," Famenne plains.
10. **Spa & the Amblève** — Spa, Coo waterfall, Stavelot abbey.

(Final list trimmed/expanded on review.)

### 6c. City history along routes — "history of cities ride go through"

For every town a route passes, pull a one-paragraph Wikidata/Wikipedia heritage note (e.g. **Bastogne**
— Battle of the Bulge; **Bouillon** — Godfrey of Bouillon's castle; **Dinant** — citadel + Adolphe Sax;
**Stavelot** — princely abbey; **Durbuy** — medieval "smallest city"; **Huy** — collegiate church).
Surface these as J · History features *and* as a "towns on this ride" block on the route card, so the
narrative the user asked for is literal: ride the route, read the history of each place it crosses.

---

## 7. Map wiring changes (`atlas/demo/map.html`)

- Load the 6 new POI fixtures + `climbs-data.js` + `heatmap-data.js` alongside the existing ones.
- Extract the hardcoded B/D/E/F/G/H/I/J demo records out of the `CATALOG` literal; point each layer at
  its fixture (keep 1–2 hand-written "featured" exemplars per layer if we want editorial highlights).
- Fill the **L heatmap panel** with `heatmap-data.js` and wire its season toggle to season-weighted
  grids.
- Add an optional **province filter** (uses the new `prov` property) — nice-to-have, flagged not core.
- Update legend counts / "figures are illustrative" demo banner copy to reflect real-but-capped data.

---

## 8. Verification & quality gates

Per the project's "verify lightly" rule — no Playwright screenshot marathon:

- **Per fixture:** node/grep sanity — valid JS, record count in header matches array length, every
  feature has coords inside the Wallonia bbox, required keys present, photo entries carry a licence.
- **Region balance:** a `build_all.py --report` printing per-province counts per layer, so we can eyeball
  the spread.
- **Licence lint:** fail the build if any photo lacks `credit`+`license`.
- **One manual map load** at the end (browser) to confirm layers render and the heatmap shows — only
  once, not per edit.

---

## 9. Phased implementation roadmap

Each phase is independently reviewable; data lands incrementally so the map gets richer step by step.

- **Phase 0 — Toolchain spine.** `bbox.py`, `overpass.py` (cached), region polygons, `harvest_poi.py`
  generic harvester, `make wallonia-data` target, README. Deliverable: one POI layer (D · services)
  harvested end-to-end as the reference implementation.
- **Phase 1 — Bulk POI layers.** E, G, H, I, J via the generic harvester + per-source joins (SNCB for
  G). Region-balanced, capped. Deliverable: 6 POI fixtures wired into the map.
- **Phase 2 — Enrichment.** Wikidata/Wikipedia descriptions + Commons photos for E/I/J; SWDE potability
  for C; licence lint green. Deliverable: rich cards.
- **Phase 3 — Curated geometry.** A surface expansion; B legendary-climbs seed + DEM profiles
  (`climbs-data.js`); city-history notes. Deliverable: legendary climbs + surface live.
- **Phase 4 — Routes & heatmap.** K themed routes (BRouter geometry + linked POIs); L heatmap raster +
  panel wiring. Deliverable: routes tell stories, heatmap glows.
- **Phase 5 — Polish & verify.** Region-balance report, demo-banner/legend copy, single manual map load,
  data-catalog wiki update. Deliverable: review-ready "live-real" Wallonia.

After your approval I'll expand this roadmap into a task-level implementation plan (writing-plans skill)
and start at Phase 0.

---

## 10. Risks & open questions

1. **SWDE licence (C potability)** — need to confirm their open-data terms permit redistribution before
   wiring it. Fallback: ship water without potability verification (as today), flag unverified.
2. **Commons photo licences** — per-file check is unavoidable; automate the licence pull, drop any
   photo we can't licence cleanly rather than guess.
3. **Overpass load** — large Wallonia-wide queries; mitigate with bbox tiling + caching + off-peak runs.
4. **"Region-balanced" caps** — exact per-layer totals in §3 are proposals; easy to tune on review.
5. **Heatmap honesty** — *resolved:* synthetic illustrative heat, grounded in OSM network density,
   labelled "illustrative" under the demo banner. No real ride data, no Strava.
6. **Curated route taste** — the §6b route list is my pick; tell me which to keep/drop/add.
7. **Hazards (F)** — confirmed left as-is (won't fabricate safety data).

---

## 11. Decision log

- Heatmap is **L**, already specced as a derived overlay (2026-06-18) — this fills it, doesn't add a
  layer.
- **L heatmap is synthetic/illustrative** (no real ride data, no Strava), but grounded in OSM
  cycle-network density so it looks plausible; shown under the existing "figures are illustrative" demo
  banner. (User decision, 2026-06-24.)
- Curated editorial choices (climbs, routes) live as version-controlled **seed files**, geometry fetched
  by script — not hand-pasted into JS.
- "Make up nice routes" interpreted as **design appealing curated routes on real geometry**, not invent
  fake paths — every route must be rideable and OSM/BRouter-derived.
