<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# GIS from scratch — a wiki primer for new developers

**Status:** design, approved 2026-07-23 · **Audience:** contributors to Cycling Commons ·
**Output:** 11 new pages under `wiki/developers/gis/`

## 1. Why

Cycling Commons is a GIS application wearing a Symfony coat. A new contributor can be a strong
PHP, JavaScript or Python developer and still be unable to read `RideCheckService`, change a
MapLibre layer, or understand why the coverage pipeline exists at all — because nothing in the
repo teaches the spatial concepts those things assume. The onboarding gap is not "learn our
codebase"; it is "learn the field our codebase is in".

This primer closes that gap. It assumes **zero GIS knowledge** and takes a competent developer to
the point where the spatial parts of this repo read as ordinary code.

## 2. Decisions

Seven decisions were taken with the owner on 2026-07-23:

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | Reader has **zero GIS knowledge** | Widest usefulness; a maps-literate reader can skip chapters, a beginner cannot invent them |
| D2 | Lives in a **new "For developers" wiki section** | Keeps the community-facing IA (manifesto, catalog, governance) clean while giving developer docs room to grow beyond this series |
| D3 | Visuals are **hand-authored inline SVG only** | Originally SVG plus Mermaid for the two pipeline flowcharts. Mermaid dropped 2026-07-23: Material 9.7.6 does not bundle it and lazy-loads `mermaid@11` from unpkg, which would put a third-party request on a wiki that ships no third-party JS. Two more boxes-and-arrows diagrams against fourteen already being drawn is a small cost for one visual language and no CDN |
| D4 | **Read-only** body with real code citations | No exercise can break or drift; every claim is checkable by opening a file |
| D5 | Exercise slots **reserved, filled later** | Each chapter ends with a marked slot so hands-on "run this against the Docker stack" boxes drop in without a rewrite |
| D6 | Structure is **layered order, narrative spine** | Concepts must build (no tiles before coordinates), but each chapter opens on the next stop in one worked example so the reader always knows why |
| D7 | **Short excerpts, path citations** | The wiki is CC BY-SA 4.0, the code is PolyForm Shield; citing `File.php` + symbol beats pasting functions, and it rots slower |
| D8 | A concept with no repo anchor is **written and flagged, never cut** | An unanchored concept is a signal. Either it is general GIS knowledge this system genuinely has no counterpart for, or it is a gap the owner may want built. Cutting it destroys that signal; §10 collects them for review |
| D10 | **Text first; figures are drawn later, from a written brief** | Owner decision 2026-07-23, after reviewing the first four figures. A drawing cannot be judged before the passage it serves exists — "without the text I do not yet know if they visualize correctly what is thought." So a chapter written from here on carries a **visible placeholder** where its figure goes: what the figure must make the reader see, and a brief concrete enough to author from. The `<figcaption>` is written **now**, not deferred: it states the point the figure has to land, so it doubles as the brief's acceptance test. Placeholders are greppable (`<!-- FIGURE-TODO id=FN ch=N -->`) so none can ship unreplaced. F1–F5, F8, F9 and F16 were drawn before this decision and stand |
| D9 | **No page-count or word budget** | Owner decision 2026-07-23. A chapter runs as long as the teaching needs, and the series takes as many pages as the material warrants. Completeness beats brevity here — this is reference material a developer reads once and returns to, not a landing page |

### The narrative spine

One drinking-water fountain in Wallonia travels the whole system: an OSM node → a Geofabrik PBF →
a parsed record → a `coverage_poi` row → a tile feature → a pixel on the map → a rider tapping
"still here?". Each chapter opens on that fountain's next stop, then teaches the concept the stop
depends on. Chapter 9 (routes) steps off the spine deliberately, because a line is not a point and
the difference is the lesson.

## 3. Index

Filenames are semantic, not numbered — MkDocs nav is explicit, and the existing wiki
(`building.md`, `landscape.md`) sets that house style.

| # | File | Title | Teaches | Anchored in |
|---|------|-------|---------|-------------|
| 0 | `index.md` | Start here | Who this is for, the fountain's journey as a one-screen map of the series, how to read the code citations | — |
| 1 | `coordinates.md` | The Earth is awkward | Latitude/longitude, degrees, why a degree of longitude shrinks toward the poles, what a projection is and what it costs, SRIDs, WGS84 (EPSG:4326) vs Web Mercator (EPSG:3857) | `Catalog/Doctrine/GeometryType.php` — why every column is `geometry(Geometry, 4326)` |
| 2 | `shapes.md` | The shapes | Point, LineString, Polygon and their Multi- forms; GeoJSON as the wire format; ring winding | `item.geom` = the fountain, `recommended_route.geom` = a ride, `region.geom` = Wallonia; the `ST_AsGeoJSON`/`ST_GeomFromGeoJSON` round-trip in `GeometryType.php` |
| 3 | `metres-vs-degrees.md` | Metres vs degrees | The most common beginner bug: degrees are not distance. The `::geography` cast, what it buys, what it costs | `Service/BaseAreaResolver.php` — `ST_DWithin(r.geom::geography, …, :m)` answering "regions within N km of me" |
| 4 | `spatial-questions.md` | Asking spatial questions | `ST_Contains`, `ST_Intersects`, `ST_DWithin`, `ST_Distance`, `ST_Buffer`, `ST_ClosestPoint`, `ST_LineLocatePoint`; closing section on asking *by name* (geocoding is a text problem with a spatial tiebreak, not a spatial query) | `Contribution/SpatialResolver.php` (which region is this point in?), `BaseAreaResolver` (what is near me?), `Catalog/RideCheckService.php` (what did I ride past, and in what order?) |
| 5 | `making-it-fast.md` | Making it fast | Bounding boxes, the two-phase GiST index (cheap candidate filter, then exact recheck), why "it works on 100 rows" lies | `CREATE INDEX … USING GIST (geom)` in `Version20260703153611.php`; the war story in `RideCheckService::corridorGroups()`, where the naive `ST_DWithin(::geography)` sequential-scanned with spheroid maths and a `MATERIALIZED` buffer + `ST_Intersects` let the index serve it |
| 6 | `osm-to-database.md` | From OpenStreetMap to our database | The OSM data model (node/way/relation plus free-form tags), why tags are not a schema, the PBF format, Geofabrik extracts, normalising foreign data into a table we own | `pipeline/coverage/extract.py`, `parse.py`, `load.py`; the 4.7M-row planet target and the decision to keep only the contract's tag keys |
| 7 | `tiles.md` | Tiles | Why you cannot send 300,000 points to a browser; the z/x/y pyramid and slippy-map maths; raster vs vector tiles (MVT); PMTiles as a single range-read archive; server-side clustering | `pipeline/coverage/tiles.py` — zoom 6–14, `--cluster-distance 20`, `--cluster-maxzoom 11`, and why layers split per country so tippecanoe never clusters across a border |
| 8 | `on-screen.md` | Putting it on screen | MapLibre's model: style → source → layer → `source-layer`; paint vs layout; data-driven styling; hit-testing with `queryRenderedFeatures` | `web/assets/map/map.js` — the `pmtiles://` vector source, one symbol layer per (letter, country), the GeoJSON region mask, the heatmap, client-side clustering |
| 9 | `routes.md` | Lines that mean something | GPX to LineString, ascent, buffer-based attribution, and the honesty lesson: a spatial answer must disclose its uncertainty | `Catalog/SurfaceProfiler.php` — `parts` and `covered` measured on deliberately different sides so neither figure can exceed 100% |
| 10 | `pitfalls.md` | Pitfalls, glossary, where to look | Longitude-before-latitude, SRID mismatch, buffering in degrees, the forgotten GiST index, the inlined CTE, the antimeridian, ring winding. Then the "I need to change X, look here" table and a glossary | Every trap listed is one that exists, or existed, somewhere in this repo |

Geocoding gets a closing section in chapter 4 rather than its own page: it is one paragraph of
concept plus a pointer at Photon, and promoting it would imply a depth the topic does not carry
here.

## 4. Figures

Sixteen figures, all hand-authored inline SVG (D3). Two of them (F10, F13) are pipeline
flowcharts — plain boxes and arrows — and the rest carry spatial meaning.

| ID | Ch | Kind | Shows |
|----|----|------|-------|
| F1 | 1 | SVG | Converging meridians — one degree of longitude at the equator vs at 50°N |
| F2 | 1 | SVG | The same landmasses in EPSG:4326 and EPSG:3857, with the distortion called out |
| F3 | 2 | SVG | The three shapes labelled with the real entities: `item` (Point), `recommended_route` (LineString), `region` (Polygon) |
| F4 | 2 | SVG | The GeoJSON ⇄ PostGIS round-trip, annotated with `GeometryType`'s two SQL casts |
| F5 | 3 | SVG | "10 km" drawn as a 0.09° circle and as a true geography circle at 50°N — the ellipse error |
| F6 | 4 | SVG | Predicate picture: Contains / Intersects / DWithin / Distance over one region, one point, one track |
| F7 | 4 | SVG | `ST_LineLocatePoint` — the 0…1 fraction along a ride, and why it orders the ride-check results |
| F8 | 5 | SVG | Bounding box vs true polygon: the index's candidate set, then the exact recheck |
| F9 | 5 | SVG | The RideCheck rewrite, before and after: spheroid sequential scan vs buffer-then-`ST_Intersects` on GiST |
| F10 | 6 | SVG | Geofabrik PBF → extract → parse → load → `coverage_poi` |
| F11 | 6 | SVG | One OSM node with its tags → our normalised row, showing which keys survive and which are dropped |
| F12 | 7 | SVG | The tile pyramid z6→z14, with one tile subdividing into four |
| F13 | 7 | SVG | `coverage_poi` → per-(letter, country) GeoJSONL → tippecanoe → `.pmtiles` → range request → browser |
| F14 | 7 | SVG | The same points at z9 (clustered bubbles) and z12 (individual pins) |
| F15 | 8 | SVG | MapLibre anatomy: style → source → layer → `source-layer`, with paint and layout separated |
| F16 | 9 | SVG | `SurfaceProfiler`'s two measurements — `parts` measured segment-side, `covered` measured route-side |

**SVGs are inlined in the Markdown**, not referenced as `<img>`. An external SVG file cannot
inherit the page's CSS; an inline one reads the site's own custom properties and so cannot drift
from the palette. `md_in_html` and `attr_list` are already enabled in `mkdocs.yml`, so this works
today with no extension changes.

The wiki has **one palette** — `scheme: atlas`, a light paper-and-ink theme, with no dark toggle
configured. Figures therefore target that single scheme and reuse the existing `--cc-*` custom
properties in `wiki/stylesheets/extra.css` (`--cc-ink`, `--cc-paper`, `--cc-spruce`, `--cc-trail`,
`--cc-clay`, `--cc-glacier`, `--cc-ochre`) rather than inventing a parallel palette. If a dark
scheme is ever added, figures built on those properties follow it for free.

## 5. Mechanics

1. **`mkdocs.yml`** — add a `For developers` nav section listing the pages. That is the whole
   config change: no Markdown extension is added or altered. `admonition`, `attr_list`,
   `md_in_html` and `tables` are already enabled, which covers everything the series needs, and
   inline SVG needs no extension at all.
2. **`wiki/developers/gis/*.md`** — eleven files, each opening with
   `<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->`. The `tools/check-wiki-spdx.sh` pre-commit gate
   globs `wiki/**/*.md`, so the nested path is already covered and a missing header fails the
   commit.
3. **`wiki/stylesheets/extra.css`** — a `.gis-fig` block: figure sizing, caption typography, and
   the CSS custom properties the SVGs read for stroke, fill, accent and muted tones in each scheme.
4. **Preview** through the existing `wiki` service in `developers/docker/compose.yaml`, which
   bind-mounts `wiki/` and serves MkDocs live.
5. **Verify** with `mkdocs build --strict` so no dead internal link ships.

## 6. Writing rules

These are acceptance criteria, not style suggestions.

- **Simple English.** Short sentences. No unexplained jargon. Every term is defined at first use,
  in-line, and again in the chapter 10 glossary.
- **Concrete before abstract.** No concept is introduced before the reader has seen the thing it
  explains. The fountain comes first; the projection comes second.
- **Every claim is checkable.** Each concept cites a real file and symbol. Line numbers only where
  the anchor is stable; otherwise file plus symbol name. Where no anchor exists, the concept is
  still written — see D8 and §10.
- **No length budget** (D9). A chapter runs as long as the teaching needs. Split a chapter when it
  covers two genuinely separate ideas, never merely because it got long.
- **No page depends on a later page.** Forward references are links, never prerequisites.
- **Each chapter ends with a reserved exercise slot** (an HTML comment marking where the hands-on
  box goes), per D5.

## 7. Acceptance

- `mkdocs build --strict` passes with no warnings.
- `tools/check-wiki-spdx.sh` passes across all eleven new files.
- Every `file`/`file:line` citation is verified against the working tree at write time.
- All sixteen figures render legibly against the `atlas` paper background, at both narrow (mobile)
  and wide viewports, using only the existing `--cc-*` custom properties.
- The pages appear in nav order under **For developers**.
- Every `UNANCHORED` marker in the pages has a matching row in the §10 queue, and every
  `type: absent` marker carries its reader-visible admonition.

## 8. Risks

| Risk | Mitigation |
|------|------------|
| Code citations drift as the repo changes | Cite file + symbol over line numbers; add the series to the docs consistency sweep |
| Licence boundary (CC BY-SA wiki quoting PolyForm Shield code) | D7: short illustrative excerpts and path citations, never whole functions |
| Scope creep into a full GIS textbook | With no word budget (D9), the discipline is the §10 review queue rather than a cap: an unanchored concept must earn its place by being useful to *this* reader, and it is visible in a list the owner reviews rather than buried |
| An unanchored concept reads as "the system does this" when it does not | The `type: absent` marker in §10 is reader-visible by rule, so the page never implies functionality that is not built |
| Long chapters lose the reader | No cap (D9), so structure carries the load instead: clear `##` sections, a figure per major idea, and the glossary in ch 10 so no reader is stuck on a term |

## 9. Out of scope

Writing a concept that this system does not implement is explicitly **in** scope (D8). What is out
of scope is *building* anything it turns out we want — §10 hands the owner a list, and each entry
becomes its own decision.

- Hands-on exercises (D5 — slots reserved, content later).
- Raster/DEM analysis, elevation modelling beyond what `SurfaceProfiler` already does.
- Valhalla routing internals; the primer names it and points at the compose profile.
- Any change to application code. This design touches `mkdocs.yml`, `wiki/`, and
  `wiki/stylesheets/extra.css` only.

## 10. Unanchored concepts — review queue

Per D8, a concept the repo does not demonstrate is written anyway and flagged here. The owner
reviews this list after the pages land; each entry resolves one of three ways: an anchor was
missed and gets cited, the concept is genuinely general and the marker stays, or it becomes a
roadmap item.

### Two kinds, marked differently

The distinction is load-bearing, because only one of them risks misleading the reader.

**`type: general`** — ordinary GIS knowledge this system has no counterpart for, and none is
expected. The antimeridian is the archetype: worth knowing, and we have no code for it because our
data has not crossed it. **No reader-visible marker.** Nothing here implies a missing feature.

**`type: absent`** — describes behaviour a reader could reasonably assume the Commons has, but it
is not built. **A reader-visible marker is mandatory**, because an unmarked description of
non-existent behaviour is simply false, and a new developer is exactly the reader least able to
tell the difference:

```markdown
!!! note "Not in the Commons — yet"
    This is how the problem is usually solved. We do not do it today.
```

The `admonition` extension is already enabled in `mkdocs.yml`, so this needs no config change.

### What counts as unanchored

Not every sentence without a file citation. A teaching document is mostly **background exposition** —
what a projection is, what the EPSG registry is, why degrees are written in minutes and seconds —
and that exists to make an anchored point comprehensible. Marking all of it would bury the queue in
noise and destroy the thing the queue is for: surfacing gaps the owner might want closed.

A marker is required when the concept names **a capability or technique a reader could reasonably
expect this system to have**:

- every `type: absent` concept, without exception; and
- a `type: general` concept that names a specific technique a system like ours would plausibly
  implement and we do not — antimeridian handling, on-the-fly reprojection, and the like.

A marker is **not** required for definitions, history, or general theory that carries no implied
claim about what the Commons does.

> **Controller decision, 2026-07-23 — open for the owner.** Chapter 1's review found the original
> §10 wording ("every unanchored concept, of either kind") would have required markers on ordinary
> background exposition, and flagged the contradiction before chapter 2 was written. The rule above
> is the resolution taken so the run could continue; it narrows §10 rather than expanding it.
> Overturn it if you want the broader reading — the cost is retro-fitting markers to every chapter.

### The marker

Every unanchored concept meeting the test above carries a greppable HTML comment **immediately after
the block it refers to** — after the paragraph for a `type: general` concept, and after the
admonition for a `type: absent` one. The marker closes the passage rather than introducing it, so a
reader never meets a bare comment before the thing it annotates, and the pairing check below can
read backwards from the marker.

*(Amended 2026-07-23. The rule first said "immediately above the paragraph". Four chapter authors
independently placed it after instead, which reads better and which the verification now matches.)*

```html
<!-- UNANCHORED id=U01 type=general concept="antimeridian / dateline wrapping" -->
```

Listing the whole queue is then one command:

```sh
grep -rn 'UNANCHORED' wiki/developers/gis/
```

### The queue

Filled during writing, one row per marker. Empty until the pages are drafted.

| ID | Ch | Type | Concept | Why it has no anchor | Resolution |
|----|----|------|---------|----------------------|------------|
| U01 | 1 | general | On-the-fly reprojection (`ST_Transform`) | Everything that feeds the system already speaks EPSG:4326 (GPS, GPX, OSM, GeoJSON), so storage never leaves it and the one projection that does happen — to Web Mercator for tiles — is done by tippecanoe on a copy on the way out, not by us. Verified 2026-07-23: `grep -rn 'ST_Transform'` matches nothing outside the wiki page describing it | open — owner review |
| U40 | 4 | absent | Reverse geocoding (coordinates → place name) | We only ever forward-geocode. `base_place` is stored as its own column at the moment a rider picks a town from search, specifically so the "Near Namur · 40 km" label never needs a live reverse lookup (`2026-07-19-region-scoping-design.md` §4) | open — owner review |
| U60 | 6 | absent | Ingesting OSM **relations** into `coverage_poi` | The pipeline reads nodes and ways only: `extract.py::selector_expressions()` filters on an `nw/` prefix and `parse.py`'s `_Collector` has no `relation()` handler. A castle mapped as a multipolygon relation is invisible to it. `coverage-provider.md` calls this an approved fast-follow with no scheduled plan | open — owner review |
| U90 | 9 | general | Elevation-gain smoothing (minimum-threshold accumulation) | GPS noise inflates raw ascent, and the usual fix is a threshold before a climb counts. We surface ascent without documenting a smoothing choice | open — owner review |
| U91 | 9 | absent | Turn-by-turn route computation (A → B) via a routing engine | Not implemented. Valhalla exists as an opt-in compose profile only; nothing in the app computes a route between two points | open — owner review |
| U100 | 10 | general | Antimeridian / dateline wrapping | Not merely absent — it is a *named, accepted* risk. `2026-07-19-region-scoping-design.md` §8 risk 11 already identifies the exact break point (`RegionRegistryProvider`, `CCScope.bbox()` in `web/assets/map/scope.js`) for a hypothetical future country crossing 180°. No onboarded country does today | open — owner review |

Each chapter's review step checks that every `UNANCHORED` marker in the page has a matching row
here, and that every `type: absent` marker is accompanied by its reader-visible admonition. That
pairing is an acceptance criterion, not a convention.
