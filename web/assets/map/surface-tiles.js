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
import { D, trVal } from './i18n.js';
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
/* The "needs recording" arm, in its own artifact and its own source.

   Separate artifacts because a vector tile is fetched whole: folding this arm
   into the classified tiles would cost its bytes to every rider, for lines most
   of them never switch on (Dated/2026-08-09-surface-line-tiles-design.md D3).

   It is the CONTRIBUTION view — every line is a road somebody could go and
   record, which is why the owner asked for it to be servable: "then people will
   know what to tag and extend the map knowledge" (2026-08-12).

   **It is not every untagged road, and that is the point.** It used to be, and
   it cost as much as the whole classified skin. Measured against our own tagged
   data (2026-08-12), a Belgian primary road with a surface tag is unpaved 0.1%
   of the time, secondary 0.5%, tertiary 2.5%, cycleway 0.0%, residential 7.2% —
   so drawing those as homework asked riders to go and confirm asphalt. The arm
   now carries the classes where nobody can predict the answer (tracks: 88%
   unpaved; paths: a coin flip; rural lanes), which is a third of the bytes and a
   sharper question. The pipeline decides the set, from contract surface.todo. */
export const SURFACE_TODO_SOURCE = 'surface-todo';
const TODO_PREFIX = 'surftodo-';
/* Where the to-do LINES start, and therefore where the gap GRID stops.

   The same question at two resolutions, on one legend row: below this a rider
   is asking "which area needs work", and a whole country of dashed lines
   answers that worse than a grid does while costing many times the bytes. Above
   it they are looking at a road they could actually go and ride. Pinned to
   contract surface.todo.minZoom / surface.gaps.maxZoom by surface-zooms.test.cjs
   — a client that disagreed with the build would show a blank band of zoom
   where the artifact simply has no tiles. */
const TODO_MIN_ZOOM = 11;

/* The classified skin's floor, and it is a WEIGHT decision, not a taste one
   (owner-reported 2026-08-20: "for default wallonia view it is about 16MB").

   The artifact is built z8-13 with tippecanoe's --no-tile-size-limit, so a
   low-zoom tile carries every classified way under it rather than a sampled
   500 KB of them. Measured over Wallonia, one tile:

       z8  1119 KB      z10   93 KB
       z9   455 KB      z11   41 KB      z12  22 KB

   A screen is roughly sixteen tiles at any zoom, so the same view costs about
   17 MB at z8 and about 1.5 MB at z10. Nothing legible is lost: this layer's
   own paint draws 0.6 px at 50% opacity at z8, which its own comment calls a
   smear, and the design already answers the planning-zoom question with the
   gaps grid (built z4-11) and the riding-zoom one with roads. This is the line
   where the two meet.

   NOT a scope clip. The skin deliberately keeps drawing across a border: a
   rider planning a Wallonia ride into Germany wants the German roads without
   having to re-scope the map (owner, same report). */
const CLASSIFIED_MIN_ZOOM = 10;
export const SURFACE_GAPS_SOURCE = 'surface-gaps';
const GAPS_FILL = 'surfgaps-fill';
const GAPS_LINE = 'surfgaps-line';
export const surfaceTileLayerIds = () => SURFACE_CLS.filter(c => c !== 'other').map(c => 'surftile-' + c);

/* ── The quality channel ───────────────────────────────────────────────────
   Surface QUALITY finally gets a visual channel (owner shape, 2026-08-12):
   short coloured ticks drawn over the class lines, green → amber → red, from
   the OSM `smoothness` the pipeline ships as `sm`. Pattern is a free channel —
   it stacks on the class colour instead of competing with it — and the ticks
   obey the same honesty rule as the red dotted line: NO tick means nobody has
   said, so the layer is filtered to features that HAVE sm, never defaulted.

   The tone map's keys are the full OSM vocabulary and are pinned to contract
   surface.quality.values by surface-quality.test.cjs: a value the pipeline
   ships but this map does not name would fall through MapLibre's match to
   transparent — a tick silently missing — and a value named here but dropped
   by the pipeline would be a promise nobody keeps. */
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
/* Raw OSM value → the A form's five-value vocabulary, for the drawer row.
   The finer OSM values collapse for DISPLAY only — the tile keeps the raw
   value, so nothing is lost, and the drawer shows the rider the same word the
   contribute form would ask them to pick (the Scout lesson: the fine values
   live in the form vocabulary, not the map scale). */
const SM_LABEL = {
  excellent: 'Excellent', good: 'Good', intermediate: 'Intermediate',
  bad: 'Bad', very_bad: 'Very bad', horrible: 'Very bad',
  very_horrible: 'Very bad', impassable: 'Very bad',
};
/* Detail on demand: below this the ticks would be confetti on lines a pixel
   wide. Pinned to contract surface.quality.minZoom by surface-quality.test.cjs
   — the build does not stop shipping sm below it, but the CLIENT's floor and
   the contract's must agree or the legend note lies about when ticks appear. */
const SM_MIN_ZOOM = 13;
const QUALITY_PREFIX = 'surfq-';

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
        // Every layer on this source carries a floor, which is what stops
        // MapLibre requesting the z8/z9 tiles at all: it derives a source's
        // zoom range from the layers that need it.
        minzoom: CLASSIFIED_MIN_ZOOM,
        filter: ['==', ['get', 'cls'], cls],
        layout: { 'line-cap': st.cap || 'round', 'line-join': 'round', visibility: 'none' },
        paint,
      });
      map.on('click', id, e => openSurfaceDrawer(e.features[0].properties, e.lngLat, e.features[0].geometry,
        { source: SURFACE_TILE_SOURCE, sourceLayer: srcLayer }));
      map.on('mouseenter', id, () => { map.getCanvas().style.cursor = 'pointer'; });
      map.on('mouseleave', id, () => { map.getCanvas().style.cursor = ''; });
    });

    // The quality ticks, ABOVE the class lines of the same country (added
    // after them, same source): short dashes whose colour is the smoothness
    // tone, spaced ~12 px so they read as a stitch over the class colour
    // rather than as a second line. Dash units are line-widths, so the
    // spacing arithmetic lives with the width.
    const qid = QUALITY_PREFIX + (srcLayer === 'surface' ? 'all' : srcLayer.slice(8));
    // Cream casing tick UNDER each colour tick (2026-08-14, same as the
    // curated layer's surface-q-case): an amber tick on the ochre gravel line
    // is invisible without it. Casing dash = tick dash scaled by the width
    // ratio, so both patterns share one physical period and stay in step.
    // The id keeps the surfq- prefix so applyClassVisibility toggles and
    // re-filters it with the ticks.
    const qcase = QUALITY_PREFIX + 'case-' + (srcLayer === 'surface' ? 'all' : srcLayer.slice(8));
    if (!map.getLayer(qcase)) {
      map.addLayer({
        id: qcase,
        type: 'line',
        source: SURFACE_TILE_SOURCE,
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
        source: SURFACE_TILE_SOURCE,
        'source-layer': srcLayer,
        minzoom: SM_MIN_ZOOM,
        filter: qualityFilter(),
        layout: { 'line-cap': 'butt', visibility: 'none' },
        paint: {
          'line-color': ['match', ['get', 'sm'],
            ...Object.entries(SM_TONE).flat(), 'rgba(0,0,0,0)'],
          'line-width': 3.5,
          // dash 0.6 + gap 2.8 line-widths ≈ a 2 px tick every 12 px at the
          // width above.
          'line-dasharray': [0.6, 2.8],
          'line-opacity': 0.9,
        },
      });
    }
  });
  added = true;
}

/* Curated-ref dedupe for the LINE layers — the same rule the coverage points
   have had all along (osm-data-architecture.md §8): a way already answered as
   one of our items draws once, as curated. Without it, a rider's approved
   asphalt stretch kept its red "Surface not recorded" dashes UNDERNEATH the
   green curated line, and the map contradicted itself about a road somebody
   had just answered (owner-reported 2026-08-13, the Zuiderdijk). Applied in
   applyClassVisibility rather than baked in at addLayer time, because the
   skin's layers mount at map load while CC_CURATED_REFS arrives with the
   catalog fetch — re-filtering on every toggle keeps the two in step. */
function surfDedupeFilter() {
  return ['!', ['in', ['get', 'ref'], ['literal', Array.from(window.CC_CURATED_REFS || [])]]];
}

/* The tick layer's filter: only features that HAVE a smoothness (absence stays
   absent), minus whatever classes the legend has ticked off — a tick floating
   over a hidden gravel line would claim a road the rider asked not to see —
   and minus curated-answered ways (surfDedupeFilter). */
function qualityFilter() {
  const off = [...classesOff];
  const base = ['all', ['has', 'sm'], surfDedupeFilter()];
  if (off.length) base.push(['!', ['in', ['get', 'cls'], ['literal', off]]]);
  return base;
}

/** Is the to-do artifact configured (an URL, not necessarily loaded)? */
export const todoConfigured = () =>
  typeof window.CC_SURFACE_TODO_URL === 'string' && !!window.CC_SURFACE_TODO_URL;

/** Is the gap grid configured? Independent of the to-do arm: either half of
    the "what needs recording" answer can be served without the other. */
export const gapsConfigured = () =>
  typeof window.CC_SURFACE_GAPS_URL === 'string' && !!window.CC_SURFACE_GAPS_URL;

let untaggedAdded = false;
let gapsAdded = false;
/* The gaps grid is OPT-IN (owner 2026-08-14): at planning zoom the red
   squares tinted a whole region pink — and over water the translucent red
   reads PURPLE, which riders took for somebody else's basemap layer. The
   grid answers a contributor's question ("where is recording needed?"), not
   a rider's, so it waits behind its own legend toggle instead of arriving
   with the skin. */
let gapsOn = false;
export const gapsGridOn = () => gapsOn;
export function setGapsGrid(on) {
  gapsOn = !!on;
  applyClassVisibility();
  return gapsOn;
}

/* Where a lazily-mounted surface layer belongs in the stack.

   The classified skin is added at map-load time, which puts it under
   everything render() adds afterwards. The to-do arm and the grid are mounted
   on demand — later than render() — so without a `beforeId` they would land on
   TOP of the curated catalog: a translucent red wash over the pins and lines
   riders actually came for. Both are background information about somebody
   else's data, so both go under our own: below the first surface-tile layer if
   the skin is up, else below the first layer that is not the basemap. */
function belowOurLayers() {
  const layers = map.getStyle()?.layers || [];
  const skin = layers.find(l => l.id.startsWith('surftile-'));
  if (skin) return skin.id;
  const ours = layers.find(l => l.type !== 'background' && !BASEMAP_SOURCES.has(l.source));
  return ours ? ours.id : undefined;
}

export function addUntaggedTiles() {
  addGapsGrid();
  if (!todoConfigured() || untaggedAdded || typeof pmtiles === 'undefined') return;
  maplibregl.addProtocol('pmtiles', new pmtiles.Protocol().tile);
  if (!map.getSource(SURFACE_TODO_SOURCE)) {
    map.addSource(SURFACE_TODO_SOURCE, { type: 'vector', url: 'pmtiles://' + window.CC_SURFACE_TODO_URL });
  }
  // One layer per country, and NO per-class split: every feature in this
  // artifact is the same class by construction, so the seven-layer dance the
  // classified arm needs (one dash pattern per layer) collapses to one.
  const st = surfaceStyle('unverified');
  const under = belowOurLayers();
  sourceLayers().forEach(srcLayer => {
    const id = TODO_PREFIX + (srcLayer === 'surface' ? 'all' : srcLayer.slice(8));
    if (map.getLayer(id)) return;
    map.addLayer({
      id,
      type: 'line',
      source: SURFACE_TODO_SOURCE,
      'source-layer': srcLayer,
      // The artifact has no tiles below this, and the grid is drawn there
      // instead. Declaring it keeps MapLibre from asking for tiles that were
      // never built.
      minzoom: TODO_MIN_ZOOM,
      layout: { 'line-cap': st.cap || 'round', 'line-join': 'round', visibility: 'none' },
      paint: {
        'line-color': st.color,
        'line-dasharray': st.dash || [2, 2],
        // Thinner and fainter than the classified arm. This is a to-do list,
        // not an answer, and it must never out-shout a road somebody HAS
        // recorded.
        'line-width': ['interpolate', ['linear'], ['zoom'], 11, 0.9, 15, 2.2],
        'line-opacity': ['interpolate', ['linear'], ['zoom'], 11, 0.5, 13, 0.7],
      },
    }, under);
    map.on('click', id, e => openSurfaceDrawer(
      { ...e.features[0].properties, cls: 'unverified' }, e.lngLat, e.features[0].geometry,
      { source: SURFACE_TODO_SOURCE, sourceLayer: srcLayer }));
    map.on('mouseenter', id, () => { map.getCanvas().style.cursor = 'pointer'; });
    map.on('mouseleave', id, () => { map.getCanvas().style.cursor = ''; });
  });
  untaggedAdded = true;
}

/* ── The gap grid ──────────────────────────────────────────────────────────
   "Where should I go scanning?", answered with ~900 squares per country
   instead of 400,000 line geometries — 0.6 MB against 35 MB for the Benelux.

   It is not a second feature: it is the SAME legend row as the to-do lines,
   drawn at the zooms where individual roads cannot be read anyway. A rider who
   ticks "surface not recorded" sees dark squares over the areas nobody has
   surveyed, zooms into one, and at z11 the squares hand over to the actual
   roads they were summarising. That handover is pinned to the contract on both
   sides, because a mismatch would leave a band of zoom showing neither.

   Colour is the SHARE unrecorded, not the absolute kilometres: a rider is
   choosing where to ride, and "almost nothing here is known" is the useful
   signal — a dense city cell would otherwise always out-shout the empty
   countryside that actually needs the survey. */
export function addGapsGrid() {
  if (!gapsConfigured() || gapsAdded || typeof pmtiles === 'undefined') return;
  maplibregl.addProtocol('pmtiles', new pmtiles.Protocol().tile);
  if (!map.getSource(SURFACE_GAPS_SOURCE)) {
    map.addSource(SURFACE_GAPS_SOURCE, { type: 'vector', url: 'pmtiles://' + window.CC_SURFACE_GAPS_URL });
  }
  const under = belowOurLayers();
  const shade = ['interpolate', ['linear'], ['coalesce', ['get', 'pct'], 0],
    25, 0.06, 60, 0.18, 90, 0.34];
  map.addLayer({
    id: GAPS_FILL,
    type: 'fill',
    source: SURFACE_GAPS_SOURCE,
    'source-layer': 'gaps',
    maxzoom: TODO_MIN_ZOOM,
    layout: { visibility: 'none' },
    paint: { 'fill-color': surfaceStyle('unverified').color, 'fill-opacity': shade },
  }, under);
  map.addLayer({
    id: GAPS_LINE,
    type: 'line',
    source: SURFACE_GAPS_SOURCE,
    'source-layer': 'gaps',
    maxzoom: TODO_MIN_ZOOM,
    layout: { visibility: 'none' },
    // A hairline edge so the grid reads as a grid rather than as a blurry
    // stain, without drawing attention away from the fill it encloses.
    paint: { 'line-color': surfaceStyle('unverified').color, 'line-width': 0.4, 'line-opacity': 0.35 },
  }, under);
  map.on('click', GAPS_FILL, e => openGapsDrawer(e.features[0].properties, e.lngLat));
  map.on('mouseenter', GAPS_FILL, () => { map.getCanvas().style.cursor = 'pointer'; });
  map.on('mouseleave', GAPS_FILL, () => { map.getCanvas().style.cursor = ''; });
  gapsAdded = true;
}

/**
 * Drawer for one grid square: how much is unrecorded here, and what to do.
 *
 * Deliberately NOT an "improve this" bridge like a tile line's drawer. A square
 * is 6 km of countryside, not a road — there is nothing here to edit, and the
 * honest next step is to go and ride it (with Scout, or by recording stretches
 * afterwards). Offering a wizard would ask a rider to invent an answer for a
 * road they have not seen, which is exactly the fiction this layer exists to
 * remove.
 */
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
  // The untagged arm is part of the same skin, governed by its legend row.
  // Added lazily and only when it is actually wanted: it is a second artifact,
  // and mounting its layers for a rider who has ticked the class off would
  // fetch tiles nobody asked to see.
  if (on && surfaceClassEnabled('unverified')) addUntaggedTiles();
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
 * **The improve action works now**, and did not at first. Setting `osmRef`
 * makes the existing edit bridge build `/improve?ref=way/NNN&type=A`, but
 * `ContributeController::materialize()` used to resolve every ref through
 * `coverage_poi` — the POINT index — and surface lines never enter PostGIS at
 * all, so the lookup missed and the wizard fell through to "pick a place".
 * Segment-located types skip that lookup now: the rider DRAWS the geometry, so
 * the OSM ref is provenance and the one-item-per-ref key, nothing more.
 *
 * The source line still links the exact OSM way, because a surface tag is
 * upstream data and data-priority.md says upstream fixes belong upstream — a
 * rider can fix OSM directly or give the Commons its own answer, and both are
 * legitimate.
 */
/* The tile classes a rider can confirm. Mirrors SurfaceVocabulary::TILE_CLASS
   in PHP, which owns the translation into the declarable vocabulary and is the
   side that ultimately validates; SurfaceConfirmClassContractTest asserts the
   two lists stay identical, because a silent drift here would show a confirm
   button that quietly does nothing. */
export const CONFIRMABLE_CLASSES = ['paved', 'gravel', 'pave', 'dirt', 'rock'];
const CONFIRMABLE = new Set(CONFIRMABLE_CLASSES);

/* ── What we can infer, kept visibly separate from what we were told ────────
   The A layer's traffic field has been mostly fiction: the Wallonia harvester
   wrote 'Open road' on every non-cycleway and 'Car-free' on every cycleway, a
   constant keyed on surface class, never a tag (owner review 2026-08-12). That
   is worse than an empty field, because it is an empty field wearing a fact's
   clothes.

   An inference from `highway` IS worth showing — a residential street really is
   quieter than a secondary road, and until riders have answered, that is the
   only thing anyone can say. The rules are conservative and each carries the
   sentence that justifies it, which the drawer prints beside the value. Nothing
   here is stored, and nothing here is prefilled into the form: a rider's
   submission has to be a rider's claim, or the assumption launders itself into
   data the next person reads as measured. */
const TRAFFIC_RULES = [
  { hw: ['cycleway'], value: 'Car-free', why: 'trafficWhyCycleway' },
  { hw: ['residential', 'living_street'], value: 'Quiet', why: 'trafficWhyResidential' },
  { hw: ['track', 'path', 'footway', 'bridleway'], value: 'Quiet', why: 'trafficWhyTrack' },
  { hw: ['primary', 'primary_link', 'secondary', 'secondary_link'], value: 'Busy', why: 'trafficWhyMain' },
];

/** The traffic level implied by the OSM highway value, with its reason — or null. */
export function assumedTraffic(hw) {
  const rule = TRAFFIC_RULES.find(r => r.hw.includes(hw));
  return rule ? { value: rule.value, why: D[rule.why] || '' } : null;
}

/* OSM `highway` → the rider-facing kind. Mirrors App\Catalog\RoadType in PHP,
   which owns the vocabulary the form offers; RoadTypeContractTest fails if the
   two ever disagree. Unknown values return null rather than a nearest guess. */
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
  // Quality, when OSM has it — the same word the contribute form offers
  // (SM_LABEL collapses OSM's eight values to the form's five for display;
  // the raw tag stays visible beside it when the collapse was lossy, so a
  // rider following the OSM link is never surprised by what they find).
  if (p.sm && SM_LABEL[p.sm]) {
    const smLabel = trVal(SM_LABEL[p.sm]);
    const lossy = p.sm !== SM_LABEL[p.sm].toLowerCase().replace(' ', '_');
    rec.push({ label: D.smoothness || 'Smoothness',
               value: lossy ? smLabel + ' · ' + p.sm : smLabel, method: 'OSM' });
  }
  if (p.mtb) {
    // The raw scale value: it is a standard riders look up, not a vocabulary
    // we translate.
    rec.push({ label: D.mtbScale || 'MTB scale', value: p.mtb, method: 'OSM' });
  }
  /* Road type in the rider's words, with OSM's own word kept beside it: the tag
     is what a rider needs when they follow the link back to OSM, and
     'unclassified' is a road CLASS in Britain, not an admission that nobody
     classified it — showing only the tag taught the wrong thing. */
  if (p.hw) {
    const kind = roadTypeLabel(p.hw);
    rec.push({ label: D.roadType || 'Road type', value: kind ? kind + ' · ' + p.hw : p.hw, method: 'OSM' });
  }
  // What the tag implies about traffic — never stored, never prefilled, and
  // always carrying the reasoning that produced it.
  const traffic = p.hw ? assumedTraffic(p.hw) : null;
  if (traffic) {
    rec.push({ label: D.traffic || 'Traffic', value: trVal(traffic.value), assumed: traffic.why });
  }

  // The way already has ends. Hand them to the wizard so it opens with both
  // pins placed on the stretch the rider clicked, ready to be dragged, instead
  // of a blank map asking for two taps — we know more than that, and throwing
  // it away invites a worse answer than the one we already have. A TO-DO
  // click prefills the whole unrecorded same-named run (unrecordedRunEnds);
  // a classified click keeps its own way (fullWayEnds).
  let ends, spannedRefs, seedLine = null;
  if (tileCtx && tileCtx.source === SURFACE_TODO_SOURCE) {
    const run = unrecordedRunEnds(tileCtx.source, tileCtx.sourceLayer, p, geometry);
    ends = run.ends; spannedRefs = run.refs; seedLine = run.line || null;
  } else if (tileCtx) {
    // The stitched line, when the fragments join, gives BOTH the seed and
    // more truthful ends than the farthest-pair heuristic.
    seedLine = fullWayLine(tileCtx.source, tileCtx.sourceLayer, p.ref);
    ends = seedLine
      ? { a: seedLine[0], b: seedLine[seedLine.length - 1] }
      : fullWayEnds(tileCtx.source, tileCtx.sourceLayer, p.ref, geometry);
  } else {
    ends = segmentEnds(geometry);
  }
  // Hand the stretch's actual shape to the wizard — sessionStorage, not the
  // URL: hundreds of vertices. Seeded, the wizard draws the road as the tiles
  // know it instead of asking the router for a route that may not follow it.
  // Cleared when there is no seed, so the wizard never picks up a stale one.
  try {
    if (seedLine && ends) {
      sessionStorage.setItem('ccSegSeed', JSON.stringify({ a: ends.a, b: ends.b, line: seedLine, ts: Date.now() }));
    } else {
      sessionStorage.removeItem('ccSegSeed');
    }
  } catch (e) { /* storage blocked — the wizard falls back to the router */ }

  // The street's own name leads when it has one. The basemap has been printing
  // it under our line all along, so a drawer headlined "Paved · asphalt" on a
  // way labelled Rue du Puits Saint-Martin looked like we could not read the
  // map (owner-reported 2026-08-12). The class stays, as the subtitle it is.
  //
  // Composed HERE, never in the tile: `p.name` is the OSM tag verbatim and
  // `label` comes from the locale dictionary, so a Dutch rider reads
  // "Rue du Puits Saint-Martin · Verhard · asfalt". Gluing them upstream would
  // freeze one English word into the data.
  openDrawer(layer, {
    name: p.name || label,
    headline: p.name ? p.name + ' · ' + label : label + (p.hw ? ' · ' + p.hw : ''),
    // Handed to the wizard so a rider correcting the surface of a named street
    // does not retype a name we already know.
    osmName: p.name || '',
    geom: { ll: [lngLat.lat, lngLat.lng] },
    segmentEnds: ends,
    // Every way ref the prefilled run spans — the wizard passes them on, the
    // materialized item stores them, and the red dashes of ALL of them stop
    // contradicting the answer (curatedRefs()).
    spannedRefs,
    // Confirming OSM is the same submission as correcting it, with the class
    // already chosen — the first confirmation is what MINTS our own A item, and
    // from then on the ordinary one-tap item confirmation applies to that item.
    // One mechanic, no separate "agree" store keyed on an OSM ref.
    //
    // Only for a class that actually claims a surface. `cycleway` says what the
    // way IS, not what it is made of, and `unverified` is the absence of a
    // claim — "this is correct" on either would be agreeing with nothing.
    confirmClass: CONFIRMABLE.has(p.cls) ? p.cls : undefined,
    record: rec,
    source: 'OpenStreetMap',
    // The exact way, not a coordinate query: we know the element id, so the
    // source link goes straight to the object whose tags the rider is reading.
    osmUrl: p.ref ? 'https://www.openstreetmap.org/' + p.ref : undefined,
    // Materialize-on-edit, now that A can reach it: /improve opens the road-
    // surface wizard in Segment mode, the rider drops a start and an end pin
    // and picks the surface, and submit creates a SUBMISSION that the region's
    // moderators decide. Approved, it becomes one of our A items and draws on
    // top of this tile line — the tile stays as OSM's answer underneath.
    osmRef: p.ref,
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
      // Class filter + curated dedupe, refreshed together (see surfDedupeFilter).
      map.setFilter(l.id, ['all', ['==', ['get', 'cls'], cls], surfDedupeFilter()]);
    } else if (l.id.startsWith(QUALITY_PREFIX)) {
      /* The ticks ride the skin as a whole — they are an annotation on the
         class lines, not a class of their own, so there is no legend row to
         untick. Per-class hiding happens in the FILTER instead: hide gravel
         and its ticks go with it. */
      map.setLayoutProperty(l.id, 'visibility', visible ? 'visible' : 'none');
      map.setFilter(l.id, qualityFilter());
    } else if (l.id === GAPS_FILL || l.id === GAPS_LINE) {
      /* The grid is the to-do arm at planning zoom — but behind its own
         legend toggle (gapsOn), off by default. It still obeys the
         "not recorded" legend row: a rider who ticked that class off has
         asked not to see the unrecorded arm in any form. */
      map.setLayoutProperty(l.id, 'visibility',
        visible && gapsOn && surfaceClassEnabled('unverified') ? 'visible' : 'none');
    } else if (l.id.startsWith(TODO_PREFIX)) {
      /* "Surface not recorded" is a legend CLASS like the other six, not a
         control of its own (owner, 2026-08-12). It happens to live in a second
         artifact — the classified tiles carry no untagged ways at all, and our
         own curated items carry none either — but that is a fact about where
         the data is stored, and a rider filtering the key should not have to
         know it. So the row governs these layers exactly as `gravel` governs
         the gravel ones. */
      map.setLayoutProperty(l.id, 'visibility',
        visible && surfaceClassEnabled('unverified') ? 'visible' : 'none');
      // An answered way is no longer homework: the red dash must not contradict
      // the curated line drawn over the same road (see surfDedupeFilter).
      map.setFilter(l.id, surfDedupeFilter());
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
  // Ticking "not recorded" back on has to MOUNT its arm, not merely unhide
  // layers that were never added — the second artifact is loaded on demand.
  if ('unverified' === cls && surfaceClassEnabled(cls)) addUntaggedTiles();
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
/* What each basemap layer's visibility was when study mode took it away.

   Study mode used to claim it "restores what the style had" and then set every
   basemap-source layer to `visible` on the way out — so leaving it switched ON
   the satellite imagery and the Mapillary coverage, which the rider had never
   asked for and in most cases had never had on (owner-reported 2026-08-12).
   Turning a mode off must leave the map as it found it; anything else teaches
   riders that a toggle has side effects and they stop using it. */
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
      // MapLibre reports undefined for a layer that never declared visibility,
      // which means visible — write the resolved value, so the restore is a
      // real answer rather than "whatever the default is today".
      // First snapshot only: calling setStudyMode(true) twice would otherwise
      // record the 'none' study mode itself just wrote, and the restore would
      // faithfully put back a hidden basemap.
      if (!visBefore.has(l.id)) {
        visBefore.set(l.id, map.getLayoutProperty(l.id, 'visibility') || 'visible');
      }
      map.setLayoutProperty(l.id, 'visibility', 'none');
      return;
    }
    // Restore ONLY what study mode hid, and only while it is still hidden. A
    // rider who switched satellite on during study mode meant it, and undoing
    // that on exit would be the same disrespect in the other direction.
    const before = visBefore?.get(l.id);
    if (before && map.getLayoutProperty(l.id, 'visibility') === 'none') {
      map.setLayoutProperty(l.id, 'visibility', before);
    }
  });
  if (!study) visBefore = null;
  document.querySelector('.map-wrap')?.classList.toggle('study', study);
  return study;
}

/**
 * First and last vertex of the clicked way, as [lng,lat] pairs.
 *
 * A tile feature can arrive as a LineString or, where the way crosses a tile
 * boundary, a MultiLineString — take the outermost ends of the whole thing so
 * the pins land on the stretch the rider actually sees.
 */
export function segmentEnds(geometry) {
  if (!geometry) return null;
  const parts = geometry.type === 'MultiLineString' ? geometry.coordinates
    : geometry.type === 'LineString' ? [geometry.coordinates] : [];
  const flat = parts.flat();
  if (flat.length < 2) return null;
  return { a: flat[0], b: flat[flat.length - 1] };
}

/**
 * The WHOLE way's ends, reassembled from every loaded tile fragment.
 *
 * A vector tile clips geometry at its border, so the clicked feature is only
 * the fragment inside one tile: pins built from it covered part of the road
 * ("but it is not the full piece" — owner, 2026-08-13), and a click landing
 * on a boundary sliver produced a wizard with no line at all.
 * querySourceFeatures returns the way's fragment from every LOADED tile, so
 * gathering all of them and taking the endpoint pair farthest apart
 * approximates the way's true extent — exact for anything road-shaped, and
 * for a pathological hairpin still a far better head start than one tile's
 * worth. Falls back to the clicked fragment when the source has nothing
 * (never in practice: the rider is looking at the tiles they clicked).
 *
 * Exported for routes-tiles.js: a route corridor click opens the same surface
 * wizard, and its pins deserve the same full stretch.
 */
/* The ROAD-TYPE GROUP an OSM highway value belongs to, for run-chaining.
   tertiary and unclassified are both "a local road" and chain; a cycleway is
   its own thing — at the point where a dijk road continues as a same-named
   cycleway, the chain BREAKS on purpose (owner 2026-08-13): that part is
   car-free and deserves its own item with its own answers. */
function roadGroup(hw) {
  const G = { primary:'main', primary_link:'main', secondary:'main', secondary_link:'main',
    tertiary:'local', tertiary_link:'local', unclassified:'local', road:'local',
    residential:'residential', living_street:'residential',
    track:'track', path:'path', footway:'path', bridleway:'path',
    cycleway:'cycleway' };
  return G[hw] || hw || 'other';
}

/* Join the tile FRAGMENTS of one way into one line. Clipping cuts a way at
   tile borders but never moves its vertices, so the seam vertices repeat
   exactly (up to tile-grid quantization, a metre or two) — a ~5 m tolerance
   finds the seam even where clip buffers overlap. Returns null when the
   fragments will not join: a refused seed beats a guessed shape. */
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

/* Graft `part` onto either end of `line`, in either orientation, cutting the
   clip-buffer overlap at the nearest shared vertex. Null when it touches
   neither end. */
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

/* A fragment both of whose endpoints already lie on the line is an overlap
   duplicate from a neighbouring tile's buffer — drop it, don't graft it. */
function containedIn(line, part, d2, J2) {
  return [part[0], part[part.length - 1]].every(p => line.some(v => d2(v, p) <= J2));
}

/* Order a component's stitched ways end to end into the run's actual PATH.
   Starts from a degree-1 tip (a true end touches nothing) and walks nearest
   endpoints. At a fork it takes one arm and stops when nothing joins — the
   returned refs then cover exactly what the line covers, which keeps the
   spanned-refs dedupe honest for same-named parallel roads. Null when no way
   stitched, or the run is a loop. */
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

/* Cap a seed line's vertex count (sessionStorage payload + the server's
   segment-point limit) by even sampling — endpoints always survive. */
function capLine(line, max) {
  if (!line || line.length <= max) return line;
  const out = [];
  const step = (line.length - 1) / (max - 1);
  for (let i = 0; i < max; i++) out.push(line[Math.round(i * step)]);
  return out;
}

/**
 * The whole UNRECORDED RUN a clicked to-do way belongs to: every same-named,
 * same-road-group, still-unrecorded neighbour chained end to end.
 *
 * One click on a 16 km dijk used to prefill one OSM way — sometimes 30 m —
 * and a rider who does not know the pins can be dragged gives up right there
 * (owner 2026-08-13). The name is the join key, endpoint proximity is the
 * chain (tile quantization + junction nodes, so a small tolerance), and the
 * road-type group is the honest boundary: the run stops where the road stops
 * being the same kind of road, and where somebody has already answered
 * (candidates come from the TO-DO source only). Returns the run's outermost
 * ends plus every way ref it spans — the wizard gets both.
 */
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
  // Chain: BFS from the clicked way over endpoint proximity (~35 m — tile
  // quantization means junction nodes rarely land on identical coordinates).
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
  // The run's actual PATH, when the fragments assemble: stitched per way,
  // then walked end to end. The wizard seeds its drawn line from this instead
  // of asking the router — which answers "fastest a→b" and on a long run
  // leaves the road entirely (owner-reported 2026-08-13). When the walk
  // succeeds, ends and refs come FROM the walked line, so on a fork (two
  // same-named parallel roads) the item spans exactly the arm it draws.
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
  // No honest path (a loop, or fragments that would not join): the previous
  // behaviour — farthest endpoint pair over the whole component, no seed.
  const eps = [...component].flatMap(r => wayEnds.get(r) || []);
  if (eps.length < 2) return single();
  let best = null, bd = -1;
  for (let i = 0; i < eps.length; i++) for (let j = i+1; j < eps.length; j++) {
    const d = d2(eps[i], eps[j]);
    if (d > bd) { bd = d; best = { a: eps[i], b: eps[j] }; }
  }
  return { ends: best, refs: [...component] };
}

/* One way's whole stitched line, for classified (skin) clicks — same seed
   benefit as a run, one way's worth. Null when the fragments will not join. */
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
  // Farthest-apart endpoint pair, planar with latitude correction — fragments
  // repeat across tiles and share near-identical cut points, so the true way
  // ends are exactly the two survivors that no neighbouring fragment repeats.
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
