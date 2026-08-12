// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Cycle-route NETWORK tiles — signed route corridors + knooppunt numbers.
   Plan: docs/plans/handoffs/2026-08-12-routes-layer-and-surface-quality.md.

   route=bicycle / route=mtb relations out of OSM, one line per member way,
   from their own PMTiles artifact (routes_<cc> line layers, knoop_<cc> point
   layers — see pipeline/coverage/routes.py). The benchmark is OpenCycleMap —
   purple/red corridors and knooppunt numbers are what make a map feel like it
   knows where to ride — and the brief is READABLE BY DEFAULT: CyclOSM renders
   the same data and was rejected as unreadable at scale, so corridors are
   translucent, badges wait for z13, and the whole layer is off until asked.

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
import { segmentEnds } from './surface-tiles.js';

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
   merge. The purple is deliberate inheritance: the cycleway class freed it
   when colour became surface-only (owner 2026-08-12), and purple corridors are
   the OpenCycleMap association riders already carry. Keys are contract
   routes.networks, pinned by routes-zooms.test.cjs. */
export const NET_STYLE = {
  icn: { group: 'national', color: '#7A4FCF' },
  ncn: { group: 'national', color: '#7A4FCF' },
  rcn: { group: 'regional', color: '#3E7CB8' },
  lcn: { group: 'regional', color: '#3E7CB8' },
  other: { group: 'regional', color: '#3E7CB8' },
  mtb: { group: 'mtb', color: '#8A5A32' },
};
const GROUPS = [
  { key: 'national', nets: ['icn', 'ncn'], color: '#7A4FCF' },
  { key: 'regional', nets: ['rcn', 'lcn', 'other'], color: '#3E7CB8' },
  { key: 'mtb', nets: ['mtb'], color: '#8A5A32' },
];
/* Where the knooppunt badges appear. The ARTIFACT carries the points from z11
   (contract routes.nodes.minZoom — cheap, and leaves room to lower this after
   riding with it); the CLIENT waits for z13, where a number is a sign beside a
   junction rather than confetti over a province. routes-zooms.test.cjs pins
   the client floor to at least the artifact's, or the badges would be asked
   for at zooms the tiles do not carry. */
const BADGE_MIN_ZOOM = 13;
const LINE_PREFIX = 'rttile-';
const KNOOP_PREFIX = 'rtknoop-';

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
          // road stays legible inside it — the OpenCycleMap reading.
          'line-width': ['interpolate', ['linear'], ['zoom'], 8, 1.6, 11, 3, 14, 6],
          'line-opacity': ['interpolate', ['linear'], ['zoom'], 8, 0.38, 12, 0.5],
        },
      }, under);
      map.on('click', id, e => openRouteDrawer(e.features[0].properties, e.lngLat, e.features[0].geometry));
      map.on('mouseenter', id, () => { map.getCanvas().style.cursor = 'pointer'; });
      map.on('mouseleave', id, () => { map.getCanvas().style.cursor = ''; });
    });
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
        'circle-radius': 9,
        'circle-color': '#FFFFFF',
        'circle-stroke-width': 2,
        'circle-stroke-color': '#3E7CB8',
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
        'text-size': 11,
        'text-allow-overlap': false,
        visibility: 'none',
      },
      paint: { 'text-color': '#1F4E75' },
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
export function openRouteDrawer(p, lngLat, geometry) {
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
    segmentEnds: segmentEnds(geometry),
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
