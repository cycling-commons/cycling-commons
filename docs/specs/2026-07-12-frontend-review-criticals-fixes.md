> **Consolidated into** security-architecture.md (site-wide CSP + nonce contract, |trans|rich sanitizer rule, escaping rules), map-and-search.md (consolidated-vs-per-feature layer strategy, _styleReady gate, DOM-markers-for-a11y precedent) and dev-environment.md (gzip config, vendor-volume gotcha); the per-finding ledger retires **(2026-07-16).** This dated working doc is sweepable; the canonical docs above are the source of truth.

# Frontend-review fixes (2026-07-12)

Execution note for the 2026-07-12 multi-agent frontend review (artifact:
`.lavish/frontend-review.html`, 69 findings — 6 critical / 31 warning /
32 info). **All 69 findings are resolved on `symfony-base`** — the six
criticals below in detail, then a summary of the warning/info batch.

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

## Warnings + info batch (W1–W63, fixed 2026-07-12)

All 63 remaining findings were fixed the same day, in five packages
(commits `5234539`, `c968508`, `b7980d8`, `91dbbb9`, `2acbb06`):

- **Map** (`5234539`): photoCap escaping + safeHref (W1); Mapillary
  SRI (W2); best-of stale-response guard (W5); planFromSpa guards (W6);
  backdrop-filter removal (W8); unpkg preconnect (W9); compositable
  highlight pulse + prefers-reduced-motion (W10); reusable coordinate/
  Mapillary popups (W11); uploader.name + pending-link escaping
  (W32/W33); nominatim r.ok (W34); dead area branch removed (W35);
  search index drops moderated-away pending items (W36); truthful
  "copied" label (W37); conditional Width/Traffic rows (W38); search
  debounce + delegated clicks (W41); lazy heatmap build (W43).
- **Contribute** (`c968508`): editor race/abort/timeout hardening with
  user-visible OSRM/elevation failure readouts (W12–W16, W39); place
  field populated (W17); maxGrad escaped (W18); JSON_HEX_* flags on all
  json_encode|raw script assignments (W19); photon response ordering +
  coordinate coercion in both copies (W40/W46); dot-boundary source
  matching (W45); reset clears hidden location fields (W47); full
  escAttr (W48); editor canvas filter chain removed (W7).
- **CSP** (`b7980d8`, W3): enforced Content-Security-Policy on every
  HTML response — script-src 'self' + per-request nonce + SRI-pinned
  unpkg, enumerated connect/img hosts, blob: workers. `csp_nonce()`
  stamps every inline script site-wide. CspTest asserts header shape,
  nonce coverage per page, and that non-HTML responses skip it.
- **|rich** (`91dbbb9`, W61): all 72 `|trans|raw` became `|trans|rich`
  (symfony/html-sanitizer, allowlist in config/packages/
  html_sanitizer.yaml) so the public translation catalogs are no longer
  an XSS trust boundary.
- **Site** (`2acbb06`): i18n keys for every hardcoded-English string
  (skip link, confirm prompt, plurals, stat rows, placeholders, Build
  label — 4-locale parity checked); a11y fixes (drawer tab order,
  duplicate id=main, dropped fake tabpanel/menu semantics, Apply button
  instead of onchange submit); Enter-key confirm guard; window.CC
  guard; auth shell extracted across all 7 auth templates; route_edit
  CSRF moved into the Symfony form (the review's "dead weight" premise
  was wrong — the controller validated the manual token, so the check
  moved rather than vanished); CC_I18N page-scoped; dead quicknav.js/
  config.js deleted; contributors demo banner; privacy policy names
  self-hosted Umami on analytics.bikecoders.life and treats IPs as
  personal data (W59).

Closed without change, with rationale: **W42** (DOM leaf markers →
symbol layer) — the DOM pins carry tabIndex/role/aria-label for
keyboard and screen-reader access, which canvas symbols would regress;
cost is capped by clustering and moveend/idle-only reconciliation.

Also investigated: the recurring console warning "Expected value to be
of type number, but found null" reproduces on a bare OpenFreeMap
Liberty basemap with no app code — upstream style/tile issue, not ours.

Verification: 408 tests + phpstan/psalm/php-cs-fixer/SPDX/licences/
translation-parity green; map + six site pages exercised live in the
browser under the enforced CSP with zero violations and zero console
errors (satellite, street-level, heatmap, search, popups, cluster
expansion all driven). Dev-stack note: `web`'s vendor/ is a NAMED
VOLUME in the app container — after a host-side `composer require`,
run `composer install` inside `cycling-commons-dev-app-1` or the site
500s with "component not installed".
