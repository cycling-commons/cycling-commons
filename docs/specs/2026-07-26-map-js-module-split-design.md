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
| `catalog.js` | `CATALOG`, `CATALOG_AZ`, `layerByKey`, `active`, `mode` + `setMode`, `CITIES`, `cityLink`, `LETTER_KEY`/`KEY_LETTER`, `routePathById` (§9) | |
| `scope-ui.js` | `curScope`, `inScope`, `scopeToken`, `scopeLabel`, `writeScopeHeader`, `focusSearchBox`, `renderScopeChips`, `applyScope`, the rail wiring, the area prompt, and click-to-scope (§9) | |
| `spotlight.js` | `setSpotlight`, `setCountrySpotlight`, `setCircleSpotlight`, `drawSpotlightMask`, `clearSpotlight` | |
| `icons.js` | `mintWaterDrops`, `SERVICE_GLYPH`, `miniIcon`, `pinGlyph`, `pinEl`, `coverageIconId`, `clusterEl` | |
| `osm-pools.js` | `osmLayers`, `OSM_BULK`, `addWaterOsm`, `addOsmDots`, the confirmed-pin cluster machinery | |
| `coverage.js` | the PMTiles source, per-letter/per-country layers, every `cov*` filter, `addCoverage`, `covProps`, the coverage drawers, the `cov-sel` overlay, coverage counts | |
| `item-index.js` | `ITEM_INDEX`, `IDX_IDS`, `buildItemIndex`, `nearbyItems`, `trimEnds` | |
| `render.js` | `PREFS`, `markers`, `dynamicIds`, `clearDynamic`, `drawLine`, `drawClimbLine`, the surface layer, every feature filter, `featureVisible`, `layerCounts`, `updateCounts`, `render`, route highlighting | |
| `drawer.js` | `schemaRows`, `buildRecord`, `osmDrawer`, `waterDrawer`, `renderDrawerBody`, `openDrawer`, `closeDrawer`, item history, `highlightAt`, `revealPinAt`, `mapToast` | |
| `community.js` | route community panel, non-votable utility confirmations, `rcPost`, moderation submit, the curator A/R keyboard (§9) | |
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

## 9. Execution progress — DONE (2026-07-26 / 07-27)

**The split is finished.** `map.js` is **4,060 → 304 lines**, with twenty-four
modules beside it, and the entry is now nothing but imports, the CC_* payload
population §4 assigns to it, the `map.on('load')` boot and an ordered list of
`initX()` calls. Every commit was gated (below) before the next began; the tree
is clean and **nothing is pushed**.

| file | lines | | file | lines |
|---|---|---|---|---|
| `map.js` (entry) | 304 | | `places.js` | 250 |
| `drawer.js` | 526 | | `mapillary.js` | 213 |
| `coverage.js` | 482 | | `ride-check.js` | 204 |
| `render.js` | 453 | | `spotlight.js` | 183 |
| `scope-ui.js` | 370 | | `osm-pools.js` | 140 |
| `panels.js` | 304 | | `picking.js` | 118 |
| `search-ui.js` | 288 | | `icons.js` | 114 |
| `community.js` | 262 | | the other nine | 40-113 each |

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
| `c548315` | **perf** — the region spotlight paints progressively |
| `9b22237` | step 3c — `icons.js` |
| `e286b9c` | step 4b — `scope-ui.js` |
| `567522f` | step 4c — `osm-pools.js` |
| `2fe3ba1` | step 4d — `coverage.js`, **plus the `make map-refs` gate** |
| `8eafcce` | step 5a — `item-index.js`; ride-check drops four injected deps |
| `c7548e5` | step 5b — `render.js`, **plus two latent ReferenceErrors it exposed** |
| `7aa1cc4` | step 6 (partial) — `sheet.js`, `lightbox.js` |
| `dd8fcef` | step 7 (partial) — `planner.js` |
| `533938f` | step 6 — **`drawer.js`**, the keystone (five disjoint ranges) |
| `daf4c36` | **gate round 5** — `map-refs` was parsing the modules as CommonJS |
| `6916a7c` | step 6 — `picking.js`; `routePathById` → `catalog.js` |
| `749443a` | **gate round 6** — `map-refs` now checks imports against real exports |
| `7bbf088` | step 6 — `places.js`; three handovers deleted outright |
| `695c6dd` | step 6 — `community.js` |
| `1922b1b` | step 6 — `corrections.js`; click-to-scope → `scope-ui.js` |
| `fa8e99d` | step 7 — `search-ui.js` |
| `6e0de57` | step 7 — `panels.js`; **`map.js` is entry-only** |

### The scaffolding is fully unwound

The table of injected deps is **empty**. Every `initX(deps)` became either a
plain import or nothing at all — five init functions were deleted outright once
their whole body was a handover: `initOsmPools`, `initCoverage`, `initItemIndex`,
`initRender`, `initPlaces`, `initDrawer` (the chrome half survives as
`initDrawerChrome`). `initRideCheck`, `initSheet`, `initLightbox`, `initPlanner`,
`initCommunity` and `initScope` still exist but take no arguments, because each
has a real side effect of its own.

What crosses module lines through an accessor rather than a binding, and why —
this is the list to check before "just exporting the variable":

| binding | owner | accessor | why |
|---|---|---|---|
| `_styleReady` | `map-init.js` | `styleReady()` | reassigned on load |
| `_mode` | `catalog.js` | `mode()` / `setMode()` | reassigned by the mode toggle |
| `ITEM_INDEX`, `IDX_IDS` | `item-index.js` | `itemIndex()`/`idxIds()` + two mutators | rebuilt wholesale |
| chip facets | `render.js` | `syncFacetChips()` | Sets reassigned per click |
| preference filter | `render.js` | `prefFilterEnabled()`/`setPrefFilter()` | boolean flips |
| accessibility Set | `render.js` | `staysAccessible()` | Set reassigned per chip click |
| `_pick` | `picking.js` | `isPicking()` | session starts/ends long after import |
| `_placeReq` | `places.js` | `bumpPlaceReq()` | a counter |
| `_searchDropPending` | `search-ui.js` | `dropPendingFromSearch()` | null until `initSearchUi()` runs |
| `_corrLayers` | `corrections.js` | `corrLayerIds()` | reassigned by `clearCorrections()` |
| `boSeason`/`boBike`, `app`, the facet selects | `panels.js` | module-scope `let`s | read by a LATER init |

Two ownership calls §4's table never made:

- **`routePathById`** went to `catalog.js`. Both `picking.js` and
  `corrections.js` need it and neither owns the catalogue it reads.
- **click-to-scope + `selectableLayers()`** went to `scope-ui.js`, and could not
  move until `corrections.js` owned `_corrLayers`. It had been sitting in the
  middle of the picking block for exactly that reason.
- **the curator A/R keyboard** went to `community.js`: it arms a moderation
  decision, so it belongs with the moderation submit, and it was the last piece
  of feature logic in the entry.

### What the entry still contains, deliberately

`map.js` is 304 lines: the import block, ~165 lines that fill
`CATALOG[*].features` from the `CC_*` payloads, the `map.on('load')` boot, and
the init list. The payload population stays because §4 assigns it there
explicitly (`catalog.js`'s own header says its `features` arrays "are filled
later, by the entry, from the CC_* payloads"). If it ever wants its own module,
`catalog-payload.js` is the obvious name — but that is a new decision, not a
leftover.

### The gate, in its final form

Three checks, in this order, every time — and the lesson of all six rounds is
that the first two are necessary and never sufficient:

1. **`make map-refs`**, which is now three arms:
   - **imports vs exports.** Every `import { … } from './x.js'` is checked
     against what x.js actually exports. Retiring a dep means deleting an
     `initX()` and letting the importer take a real import, and it is trivially
     easy to delete the export with a caller still importing it — the browser
     answers that with `SyntaxError: does not provide an export named`, which
     kills the whole module graph. Added in `749443a` after the sweep caught it
     twice in one commit (`initItemIndex`, then `initRender`).
   - **ES-module syntax.** Each module is copied to a temp `.mjs` and
     `node --check`ed. Plain `node --check foo.js` parses these as CommonJS (no
     `package.json` in this repo), and that parse **accepted a module with a
     duplicated top-level `let`** — clean report, unloadable file. Fixed in
     `daf4c36`.
   - **no module references an entry-owned binding**, the original check, whose
     four-round history is below.
2. **the §6 sweep, logged in, with a GPX uploaded.** Green is
   **15 passed / 0 failed / 1 skipped AND `consoleErrors: []`**. The remaining
   skip is "no route lines drawn" — the dev routes are not curated, so they only
   draw in Everything mode or under a scope that has them.
3. **`make scope-test` (119/0) and `web/tools/check-spdx.sh`.**

The ref checker took four rounds to become trustworthy before this session, and
two more during it. Every round was paid for by a bug that reached the browser
first:

1. it stripped `'single quotes'` before template literals, so an apostrophe
   inside a template swallowed every declaration in between and it reported
   clean on a tree with six live ReferenceErrors;
2. it compared only against bindings the entry DECLARES, missing everything the
   entry merely IMPORTS — which is how `styleReady` got through;
3. it blanked template literals as inert text, but nearly every panel builds
   HTML as `` `...${escPend(x)}...` ``, so every `${}` expression was invisible
   to it — that shipped `escPend is not defined` in `lightbox.js`;
4. object-literal keys (`{D:'services'}`), function parameters
   (`setSpotlight(slug)`) and multi-name declarations (`const S=2, D=24*S`) each
   produced false positives that had to be excluded;
5. it was parsing every module as CommonJS (`daf4c36`);
6. it never compared imports against exports (`749443a`).

### What the sweep still does not cover — and what was done instead

Every gap below was exercised BY HAND for the module that touched it, with a
scripted probe rather than clicking. The probes live in `.playwright-mcp/`
(gitignored): `probe-coverage.js`, `probe-gaps.js`, `probe-picking.js`,
`probe-deeplinks.js`, `probe-community.js`, `probe-clicktoscope.js`,
`probe-search.js`, `probe-panels.js`, `probe-corrections.js`.

| not covered | verified instead |
|---|---|
| the coverage-icon → drawer path, whenever no coverage POI is in the viewport (the dev NL scope + the Afternoon_Ride bbox is exactly that case, so the checkpoint SKIPS there — confirmed identical on the parent commit) | `scope=BE` at Liège z13: 103 features, a click opens a real card, 0 errors |
| the lightbox | opens from a drawer photo, caption renders the credit (so `photoCap` resolves across the drawer↔lightbox cycle), Escape closes, drawer stays open. Arrow stepping still unverified: the reachable feature has one photo, where stepping is a no-op by design |
| Mapillary | still no checkpoint |
| the mobile snap sheet | drawer opens at 390×820, scrim closes it; the map-ctrl and filters sheet were driven at that width too |
| confirmed-pin clusters | Hautes Fagnes z11: leaf pins present, a click opens a drawer |
| picking (no checkpoint at all) | full session: two snapped clicks → a pickseg line → Done → "· 1 stretch marked" in the community panel; plus both guards (openDrawer bails mid-pick, closeDrawer cancels) |
| deep links | `?feature=<name>` opens + widens; `?feature=<nonsense>` opens nothing AND leaves the scope alone (07-20 finding 9); `?route=23` opens selected |
| the community loop | route panel repaints "…" → "· 1 of 3 to verify"; a utility confirmation POSTs, repaints `is-mine`, 0→1, "· 1 rider confirmed", toasts |
| click-to-scope | empty ground re-scopes country:BE → region:1 with the header following; a feature click does not |
| search beyond the one checkpoint | item search, keyboard highlight + Enter, Escape close, the coverage/widen rungs |
| panels beyond one layer toggle | select-all both ways with its label cycling, satellite on/off, legend collapse, burger + Escape, both best-of facets rewriting the subtitle, pref chip, filter chip, lazy heat layer + season chip |
| the curator corrections overlay | VERIFIED end to end as a real curator (see the access recipe below): 3 segmented corrections draw 3 `corr-*` line layers in 3 different palette colours with 6 numbered endpoint pins, the "Pending corrections" panel lists all 4 with reason + note + stretch count, clicking one with a stretch flies to it (z14→15) while the segment-less one is a deliberate no-op, and closing the drawer tears the whole overlay down. 0 console errors |

### Getting into the app from a probe — two things that cost an hour

Both of these make a scripted login fail SILENTLY, which is the worst way for a
harness to fail: the sweep runs anonymously, three checkpoints skip, and nothing
anywhere says "you are not logged in".

- **Submit with `form.requestSubmit()`, never `page.click()`.** The login form
  carries the `cc-validate` script, which can swallow a programmatic click — the
  POST never leaves the page. There is no error, no flash, no failed request:
  the URL simply stays `/login`. And reach the form THROUGH its field
  (`document.querySelector('input[name=_password]').closest('form')`), because
  `document.querySelector('form')` is the page's first form, which is not this
  one. `smoke-auth.js` now does both, and additionally asserts the session by
  fetching `/profile` — a run that is not authenticated now reports `ok: false`
  instead of quietly skipping.
- **Log out through the account chip's POST form**, not `GET /logout` (405,
  `enable_csrf`) and not by clearing cookies.

**The curator IS scriptable**, which is how the corrections overlay finally got
verified. `AppFixtures::makeUser(..., presetTotp: true)` presets the well-known
seed `JBSWY3DPEHPK3PXP` on `admin@example.test` and `moderator@example.test`
(ROLE_CURATOR), exactly so dev and demo can generate valid codes — the fixture
comment says as much and points at `oathtool --totp -b JBSWY3DPEHPK3PXP`. From a
probe, compute the code **in the page** with `crypto.subtle` (HMAC-SHA1 over the
30 s counter): the MCP runner context has no `require`, no dynamic `import` and
no WebCrypto of its own. Flow: `/login` → `/2fa` → fill `#_auth_code` → submit
`document.getElementById('_auth_code').closest('form')`. Retry once with the next
30 s step if the first code lands on a boundary. `probe-corrections.js` does all
of this.

Note the OTHER curator, `curator@map.test`, is NOT scriptable — its secret is a
real encrypted one, not the fixture seed.

### Three things about the verification itself

- **The sweep's baseline is environment-dependent.** A fresh browser profile
  reported 15/0/1; the same tree in a warmed profile reported 14/0/2 or 12/0/3,
  the extra skips being the coverage viewport and the spotlight's 20 s boundary
  paint (backlog item 10). Whenever a count looked off it was checked against
  the PARENT commit with the same profile before being believed — twice that
  showed the difference was the environment, not the extraction.
- **The browser keeps one page across probe runs**, so a mobile probe's viewport
  sticks. The sweeps for picking/places/community/corrections ran at 390×820
  without anyone asking them to; all four were green there, and the cumulative
  tree is green at 1440×900 too. Free extra coverage, but the probes now set the
  viewport explicitly.
- **Ride-check is rate-limited to 20 uploads/day per rider**, and the sweep
  spends one per run. `docker compose exec app php bin/console cache:pool:clear
  cache.ride_check_limiter` resets just that counter (its own dedicated pool) —
  needed once in this session.

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
- **One unexplained vendor blip**, recorded rather than explained away: in one
  sweep out of about a dozen, three errors came from inside `maplibre-gl.js`
  ("pt", the minified error class, three at the same millisecond during a scope
  switch, no frame of ours in the trace). Not reproducible on an immediate re-run
  with the identical saved scope. If it recurs, this is the prior.

### The recipe, for whoever comes next

Still accurate, and it survived seven more extractions:

1. Locate the block's exact line range and **assert both boundary lines before
   cutting**. Every extraction here was a short Python script that `assert`s on
   the first and last line of every range and deletes ranges **in strictly
   descending start order**. Several bugs were caught that way before they
   reached a file; all of them would have silently mangled a range.
2. Move bodies verbatim, de-indent by two, hand-write only the module header and
   the `import`/`export` lines. Two cuts needed no re-indentation at all
   (`search-ui.js`, `panels.js`) because the code was already inside a block —
   those are the cleanest diffs in the whole split.
3. Every side effect becomes an exported `initX()` the entry calls **at the exact
   point the code used to occupy** (§4.2). When other init calls are interleaved
   through a block, split on those seams rather than reordering — `panels.js`
   became six inits for exactly that reason.
4. Any `let` an importer reads OR writes becomes a getter/setter in its owner
   module. The full list is the accessor table above.
5. Inject live values as closures, never snapshots — and when the owning module
   lands, the closure becomes an import.
6. Run all three gate arms, then the sweep **logged in**, then hand-exercise
   whatever the sweep does not reach, then commit.
