# Spec — Add a climb to the atlas

- **Status:** Draft for review
- **Date:** 2026-06-17
- **Surface:** `atlas/demo/add-climb.html` (new), plus MapLibre migration of every map-bearing page: `atlas/demo/map.html`, `atlas/demo/improve.html`, `atlas/demo/moderate.html`
- **Author:** Cycling Commons / prototype team
- **Related:** [`atlas/demo/designer-brief.md`](../../atlas/demo/designer-brief.md), [`atlas/demo/style-guide.md`](../../atlas/demo/style-guide.md), [`atlas/demo/improve.html`](../../atlas/demo/improve.html), [`atlas/demo/vote.html`](../../atlas/demo/vote.html)

> This is the **reference template** for the per-page documentation pattern: every prototype page
> should eventually have a clickable demo, a spec doc (this format), and an implementation plan.
> Backfilling the existing pages happens in later passes; this page sets the conventions.

---

## 1. Problem

The prototype lets riders *improve an existing place* ([`improve.html`](../../atlas/demo/improve.html)) and *rank a
region's best* ([`vote.html`](../../atlas/demo/vote.html)), but there is **no flow for contributing a brand-new climb**.
A climb is also not a point like a water tap — it is a **linear feature** (a foot and a summit with a
profile between them), so it cannot reuse the single-pin `improve.html` pattern as-is.

We need a guided, on-brand flow that lets a rider add a climb the atlas is missing, and that honestly
shows what happens to that contribution afterward (curator review → backlog → voting → curated best-of).

## 2. Goals / Non-goals

**Goals**
- A clickable, self-contained prototype page (`add-climb.html`) in the "Riders' Atlas" skin.
- A **draw-a-segment** locate step: click the foot, then the summit; the map draws the line and derives length.
- A short, honest **profile** step (length auto-derived; elevation gain hand-entered in the prototype).
- A **governance flow diagram** ("the journey of a climb") that is always visible and educates before submit.
- Migrate the prototype's map pages to **MapLibre GL JS** (keyless **OpenFreeMap** basemap).
- Establish the spec + plan doc template under `docs/`.

**Non-goals (YAGNI)**
- No real backend, auth, persistence, or moderation logic — this is a visual prototype.
- No real elevation service (no DEM) in the prototype; see the honesty note in §6.
- No routing / road-snapping of the drawn segment in the prototype.
- Not backfilling specs/plans for the other ~12 pages in this effort.

## 3. Success criteria

- Opening `add-climb.html` and clicking through reaches the "submitted" state with no dead ends.
- Drawing a foot + summit produces a visible line and a **live length** figure (real, from the map).
- Avg gradient updates live from `gain ÷ length`; changing gain updates it.
- The page passes a manual a11y pass: keyboard-reachable controls, focus rings, ≥7:1 body contrast,
  `--trail` used only for non-text / large text, `prefers-reduced-motion` respected.
- `map.html` and `improve.html` render on MapLibre with the atlas tint and no Leaflet references remain.

## 4. The flow (wizard)

A numbered stepper rail; one pane visible at a time; Back/Next controls (Next disabled until the
step's minimum is met). Sequential variant of the `improve.html` tab pattern.

| # | Step | Contents | Gate to advance |
|---|------|----------|-----------------|
| 1 | **Where does it climb?** | MapLibre map. Click the **foot**, then the **summit**. A Trail-Orange `LineString` is drawn between them; start/summit markers placed. A coordinate tick shows `◎ start → ▲ summit`. **Length** computed live (haversine). "Reset" clears the two points. | Both points placed. |
| 2 | **The profile** | Name (Fraunces live preview), Length (auto, editable), **Elevation gain** (number input), **Avg gradient** (auto = gain ÷ length, read-only), Max gradient (optional), Surface (select: Asphalt / Concrete / Gravel / Cobbles / Mixed). | Name + elevation gain present. |
| 3 | **Details** | Discipline chips (Road / Gravel / MTB / Touring / Bikepacking / E-bike), multi-select. Rider note (textarea). Photo drop with the **CC BY-SA 4.0** notice copy reused verbatim from `improve.html`. "Already in OpenStreetMap?" toggle (Unknown / Yes / No). | None (all optional). |
| 4 | **Review** | An atlas result-card summarising every entered value (title, coord tick, tags, profile figures, disciplines, note). Provenance line: goes to a curator's queue; approved facts join the Commons under **ODbL** (data) / **CC BY-SA 4.0** (media); provenance kept, identity not. | — (Submit button). |
| 5 | **Submitted** | "It's in the queue" confirmation. Highlights node 1 of the governance diagram as "you are here." Link back to the map. | — |

## 5. The governance flow diagram (Part B)

An always-visible, contour-styled storyboard below the wizard titled **"The journey of a climb."**
Linear spine with two branches:

```
You draw it  →  Curator review queue  →  ✓ Approved  →  Joins "Everything" backlog
                                                           →  Eligible next voting round
                                                           →  Voted up  →  Curated best-of
                          │
                          ├─ ⟲ Needs info  →  back to you
                          └─ ✕ Rejected (with reason)
```

- Nodes are atlas cards (paper-deep, hairline border, mono micro-label, freshness-style dot).
- On the **Submitted** step, node 1 ("You draw it") gets a "you are here" highlight.
- Copy ties to governance language already in the prototype (rounds, re-ranked never reset, provenance not identity).

## 6. Honesty note (important)

There is **no elevation service** in a static prototype. Therefore:
- **Length is real** — computed by haversine from the two clicked map points.
- **Elevation gain is hand-entered**, with a clear helper hint ("from your computer/GPS — in production this
  is sampled from an open DEM"). We do **not** fabricate a derived elevation and present it as measured.
- Avg gradient is an honest computation of the two (`gain ÷ horizontal length`).

## 7. Tech / stack

**Prototype (this work)**
- **MapLibre GL JS** via CDN (replacing Leaflet across the prototype).
- Basemap: **OpenFreeMap** `liberty` style (keyless, MapLibre-native, ODbL/OpenMapTiles). Falls back
  gracefully if offline (map area shows paper-deep background; flow still works).
- Atlas tint applied via a CSS `filter` on the map canvas (matching the existing grayscale/sepia leaflet tint).
- Drawn climb = a GeoJSON `LineString` source + line layer; foot/summit = MapLibre markers.
- Fonts, palette tokens, grain overlay, dual-licence footer: identical to the other pages.

**Production (recorded, not built here)** — recommended open-source stack:
- Render: **MapLibre GL JS**. Tiles: **PMTiles** (OpenMapTiles/Shortbread) on a CDN; start on OpenFreeMap.
- Contours + **elevation**: terrain-RGB from **Copernicus GLO-30 / SRTM** (this is where production derives
  elevation gain, replacing the prototype's hand-entry). Search: **Photon**/**Nominatim**.
  Optional climb snapping: **Valhalla**/**GraphHopper**.

## 8. Migration: Leaflet → MapLibre  ✅ done

*(Historical — completed. Every map-bearing page — `map.html`, `improve.html`, `moderate.html`,
`add-climb.html` — now uses MapLibre GL; no Leaflet remains in the codebase.)*

These three pages used Leaflet originally: `map.html`, `improve.html`, and `moderate.html` (moderator queue map).
- Replace Leaflet CSS/JS includes with MapLibre GL CSS/JS.
- Replace `L.map`/`L.tileLayer`/`L.marker`/`L.divIcon`/`L.popup` with MapLibre equivalents
  (`maplibregl.Map`, OpenFreeMap style, `maplibregl.Marker` with custom HTML elements, `maplibregl.Popup`).
- Preserve current behaviour: `map.html` clustered/category pins + popups + legend; `improve.html` single
  context pin + mini-map; `moderate.html` pulsing queue pins + click-to-select + fly-to. Keep the atlas
  tint and the `.cc-pin` / `.mp` marker visuals.
- Verify no `leaflet`/`L.` references remain.

## 9. Wiring / entry points

- `map.html`: add a `+ Add a climb` affordance (near the region label / rail foot) → `add-climb.html`.
- `pages.html`: add a "Contributor — Add a climb" card.
- Keep cross-links consistent with existing nav/footer patterns.

## 10. Risks

- **OpenFreeMap availability / CORS** in a file:// context — mitigate with graceful fallback and test via a local static server.
- **MapLibre marker rotation** for the teardrop `.cc-pin` — markers are HTML elements, rotate via CSS as today.
- Scope creep into the other pages' migration — explicitly bounded to the 2 Leaflet pages.

## 11. Out of scope / follow-ups

- Backfill spec + plan + demo verification for the other prototype pages.
- Real DEM-derived elevation, geocoding search, routing.
