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
import { map, flyToPin } from './map-init.js';
import { SURFACE_CLS, surfaceStyle } from './render.js';
import { D } from './i18n.js';
import { layerByKey } from './catalog.js';
import { openDrawer } from './drawer.js';

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
      map.on('click', id, e => openSurfaceDrawer(e.features[0].properties, e.lngLat));
      map.on('mouseenter', id, () => { map.getCanvas().style.cursor = 'pointer'; });
      map.on('mouseleave', id, () => { map.getCanvas().style.cursor = ''; });
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
  applyClassVisibility();   // per-class filters compose with the layer switch
  return visible;
}

/** Localised name for a canonical class, falling back to the raw key. */
export function classLabel(cls) {
  const k = { cycleway: 'surfCycleway', paved: 'surfPaved', gravel: 'surfGravel',
              pave: 'surfCobbles', dirt: 'surfDirt', rock: 'surfRock',
              unverified: 'surfUnverified' }[cls];
  return (k && D[k]) || cls;
}

/**
 * Drawer for one tile line.
 *
 * Opened against the **A layer** (`layerByKey.surface`), so it wears road
 * surface's colour, icon and label: a tile line IS road-surface data, just
 * nobody's-curated-it-yet road-surface data.
 *
 * **No "improve this" action, deliberately.** The obvious move was to set
 * `osmRef` and let the existing edit bridge build `/improve?ref=way/NNN&type=A`
 * — materialize-on-edit, exactly as an uncurated coverage POI works. It does
 * not work here and cannot: `ContributeController::materialize()` resolves the
 * ref through `coverage_poi`, the POINT index, and surface lines never enter
 * PostGIS at all (that is the whole reason country-scale lines are cheap). The
 * lookup misses, the wizard falls through to "Pick a place to improve", and the
 * rider gets a dead end that looks like a feature. Verified by clicking it.
 *
 * So the drawer links to the exact OSM way instead — which is also the honest
 * destination: a surface tag is upstream data, and data-priority.md says
 * upstream fixes belong upstream. Wiring a real A-item bridge needs a
 * materialize path that does not go through coverage_poi, and that is its own
 * piece of work.
 */
export function openSurfaceDrawer(p, lngLat) {
  const layer = layerByKey.surface;
  if (!layer) return;
  const label = classLabel(p.cls);
  const rec = [{ label: D.surface || 'Surface', value: label, method: 'OSM' }];
  // The raw OSM highway value, unlocalised on purpose: it is the tag, and a
  // rider following it back to OSM needs the word OSM uses.
  if (p.hw) rec.push({ label: D.roadType || 'Road type', value: p.hw });

  openDrawer(layer, {
    name: label,
    headline: label + (p.hw ? ' · ' + p.hw : ''),
    geom: { ll: [lngLat.lat, lngLat.lng] },
    record: rec,
    source: 'OpenStreetMap',
    // The exact way, not a coordinate query: we know the element id, so the
    // source link goes straight to the object whose tags the rider is reading.
    osmUrl: p.ref ? 'https://www.openstreetmap.org/' + p.ref : undefined,
  });
  flyToPin([lngLat.lng, lngLat.lat]);
}

/* ── Class filters ─────────────────────────────────────────────────────────
   The legend doubles as a filter: tick gravel alone and the map shows gravel
   alone. It applies to BOTH layers on purpose — the tile skin AND the curated
   A items — because the legend is one key for both, and "show me only gravel"
   that still drew curated asphalt would be answering a different question. */
const classesOff = new Set();

export const surfaceClassEnabled = cls => !classesOff.has(cls);

/** Visibility for one class = its own toggle AND (for tiles) the layer switch. */
function applyClassVisibility() {
  const style = map.getStyle();
  if (!style) return;
  style.layers.forEach(l => {
    if (l.id.startsWith('surftile-')) {
      const cls = l.id.slice('surftile-'.length).replace(/-[a-z]{2}$/, '');
      map.setLayoutProperty(l.id, 'visibility',
        visible && surfaceClassEnabled(cls) ? 'visible' : 'none');
    } else if (l.id.startsWith('surface-cls-')) {
      // The curated A layer. Hidden per class too, but never gated on the tile
      // toggle — these are our own items and stay on when the skin is off.
      const cls = l.id.slice('surface-cls-'.length);
      map.setLayoutProperty(l.id, 'visibility', surfaceClassEnabled(cls) ? 'visible' : 'none');
    }
  });
}

export function toggleSurfaceClass(cls) {
  if (classesOff.has(cls)) classesOff.delete(cls); else classesOff.add(cls);
  if (!added) addSurfaceTiles();
  applyClassVisibility();
  return surfaceClassEnabled(cls);
}

/* ── Study mode ────────────────────────────────────────────────────────────
   Drops the basemap so the surface classes can be read on their own. The
   basemap is a NAMED set of sources rather than a guess at layer ids: the
   OpenFreeMap style ships `openmaptiles` (109 layers) and `ne2_shaded`, and
   the satellite/street-level overlays go with them because the point is a
   blank field. Everything else on the map is ours and stays.

   The background layer is recoloured rather than hidden — hiding it leaves
   the canvas transparent, which shows whatever is behind the map element. */
const BASEMAP_SOURCES = new Set(['openmaptiles', 'ne2_shaded', 'satellite', 'mly']);
const STUDY_BG = '#EDEDE8';
let study = false;
let bgBefore = null;

export const studyModeOn = () => study;

export function setStudyMode(on) {
  const style = map.getStyle();
  if (!style) return study;
  study = !!on;
  style.layers.forEach(l => {
    if (l.type === 'background') {
      if (study) {
        if (bgBefore === null) bgBefore = map.getPaintProperty(l.id, 'background-color') ?? '#ffffff';
        map.setPaintProperty(l.id, 'background-color', STUDY_BG);
      } else if (bgBefore !== null) {
        map.setPaintProperty(l.id, 'background-color', bgBefore);
      }
      return;
    }
    if (!BASEMAP_SOURCES.has(l.source)) return;
    // Satellite and street-level keep their own toggles; study mode only
    // forces them off, and turning study off restores what the style had.
    map.setLayoutProperty(l.id, 'visibility', study ? 'none' : 'visible');
  });
  document.querySelector('.map-wrap')?.classList.toggle('study', study);
  return study;
}
