// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Cycle-route NETWORK tiles — signed route corridors + knooppunt numbers.
   Plan: docs/plans/handoffs/2026-08-12-routes-layer-and-surface-quality.md.

   route=bicycle / route=mtb relations out of OSM, one line per member way,
   from their own PMTiles artifact (routes_<cc> line layers, knoop_<cc> point
   layers — see pipeline/coverage/routes.py). The benchmark is OpenCycleMap —
   purple/red corridors and knooppunt numbers are what make a map feel like it
   knows where to ride — and the brief is READABLE BY DEFAULT: CyclOSM renders
   the same data and was rejected as unreadable at scale, so corridors are
   translucent, badges wait for z12, and the whole layer is off until asked.

   What the layer is FOR is the loop with the surface skin: rendering networks
   is borrowed, the trust layer is the product. Clicking a corridor opens the
   surface drawer for that WAY — a signed route with no recorded surface is
   exactly the road worth asking a rider about, and the improve bridge
   (/improve?ref=way/NNN&type=road-surface) already exists, so this module
   reuses it rather than inventing a route drawer. */
import { map, flyToPin } from './map-init.js';
import { D } from './i18n.js';
import { layerByKey } from './catalog.js';
import { openDrawer } from './drawer.js';
import { fullWayEnds } from './surface-tiles.js';

/* Same two-gate pattern as the surface skin, for the same reasons: the rail
   button asks "does an artifact exist?" (configured), the add path asks "can I
   draw it yet?" (available — the pmtiles global is loaded by a classic script
   and can lag the module graph). */
export const routesTilesConfigured = () =>
  typeof window.CC_ROUTES_URL === 'string' && !!window.CC_ROUTES_URL;
export const routesTilesAvailable = () =>
  routesTilesConfigured() && typeof pmtiles !== 'undefined';

export const ROUTES_TILE_SOURCE = 'routes-tiles';

/* Three visual families, not six network values: a rider plans against
   "national route", "regional network", "MTB" — icn/ncn merge, rcn/lcn/other
   merge. The colours are the OpenCycleMap associations riders already carry
   (the owner's benchmark: "purple/red corridors are what makes a map feel
   like it knows where to ride"): the REGIONAL/node network — the one that
   dominates the Low Countries — is purple, national long-distance routes are
   rose-red. The first cut had regional in blue and it read as grey-green over
   polder fields (owner feedback 2026-08-13: "most routes are green instead of
   purple"); a translucent line's colour has to survive blending with the
   basemap, not just look right on a swatch. Keys are contract
   routes.networks, pinned by routes-zooms.test.cjs. */
export const NET_STYLE = {
  icn: { group: 'national', color: '#C84E64' },
  ncn: { group: 'national', color: '#C84E64' },
  rcn: { group: 'regional', color: '#7A4FCF' },
  lcn: { group: 'regional', color: '#7A4FCF' },
  other: { group: 'regional', color: '#7A4FCF' },
  mtb: { group: 'mtb', color: '#8A5A32' },
};
const GROUPS = [
  { key: 'national', nets: ['icn', 'ncn'], color: '#C84E64' },
  { key: 'regional', nets: ['rcn', 'lcn', 'other'], color: '#7A4FCF' },
  { key: 'mtb', nets: ['mtb'], color: '#8A5A32' },
];
/* Where the knooppunt badges appear. The ARTIFACT carries the points from z10
   (contract routes.nodes.minZoom, lowered twice on owner feedback 2026-08-13):
   the numbers ARE how riders navigate these networks, and z10 is where
   planning starts — a first cut held them to z13 and read as "there are no
   knooppunt numbers" at exactly the zooms that matter. Small at z10, a step
   bigger from z12 ("a bit bigger from zoom 12"); symbol collision culls what
   does not fit, so dense areas stay readable and fill in as you zoom.
   routes-zooms.test.cjs pins this floor to at least the artifact's, or the
   badges would be asked for at zooms the tiles do not carry. */
const BADGE_MIN_ZOOM = 10;
const LINE_PREFIX = 'rttile-';
const KNOOP_PREFIX = 'rtknoop-';
/* The corridor's own width and opacity, named once so the "dim everything
   else" pass can put them back EXACTLY. Retyping them at the restore site is
   how a dim becomes permanent after somebody tunes the paint. */
const LINE_WIDTH = ['interpolate', ['linear'], ['zoom'], 8, 1.8, 11, 3.2, 14, 6];
const LINE_OPACITY = ['interpolate', ['linear'], ['zoom'], 8, 0.55, 12, 0.72];
/* Low enough that the chosen route reads as the only one on the map, high
   enough that the network it belongs to is still legible around it - a rider
   following LF1 still wants to see where it crosses everything else. */
const DIM_OPACITY = 0.14;
const DIM_BADGE = 0.3;

let added = false;
let visible = false;

/* The source-layers per country, same derivation as the surface skin's. */
function lineLayers() {
  const ccs = Array.isArray(window.CC_COVERAGE_COUNTRIES) ? window.CC_COVERAGE_COUNTRIES : [];
  return ccs.length ? ccs.map(cc => 'routes_' + String(cc).toLowerCase()) : ['routes'];
}
function knoopLayers() {
  const ccs = Array.isArray(window.CC_COVERAGE_COUNTRIES) ? window.CC_COVERAGE_COUNTRIES : [];
  return ccs.length ? ccs.map(cc => 'knoop_' + String(cc).toLowerCase()) : ['knoop'];
}

/* Where the corridors belong in the stack: ABOVE the surface skin — the
   corridor is the headline a rider toggles this layer for, and the skin is
   reference ink — but BELOW our own curated layers, which render() keeps on
   top anyway. Mounted on demand, so without a beforeId they would land on top
   of everything; the first non-surface, non-basemap layer is the ceiling. */
const BASEMAP_SOURCES = new Set(['openmaptiles', 'ne2_shaded', 'satellite', 'mly']);
function belowOurLayers() {
  const layers = map.getStyle()?.layers || [];
  const ours = layers.find(l => l.type !== 'background'
    && !BASEMAP_SOURCES.has(l.source)
    && l.source !== 'surface-tiles' && l.source !== 'surface-todo' && l.source !== 'surface-gaps'
    && !l.id.startsWith(LINE_PREFIX) && !l.id.startsWith(KNOOP_PREFIX));
  return ours ? ours.id : undefined;
}

export function addRoutesTiles() {
  if (!routesTilesAvailable() || added || map.getSource(ROUTES_TILE_SOURCE)) return;
  maplibregl.addProtocol('pmtiles', new pmtiles.Protocol().tile);
  map.addSource(ROUTES_TILE_SOURCE, { type: 'vector', url: 'pmtiles://' + window.CC_ROUTES_URL });
  const under = belowOurLayers();

  lineLayers().forEach(srcLayer => {
    const cc = srcLayer === 'routes' ? 'all' : srcLayer.slice(7);
    GROUPS.forEach(g => {
      const id = LINE_PREFIX + g.key + '-' + cc;
      if (map.getLayer(id)) return;
      map.addLayer({
        id,
        type: 'line',
        source: ROUTES_TILE_SOURCE,
        'source-layer': srcLayer,
        filter: ['in', ['get', 'net'], ['literal', g.nets]],
        layout: { 'line-cap': 'round', 'line-join': 'round', visibility: 'none' },
        paint: {
          'line-color': g.color,
          // A corridor, not a wire: wide and translucent, so the basemap's
          // road stays legible inside it — the OpenCycleMap reading. Raised
          // from the first cut's 0.38/0.5 (owner 2026-08-13: "make them a bit
          // less transparent so they are better to see").
          'line-width': LINE_WIDTH,
          'line-opacity': LINE_OPACITY,
        },
      }, under);
      map.on('click', id, e => openRouteDrawer(e.features[0].properties, e.lngLat, e.features[0].geometry, srcLayer));
      map.on('mouseenter', id, () => { map.getCanvas().style.cursor = 'pointer'; });
      map.on('mouseleave', id, () => { map.getCanvas().style.cursor = ''; });
    });

    /* THE SELECTED ROUTE, drawn once per country ABOVE its three families.
       One layer rather than three: the colour comes from the feature's own
       network, so a highlighted LF route stays the national pink it was.

       It starts matching nothing. Filters, not a separate source, because the
       whole route is already in these tiles - selecting it is a question about
       what is drawn, not a fetch. */
    const selId = LINE_PREFIX + 'sel-' + cc;
    if (!map.getLayer(selId)) {
      map.addLayer({
        id: selId,
        type: 'line',
        source: ROUTES_TILE_SOURCE,
        'source-layer': srcLayer,
        filter: MATCH_NOTHING,
        layout: { 'line-cap': 'round', 'line-join': 'round', visibility: 'none' },
        paint: {
          'line-color': ['match', ['get', 'net'],
            'icn', GROUPS[0].color, 'ncn', GROUPS[0].color,
            'mtb', GROUPS[2].color, GROUPS[1].color],
          // Wider than the corridor it replaces and fully opaque: the point of
          // a selection is that the eye finds the line without hunting.
          'line-width': ['interpolate', ['linear'], ['zoom'], 8, 3.2, 11, 5.5, 14, 9],
          'line-opacity': 1,
        },
      }, under);
      map.on('click', selId, e => openRouteDrawer(e.features[0].properties, e.lngLat, e.features[0].geometry, srcLayer));
      map.on('mouseenter', selId, () => { map.getCanvas().style.cursor = 'pointer'; });
      map.on('mouseleave', selId, () => { map.getCanvas().style.cursor = ''; });
    }
  });

  // Knooppunt badges: a numbered disc, the compromise between OpenCycleMap's
  // circles and our own chip styling. Circle + symbol as two layers because
  // MapLibre text cannot ride a circle layer.
  knoopLayers().forEach(srcLayer => {
    const cc = srcLayer === 'knoop' ? 'all' : srcLayer.slice(6);
    const discId = KNOOP_PREFIX + 'disc-' + cc;
    const nrId = KNOOP_PREFIX + 'nr-' + cc;
    if (map.getLayer(discId)) return;
    map.addLayer({
      id: discId,
      type: 'circle',
      source: ROUTES_TILE_SOURCE,
      'source-layer': srcLayer,
      minzoom: BADGE_MIN_ZOOM,
      layout: { visibility: 'none' },
      paint: {
        // Small at planning zoom, a clear step bigger from z12 (owner
        // 2026-08-13) — discs grow with the reading distance.
        'circle-radius': ['interpolate', ['linear'], ['zoom'], 10, 5.5, 12, 8.5, 15, 10],
        'circle-color': '#FFFFFF',
        'circle-stroke-width': 2,
        'circle-stroke-color': '#7A4FCF',
      },
    }, under);
    map.addLayer({
      id: nrId,
      type: 'symbol',
      source: ROUTES_TILE_SOURCE,
      'source-layer': srcLayer,
      minzoom: BADGE_MIN_ZOOM,
      layout: {
        'text-field': ['get', 'nr'],
        // The basemap's own glyph stack — the map already loads it, and a
        // badge is a label, not brand typography.
        'text-font': ['Noto Sans Bold'],
        'text-size': ['interpolate', ['linear'], ['zoom'], 10, 9, 12, 11.5, 15, 13],
        'text-allow-overlap': false,
        visibility: 'none',
      },
      paint: { 'text-color': '#5B3A9E' },
    }, under);
    map.on('click', discId, e => openKnoopDrawer(e.features[0].properties, e.lngLat));
    map.on('mouseenter', discId, () => { map.getCanvas().style.cursor = 'pointer'; });
    map.on('mouseleave', discId, () => { map.getCanvas().style.cursor = ''; });
  });
  added = true;
}

/** Is the layer currently drawn? */
export const routesTilesVisible = () => visible;

/** Turn the route network on or off. Adds the source lazily on first use. */
export function setRoutesTiles(on) {
  if (!routesTilesAvailable()) return false;
  if (!added) addRoutesTiles();
  // Switching the network off drops the selection with it: coming back to a
  // dimmed map with one route lit, having forgotten choosing it, reads as a
  // broken layer.
  if (!on) clearRouteSelection();
  visible = !!on;
  const style = map.getStyle();
  if (style) {
    style.layers.forEach(l => {
      if (l.id.startsWith(LINE_PREFIX) || l.id.startsWith(KNOOP_PREFIX)) {
        map.setLayoutProperty(l.id, 'visibility', visible ? 'visible' : 'none');
      }
    });
  }
  return visible;
}

/* A filter that is false for every feature. `['boolean', false]` would be
   simpler and MapLibre rejects it in a filter slot; comparing a property to a
   value no network can hold is the portable way to say "nothing yet". */
const MATCH_NOTHING = ['==', ['get', 'net'], '\u0000none'];

let selectedRoute = null;   // {net, rr} or null

/**
 * Highlight ONE signed route and dim everything else (owner 2026-08-17).
 *
 * Clicking a corridor used to answer only "what is this stretch". The question
 * a rider actually has in front of a Dutch screen is "where does THIS one go",
 * and the answer was buried in a hundred overlapping purple lines.
 *
 * Matched by (net, rr) rather than by way id, which is what makes it the whole
 * route instead of the clicked fragment. `refs` is checked too: a way carrying
 * three routes has only one of them in `rr`, so the Zuiderdijk would drop out
 * of its own LF route without this arm. The comparison is padded with the
 * delimiter on both sides, or "ncn LF1" would also select "ncn LF10".
 *
 * **Honest limit:** the highlight paints what is in LOADED tiles. Pan to a
 * part of the route the map has not fetched and it lights up when it arrives.
 * There is no way around that short of shipping route geometry separately, and
 * at the planning zooms this layer is built for (z8-13) a national route is
 * mostly on screen already.
 *
 * A route with no code cannot be identified, so it selects nothing rather than
 * guessing - and nothing dims, so the map does not look broken.
 */
export function selectRoute(net, rr) {
  if (!added || !net || !rr) { clearRouteSelection(); return false; }
  selectedRoute = { net, rr };

  const key = String(net) + ' ' + String(rr);
  const filter = ['any',
    ['all', ['==', ['get', 'net'], net], ['==', ['get', 'rr'], rr]],
    ['in', '|' + key + '|', ['concat', '|', ['coalesce', ['get', 'refs'], ''], '|']],
  ];
  eachRouteLayer((id, kind) => {
    if (kind === 'sel') { map.setFilter(id, filter); return; }
    if (kind === 'line') { map.setPaintProperty(id, 'line-opacity', DIM_OPACITY); return; }
    if (kind === 'disc') {
      map.setPaintProperty(id, 'circle-opacity', DIM_BADGE);
      map.setPaintProperty(id, 'circle-stroke-opacity', DIM_BADGE);
      return;
    }
    map.setPaintProperty(id, 'text-opacity', DIM_BADGE);
  });
  return true;
}

/** Put the network back exactly as it was. Idempotent: closeDrawer calls it. */
export function clearRouteSelection() {
  if (!added || selectedRoute === null) return;
  selectedRoute = null;
  eachRouteLayer((id, kind) => {
    if (kind === 'sel') { map.setFilter(id, MATCH_NOTHING); return; }
    if (kind === 'line') { map.setPaintProperty(id, 'line-opacity', LINE_OPACITY); return; }
    if (kind === 'disc') {
      map.setPaintProperty(id, 'circle-opacity', 1);
      map.setPaintProperty(id, 'circle-stroke-opacity', 1);
      return;
    }
    map.setPaintProperty(id, 'text-opacity', 1);
  });
}

/** Which route is lit, for anything that needs to ask. */
export const selectedRouteRef = () => selectedRoute;

/**
 * Every layer this module owns, tagged by what it is. One walk, so a new
 * country's layers are covered the moment they are added and neither the
 * select nor the clear can miss one.
 */
function eachRouteLayer(fn) {
  const layers = map.getStyle()?.layers || [];
  layers.forEach(l => {
    if (!map.getLayer(l.id)) return;
    if (l.id.startsWith(LINE_PREFIX + 'sel-')) fn(l.id, 'sel');
    else if (l.id.startsWith(LINE_PREFIX)) fn(l.id, 'line');
    else if (l.id.startsWith(KNOOP_PREFIX + 'disc-')) fn(l.id, 'disc');
    else if (l.id.startsWith(KNOOP_PREFIX)) fn(l.id, 'nr');
  });
}

/** Localised label for a network value, falling back to the raw key. */
export function netLabel(net) {
  const k = { icn: 'netIcn', ncn: 'netNcn', rcn: 'netRcn', lcn: 'netLcn',
              mtb: 'netMtb', other: 'netOther' }[net];
  return (k && D[k]) || net;
}

/**
 * Drawer for one corridor stretch — opened against the A layer, because the
 * point of clicking a route is the surface loop: the improve action opens the
 * road-surface wizard for exactly this way (osmRef → materialize-on-edit),
 * with both pins already on the stretch. The route facts (network, code,
 * every membership) are the drawer rows.
 */
export function openRouteDrawer(p, lngLat, geometry, sourceLayer) {
  const layer = layerByKey.surface;
  if (!layer) return;
  const rec = [{ label: D.routeNetwork || 'Network', value: netLabel(p.net), method: 'OSM' }];
  if (p.rr) rec.push({ label: D.routeRef || 'Route', value: p.rr, method: 'OSM' });
  if (p.refs) {
    // Every signed route on this stretch — the Zuiderdijk carries three. The
    // tile joins them as "net ref|net ref|…"; localise the net half of each.
    const all = String(p.refs).split('|').map(entry => {
      const [net, ...ref] = entry.split(' ');
      return ref.length ? netLabel(net) + ' ' + ref.join(' ') : entry;
    });
    rec.push({ label: D.routesHere || 'Routes here', value: all.join(' · '), method: 'OSM' });
  }
  const name = p.name || (p.rr ? netLabel(p.net) + ' ' + p.rr : netLabel(p.net));
  openDrawer(layer, {
    name,
    headline: p.rr && p.name ? p.name + ' · ' + p.rr : name,
    osmName: p.name || '',
    geom: { ll: [lngLat.lat, lngLat.lng] },
    // The whole way across tiles, not the clicked fragment (fullWayEnds).
    segmentEnds: fullWayEnds(ROUTES_TILE_SOURCE, sourceLayer, p.ref, geometry),
    record: rec,
    // What a click is FOR: this stretch probably has no recorded surface (a
    // signed route with none is the to-do arm's headline case), and the same
    // wizard the skin opens is one tap away.
    desc: D.routeSurfaceHint || 'A signed route — help record what is under the tyres. '
      + 'Improve this stretch to add its surface.',
    source: 'OpenStreetMap',
    osmUrl: p.ref ? 'https://www.openstreetmap.org/' + p.ref : undefined,
    osmRef: p.ref,
  });
  /* AFTER openDrawer, never before. openDrawer clears the corridor selection
     on its way in - which is right, because opening a climb's drawer must not
     leave a route lit behind it - so setting the highlight first would have it
     wiped one line later. The drawer says what this stretch is; the map then
     says where the whole route goes. */
  selectRoute(p.net, p.rr);
  flyToPin([lngLat.lng, lngLat.lat]);
}

/**
 * Drawer for one knooppunt. Info-only, no improve bridge: the number is a
 * navigation fact about a junction, not a road with a surface — offering the
 * wizard here would ask a rider to record a surface for a point.
 */
export function openKnoopDrawer(p, lngLat) {
  const layer = layerByKey.surface;
  if (!layer) return;
  const title = (D.knoopTitle || 'Node {nr}').replace('{nr}', p.nr || '?');
  openDrawer(layer, {
    name: title,
    headline: title,
    geom: { ll: [lngLat.lat, lngLat.lng] },
    record: [
      { label: D.routeNetwork || 'Network', value: netLabel(p.net), method: 'OSM' },
    ],
    desc: D.knoopHint || 'A numbered junction of the cycle node network — '
      + 'plan by riding number to number.',
    source: 'OpenStreetMap',
    osmUrl: p.ref ? 'https://www.openstreetmap.org/' + p.ref : undefined,
  });
  flyToPin([lngLat.lng, lngLat.lat]);
}
