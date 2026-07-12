# Frontend-review criticals — fixes (2026-07-12)

Execution note for the six critical findings from the 2026-07-12 multi-agent
frontend review (artifact: `.lavish/frontend-review.html`, 69 findings — 6
critical / 31 warning / 32 info). All six are fixed on `symfony-base`; the
warnings and info findings remain open for later triage.

## C1 — Stored XSS: item `desc` rendered raw into the drawer

`web/assets/map/map.js` `buildRecord()`: `f.desc` was the only interpolated
field not passed through `escPend()` before landing in `#drawerBody.innerHTML`.
`desc` is in `AttributeVocabulary::COMMON`, so it survives OSM/Wikidata import
(attacker-editable upstream). Fixed by wrapping it: `${escPend(f.desc)}` —
matching `f.name`, record values and the history rows. `descTr` is a boolean
flag rendering static text; nothing to escape there.

## C2 — Cluster-expansion click was a no-op

`map.js` used the pre-v3 callback form
`getClusterExpansionZoom(id, (err, z) => …)`; since MapLibre v3 the method
returns a Promise and ignores the callback, so clicking a confirmed-points
cluster bubble never zoomed. Fixed with the Promise API
(`.then(z => map.easeTo(…)).catch(() => {})`). Verified live: clicking a
bubble now eases in and the cluster expands to leaf pins.

## C3 + C4 — Per-feature sources/layers and listeners (A layer)

`drawSurfaceLine()` created one GeoJSON source + a casing layer + a line layer
+ four delegated listeners **per road-surface segment** — ~351 sources, ~700
layers, ~1400 pointer hit-tests per mousemove, and a full synchronous
teardown/rebuild on every filter change.

Replaced with consolidated rendering (`renderSurfaceLayer()`):

- ONE persistent GeoJSON source (`surface-src`) holding all visible segments
  as a FeatureCollection; re-renders are a single `setData()`.
- ONE shared casing layer (`surface-case`) + one line layer per surface class
  (`surface-cls-<cls>`, 8 incl. the `other` fallback for unknown classes) —
  dash pattern and line-cap cannot vary per feature inside a layer, hence
  per-class layers rather than fully data-driven paint.
- Listeners (click / mouseenter / mousemove / mouseleave) bind once per class
  layer; the feature resolves via `e.features[0].properties.idx` back into
  `layer.features`.
- The consolidated layers are persistent — `clearDynamic()` never tears them
  down, so an inactive A layer renders an empty collection (the render loop
  handles `kind === 'surface'` before the `active` check).
- Z-order: `liftInfoLayersAboveRoutes()` lifts `surface-case` + the class
  layers instead of per-feature id groups.

Climbs keep per-feature sources: their `line-gradient` ramp is built from
per-climb gradient data over `line-progress`, which cannot be expressed in a
shared layer. Routes (11) keep per-feature layers because the selection
highlight (`highlightRoute`) styles and re-orders individual layer ids.

**Style-load race surfaced by this work:** the initial best-of fetch can
resolve before map `load`; `render()` then hit `addSource` on a not-yet-loaded
style ("Style is not done loading"). `render()` now no-ops until the `load`
handler flips `_styleReady` — the load handler runs `render()` itself and sees
all state mutated by earlier calls (`f.cur`, mode).

## C5 — catalog.json uncompressed + serialized map.js download

- `developers/docker/nginx/app.conf`: `gzip on` for
  `application/json` (+ geo+json, js, css, svg). Measured live:
  `/map/catalog.json` 1,173,008 → 252,800 bytes.
- `templates/map/index.html.twig`: `<link rel="preload" as="script">` for
  `CC_MAP_SRC`, so the map.js download runs in parallel with the catalog
  fetch; `catalog-load.js` still injects/executes it only after the catalog
  globals exist (its boot contract is unchanged — execution gated, download
  not).

## C6 — Segment location silently discarded on submit

The wizard's segment branch (`web/assets/contribute/improve.js` `syncLoc`)
kept the two drawn endpoints only in `WZ.loc` while `announceMove()` toasted
"submit to record it" — the POST carried nothing. Fixed end-to-end:

- `ImproveType` adds a hidden `segment` field when the bound type's
  `LocationMode` is `Segment` (road surface); rendered in
  `improve.html.twig` next to the climb geometry carriers.
- `syncLoc` writes `{"a":[lng,lat],"b":[lng,lat]}` JSON into the field when
  both endpoints are placed, and clears it when incomplete or reset.
- The value lands in the persisted `Submission::payload` (parity with the
  point branch's `lat`/`lng`, which likewise record location in the payload).
  Applying geometry changes to `Item::geom` on approve is a separate
  moderation feature for points and segments alike — out of scope here.
- Tests: `ImproveTest::testRoadSurfaceFormExposesSegmentHiddenField`,
  `testPointTypeHasNoSegmentField`, `testSegmentPostLandsInSubmissionPayload`.

## Verification

- phpunit 400/400, phpstan/psalm/php-cs-fixer clean.
- Browser (dev stack, `/map`): surfaces render per class (solid teal RAVeL,
  dashed unverified red, …); hover tooltip and click→drawer work off the
  consolidated layers; A-layer toggle 277↔15; Curated 277 / Everything 378
  (surfaces 351/351); cluster click expands; zero console errors (the two
  "Expected value to be of type number, found null" worker warnings pre-date
  these changes — verified against the pre-change map.js).
