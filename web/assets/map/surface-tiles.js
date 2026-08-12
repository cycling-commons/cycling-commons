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
/* The "needs a tag" arm, in its own artifact and its own source.

   Two arms exist because a vector tile is fetched whole: folding 381,313
   untagged Belgian ways into the classified tiles would make every surface tile
   several times larger for every rider, to carry lines most of them will never
   switch on. Separate artifacts mean you download this one only if you ask for
   it (Dated/2026-08-09-surface-line-tiles-design.md D3).

   It is the CONTRIBUTION view: every line here is a road OpenStreetMap has no
   `surface` tag for, which is to say a road somebody could go and record. That
   is why the owner asked for it to be servable — "then people will know what to
   tag and extend the map knowledge" (2026-08-12). */
export const SURFACE_UNTAGGED_SOURCE = 'surface-untagged';
const UNTAGGED_PREFIX = 'surfuntag-';
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
      map.on('click', id, e => openSurfaceDrawer(e.features[0].properties, e.lngLat, e.features[0].geometry));
      map.on('mouseenter', id, () => { map.getCanvas().style.cursor = 'pointer'; });
      map.on('mouseleave', id, () => { map.getCanvas().style.cursor = ''; });
    });
  });
  added = true;
}

/** Is the untagged artifact configured (an URL, not necessarily loaded)? */
export const untaggedConfigured = () =>
  typeof window.CC_SURFACE_UNTAGGED_URL === 'string' && !!window.CC_SURFACE_UNTAGGED_URL;

let untaggedAdded = false;

export function addUntaggedTiles() {
  if (!untaggedConfigured() || untaggedAdded || typeof pmtiles === 'undefined') return;
  maplibregl.addProtocol('pmtiles', new pmtiles.Protocol().tile);
  if (!map.getSource(SURFACE_UNTAGGED_SOURCE)) {
    map.addSource(SURFACE_UNTAGGED_SOURCE, { type: 'vector', url: 'pmtiles://' + window.CC_SURFACE_UNTAGGED_URL });
  }
  // One layer per country, and NO per-class split: every feature in this
  // artifact is the same class by construction, so the seven-layer dance the
  // classified arm needs (one dash pattern per layer) collapses to one.
  const st = surfaceStyle('unverified');
  sourceLayers().forEach(srcLayer => {
    const id = UNTAGGED_PREFIX + (srcLayer === 'surface' ? 'all' : srcLayer.slice(8));
    if (map.getLayer(id)) return;
    map.addLayer({
      id,
      type: 'line',
      source: SURFACE_UNTAGGED_SOURCE,
      'source-layer': srcLayer,
      layout: { 'line-cap': st.cap || 'round', 'line-join': 'round', visibility: 'none' },
      paint: {
        'line-color': st.color,
        'line-dasharray': st.dash || [2, 2],
        // Thinner and fainter than the classified arm. This is a to-do list,
        // not an answer, and it must never out-shout a road somebody HAS
        // recorded — 381k lines at full weight is a red smear over a country.
        'line-width': ['interpolate', ['linear'], ['zoom'], 9, 0.5, 12, 1.1, 15, 2.2],
        'line-opacity': ['interpolate', ['linear'], ['zoom'], 9, 0.35, 13, 0.7],
      },
    });
    map.on('click', id, e => openSurfaceDrawer(
      { ...e.features[0].properties, cls: 'unverified' }, e.lngLat, e.features[0].geometry));
    map.on('mouseenter', id, () => { map.getCanvas().style.cursor = 'pointer'; });
    map.on('mouseleave', id, () => { map.getCanvas().style.cursor = ''; });
  });
  untaggedAdded = true;
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

export function openSurfaceDrawer(p, lngLat, geometry) {
  const layer = layerByKey.surface;
  if (!layer) return;
  const label = classLabel(p.cls);
  const rec = [{ label: D.surface || 'Surface', value: label, method: 'OSM' }];
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
  // it away invites a worse answer than the one we already have.
  const ends = segmentEnds(geometry);

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
    } else if (l.id.startsWith(UNTAGGED_PREFIX)) {
      /* "Surface not recorded" is a legend CLASS like the other six, not a
         control of its own (owner, 2026-08-12). It happens to live in a second
         artifact — the classified tiles carry no untagged ways at all, and our
         own curated items carry none either — but that is a fact about where
         the data is stored, and a rider filtering the key should not have to
         know it. So the row governs these layers exactly as `gravel` governs
         the gravel ones. */
      map.setLayoutProperty(l.id, 'visibility',
        visible && surfaceClassEnabled('unverified') ? 'visible' : 'none');
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

/**
 * First and last vertex of the clicked way, as [lng,lat] pairs.
 *
 * A tile feature can arrive as a LineString or, where the way crosses a tile
 * boundary, a MultiLineString — take the outermost ends of the whole thing so
 * the pins land on the stretch the rider actually sees.
 */
function segmentEnds(geometry) {
  if (!geometry) return null;
  const parts = geometry.type === 'MultiLineString' ? geometry.coordinates
    : geometry.type === 'LineString' ? [geometry.coordinates] : [];
  const flat = parts.flat();
  if (flat.length < 2) return null;
  return { a: flat[0], b: flat[flat.length - 1] };
}
