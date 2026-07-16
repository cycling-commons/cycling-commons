> **Consolidated into** map-and-search.md (rail-as-catalog-index, tooltip+drawer model, drawer anatomy, omit-unknowns rule, line/area layer rule) and edit-items/README.md (best-of derivation, already official) **(2026-07-16).** This dated working doc is sweepable; the canonical docs above are the source of truth.

# The Map is the Showcase — real Ardennes data across the full catalog

> ⚠️ **Superseded by [2026-06-18-catalog-v2-and-per-type-forms.md](2026-06-18-catalog-v2-and-per-type-forms.md).**
> The catalog rows below use the *old* A–K scheme (A = Map & surface, I = Scenic & cultural,
> J = Cyclist-experience, K = Derived). The live catalog is **A–L**: A Road surface · B Climbs ·
> C Water & food · D Bike services · E Where to sleep · F Hazards & conditions · G Getting there ·
> H Shelter & emergency · **I Scenic views** · **J History & culture** · **K Quality rides** (carries the
> cyclist-experience attributes) · **L Ride heatmap** (derived). Read this file for the showcase intent,
> not the letters.

> ⚠️ **Route-domain carve-out (2026-07-08):** today's **K (Quality rides)** is now a full route
> domain — see the [route-domain design](2026-07-08-route-domain-design.md). Routes are
> rider-proposed (GPX + metadata), desk-moderated by curators, ride-verified ("I rode this"),
> and voted with typed seasonal votes per (region, season, bike type); riders never edit route
> data (`/improve` refuses `type=K`). Every "K" *below* is the old Derived & aggregate overlay
> (today's **L Ride heatmap**) — the inline K notes re-point those passages.

**Date:** 2026-06-17
**Status:** Superseded (catalog rows) — see catalog-v2
**Surface:** `atlas/demo/map.html` (primary). `atlas/demo/region.html` deferred to a later pass.

## Goal

Turn `map.html` into the single surface that makes the whole Commons vision legible.
The rail's controls are the index of the data catalog (A–K); you filter/search to define
the visible set; you select a feature; the full **real** record is revealed. One fully
populated, verified Ardennes example exists for every content type in the catalog, so a
viewer (and a future intro video) sees exactly what the finished Commons feels like.

This is the "inspirational preview" made concrete: a curated set of *real, hand-placed*
examples — not a live feed. The existing preview badge stays; honesty is preserved by
sourcing only verifiable facts and citing provenance per record.

## Context

- Catalog taxonomy lives in `wiki/data-catalog.md`: types **A–K** with a per-type
  attribute schema ("Sub-items & attributes"). B (Climbs) is the canonical worked example.
- `map.html` already holds a `CATS` object with 6 layers (climbs, water, stays, hazards,
  views, services) of Ardennes-anchored *sample* points. Each point today is thin:
  `{name, ll, co, tags, cur, sq?, tr?}`, rendered as a MapLibre marker with a one-line popup.
- Region demo = Wallonia / the Ardennes (Liège–Bastogne–Liège country). Keep this anchor.
- MapLibre GL 5.24.0, OpenFreeMap "liberty" style, region mask via Nominatim.

## Decisions (from brainstorming)

1. **Deliverable:** populate the real prototype; **map only** this round (region deferred).
2. **The map is the showcase** — select on the map → the record shows.
3. **Display = hybrid:** hover/focus tooltip (name + headline stat) + a right-side detail
   drawer with the full structured record on click.
4. **The rail's search/filter controls define the feature set** — expose the full A–K
   taxonomy as layers/filters, making the rail a navigable index of the Commons.
5. **Data rule:** verified real anchors; fill only attributes that can be verified/
   researched; **omit** unverifiable fields rather than invent them.
6. **K (derived/aggregate):** implement fully as a computed map overlay.

   > **Superseded for K (2026-07-08):** old-letter K — the computed overlay is today's
   > **L (Ride heatmap)**; the current K is the rider-facing recommended-route domain
   > (line features, not a derived layer).

## Content types → map representation

One real, verified Ardennes example per type (more where natural). `[OSM]`/`[edit]`/
`[auto]`/`[safety]` mark contribution method. Coordinates/stats verified at build time.

**Point features (pins):**
- **B · Climbs** — Côte de la Redoute (full worked example) + Mur de Huy, Côte de Stockeu,
  Côte de la Roche-aux-Faucons (enrich the existing four to full records).
- **C · Water & food** — a verified fountain/tap in Stavelot + one cyclist café (only if a
  real, verifiable establishment; else fountain only).
- **D · Bike services** — a real repair station (Malmedy) / bike shop.
- **E · Where to sleep** — a real bike-friendly gîte/B&B (name + booking link only if
  verifiable; otherwise type/amenities omitted).
- **F · Hazards** — a *persistent*, real hazard (Hautes Fagnes crosswind exposure, or a
  known technical descent) with freshness/last-confirmed.
- **G · Getting there** — a real station with bike-on-train access (Aywaille or Verviers).
- **H · Shelter & emergency** — a real refuge/cabane on the Hautes Fagnes, or a pharmacy.
- **I · Scenic & cultural** — Signal de Botrange (694 m, highest point of Belgium) +
  Stavelot Abbey (heritage POI, at the foot of the Côte de Stockeu).

**Line / segment features:**
- **A · Map & surface** — one real road/segment (a RAVeL cycleway, or a gravel/pavé
  sector) as a line with surface · smoothness · width · traffic.
- **J · Cyclist-experience** — 1–5 ratings (quietness, scenery, surface, suitability,
  accessibility) on a real route (e.g. the quiet Amblève valley road), as a rated segment.

  > **Superseded for K (2026-07-08):** the cyclist-experience attributes moved to K, where
  > suitability is a curator-declared multi-select of bike types (Road/Gravel/MTB/E-bike/
  > Handbike) with typed votes and ride confirmations — not a 1–5 rating on a rated segment.

**Computed overlay:**
- **K · Derived & aggregate** — a shaded area layer over the region showing a computed
  metric (coverage % / road popularity), labelled **"computed · auto"**. Selecting it opens
  a drawer card explaining it is an *output* of the Commons, never hand-entered.

  > **Superseded for K (2026-07-08):** this is old-letter K, today's **L (Ride heatmap)** —
  > "never hand-entered" now belongs to L, while the current K *is* hand-entered
  > (rider-proposed GPX, desk-moderated, curator-edited only) and renders as route lines
  > with a proposed/verified badge.

**Deliberate geographic cluster (storytelling beat):** Stockeu (B) + Stavelot Abbey (I) +
Stavelot fountain (C) sit adjacent. Place them so a single area zoom reveals climb +
heritage + water together — the "one click, the whole story" moment for the map and video.

## Data model (extended `CATS` schema)

Extend each feature beyond the current thin shape to carry the full record. Per feature:

```
{
  id, name, type: 'B',            // catalog letter
  geom: { kind:'point'|'line'|'area', ll | path | polygon },
  headline,                       // one-line stat for tooltip ("8.9% avg · 2.0 km")
  cur: true|false,                // demo best-of flag (drives Best-of vs Everything). In production this is
                                  // DERIVED, not hand-set: an item is best-of only if it's a *votable* type
                                  // that's been verified and top-voted. Utility/coverage types are never
                                  // best-of. See edit-items/README.md#item-lifecycle-and-votability.
  sq?, tr?,                       // surface/traffic (climbs) for the existing chip filters
  record: [                       // ordered attribute rows for the drawer
    { label, value, method?: 'OSM'|'edit'|'auto'|'safety' }
  ],
  freshness?: { state:'fresh'|'ageing'|'stale', lastConfirmed },  // dynamic/[safety] items
  source: 'OSM + community edits' | 'OSM' | 'computed' | …,        // provenance line
}
```

> **Superseded for K (2026-07-08):** routes left the item pipeline — the
> item-lifecycle-and-votability reference no longer applies to K, whose verification is the
> ride-confirmation threshold (`route_ride`) and whose best-of is computed per
> (region, season, bike type) from typed `route_vote` rows.

Only attributes with a real value appear in `record`; unknowns are omitted (no blank rows).

## The rail = the catalog index

- "Data layers" expands from 6 → the full **A–K**, each labelled by letter + name, with a
  count, individually toggleable. Group ordering follows the catalog.
- Existing controls stay and compose: search box, Best-of/Everything mode, surface/traffic
  chips, discipline chips. Together they *define* the visible feature set.
- Line and area types (A, J, K) render as MapLibre sources/layers (not markers); their rail
  toggles control layer visibility the same way.

  > **Superseded for K (2026-07-08):** old letters — the area type meant by K here is
  > today's L; the current K (recommended routes) renders as line features, so the
  > non-marker approach stands. See also the
  > [route-domain design](2026-07-08-route-domain-design.md): Best-of mode gets concrete
  > route backing (rankings per region · season · bike type from typed votes).

## Interaction (hybrid)

- **Hover / keyboard-focus** a feature → lightweight tooltip: `name · headline`.
- **Click / Enter** → right-side **detail drawer** slides in:
  - Header: type chip (letter + name), feature name, best-of badge if applicable.
  - Body: the `record` rows; method tag per row where relevant.
  - Freshness block for `[safety]`/dynamic items (state + last-confirmed).
  - Source/provenance line.
  - Footer action: contextual link (vote in round / improve this place) reusing existing
    `improve.html` / vote affordances.

  > **No longer true for K (2026-07-08):** riders never edit route data (`/improve` refuses
  > `type=K`), so the improve link is dead for routes; the route drawer footer is instead
  > vote (typed: season + bike type, replacing round-based voting) · "I rode this" ·
  > GPX download · suggest a correction, and the header adds a "proposed" badge on
  > unverified routes.

- Drawer is dismissible (close button, Esc, click-away). Map stays visible and interactive.
- Accessibility: drawer is focus-managed; tooltip is non-essential (drawer is the source of
  truth); honors the existing skip-link/landmark conventions.

## Honesty / framing

- "Inspirational preview" badge unchanged.
- Drawer shows provenance per record; nothing is asserted as live.
- Map result count reflects the real best-of example set, not invented totals.

## Scope

- **In:** `atlas/demo/map.html` — extend `CATS` to full A–K with real data; add line + area
  features; tooltip; detail drawer (markup + CSS + render logic); expand rail layer list;
  wire line/area layers to MapLibre sources.
- **Deferred:** `atlas/demo/region.html` consistency/honesty pass (separate later task).
- **Out (now):** the intro video itself (this produces the video-ready showcase); any real
  backend/live data; per-feature standalone pages.

## Verification rule (real data)

Verified at build time: climb length/avg/max gradient and coordinates; place names and
coordinates; station bike-access facts; viewpoint elevation; heritage facts. Use web/OSM
checks. **Omit** anything not standable-behind (specific café hours, a B&B's exact
amenities) rather than invent. Note source per record.

## Testing / verification

- Load `map.html` headless (Chrome) + via Playwright: 0 console errors, MapLibre renders.
- Each A–K layer toggles on/off; counts match the data.
- Selecting one feature per type opens the drawer with its real record; tooltip shows on
  hover/focus.
- The K overlay renders as a shaded area and is clearly labelled computed.

  > **Superseded for K (2026-07-08):** the shaded computed overlay is today's L; new-K
  > checks (route lines, drawer vote/rode-it/GPX/suggestion actions, proposed badge) live
  > in the route-domain design's test plan.

- Region mask + base map still load (no regression to existing behaviour).
- SRI on the MapLibre tags remains intact (no version change here).

## Risks / open items

- **K overlay realism:** the computed metric is illustrative (no live pipeline); it must be
  visibly labelled "computed · auto" so it is not read as a measured statistic.
- **Real-data gaps:** some types (E stays, F hazards) may yield sparse records under the
  omit-unknowns rule; that is acceptable and honest.
- **Drawer vs existing popup:** the current popup is replaced by tooltip+drawer; ensure the
  vote/improve links survive the move.

> **Superseded for K (2026-07-08):** the overlay-realism risk now attaches to L (Ride
> heatmap); and for routes the improve link must *not* survive the move — riders get
> vote / "I rode this" / suggest-a-correction instead (only the vote link survives, in
> typed seasonal form).
