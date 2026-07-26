<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# map.js module split — design

**Status:** IN PROGRESS, 2026-07-26. Owner decisions in §2; what has landed
and what has not is §9.
**Audience:** contributors working on the map front end.
**Scope:** a behaviour-preserving decomposition of `web/assets/map/map.js`
(4,060 lines) into ES modules served by AssetMapper, plus one behaviour change
folded in deliberately and separately: the ride-check `coverage` arm's frontend
(`2026-07-26-ride-check-coverage-design.md` §3.3).
**Blocks:** the first push of the map work (backlog item #1).

## 1. Why now

`web/assets/map/map.js` is one 4,060-line classic script holding MapLibre init,
the region spotlight, scope UI, Mapillary, the bulk-OSM pools, coverage tiles,
the searchable item index, the render loop, the drawer, community panels,
stretch-picking, located corrections, place cards, the lightbox, ride-check, and
every panel's DOM wiring. Every map feature touches it, no part of it can be
reviewed in isolation, and `2026-07-23-map-js-phase0-extraction-design.md` §1
already recorded two same-day production bugs that escaped the 69-test suite
purely because they lived in map.js rather than in a tested module.

Phase 0 extracted the one genuinely pure decision (`scope-chips.js`) and
explicitly deferred the general split. This is that split.

## 2. Owner decisions (2026-07-26)

- **ES modules via AssetMapper**, not the `window.CC*` IIFE idiom `scope.js` and
  `scope-chips.js` use. Modules give real encapsulation and an explicit
  dependency graph; thirteen-plus `window.CC*` namespaces with implicit load
  ordering would be the monolith with extra steps. AssetMapper resolves each
  relative import against the asset map and, under `missing_import_mode:
  strict`, refuses to compile one it cannot find — see §3.1 for what it does
  with the ones it can.
- **Owner modules with setters, not one shared `ctx` object.** Shared mutable
  state (`mode`, `markers`, `ITEM_INDEX`, `active`, …) lives in the module that
  owns it and is mutated through exported functions. A `ctx` bag would let any
  module mutate any field, reproducing the exact property that made map.js hard.
- **Verification is a scripted browser smoke pass run after every extraction
  commit**, not a manual click-through and not a single pass at the end (§6).
- **Ride-check coverage renders as a dedicated small-icon overlay** along the
  track, independent of the coverage layer's on/off state, the region scope and
  the current mode (§7).

## 3. What changes in the load model

Four edits, nothing more:

| file | today | after |
|---|---|---|
| `web/assets/map/catalog-load.js` | `s = createElement('script'); s.src = CC_MAP_SRC` | the same, plus `s.type = 'module'` |
| `web/importmap.php` | `app`, `admin_confirm` | plus a `map` entry (**not** an entrypoint) |
| `web/templates/map/index.html.twig` | `<link rel="preload" as="script">` | `{{ importmap([], {nonce: csp_nonce()}) }}` + `<link rel="modulepreload">` |
| `web/assets/map/map.js` | 4,060-line body | ~130-line entry: imports + the boot sequence |

Everything else stays: the catalog fetch still gates execution, `scope.js` /
`scope-chips.js` / `scope-header.js` stay classic scripts loaded before it, and
the `CC_*` globals remain the injection contract.

No CSP change is needed. `script-src` is `'self' 'nonce-…' https://unpkg.com`
(`src/EventSubscriber/CspSubscriber.php`); module scripts served from `/assets/`
are same-origin, and the inline importmap block carries `csp_nonce()`.

### 3.1 Why the importmap entry is load-bearing

AssetMapper does **not** rewrite a relative import to the digested filename.
`JavaScriptImportPathCompiler` rewrites it to the *undigested* public path
(`./i18n.js` → `/assets/map/i18n.js`) and registers the module as an importmap
dependency; resolving that path to the digested file is the importmap's job. So
without both halves — the `map` entry in `importmap.php` **and** an
`{{ importmap() }}` call on the page — every import 404s. (Verified the hard
way: the first extraction booted to two 404s and a dead map.)

The entry is deliberately **not** `entrypoint: true`. An entrypoint makes
`importmap()` emit `import 'map'`, which would execute the module as soon as it
loads — before the catalog fetch has populated the `CC_*` globals map.js reads
at module scope. Keeping it a plain entry means `importmap()` emits only the map
and no import statement, so catalog-load.js's injection remains the one thing
that starts execution.

One consequence: `importmap()` preloads entrypoints only, so it emits nothing
for `map`. The 2026-07-12 C5 optimisation (download in parallel with the ~1 MB
catalog transfer instead of serialized behind it) therefore keeps its
hand-written preload tag — now `modulepreload`, which the browser follows
through the graph, warming the imported modules too.

### 3.2 Strict mode is the other load-model risk

ES modules are strict mode; the current classic script is sloppy mode. The
behaviours that change are all errors-not-silence: assignment to an undeclared
identifier throws instead of minting a global, duplicate parameter names are
syntax errors, and octal literals are rejected. *Reading* an undeclared global
still works, so the many bare `CC_ROUTES` / `CC_RIDECHECK` / `CATALOG` reads are
unaffected.

Mitigation: strict mode arrives in step 1 (§5), alone, before a single line
moves — so anything it surfaces is attributable to one 3-line commit rather than
to a 4,000-line reshuffle.

## 4. Module layout

All files stay flat in `web/assets/map/`, matching the existing `scope*.js`.
"Pure" marks a module with no MapLibre and no DOM dependency, and therefore a
candidate for `node --test` coverage under `web/tests/js/` alongside
`scope.test.cjs` — the split creates that surface, it does not populate it (§8).

| module | owns | pure |
|---|---|---|
| `i18n.js` | `I18N`, `D`, `LAYER_L10N`, `VALUE_TR`, `tpl`, `trVal`, `sourceLabel`, `DIFF_LABELS`, season/bike labels | ✅ |
| `util.js` | `escPend`, `safeHref`, `slug`, `stars`, `txtOn`, `haversine`, `featurePoint`, `currentSeason`, `ccUrl`, `wc`, `gradColor`, `DIFF_PURPLE` | ✅ |
| `map-init.js` | the `maplibregl.Map` instance, attribution/nav/zoom controls, `addSatellite`, style-ready flag, `flyToPin`, the coordinate context menu | |
| `catalog.js` | `CATALOG`, `CATALOG_AZ`, `layerByKey`, `active`, `mode` + `setMode`, `CITIES`, `cityLink`, `LETTER_KEY`/`KEY_LETTER` | |
| `scope-ui.js` | `curScope`, `inScope`, `scopeToken`, `scopeLabel`, `writeScopeHeader`, `focusSearchBox`, `renderScopeChips`, `applyScope`, the rail wiring and the area prompt | |
| `spotlight.js` | `setSpotlight`, `setCountrySpotlight`, `setCircleSpotlight`, `drawSpotlightMask`, `clearSpotlight` | |
| `icons.js` | `mintWaterDrops`, `SERVICE_GLYPH`, `miniIcon`, `pinGlyph`, `pinEl`, `coverageIconId`, `clusterEl` | |
| `osm-pools.js` | `osmLayers`, `OSM_BULK`, `addWaterOsm`, `addOsmDots`, the confirmed-pin cluster machinery | |
| `coverage.js` | the PMTiles source, per-letter/per-country layers, every `cov*` filter, `addCoverage`, `covProps`, the coverage drawers, the `cov-sel` overlay, coverage counts | |
| `item-index.js` | `ITEM_INDEX`, `IDX_IDS`, `buildItemIndex`, `nearbyItems`, `trimEnds` | |
| `render.js` | `PREFS`, `markers`, `dynamicIds`, `clearDynamic`, `drawLine`, `drawClimbLine`, the surface layer, every feature filter, `featureVisible`, `layerCounts`, `updateCounts`, `render`, route highlighting | |
| `drawer.js` | `schemaRows`, `buildRecord`, `osmDrawer`, `waterDrawer`, `renderDrawerBody`, `openDrawer`, `closeDrawer`, item history, `highlightAt`, `revealPinAt`, `mapToast` | |
| `community.js` | route community panel, non-votable utility confirmations, `rcPost`, moderation submit | |
| `picking.js` | the located-correction picking session | |
| `corrections.js` | correction geometry, `showRouteCorrections`, the correction panel | |
| `places.js` | `openPlace`, `renderPlaceCard`, `openCity`, `openFeatureByName`, `openLocalFeature`, `openRouteById`, `openPendingById`, `openStayPivot` | |
| `lightbox.js` | the photo lightbox and its key handling | |
| `sheet.js` | the mobile snap sheet and the hover tip | |
| `ride-check.js` | GPX upload, the track overlay, the results drawer, **and the new coverage arm** (§7) | |
| `search-ui.js` | the sidebar town + feature search | |
| `panels.js` | layer list, base segmented control, map-ctrl toggle, rail foot, legend, burger, breakpoint resize, best-of, mode buttons, preference chip, filter chips, heat toggle, season chips | |
| `mapillary.js` | the imagery layer, viewer, dock and dock resizing | |
| `planner.js` | the illustrative Spa planner | |
| `map.js` | **entry** — imports and the boot sequence, nothing else | |

### 4.1 Cycles are expected and safe

`drawer` ↔ `render`, `places` ↔ `drawer`, `coverage` ↔ `drawer` are genuine
cycles and will not be designed away — the map's interactions really are mutually
recursive. They are safe here because every cross-module reference is *called at
runtime*, never evaluated at module-evaluation time, and because the exports
involved are hoisted `function` declarations, whose bindings are initialised
before any module body runs.

The rule this imposes, and the one reviewers should enforce: **no module may
call an imported function at its top level.** Which is also §4.2's rule, arrived
at from the other direction.

### 4.2 Side effects belong to the entry

Every top-level side effect in today's file — `map.addControl`, the
`document.addEventListener` delegations, `document.getElementById(…).onclick`,
the `map.on('load')` boot, the index builders — becomes an exported `initX()`
that `map.js` calls, in the original source order. No module runs anything but
declarations at import time.

This is what makes the split verifiable rather than hopeful: ordering stops
being an emergent property of the import graph and becomes a list you can read
in one screen and diff against the original.

The two exceptions are unavoidable and deliberate: `map-init.js` constructs the
`maplibregl.Map` at module scope, and `catalog.js` reads the `CC_*` globals at
module scope. Both are values every other module needs before any `initX()`
runs, both are already guaranteed by the catalog-fetch gate, and both are
single-assignment.

## 5. Sequencing

Each step is one commit, and each commit is browser-verified (§6) before the
next begins. Steps 2 onward cut code with `sed` line ranges rather than
retyping, so a module body is byte-identical to the lines it replaces and the
only hand-written lines in the diff are the `import`/`export` headers.

1. **Load model only.** `type='module'`, `modulepreload`. No code moves. This
   is the strict-mode step (§3.1).
2. **Leaves:** `i18n.js`, `util.js` — no imports of their own.
3. **Foundation:** `map-init.js`, `catalog.js`, `icons.js`.
4. **Layers:** `spotlight.js`, `scope-ui.js`, `osm-pools.js`, `coverage.js`.
5. **Model:** `item-index.js`, `render.js`.
6. **Interaction:** `drawer.js`, `community.js`, `picking.js`, `corrections.js`,
   `places.js`, `lightbox.js`, `sheet.js`.
7. **Chrome:** `search-ui.js`, `panels.js`, `mapillary.js`, `planner.js`.
8. **`ride-check.js`** — extracted last, then the coverage arm added on top of
   it as its own commit, so the refactor and the behaviour change are never in
   one diff.

## 6. Verification

There is no JS harness for this code and this design does not pretend to create
one: the split is what makes a harness *possible*, and populating it is §8.
Verification here is a scripted browser pass.

`web/tests/browser/map-smoke.js` is a single async function evaluated in the
page. It is not a Playwright project (there is no `package.json` in this repo
and this design does not add one) — it is the checkpoint list, in code, run
through whichever browser driver is at hand, so the check is identical every
time instead of depending on what the operator remembered to click.

Checkpoints, each asserting both a DOM outcome and an empty console-error log:

1. boot: map canvas present, `#layers` populated, zero console errors
2. scope switch to another region: header text, spotlight source present
3. scope switch to Everywhere: spotlight cleared
4. layer toggle off/on: marker count changes and returns
5. mode flip curated ↔ everything: coverage layer visibility follows
6. coverage icon click: drawer opens with a coverage source line
7. curated pin click: drawer opens with the item's name
8. town search: results list, first result opens a place card
9. feature search: a named Commons feature resolves and opens
10. route select: highlight layer applied, community panel hydrates
11. lightbox: opens from a drawer photo, arrows step, Escape closes
12. ride-check: GPX upload draws the track and fills the results drawer
13. **ride-check coverage: the coverage section lists POIs and the overlay draws**
14. best-of: season/bike change repaints without error
15. planner chip: draws, second click clears

Steps that cannot touch a checkpoint (a pure-helper extraction) still run the
full sweep — the point is that boot order and imports are exercised end to end.

Gate on every commit: the sweep green, plus `make scope-test` (unchanged, 100
tests) and the SPDX/licence gates.

## 7. Ride-check coverage frontend

The backend arm landed in `517dc49`
(`2026-07-26-ride-check-coverage-design.md` §3.1). This is its frontend, folded
into the split because it lives in exactly the region being moved.

**Correction to that spec's §3.3.** It describes the curated `groups` arm as
"per-letter marker sets + a panel list". It is not: `groups` is a panel list
only, because curated items are already drawn on the map by their own served
layers. Coverage POIs have no such guarantee — the rider's ride routinely leaves
their region scope, and the coverage layer may be off or the map in Curated
mode — so panel-only would list refill points the rider cannot see.

Therefore:

- **Overlay.** `ride-check.js` owns a `ridecheck-cov` GeoJSON source and one
  symbol layer drawing every corridor coverage POI with the existing small
  coverage icons (`coverageIconId`, and the `_s8`/`_s13`/`_s18` size ramp the
  `cov-sel` overlay already uses), so it reads as coverage, not as a curated
  spot pin — which is the visual distinction §3.3 asks for. It is independent of
  the coverage layer's on/off state, the region scope and the current mode, and
  it is torn down by Clear together with the track.
- **Panel.** A section headed as open coverage, distinct from
  "In the Commons along the track", rendered from the `coverage` array with the
  same per-letter group rows, `alongKm`/`distM` metadata and truncation note as
  the curated arm.
- **Click.** A row opens `openCoverageByRef(ref, letter, ll, name)`, which
  already flies, fetches `/map/coverage/poi/{ref}` and falls back to a minimal
  drawer. Hover uses `highlightAt` like the curated rows.

**This requires one backend change.** `corridorCoverage()` selects `cp.id` but
not `cp.ref`, and `id` is a row id that no endpoint accepts —
`openCoverageByRef` needs the `ref`. Add `cp.ref` to the SELECT and to the item
shape (`groupByLetter` gains an optional passthrough), extend the service
docblock, and extend `RideCheckServiceTest` to assert `ref` is present on a
coverage item. The curated arm is untouched.

**i18n.** New keys go through `MapController::mapI18n` like every other map
string, in all four locales (EN/FR/NL/DE): the section heading, the empty state,
and the truncation note.

## 8. Out of scope

- **Behaviour changes anywhere but §7.** Every other commit is a move.
- **Populating `web/tests/js/` with tests for the newly pure modules.** The
  split makes `i18n.js` and `util.js` requirable from `node --test`; writing
  those tests is worthwhile and is a follow-up, not a precondition. Bundling
  them here would put new assertions and a 4,000-line move in one review.
- **A `package.json` / a real Playwright project.** §6 deliberately stays
  dependency-free.
- **CSS.** `web/assets/styles/` is untouched.
- **`scope.js` / `scope-chips.js` / `scope-header.js`.** They keep their IIFE
  form and their classic `<script>` tags. Converting them to ESM would break the
  `node --test` `require()` they are tested through, and would have to move
  `scope-header.js` after the catalog fetch, reintroducing the header flash
  Phase 0 §5 exists to prevent.

## 9. Execution progress (2026-07-26)

`map.js` is **4,060 → 3,473 lines**, with eight modules beside it. Every commit
below was browser-verified with the §6 sweep before the next began; the tree is
clean and nothing is pushed.

### Landed

| commit | what |
|---|---|
| `c7a46c9` | **fix** — coverage heat layers dropped under an Everywhere scope (found by the sweep's first run) |
| `2483c89` | the §6 smoke sweep + the non-prod `window.__ccMap` handle |
| `42fd424` | sweep: the spotlight checkpoint guards the call path, not the paint time |
| `76436d5` | step 1 — ESM load model |
| `f5bace5` | step 2 — `i18n.js`, `util.js` (+ the §3 correction) |
| `23e2c0e` | step 3a — `map-init.js` |
| `99a016f` | **backend** — `ref` on the coverage arm; `coverage_poi` provisioned in the controller test |
| `bb65fe6` | `ride-check.js` + the coverage frontend (§7) |
| `e84d70e` | specs synced |
| `606005a` | step 4a — `spotlight.js` |
| `8d1f10c` | step 3b — `catalog.js` |
| `04f1618` | step 7 (early) — `mapillary.js` |

### Not yet extracted

`icons.js`, `scope-ui.js`, `osm-pools.js`, `coverage.js`, `item-index.js`,
`render.js`, `drawer.js`, `community.js`, `picking.js`, `corrections.js`,
`places.js`, `lightbox.js`, `sheet.js`, `search-ui.js`, `panels.js`,
`planner.js`.

### The recipe, for whoever continues

1. Locate the block's exact line range and assert both boundary lines before
   cutting — every extraction here was done by a short Python script that
   `assert`s on the first and last line and deletes ranges **in strictly
   descending start order**. Two bugs were caught that way before they reached
   the file; both would have silently mangled a range.
2. Move bodies verbatim, de-indent by two, and hand-write only the module header
   and the `import`/`export` lines.
3. Every side effect becomes an exported `initX()` the entry calls **at the exact
   point the code used to occupy** (§4.2).
4. Any `let` an importer would read becomes a getter + setter in its owner
   module. Three have needed it so far: `_styleReady` → `styleReady()`, `mode` →
   `mode()`/`setMode()`, `mlyOn` → kept private by moving its only writer in.
5. `node --check` every file, then run the §6 sweep, then commit.

### Two things the split did not cause but did surface

- **Backlog item 10** — a single-region spotlight paints ~3.4 s late (seven
  concurrent boundary requests, most of the cost client-side polygon parsing and
  tessellation). Measured, filed, untouched. An earlier reading of ~26 s was a
  low-power-mode artefact; the session-lock and missing-cache hypotheses it
  produced were both tested and disproved — see the backlog entry.
- **Ride-check duplicates on dev** — curated fixtures carry `fx:` refs while
  coverage rows carry real OSM refs, so the `(source_ref, letter)` dedup cannot
  match and the same fountain lists in both arms. Correct on prod, where curated
  items fork from coverage. See `2026-07-26-ride-check-coverage-design.md` §7.
