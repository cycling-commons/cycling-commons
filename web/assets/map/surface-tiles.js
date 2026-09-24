// SPDX-License-Identifier: AGPL-3.0-only
/* Road-surface LINE tiles (docs/specs/coverage-provider.md §4): OSM ways from
   their own PMTiles artifact, drawn under curated A items. Off until the rail
   toggle. SURFACE_STYLE is shared with A so a tile line and an A-item of the
   same class look the same. */
import { map, flyToPin } from './map-init.js';
import { SURFACE_CLS, surfaceStyle } from './render.js';
import { D, trVal } from './i18n.js';
import { layerByKey } from './catalog.js';
import { openDrawer } from './drawer.js';
import { familyConfigured, mountInView, ensureProtocol } from './tile-sources.js';

// Function not constant: classic <script> pmtiles vs ES module eval order.
export const surfaceTilesAvailable = () =>
  surfaceTilesConfigured() && typeof pmtiles !== 'undefined';

/* Whether an artifact exists — the rail toggle's question. Not the same as
   surfaceTilesAvailable(): the control is wired at init, which can precede
   the pmtiles global. */
export const surfaceTilesConfigured = () => familyConfigured('surface', 'classified');

/* Per-country sources are `surface-classified-<cc>` and `surface-todo-<cc>` (tile-sources.js). */
export const isSurfaceSource = id => /^surface-(classified|todo|gaps)(-|$)/.test(String(id || ''));
export const isTodoSource = id => String(id || '').startsWith('surface-todo-');
const TODO_PREFIX = 'surftodo-';
/* To-do lines start here; the gaps grid stops. Pinned to contract
   surface.todo.minZoom / surface.gaps.maxZoom by surface-zooms.test.cjs. */
const TODO_MIN_ZOOM = 11;
/* The grid draws one level past the handover (owner 2026-09-18): MapLibre
   stretches the z11 tiles, so squares and to-do lines overlap from z11 to
   z12. The build still stops the grid at the handover; no rebuild needed. */
export const GAPS_MAX_ZOOM = 12;

/* Classified-skin floor (docs/specs/coverage-provider.md §4): MapLibre derives
   the source zoom range from layers, so this stops z8/z9 tile fetches. */
export const CLASSIFIED_MIN_ZOOM = 10;
export const SURFACE_GAPS_SOURCE = 'surface-gaps';
const GAPS_FILL = 'surfgaps-fill';
const GAPS_LINE = 'surfgaps-line';
export const surfaceTileLayerIds = () => SURFACE_CLS.filter(c => c !== 'other').map(c => 'surftile-' + c);

/* Smoothness ticks over class lines (docs/specs/coverage-provider.md §4).
   No tick means nobody has said — filtered to features that HAVE sm.
   Keys pinned to contract surface.quality.values by surface-quality.test.cjs. */
const SM_TONE = {
  excellent: '#1E8E4F',
  good: '#5FA845',
  intermediate: '#D9A62E',
  bad: '#D4763B',
  very_bad: '#C2402F',
  horrible: '#C2402F',
  very_horrible: '#C2402F',
  impassable: '#8E2A20',
};
/* Raw OSM smoothness → the A form's five-value vocabulary (display only). */
const SM_LABEL = {
  excellent: 'Excellent', good: 'Good', intermediate: 'Intermediate',
  bad: 'Bad', very_bad: 'Very bad', horrible: 'Very bad',
  very_horrible: 'Very bad', impassable: 'Very bad',
};
/* Pinned to contract surface.quality.minZoom by surface-quality.test.cjs. */
const SM_MIN_ZOOM = 13;
const QUALITY_PREFIX = 'surfq-';

let added = false;
let visible = false;

/* Pipeline emits surface_<cc> per country; plain `surface` is the pre-split fallback. */
function sourceLayers() {
  const ccs = Array.isArray(window.CC_COVERAGE_COUNTRIES) ? window.CC_COVERAGE_COUNTRIES : [];
  return ccs.length ? ccs.map(cc => 'surface_' + String(cc).toLowerCase()) : ['surface'];
}

/* One mounted source can serve one country's own source-layer, or (the shared
   '*' archive) every country's — same split coverage.js already made. */
function sourceLayersFor(key) {
  return key === '*' ? sourceLayers() : ['surface_' + key];
}

// One layer per class: a dash pattern cannot vary per feature inside one layer.
function addClassifiedLayers(srcLayer, src) {
  SURFACE_CLS.filter(c => c !== 'other').forEach(cls => {
    const st = surfaceStyle(cls);
    const id = 'surftile-' + cls + (srcLayer === 'surface' ? '' : '-' + srcLayer.slice(8));
    if (map.getLayer(id)) return;
    const paint = {
      'line-color': st.color,
      // Thinner than curated A: this is background; full weight at country zoom is a smear.
      'line-width': ['interpolate', ['linear'], ['zoom'], 8, 0.6, 11, 1.4, 14, 3],
      'line-opacity': ['interpolate', ['linear'], ['zoom'], 8, 0.5, 12, 0.85],
    };
    if (st.dash) paint['line-dasharray'] = st.dash;
    map.addLayer({
      id,
      type: 'line',
      source: src,
      'source-layer': srcLayer,
      // Floor stops MapLibre requesting z8/z9 tiles (source zoom range comes from layers).
      minzoom: CLASSIFIED_MIN_ZOOM,
      filter: ['==', ['get', 'cls'], cls],
      layout: { 'line-cap': st.cap || 'round', 'line-join': 'round', visibility: 'none' },
      paint,
    });
    map.on('click', id, e => openSurfaceDrawer(e.features[0].properties, e.lngLat, e.features[0].geometry,
      { source: src, sourceLayer: srcLayer }));
    map.on('mouseenter', id, () => { map.getCanvas().style.cursor = 'pointer'; });
    map.on('mouseleave', id, () => { map.getCanvas().style.cursor = ''; });
  });

  // Quality ticks above the class lines of the same country.
  const qid = QUALITY_PREFIX + (srcLayer === 'surface' ? 'all' : srcLayer.slice(8));
  // Cream casing under each colour tick so amber-on-ochre stays visible.
  // Dash scaled by width ratio so casing and tick share one period.
  const qcase = QUALITY_PREFIX + 'case-' + (srcLayer === 'surface' ? 'all' : srcLayer.slice(8));
  if (!map.getLayer(qcase)) {
    map.addLayer({
      id: qcase,
      type: 'line',
      source: src,
      'source-layer': srcLayer,
      minzoom: SM_MIN_ZOOM,
      filter: qualityFilter(),
      layout: { 'line-cap': 'butt', visibility: 'none' },
      paint: {
        'line-color': '#FBF4E4',
        'line-width': 5.5,
        'line-dasharray': [0.6 * 3.5 / 5.5, 2.8 * 3.5 / 5.5],
        'line-opacity': 0.9,
      },
    });
  }
  if (!map.getLayer(qid)) {
    map.addLayer({
      id: qid,
      type: 'line',
      source: src,
      'source-layer': srcLayer,
      minzoom: SM_MIN_ZOOM,
      filter: qualityFilter(),
      layout: { 'line-cap': 'butt', visibility: 'none' },
      paint: {
        'line-color': ['match', ['get', 'sm'],
          ...Object.entries(SM_TONE).flat(), 'rgba(0,0,0,0)'],
        'line-width': 3.5,
        // dash 0.6 + gap 2.8 line-widths ≈ a 2 px tick every 12 px at this width.
        'line-dasharray': [0.6, 2.8],
        'line-opacity': 0.9,
      },
    });
  }
}

export function addSurfaceTiles() {
  if (!surfaceTilesAvailable() || added) return;
  added = true;
  const mount = () => mountInView(map, 'surface', 'classified', CLASSIFIED_MIN_ZOOM, (key, src) => {
    sourceLayersFor(key).forEach(sl => addClassifiedLayers(sl, src));
    applyClassVisibility();
  });
  mount();
  map.on('moveend', mount);
}

/* Curated-ref dedupe (docs/specs/coverage-provider.md §6): a way already served
   as an item draws once, as curated. Applied in applyClassVisibility because
   layers mount at map load while CC_CURATED_REFS arrives with the catalog. */
function surfDedupeFilter() {
  return ['!', ['in', ['get', 'ref'], ['literal', Array.from(window.CC_CURATED_REFS || [])]]];
}

/* Ticks only where smoothness exists, minus legend-hidden classes and curated refs. */
function qualityFilter() {
  const off = [...classesOff];
  const base = ['all', ['has', 'sm'], surfDedupeFilter()];
  if (off.length) base.push(['!', ['in', ['get', 'cls'], ['literal', off]]]);
  return base;
}

/** Is the to-do artifact configured (an URL, not necessarily loaded)? */
export const todoConfigured = () => familyConfigured('surface', 'todo');

/** Gap grid configured? Independent of the to-do arm. */
export const gapsConfigured = () =>
  !!(window.CC_TILES && window.CC_TILES.gaps && window.CC_TILES.gaps.url);

let untaggedAdded = false;
let gapsAdded = false;
/* Gaps grid is opt-in: it answers a contributor question, not a rider's. */
let gapsOn = false;
export const gapsGridOn = () => gapsOn;
export function setGapsGrid(on) {
  gapsOn = !!on;
  applyClassVisibility();
  return gapsOn;
}

/* Lazy-mounted to-do/grid layers go under curated catalog (skin if present,
   else first non-basemap). */
function belowOurLayers() {
  const layers = map.getStyle()?.layers || [];
  const skin = layers.find(l => l.id.startsWith('surftile-'));
  if (skin) return skin.id;
  const ours = layers.find(l => l.type !== 'background' && !BASEMAP_SOURCES.has(l.source));
  return ours ? ours.id : undefined;
}

function addTodoLayer(srcLayer, src, under) {
  const st = surfaceStyle('unverified');
  const id = TODO_PREFIX + (srcLayer === 'surface' ? 'all' : srcLayer.slice(8));
  if (map.getLayer(id)) return;
  map.addLayer({
    id,
    type: 'line',
    source: src,
    'source-layer': srcLayer,
    // Artifact has no tiles below this; declaring minzoom stops unbuilt fetches.
    minzoom: TODO_MIN_ZOOM,
    layout: { 'line-cap': st.cap || 'round', 'line-join': 'round', visibility: 'none' },
    paint: {
      'line-color': st.color,
      'line-dasharray': st.dash || [2, 2],
      // Thinner/fainter than classified: a to-do list must not out-shout a recorded road.
      'line-width': ['interpolate', ['linear'], ['zoom'], 11, 0.9, 15, 2.2],
      'line-opacity': ['interpolate', ['linear'], ['zoom'], 11, 0.5, 13, 0.7],
    },
  }, under);
  map.on('click', id, e => openSurfaceDrawer(
    { ...e.features[0].properties, cls: 'unverified' }, e.lngLat, e.features[0].geometry,
    { source: src, sourceLayer: srcLayer }));
  map.on('mouseenter', id, () => { map.getCanvas().style.cursor = 'pointer'; });
  map.on('mouseleave', id, () => { map.getCanvas().style.cursor = ''; });
}

export function addUntaggedTiles() {
  addGapsGrid();
  if (!todoConfigured() || untaggedAdded || typeof pmtiles === 'undefined') return;
  untaggedAdded = true;
  const mount = () => mountInView(map, 'surface', 'todo', TODO_MIN_ZOOM, (key, src) => {
    const under = belowOurLayers();
    sourceLayersFor(key).forEach(sl => addTodoLayer(sl, src, under));
    applyClassVisibility();
  });
  mount();
  map.on('moveend', mount);
}

/* Gap grid: same legend row as to-do lines, at zooms where individual roads
   cannot be read (docs/specs/coverage-provider.md §4). Colour is share
   unrecorded, not absolute kilometres. */
export function addGapsGrid() {
  if (!gapsConfigured() || gapsAdded || typeof pmtiles === 'undefined') return;
  ensureProtocol();
  if (!map.getSource(SURFACE_GAPS_SOURCE)) {
    map.addSource(SURFACE_GAPS_SOURCE, { type: 'vector', url: 'pmtiles://' + window.CC_TILES.gaps.url });
  }
  const under = belowOurLayers();
  const shade = ['interpolate', ['linear'], ['coalesce', ['get', 'pct'], 0],
    25, 0.06, 60, 0.18, 90, 0.34];
  map.addLayer({
    id: GAPS_FILL,
    type: 'fill',
    source: SURFACE_GAPS_SOURCE,
    'source-layer': 'gaps',
    maxzoom: GAPS_MAX_ZOOM + 1,
    layout: { visibility: 'none' },
    paint: { 'fill-color': surfaceStyle('unverified').color, 'fill-opacity': shade },
  }, under);
  map.addLayer({
    id: GAPS_LINE,
    type: 'line',
    source: SURFACE_GAPS_SOURCE,
    'source-layer': 'gaps',
    maxzoom: GAPS_MAX_ZOOM + 1,
    layout: { visibility: 'none' },
    // Hairline so the grid reads as a grid rather than a stain.
    paint: { 'line-color': surfaceStyle('unverified').color, 'line-width': 0.4, 'line-opacity': 0.35 },
  }, under);
  map.on('click', GAPS_FILL, e => openGapsDrawer(e.features[0].properties, e.lngLat));
  map.on('mouseenter', GAPS_FILL, () => { map.getCanvas().style.cursor = 'pointer'; });
  map.on('mouseleave', GAPS_FILL, () => { map.getCanvas().style.cursor = ''; });
  gapsAdded = true;
}

/** Grid-square drawer: not an improve bridge — a square is an area, not a road. */
export function openGapsDrawer(p, lngLat) {
  const layer = layerByKey.surface;
  if (!layer) return;
  const km = Number(p.km || 0);
  const pct = Number(p.pct || 0);
  openDrawer(layer, {
    name: D.gapsTitle || 'Not recorded here',
    headline: (D.gapsTitle || 'Not recorded here') + ' · ' + pct + '%',
    geom: { ll: [lngLat.lat, lngLat.lng] },
    record: [
      { label: D.gapsUnrecorded || 'Still to record',
        value: km.toLocaleString(undefined, { maximumFractionDigits: 0 }) + ' km' },
      { label: D.gapsShare || 'Share of local network', value: pct + '%' },
      { label: D.gapsRoads || 'Roads', value: String(p.n || 0) },
    ],
    desc: D.gapsHint || 'Tracks, paths and lanes around here have no recorded surface. '
      + 'Zoom in to see which ones, or ride them with Scout and the tags come back with you.',
    source: 'OpenStreetMap',
  });
  flyToPin([lngLat.lng, lngLat.lat]);
}

/** Is the layer currently drawn? */
export const surfaceTilesVisible = () => visible;

/** Turn the whole skin on or off. Adds the source lazily on first use. */
export function setSurfaceTiles(on) {
  if (!surfaceTilesAvailable()) return false;
  if (!added) addSurfaceTiles();
  if (on && surfaceClassEnabled('unverified')) addUntaggedTiles();   // second artifact: mount lazily
  visible = !!on;
  applyClassVisibility();   // per-class filters compose with the layer switch
  return visible;
}

export function classLabel(cls) {
  const k = { cycleway: 'surfCycleway', paved: 'surfPaved', gravel: 'surfGravel',
              pave: 'surfCobbles', dirt: 'surfDirt', rock: 'surfRock',
              unverified: 'surfUnverified' }[cls];
  return (k && D[k]) || cls;
}

/* Mirrors SurfaceVocabulary::TILE_CLASS; SurfaceConfirmClassContractTest pins them. */
export const CONFIRMABLE_CLASSES = ['paved', 'gravel', 'pave', 'dirt', 'rock'];
const CONFIRMABLE = new Set(CONFIRMABLE_CLASSES);

/* Highway → assumed traffic. Never stored, never prefilled into the form. */
const TRAFFIC_RULES = [
  { hw: ['cycleway'], value: 'Car-free', why: 'trafficWhyCycleway' },
  { hw: ['residential', 'living_street'], value: 'Quiet', why: 'trafficWhyResidential' },
  { hw: ['track', 'path', 'footway', 'bridleway'], value: 'Quiet', why: 'trafficWhyTrack' },
  { hw: ['primary', 'primary_link', 'secondary', 'secondary_link'], value: 'Busy', why: 'trafficWhyMain' },
];

export function assumedTraffic(hw) {
  const rule = TRAFFIC_RULES.find(r => r.hw.includes(hw));
  return rule ? { value: rule.value, why: D[rule.why] || '' } : null;
}

/* OSM highway → rider-facing kind. Mirrors App\Catalog\RoadType; RoadTypeContractTest pins them. */
const ROAD_TYPE = {
  primary: 'roadMain', primary_link: 'roadMain', secondary: 'roadMain', secondary_link: 'roadMain',
  tertiary: 'roadLocal', tertiary_link: 'roadLocal', unclassified: 'roadLocal', road: 'roadLocal',
  residential: 'roadResidential', living_street: 'roadResidential',
  track: 'roadTrack', path: 'roadPath', footway: 'roadPath', bridleway: 'roadPath',
  cycleway: 'roadCycleway',
};

export function roadTypeLabel(hw) {
  const k = ROAD_TYPE[hw];
  return k ? (D[k] || k) : null;
}

export function openSurfaceDrawer(p, lngLat, geometry, tileCtx) {
  const layer = layerByKey.surface;
  if (!layer) return;
  const label = classLabel(p.cls);
  const rec = [{ label: D.surface || 'Surface', value: label, method: 'OSM' }];
  // Quality: same word the contribute form offers. Raw tag stays when collapse is lossy.
  if (p.sm && SM_LABEL[p.sm]) {
    const smLabel = trVal(SM_LABEL[p.sm]);
    const lossy = p.sm !== SM_LABEL[p.sm].toLowerCase().replace(' ', '_');
    rec.push({ label: D.smoothness || 'Smoothness',
               value: lossy ? smLabel + ' · ' + p.sm : smLabel, method: 'OSM' });
  } else {
    /* Say so rather than leave the row out. An absent row reads as "we do not
       track this"; the map's ticks draw only where smoothness exists, so a road
       with none looks exactly like a road nobody has tagged, and a rider cannot
       tell the two apart. Naming the gap is what the Traffic row and the
       "Surface not recorded" legend class already do (owner 2026-08-31).

       The `empty` row style plus a link into the wizard is the same affordance
       the catalog drawer uses for an unset field (drawer.js). The wizard is
       reached by ref, the way the Edit action below it is: this stretch may
       have no item behind it yet, and materialize-on-edit is what creates one
       (osm-data-architecture.md §6). */
    const href = p.ref
      ? '/improve?ref=' + encodeURIComponent(p.ref)
        + '&type=' + encodeURIComponent(layer.letter) + '&field=smoothness'
      : null;
    rec.push({
      label: D.smoothness || 'Smoothness',
      empty: true,
      html: !!href,
      value: href
        ? '<a class="cc-d-add" href="' + href + '">＋ ' + (D.add || 'add') + '</a>'
        : (D.notRecorded || 'Not recorded'),
    });
  }
  if (p.mtb) {
    rec.push({ label: D.mtbScale || 'MTB scale', value: p.mtb, method: 'OSM' });
  }
  if (p.hw) {
    const kind = roadTypeLabel(p.hw);
    rec.push({ label: D.roadType || 'Road type', value: kind ? kind + ' · ' + p.hw : p.hw, method: 'OSM' });
  }
  const traffic = p.hw ? assumedTraffic(p.hw) : null;
  if (traffic) {
    rec.push({ label: D.traffic || 'Traffic', value: trVal(traffic.value), assumed: traffic.why });
  }

  let ends, spannedRefs, seedLine = null;
  if (tileCtx && isTodoSource(tileCtx.source)) {
    const run = unrecordedRunEnds(tileCtx.source, tileCtx.sourceLayer, p, geometry);
    ends = run.ends; spannedRefs = run.refs; seedLine = run.line || null;
  } else if (tileCtx) {
    seedLine = fullWayLine(tileCtx.source, tileCtx.sourceLayer, p.ref);
    ends = seedLine
      ? { a: seedLine[0], b: seedLine[seedLine.length - 1] }
      : fullWayEnds(tileCtx.source, tileCtx.sourceLayer, p.ref, geometry);
  } else {
    ends = segmentEnds(geometry);
  }
  // sessionStorage seed (too many vertices for the URL); cleared so the wizard never picks up a stale one.
  try {
    if (seedLine && ends) {
      sessionStorage.setItem('ccSegSeed', JSON.stringify({ a: ends.a, b: ends.b, line: seedLine, ts: Date.now() }));
    } else {
      sessionStorage.removeItem('ccSegSeed');
    }
  } catch (e) { /* storage blocked — the wizard falls back to the router */ }

  openDrawer(layer, {
    name: p.name || label,
    headline: p.name ? p.name + ' · ' + label : label + (p.hw ? ' · ' + p.hw : ''),
    osmName: p.name || '',
    geom: { ll: [lngLat.lat, lngLat.lng] },
    segmentEnds: ends,
    spannedRefs,
    confirmClass: CONFIRMABLE.has(p.cls) ? p.cls : undefined,  // cycleway/unverified claim no surface
    /* What OSM says, carried through the edit link so the submission can record
       it as the side the rider changed FROM. Without it a rider turning an
       asphalt road to gravel produces the same submission as one filling in a
       blank road, and a curator cannot tell the two apart
       (owner-reported 2026-08-31). Separate from confirmClass, which is only
       set for classes a rider may confirm. */
    osmSurface: p.cls || undefined,
    osmHighway: p.hw || undefined,
    record: rec,
    source: 'OpenStreetMap',
    osmUrl: p.ref ? 'https://www.openstreetmap.org/' + p.ref : undefined,
    osmRef: p.ref,
  });
  flyToPin([lngLat.lng, lngLat.lat]);
}

/* Legend filters both the tile skin and curated A items. */
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
      // Class filter + curated dedupe, refreshed together (see surfDedupeFilter).
      map.setFilter(l.id, ['all', ['==', ['get', 'cls'], cls], surfDedupeFilter()]);
    } else if (l.id.startsWith(QUALITY_PREFIX)) {
      /* Ticks ride the skin as a whole; per-class hiding is in the filter. */
      map.setLayoutProperty(l.id, 'visibility', visible ? 'visible' : 'none');
      map.setFilter(l.id, qualityFilter());
    } else if (l.id === GAPS_FILL || l.id === GAPS_LINE) {
      /* Grid is the to-do arm at planning zoom, behind its own toggle (gapsOn). */
      map.setLayoutProperty(l.id, 'visibility',
        visible && gapsOn && surfaceClassEnabled('unverified') ? 'visible' : 'none');
    } else if (l.id.startsWith(TODO_PREFIX)) {
      /* "Surface not recorded" is a legend class; it just lives in a second artifact. */
      map.setLayoutProperty(l.id, 'visibility',
        visible && surfaceClassEnabled('unverified') ? 'visible' : 'none');
      map.setFilter(l.id, surfDedupeFilter());
    } else if (l.id.startsWith('surface-cls-')) {
      // Curated A: hidden per class, never gated on the tile toggle.
      const cls = l.id.slice('surface-cls-'.length);
      map.setLayoutProperty(l.id, 'visibility', surfaceClassEnabled(cls) ? 'visible' : 'none');
    }
  });
}

export function toggleSurfaceClass(cls) {
  if (classesOff.has(cls)) classesOff.delete(cls); else classesOff.add(cls);
  if (!added) addSurfaceTiles();
  if ('unverified' === cls && surfaceClassEnabled(cls)) addUntaggedTiles();  // mount on demand
  applyClassVisibility();
  return surfaceClassEnabled(cls);
}

/* Study mode: hide named basemap sources so surface classes read alone.
   Recolour the background rather than hide it (transparent canvas shows through). */
const BASEMAP_SOURCES = new Set(['openmaptiles', 'ne2_shaded', 'satellite', 'mly']);
const STUDY_BG = '#EDEDE8';
let study = false;
let bgBefore = null;
/* Snapshot each basemap layer's visibility on entry; restore only what this mode hid. */
let visBefore = null;

export const studyModeOn = () => study;

export function setStudyMode(on) {
  const style = map.getStyle();
  if (!style) return study;
  study = !!on;
  if (study && visBefore === null) visBefore = new Map();
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
    if (study) {
      // MapLibre reports undefined for undeclared visibility (= visible). First snapshot only.
      if (!visBefore.has(l.id)) {
        visBefore.set(l.id, map.getLayoutProperty(l.id, 'visibility') || 'visible');
      }
      map.setLayoutProperty(l.id, 'visibility', 'none');
      return;
    }
    // Restore only what study hid, and only while still hidden.
    const before = visBefore?.get(l.id);
    if (before && map.getLayoutProperty(l.id, 'visibility') === 'none') {
      map.setLayoutProperty(l.id, 'visibility', before);
    }
  });
  if (!study) visBefore = null;
  document.querySelector('.map-wrap')?.classList.toggle('study', study);
  return study;
}

/** First/last vertex of the clicked way ([lng,lat]); MultiLineString uses outermost ends. */
export function segmentEnds(geometry) {
  if (!geometry) return null;
  const parts = geometry.type === 'MultiLineString' ? geometry.coordinates
    : geometry.type === 'LineString' ? [geometry.coordinates] : [];
  const flat = parts.flat();
  if (flat.length < 2) return null;
  return { a: flat[0], b: flat[flat.length - 1] };
}

/* OSM highway → road-type group for run-chaining. Cycleway is its own group so a dijk-then-cycleway chain breaks. */
function roadGroup(hw) {
  const G = { primary:'main', primary_link:'main', secondary:'main', secondary_link:'main',
    tertiary:'local', tertiary_link:'local', unclassified:'local', road:'local',
    residential:'residential', living_street:'residential',
    track:'track', path:'path', footway:'path', bridleway:'path',
    cycleway:'cycleway' };
  return G[hw] || hw || 'other';
}

/* Join tile fragments of one way. Clip never moves vertices; ~5 m finds the seam. Null if they will not join. */
function stitchParts(parts, d2) {
  const J2 = (5 / 111320) ** 2;
  const rem = parts.filter(p => p.length >= 2).sort((a, b) => b.length - a.length);
  if (!rem.length) return null;
  let line = rem.shift().slice();
  while (rem.length) {
    let progress = false;
    for (let i = 0; i < rem.length; i++) {
      const ext = extendWith(line, rem[i], d2, J2);
      if (ext) { line = ext; rem.splice(i, 1); progress = true; break; }
      if (containedIn(line, rem[i], d2, J2)) { rem.splice(i, 1); progress = true; break; }
    }
    if (!progress) return null;
  }
  return line;
}

/* Graft `part` onto either end of `line`, either orientation. Null when it touches neither end. */
function extendWith(line, part, d2, J2) {
  for (const cand of [part, part.slice().reverse()]) {
    const e = line[line.length - 1];
    let bi = -1, bd = Infinity;
    for (let i = 0; i < cand.length; i++) { const d = d2(cand[i], e); if (d < bd) { bd = d; bi = i; } }
    if (bd <= J2 && bi < cand.length - 1) return line.concat(cand.slice(bi + 1));
    const s = line[0];
    bi = -1; bd = Infinity;
    for (let i = 0; i < cand.length; i++) { const d = d2(cand[i], s); if (d < bd) { bd = d; bi = i; } }
    if (bd <= J2 && bi > 0) return cand.slice(0, bi).concat(line);
  }
  return null;
}

/* A fragment whose endpoints already lie on the line is a neighbour-tile overlap — drop it. */
function containedIn(line, part, d2, J2) {
  return [part[0], part[part.length - 1]].every(p => line.some(v => d2(v, p) <= J2));
}

/* Walk a component's stitched ways end to end from a degree-1 tip. Null when no way stitched, or the run is a loop. */
function walkRun(component, stitched, d2, tol2) {
  const refs = [...component].filter(r => { const l = stitched.get(r); return l && l.length >= 2; });
  if (refs.length !== component.size) return null;
  const endsOf = r => { const l = stitched.get(r); return [l[0], l[l.length - 1]]; };
  const all = refs.flatMap(r => endsOf(r).map(pt => ({ r, pt })));
  const degree = pt => all.reduce((n, o) => n + (d2(o.pt, pt) <= tol2 ? 1 : 0), 0);
  let start = null;
  for (const o of all) if (degree(o.pt) === 1) { start = o; break; }
  if (!start) return null;
  const unused = new Set(refs);
  let line = stitched.get(start.r).slice();
  if (d2(line[0], start.pt) > d2(line[line.length - 1], start.pt)) line.reverse();
  unused.delete(start.r);
  const order = [start.r];
  while (unused.size) {
    const tail = line[line.length - 1];
    let next = null, nd = Infinity;
    for (const r of unused) {
      for (const pt of endsOf(r)) { const d = d2(pt, tail); if (d < nd) { nd = d; next = r; } }
    }
    if (nd > tol2) break;
    let l = stitched.get(next).slice();
    if (d2(l[0], tail) > d2(l[l.length - 1], tail)) l.reverse();
    line = line.concat(l.slice(1));
    unused.delete(next); order.push(next);
  }
  return { line, refs: order };
}

/* Cap a seed line's vertex count (sessionStorage + server segment-point limit). Endpoints always survive. */
function capLine(line, max) {
  if (!line || line.length <= max) return line;
  const out = [];
  const step = (line.length - 1) / (max - 1);
  for (let i = 0; i < max; i++) out.push(line[Math.round(i * step)]);
  return out;
}

/** Unrecorded run: same-named, same-road-group, still-unrecorded neighbours chained end to end. */
export function unrecordedRunEnds(sourceId, sourceLayer, props, clickedGeometry) {
  const single = () => ({ ends: fullWayEnds(sourceId, sourceLayer, props.ref, clickedGeometry), refs: [props.ref] });
  if (!props.name) return single();   // no join key, no chain
  const group = roadGroup(props.hw);
  let feats = [];
  try {
    feats = map.querySourceFeatures(sourceId, { sourceLayer, filter: ['==', ['get', 'name'], props.name] });
  } catch (e) { return single(); }
  // Collect per-way parts (fragments repeat across tiles).
  const ways = new Map();
  for (const f of feats) {
    const fp = f.properties || {};
    if (roadGroup(fp.hw) !== group) continue;
    const g = f.geometry;
    const parts = g.type === 'LineString' ? [g.coordinates] : g.type === 'MultiLineString' ? g.coordinates : [];
    const w = ways.get(fp.ref) || [];
    for (const part of parts) if (part.length >= 2) w.push(part);
    ways.set(fp.ref, w);
  }
  if (!ways.has(props.ref) || !ways.get(props.ref).length) return single();
  // Each way's own outermost ends (farthest endpoint pair).
  const k = Math.cos((clickedGeometry && clickedGeometry.coordinates
    ? (clickedGeometry.type === 'LineString' ? clickedGeometry.coordinates[0][1] : clickedGeometry.coordinates[0][0][1])
    : 52) * Math.PI / 180);
  const d2 = (a, b) => { const dx = (a[0]-b[0])*k, dy = a[1]-b[1]; return dx*dx + dy*dy; };
  const wayEnds = new Map();
  for (const [ref, parts] of ways) {
    const eps = parts.flatMap(pp => [pp[0], pp[pp.length-1]]);
    let best = null, bd = -1;
    for (let i = 0; i < eps.length; i++) for (let j = i+1; j < eps.length; j++) {
      const d = d2(eps[i], eps[j]);
      if (d > bd) { bd = d; best = [eps[i], eps[j]]; }
    }
    if (best) wayEnds.set(ref, best);
  }
  // Chain: BFS from the clicked way over endpoint proximity (~35 m).
  const TOL = 35 / 111320;                     // metres → degrees of latitude
  const tol2 = TOL * TOL;
  const component = new Set([props.ref]);
  let frontier = [props.ref];
  while (frontier.length) {
    const next = [];
    for (const refA of frontier) {
      const ea = wayEnds.get(refA); if (!ea) continue;
      for (const [refB, eb] of wayEnds) {
        if (component.has(refB)) continue;
        if (ea.some(a => eb.some(b => d2(a, b) <= tol2))) { component.add(refB); next.push(refB); }
      }
    }
    frontier = next;
  }
  // Walked path when fragments assemble; otherwise farthest-pair, no seed.
  const stitched = new Map();
  for (const ref of component) stitched.set(ref, stitchParts(ways.get(ref) || [], d2));
  const walked = walkRun(component, stitched, d2, tol2);
  if (walked && walked.refs.includes(props.ref) && walked.line.length >= 2) {
    return {
      ends: { a: walked.line[0], b: walked.line[walked.line.length - 1] },
      refs: walked.refs,
      line: capLine(walked.line, 1200),
    };
  }
  // No honest path (loop, or fragments that would not join): farthest-pair, no seed.
  const eps = [...component].flatMap(r => wayEnds.get(r) || []);
  if (eps.length < 2) return single();
  let best = null, bd = -1;
  for (let i = 0; i < eps.length; i++) for (let j = i+1; j < eps.length; j++) {
    const d = d2(eps[i], eps[j]);
    if (d > bd) { bd = d; best = { a: eps[i], b: eps[j] }; }
  }
  return { ends: best, refs: [...component] };
}

/* One way's stitched line, for classified clicks. Null when fragments will not join. */
export function fullWayLine(sourceId, sourceLayer, ref) {
  const parts = [];
  try {
    for (const f of map.querySourceFeatures(sourceId, {
      sourceLayer, filter: ['==', ['get', 'ref'], ref],
    })) {
      const g = f.geometry;
      if (g.type === 'LineString') parts.push(g.coordinates);
      else if (g.type === 'MultiLineString') parts.push(...g.coordinates);
    }
  } catch (e) { return null; }
  if (!parts.length) return null;
  const k = Math.cos(parts[0][0][1] * Math.PI / 180);
  const d2 = (a, b) => { const dx = (a[0] - b[0]) * k, dy = a[1] - b[1]; return dx * dx + dy * dy; };
  const line = stitchParts(parts, d2);
  return line && line.length >= 2 ? capLine(line, 1200) : null;
}

/** Whole way's ends from loaded tile fragments; used by routes-tiles.js too. */
export function fullWayEnds(sourceId, sourceLayer, ref, clickedGeometry) {
  const parts = [];
  try {
    for (const f of map.querySourceFeatures(sourceId, {
      sourceLayer, filter: ['==', ['get', 'ref'], ref],
    })) {
      const g = f.geometry;
      if (g.type === 'LineString') parts.push(g.coordinates);
      else if (g.type === 'MultiLineString') parts.push(...g.coordinates);
    }
  } catch (e) { /* source not loaded yet — fall through to the fragment */ }
  const endpoints = parts.filter(p => p.length >= 2).flatMap(p => [p[0], p[p.length - 1]]);
  if (endpoints.length < 2) return segmentEnds(clickedGeometry);
  // Farthest-apart endpoint pair, planar with latitude correction.
  const k = Math.cos(endpoints[0][1] * Math.PI / 180);
  let best = null;
  let bd = -1;
  for (let i = 0; i < endpoints.length; i++) {
    for (let j = i + 1; j < endpoints.length; j++) {
      const dx = (endpoints[i][0] - endpoints[j][0]) * k;
      const dy = endpoints[i][1] - endpoints[j][1];
      const d = dx * dx + dy * dy;
      if (d > bd) { bd = d; best = { a: endpoints[i], b: endpoints[j] }; }
    }
  }
  return best;
}
