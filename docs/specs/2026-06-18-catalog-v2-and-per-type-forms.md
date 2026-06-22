# Spec — Catalog v2 (A–L), Road-surface layer, Quality rides & per-type edit forms

- **Status:** Approved (scheme chosen: A–L) — implementing
- **Date:** 2026-06-18
- **Scope:** Frontend demo only
- **Surfaces:** `atlas/demo/map.html`, `atlas/demo/index.html`, `atlas/demo/region.html`, `atlas/demo/edit-items.js`,
  `atlas/demo/improve.html`, **new** `atlas/demo/surface-data.js`; `wiki/data-catalog.md`, `wiki/building.md`;
  sitewide footer repo link.
- **Supersedes parts of:** [`2026-06-18-consistency-audit.md`](../2026-06-18-consistency-audit.md) findings
  A1, A2, A3, A4, B2; and the catalog rows in
  [`2026-06-17-map-showcase-design.md`](specs/2026-06-17-map-showcase-design.md).

---

## 1. Canonical catalog — A–L (12 layers)

Re-letter the live catalog to a single monotonic scheme, applied to the map, landing, wiki and spec:

| Letter | Label | Notes / change |
|---|---|---|
| **A** | Road surface | **NEW** — line layer; RAVeL / gravel / pavé segments styled by surface |
| **B** | Climbs | unchanged |
| **C** | Water & food | unchanged |
| **D** | Bike services | unchanged |
| **E** | Where to sleep | unchanged |
| **F** | Hazards & conditions | unchanged |
| **G** | Getting there | unchanged |
| **H** | Shelter & emergency | unchanged |
| **I** | Scenic views | split from old "Scenic & cultural" — keeps the viewpoint (Botrange) |
| **J** | History & culture | **split out** — heritage/POI (Stavelot Abbey) moves here |
| **K** | Quality rides | was J "Rides"; GPX loops **plus** cyclist-experience attributes |
| **L** | Ride heatmap | was the "K · derived" box; derived & aggregate |

**Note on L:** `L · Ride heatmap` is a *derived overlay* with its own toggle + season panel, **not** a
`CATALOG` entry — so the generated rail/legend list **A–K** layers, and L appears as the labelled
"derived (L)" heatmap panel. (It is intentionally not a togglable catalog layer.)

The map rail, on-map legend and landing sampler are generated from the catalog, so most re-lettering
is automatic once `CATALOG` is updated; the landing's hand-written sampler cards and the heatmap
panel's "derived (K)" label are the manual spots.

## 2. A · Road surface — data & depiction

**OSM source.** Surface lives on the way geometry: `surface=` (asphalt, concrete, paving_stones,
**sett/cobblestone** = pavé, compacted, fine_gravel, **gravel**, ground…), `smoothness=`
(excellent→impassable), `tracktype=grade1–5`, `width=`, and `cycleway:surface=`. RAVeL paths are
`highway=cycleway` gathered in route relations (`network=rcn/lcn`). Production extraction = an
Overpass `way[surface]` query in the region bbox.

**Demo data** (`atlas/demo/surface-data.js`, `window.CC_SURFACE`): 3–4 hand-picked real segments —
a RAVeL ex-railway (smooth asphalt), a forest **gravel** sector, and a **pavé/sett** sector — each
`{name, surface, smoothness, width, traffic, path:[[lat,lon]…]}`.

**Rendering (MapLibre).** Draw each segment as a line, styled by a derived `surfaceClass`:
- **colour family** — paved/asphalt = neutral slate, smooth cycleway = teal, **gravel/compacted =
  ochre**, **pavé/sett = clay**, ground/dirt = brown;
- **pattern** — solid (paved) / dashed (gravel-unpaved) / dotted (rough). MapLibre can't data-drive
  `line-dasharray`, so render **one thin sub-layer per surface class** filtered on the property
  (same technique as the gradient climb sub-layers);
- **width** scaled by the `width` tag.
- Drive primarily off `surface`, secondarily `smoothness` (CyclOSM's known bug renders
  `smoothness=intermediate` as solid and hides gravel — we avoid that by keying on surface).

Drawer rows: surface · smoothness · width · traffic, `source: 'OSM (surface=*)'`.

## 3. K · Quality rides — cyclist-experience attributes

Keep the 6 GPX loops under K, relabel "Quality rides", and show the experience attributes in the
drawer (with provenance tags in the spec's `[ ]` convention):
- Quietness / traffic level `[auto][tap]`
- Scenic rating `[tap]`
- Overall cycling-friendliness `[tap]`
- Suitability by bike type — road / gravel / MTB / e-bike `[edit]`
- Accessibility — adapted-bike / handbike friendly, gradient-limited `[edit]`
- Best direction to ride the loop `[edit]`

These are demo values per route; they make K the carrier of the wiki's "cyclist-experience" concept.

## 4. Per-type edit forms (improve.html)

The generic "Add missing" pane is useless for, e.g., a gîte. Make **every pane type-aware**:
- **Fix details** and **Add missing** fields both come from the registry per item type
  (`fields` and new `addFields`).
- **Add a photo** is available on **every** type (photos are always welcome).
- **Rides additionally** get the **GPX/FIT track** upload — rides keep *both* photo and track tabs.
- No separate HTML file per type; one data-driven `improve.html` renders the right form. Registry
  gains entries for `history-*`, `road-surface`, and keeps the shared `ride` (now Quality ride).

## 5. Provenance, honestly shown (audit A3/B2)

- Climb `source` labels: drop the stale "via OSRM"; the curated lines were hand-built from OSM road
  geometry → `source: 'OSM roads · geometry handmade'`.
- Model framing (wiki `building.md`): geometry is **handmade**, or **auto via Valhalla + DEM**, or a
  **combination** — shown per feature as the Commons is built. `add-climb.html` still uses OSRM for
  the live draw-a-route preview; that's labelled where it's used.

## 6. Repo name (audit A2)

Public repo is **`cycling-commons`**. Standardise site + wiki + spec on `cycling-commons`
(footer links, clone command, tree links). The GitHub remote (currently `CyclingCommons`) needs
renaming to match before launch; links resolve once renamed.

## 7. Acceptance

- Map rail/legend show the **A–K** layers (L is the derived heatmap panel) with the new labels; A·Road surface renders coloured/patterned
  segments; K·Quality rides drawer lists the six experience attributes; History (J) and Scenic (I)
  are separate layers/pins.
- Landing sampler has no duplicate letters; shows distinct Scenic (I) and History (J) cards.
- Editing a gîte shows gîte-relevant Add fields (not "work stand?"); every type can add a photo;
  a ride can upload a GPX/FIT track.
- No "via OSRM" on curated climbs; repo links read `cycling-commons`.
- Wiki `data-catalog.md` matches the A–L scheme.

## 8. Later additions (map behaviour)

Built on top of catalog v2:
- **City links + 5 km radius.** Route towns ("Starts at" / "Towns on route") are clickable: each flies to
  the town and opens a city card (blurb + Wikipedia + community-notes slot) listing every feature within
  **5 km**, sorted by distance, each clickable.
- **Deep-link `?feature=<name>`.** Opens that feature's drawer, activates its layer if hidden, and zooms in
  — used by **profile** contribution cards (each links to the real item on the map).
- **Hover-highlight.** Hovering a city link or a nearby-item pulses a marker at its map location.
- **Difficulty** is a 1–5 climb-purple circle scale (the feature's level enlarged/bold; hover for the label).
- **Photo galleries** open as a **slideshow** (‹ › + ←/→); every feature carries ≥1 verified Commons photo.
- **Ride privacy:** first & last ~350–750 m of each ride trimmed.
- **Defaults:** all catalog layers on at load, incl. K · Quality rides; Stavelot Abbey is curated so
  J · History & culture shows in curated mode.

## 9. Non-goals

- No live Overpass fetch (surface data is a hand-picked fixture, like the climbs/routes).
- No backend, auth, or persistence. No renaming of the GitHub remote (ops task, noted not done).
