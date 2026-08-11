// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Road-surface LINE tiles — the reference skin under the curated A layer.
   Design: docs/specs/Dated/2026-08-09-surface-line-tiles-design.md.

   Every surfaced way OpenStreetMap knows about, from its own PMTiles artifact
   (separate from the coverage points: different geometry, different build, its
   own URL). Measured on Belgium: 417,371 ways in 43 MB at z8-13.

   Three things this module is careful about:

   1. **It draws UNDERNEATH the catalog.** These are somebody else's facts about
      a road; our curated A-items are ours, and they win visually. Adding the
      layers at map-load time puts them below everything render() adds later,
      and render() additionally moves the A-layer classes to the top on every
      draw, so the ordering holds without this module policing it.

   2. **Off by default.** It is a reference skin, not a curated layer, and it is
      a lot of ink — the rail toggle is the only thing that turns it on.

   3. **The seven classes are the contract's**, shared with the pipeline that
      stamps `cls` on every line and pinned to it by surface-classes.test.cjs.
      SURFACE_STYLE is reused verbatim so a tile line and an A-item of the same
      class are the same colour and the same dash — the whole point of sharing
      the vocabulary. */
import { map } from './map-init.js';
import { SURFACE_CLS, surfaceStyle } from './render.js';

/* Gated on a real URL: absent means no artifact has been built, and the rail
   must not offer a toggle for tiles that do not exist.

   A FUNCTION, not a module-level constant, and that distinction cost an hour:
   `pmtiles` is a global from a classic <script>, and this module is evaluated
   as part of the ES module graph, which finished first. A constant captured
   `typeof pmtiles === 'undefined'` and stayed false for the life of the page —
   no error, no warning, just a toggle that never appeared. Evaluating at call
   time removes the ordering dependency entirely. */
export const surfaceTilesAvailable = () =>
  surfaceTilesConfigured() && typeof pmtiles !== 'undefined';

/* Whether an artifact EXISTS — the question the rail toggle should ask.
   Deliberately not the same as surfaceTilesAvailable(): the button is wired at
   init time, which can precede the pmtiles global, and gating the control on a
   library that has not finished loading hides it for good. Whether the
   protocol is ready is the add path's problem, not the control's. */
export const surfaceTilesConfigured = () =>
  typeof window.CC_SURFACE_URL === 'string' && !!window.CC_SURFACE_URL;

export const SURFACE_TILE_SOURCE = 'surface-tiles';
export const surfaceTileLayerIds = () => SURFACE_CLS.filter(c => c !== 'other').map(c => 'surftile-' + c);

let added = false;
let visible = false;

/* The source-layer a country's lines live in. The pipeline emits
   `surface_<cc>` per country (build_surface_pmtiles), so the client asks for
   the same set the coverage layer does — one per built country, with the
   plain `surface` name as the pre-split fallback so an older artifact still
   renders rather than silently drawing nothing. */
function sourceLayers() {
  const ccs = Array.isArray(window.CC_COVERAGE_COUNTRIES) ? window.CC_COVERAGE_COUNTRIES : [];
  return ccs.length ? ccs.map(cc => 'surface_' + String(cc).toLowerCase()) : ['surface'];
}

export function addSurfaceTiles() {
  if (!surfaceTilesAvailable() || added || map.getSource(SURFACE_TILE_SOURCE)) return;
  maplibregl.addProtocol('pmtiles', new pmtiles.Protocol().tile);
  map.addSource(SURFACE_TILE_SOURCE, { type: 'vector', url: 'pmtiles://' + window.CC_SURFACE_URL });

  // One filtered layer per class, exactly as the A layer does: a dash pattern
  // cannot vary per feature inside one layer, so the class has to be the layer.
  sourceLayers().forEach(srcLayer => {
    SURFACE_CLS.filter(c => c !== 'other').forEach(cls => {
      const st = surfaceStyle(cls);
      const id = 'surftile-' + cls + (srcLayer === 'surface' ? '' : '-' + srcLayer.slice(8));
      if (map.getLayer(id)) return;
      const paint = {
        'line-color': st.color,
        // Thinner than the curated A layer on purpose: this is background
        // information, and at country zoom a full-weight network is a smear.
        'line-width': ['interpolate', ['linear'], ['zoom'], 8, 0.6, 11, 1.4, 14, 3],
        'line-opacity': ['interpolate', ['linear'], ['zoom'], 8, 0.5, 12, 0.85],
      };
      if (st.dash) paint['line-dasharray'] = st.dash;
      map.addLayer({
        id,
        type: 'line',
        source: SURFACE_TILE_SOURCE,
        'source-layer': srcLayer,
        filter: ['==', ['get', 'cls'], cls],
        layout: { 'line-cap': st.cap || 'round', 'line-join': 'round', visibility: 'none' },
        paint,
      });
    });
  });
  added = true;
}

/** Is the layer currently drawn? */
export const surfaceTilesVisible = () => visible;

/** Turn the whole skin on or off. Adds the source lazily on first use. */
export function setSurfaceTiles(on) {
  if (!surfaceTilesAvailable()) return false;
  if (!added) addSurfaceTiles();
  visible = !!on;
  map.getStyle().layers
    .filter(l => l.id.startsWith('surftile-'))
    .forEach(l => map.setLayoutProperty(l.id, 'visibility', visible ? 'visible' : 'none'));
  return visible;
}
