# Mapillary street-level imagery layer — design

**Date:** 2026-06-19
**Page:** `atlas/demo/map.html` (static MapLibre GL JS, single file)
**Status:** approved

## Goal

Add a Google-Street-View-style feature to the Commons map: a **colored coverage line**
showing where Mapillary street-level imagery exists, and a **viewer** that opens the real
photo when a covered road is clicked — with full pano + sequence navigation (drag to look,
arrows to move along the road).

## User decisions

- **Scope:** coverage line **+** embedded photo viewer (full Street-View-like experience).
- **Token:** wired with a clearly-marked **placeholder constant**; feature is dormant
  (toggle disabled) until a real token is dropped in.
- **Viewer placement:** **docked along the bottom of the map** as a strip; from there the
  user can **expand to a large/fullscreen** version.
- **Library:** official **mapillary-js** viewer (loaded lazily from CDN).

## Architecture

Single-file change in `atlas/demo/map.html`. Five units:

### 1. Token constant
```js
// Public Mapillary client token (MLY|...). Replace the placeholder to enable the layer.
const MAPILLARY_TOKEN = 'MLY|PASTE_TOKEN_HERE';
const MLY_ENABLED = /^MLY\|/.test(MAPILLARY_TOKEN) && !/PASTE_TOKEN_HERE/.test(MAPILLARY_TOKEN);
```
When `MLY_ENABLED` is false, the toggle renders disabled with a hint and no network calls fire.

### 2. Coverage line layer + source
- Add the Mapillary public vector-tile source on map load (only if enabled):
  `https://tiles.mapillary.com/maps/vtp/mly1_public/2/{z}/{x}/{y}?access_token=<token>`
- Render the `sequence` source-layer as a **line**, color **Mapillary green `#05CB63`**
  (intentionally outside the earthy palette so it reads as "imagery coverage", like
  Street-View blue). Width interpolates with zoom; ~70% opacity.
- Hidden by default (`visibility: none`); inserted **below the pin markers** so pins stay
  clickable, **above** the ride/surface lines.

### 3. Toggle control
- New `.grp` in the left rail, mirroring the Ride-heatmap toggle:
  `Street-level imagery · Mapillary` with Off / On buttons + a `.hint`.
- On → set layer visibility visible and change the cursor over the line to a pointer.
- When `MLY_ENABLED` is false: buttons disabled, hint reads
  "Add a Mapillary token (`MAPILLARY_TOKEN`) to enable."

### 4. Click → resolve image
- Click on the green line: take the clicked `lngLat`, build a small bbox around it, and
  query the Graph API for the nearest image:
  `https://graph.mapillary.com/images?access_token=<token>&fields=id,computed_geometry&bbox=<minx,miny,maxx,maxy>&limit=1`
- On a hit: open the viewer dock on that image id, and drop/update a **"you-are-here"
  marker** at the image location, kept in sync as the user navigates the sequence.
- On no hit / network error: small popup "No street-level imagery here."

### 5. Bottom-docked mapillary-js viewer
- A fixed strip at the bottom of `.map-wrap` (~220px tall) that slides up when an image
  opens. Contains the mapillary-js `Viewer` mounted in a sized container.
- Header row in the dock: a label, an **⤢ expand** button, and a **✕ close** button.
  - **Expand** → toggles a `.full` class that grows the dock to a large overlay
    (≈ inset 4vh/4vw, covering most of the map); same button collapses it back.
  - **Close** → hides the dock, removes the you-are-here marker, optionally disposes the
    viewer to free memory.
- **Lazy load:** mapillary-js JS + CSS are injected (appended to `<head>`) on first open,
  not at page load, so the map is not slowed for users who never open it. A tiny loader
  promise guards against double-injection and concurrent opens.
- Viewer `image` event → read the current image's lng/lat → move the you-are-here marker.

## Data flow

```
toggle ON ──> show #05CB63 sequence lines (vector tiles)
click line ─> Graph API nearest-image ─> imageId
   imageId ─> ensure mapillary-js loaded ─> Viewer.moveTo(imageId)
              ─> dock slides up, marker drops at image location
   viewer "image" event ─> update marker lng/lat
   ⤢ expand ─> dock grows to fullscreen overlay (toggle)
   ✕ close  ─> dock hides, marker removed
```

## Error handling

| Condition | Behaviour |
|---|---|
| No / placeholder token | Toggle disabled + hint; zero network calls |
| Vector tiles fail to load | MapLibre logs; rest of map unaffected (source add wrapped) |
| Graph API: no image near click | Popup "No street-level imagery here." |
| Graph API: network error | Same popup; console warn |
| mapillary-js CDN fails | Popup "Viewer failed to load."; dock stays closed |

## Visual / palette

- Coverage line: `#05CB63` (Mapillary green), `line-opacity` ~0.7,
  `line-width` interpolated `z10→1.5 … z16→4`.
- You-are-here marker: reuse the `.cc-pin` style with `--c:#05CB63` and a camera glyph.
- Dock chrome: matches the existing `--ink` / `--paper` dark UI used by the drawer.

## Scope guard (YAGNI — explicitly out)

- No image upload, no date/camera/organization filtering.
- No offline caching or tile prefetch.
- No clustering of image points; we render sequence lines only (image points come for free
  inside the viewer's own navigation).

## Files touched

- `atlas/demo/map.html` — all of the above (HTML dock markup, CSS for dock/marker, JS for source,
  toggle, click resolver, lazy loader, viewer wiring).

No new committed dependencies (mapillary-js comes from CDN at runtime); no build step.
