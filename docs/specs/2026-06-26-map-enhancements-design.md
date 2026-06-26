# Spec — Map enhancements: permalink, GPX export, interactive elevation

- **Status:** Draft — awaiting user review
- **Date:** 2026-06-26
- **Scope:** Three independent, client-side enhancements to the Atlas map. All pure vanilla JS inside
  `atlas/demo/map.html` — no backend, no build step, no new dependencies.
- **Goal:** Turn the mature-but-static map into something that feels like a real tool: any view is a
  shareable link, any climb/route can be downloaded to a head unit, and the elevation profile is a
  live, scrubbing instrument rather than a static picture.
- **Builds on:** the existing map (`atlas/demo/map.html`, ≈2000 lines — MapLibre GL 5, OpenFreeMap
  Liberty base, 11 catalog layers A–K, item drawer, accent-aware local search, Mapillary). The only
  state persisted today is `?feature=<name>`.

---

## 1. Goal & success criteria

When this lands:

1. **Shareable state** — pan/zoom, which layers are on, curated-vs-Everything mode, and the open
   feature all survive a reload and copy-paste into a fresh tab. The existing `?feature=` link keeps
   working.
2. **Take it with you** — every climb, route, and POI in the drawer has a one-click GPX download that
   opens in Garmin/Wahoo/Komoot, carries elevation on tracks, and embeds source attribution + the
   ODbL licence (the data is open but attribution-bound).
3. **Live profile** — hovering or scrubbing the elevation chart drives a marker along the route on the
   map with a distance + elevation readout; keyboard arrows do the same; everything cleans up when the
   drawer closes.

**Guiding constraints:**
- No backend, no dependencies, no build. New logic is small functions/IIFEs inside `map.html`, styled
  like the surrounding code.
- Nothing existing breaks — especially the `?feature=` deep-link and the drawer/search behaviour.
- `version.js` is bumped (Demo v0.1.1 → **v0.2.0**) in the same change. Brand/logo assets are **not**
  touched.

**Out of scope:** geolocation ("find me"), measure tool, print, offline/service-worker, the faked route
planner, and wiring the dead discipline/freshness chips. Bulk "export everything visible" is explicitly
deferred (per-item only).

---

## 2. Current-state facts the design relies on

Verified against the code and data before writing:

- **Routes** (`CC_ROUTES.routes`, 11) carry `loop` (full `[lat,lng]` path, 332–877 pts), `elev` (always
  **51** samples), `gain`, `km`, `difficulty`, `season`, `start`. These are the K-layer "Quality rides".
- **Climbs** (`CC_CLIMBS`, ~10) are mostly **point** features (`geom.ll = [lat,lng]`); a couple carry a
  traced line + `grad` strip. None carry an `elev` array.
- The drawer's `elevSvg(f.elev)` (map.html ~1622) takes a flat array of elevation **values** (no coords)
  and draws a static SVG polyline. `gradStrip(f.grad)` (~1616) draws the illustrative gradient bars.
- `mode` is `let mode = 'curated'` (map.html 1324), toggled by `#mode button[data-m]` (1924–1927;
  values `'curated'` / `'all'`).
- Active layers live in an `active` Set keyed by `CATALOG` key `k` (1466, `active.has(k)`). `CATALOG`
  is defined at 926; each layer has a `.letter` (A–K), `.key`, `.exp`, `.features`, `.color`.
- `render()` (1461) redraws all layers from `active` + `mode`. `openDrawer(layer, f)` (~1632) fills the
  drawer; `openFeatureByName` honours `?feature=` on load (~903).

---

## 3. Feature 1 — Permalink (URL hash state)

### Behaviour
- **Hash format:** `#<zoom>/<lat>/<lng>&l=<letters>&m=<cur|all>&f=<slug>`
  - Example: `#15/50.335/5.920&l=ABCK&m=cur&f=cote-de-wanne`
  - `l=` is the sorted active-layer letters; **omitted only** when every layer is active (the default),
    so the common shared link stays short.
  - `m=` is `cur` or `all`; **always written** (matches the example above; avoids an omit-vs-default edge
    case).
  - `f=` is the open feature slug; omitted when no drawer is open.
  - `zoom` to 2 dp, `lat`/`lng` to 5 dp (~1 m).

### Components (all in map.html)
- `encodeState()` → reads `map.getZoom()/getCenter()`, the `active` Set (→ letters via a key→letter
  map built from `CATALOG`), `mode`, and the currently-open feature slug; returns the hash string.
- `writeHash()` → `history.replaceState(null, '', '#' + encodeState())`. Debounced ~400 ms when driven
  by `map.on('moveend')`; called immediately (no debounce) on layer toggle, mode switch, drawer open,
  and drawer close.
- `applyStateFromHash()` → parse `location.hash`; if a valid camera triple is present, `map.jumpTo`
  (during load) the camera, rebuild `active` from `l=` letters (letter→key map), set `mode` + reflect
  the `#mode` button `.on` state and the `.map-top .sub` caption, `render()`, then open `f=` if present.

### Data flow / lifecycle
- **On load:** if `location.hash` parses to a valid state → apply it (overrides the default Wallonia
  `fitBounds`). Else if `?feature=` query is present → existing `openFeatureByName` path. Else → current
  default `fitBounds`.
- **During session:** `writeHash()` keeps the hash current as the user moves/toggles. `replaceState`
  means no new history entries (no back-button spam); the browser back button still leaves the map.

### Error handling / edge cases
- Malformed hash, NaN camera values, or out-of-range zoom → ignore entirely, fall back to default; never
  throw on load.
- Unknown layer letters in `l=` → skip those letters, keep the valid ones.
- Unknown `f=` slug → camera/layers still apply; no drawer opens.
- Guard against a write/read loop: applying state on load must not trigger a redundant `writeHash` that
  corrupts the very hash being read.

### Hook points
Map init (~550+), `map.on('moveend')`, the layer-toggle handler (where `active` mutates), the `#mode`
button onclick (1924), and `openDrawer` / drawer-close.

---

## 4. Feature 2 — GPX export (per-item drawer button)

### Behaviour
- A `⬇ GPX` action button (`cc-d-act` styling, next to Edit/Vote) appears in the drawer **only** when the
  feature has geometry.
- Line features (route `loop`, traced climbs with a path) → a GPX `<trk>` / `<trkseg>` of `<trkpt>`.
- Point features (`geom.ll`) → a single `<wpt>`.
- Filename `<slug(f.name)>.gpx`, MIME `application/gpx+xml`.

### Components (all in map.html)
- `buildGpx(f, layer)` → returns a GPX 1.1 XML string:
  - `<metadata>`: `<name>`, `<desc>` (feature description, truncated), `<copyright>`/`<link>` carrying
    `f.source` attribution + the ODbL 1.0 licence URL. **All text XML-escaped** (`& < > " '`).
  - Track: each `loop` point → `<trkpt lat lon>`. The 51 `elev` samples are interpolated onto track
    points by cumulative distance (see §5 shared helper), so `<ele>` is present and monotone-correct.
  - Waypoint: `<wpt lat lon><name>…</wpt>` for point features.
- `downloadGpx(f, layer)` → `new Blob([buildGpx(...)], {type:'application/gpx+xml'})` →
  `URL.createObjectURL` → click a synthetic `<a download>` → `URL.revokeObjectURL` after.

### Error handling / edge cases
- No geometry → no button rendered (never produce an empty GPX).
- Names/descriptions with `&`, `<`, quotes → escaped so the GPX stays valid XML.
- A route whose `loop` is a closed loop is fine (GPX tracks may revisit points).

### Hook points
`buildRecord` (~1558–1614) appends the button into the `act` string when geometry exists; a delegated
click handler (or inline) calls `downloadGpx`.

---

## 5. Feature 3 — Interactive elevation (profile → map)

### Behaviour
- For features with **both** `elev` and a path (routes; any traced climb that gains an `elev`), the
  static `elevSvg` becomes interactive. Point / no-path features keep today's static chart.
- Hover or scrub the chart → (a) a vertical crosshair + dot on the SVG at the cursor, (b) a small readout
  chip showing `distance km · elevation m`, (c) a lightweight marker on the map at the interpolated
  coordinate along the route.
- `pointerleave` (and drawer close / feature switch) hides the crosshair and removes the map marker.
- **Keyboard:** the chart is focusable; ←/→ step through the 51 samples; an `aria-live` region announces
  `distance · elevation`. Matches the existing keyboard patterns (search, lightbox).
- **No camera movement on hover** — the user already flew to the feature on open; moving the camera while
  scrubbing would be jarring.

### Components (all in map.html)
- **Shared helper `pathCumDist(loop)`** → array of cumulative haversine distances along `loop` (also used
  by GPX elev interpolation in §4). `coordAt(loop, cum, t)` → the `[lat,lng]` at fraction `t∈[0,1]` of
  total distance, linearly interpolated between the two bounding vertices.
- `elevSvgInteractive(f)` → renders the same SVG plus a hidden crosshair group + readout, wires
  `pointermove`/`pointerdown`/`touchmove`/`pointerleave`/`keydown`, and on each move computes
  `t = clientX→fraction`, `i = round(t*50)`, elevation `f.elev[i]`, distance `t*f.km`, coordinate
  `coordAt(f.loop, cum, t)`.
- **Map marker:** a single GeoJSON source `elev-cursor` + a circle layer (cheaper than a DOM marker and
  consistent with how other dynamic geometry is managed). `setData` to move it; `setData(empty)` to hide.

### Error handling / edge cases
- Feature has `elev` but no `loop` (e.g. a traced climb with only `grad`) → render the chart with
  crosshair + readout but **no** map marker (graceful degrade to a crosshair-only experience).
- Marker must be removed when the drawer closes or a different feature opens — no orphaned cursor.
- Rapid pointer events: cheap math, no throttle needed, but guard against `loop.length < 2`.

### Hook points
Replace the `elev` line in `buildRecord` (~1590) to call the interactive variant when `f.loop` exists;
register the `elev-cursor` source/layer once at map init; clear it in the drawer-close path.

---

## 6. Testing & verification

Light by preference — no Playwright unless something looks off.

- **Node sanity script** (scratchpad, not committed): load `routes-data.js`, assert for each route that
  (a) `buildGpx` emits well-formed GPX (root `<gpx>`, balanced `<trk>`/`<trkpt>`, valid lat/lon ranges,
  no unescaped `&`), and (b) `coordAt(loop, cum, t)` for `t ∈ {0, .25, .5, .75, 1}` returns coords inside
  the route's bbox and `t=0`→first point, `t=1`→last point.
- **Manual pass:**
  1. Pan/zoom, toggle a couple of layers, switch to Everything, open a feature → reload → identical view.
  2. Copy the hash into a fresh tab → same camera + layers + mode + open feature.
  3. Legacy `?feature=<name>` (no hash) → still opens that feature.
  4. Open a route → `⬇ GPX` downloads a file that opens in a GPX viewer with elevation.
  5. Hover/scrub the profile → marker tracks the route; ←/→ work; leaving the chart clears the marker;
     closing the drawer leaves no orphan marker.

---

## 7. Delivery

- Single feature branch of work committed to **main** (project convention). No push without explicit
  approval.
- `version.js` bumped to Demo v0.2.0 in the same change.
- Commits scoped per feature where it reads cleanly (`feat(atlas): map permalink …`, `feat(atlas): GPX
  export …`, `feat(atlas): interactive elevation …`), or one combined commit — decided at implementation
  time. No `Co-Authored-By` trailers.
