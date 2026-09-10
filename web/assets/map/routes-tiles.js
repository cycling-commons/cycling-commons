// SPDX-License-Identifier: AGPL-3.0-only
/* Cycle-route NETWORK tiles (docs/specs/coverage-provider.md §4,
   docs/specs/route-domain.md): signed corridors + knooppunt numbers from their
   own PMTiles artifact. Off until asked. A corridor click opens the surface
   drawer for that way. */
import { map, flyToPin } from './map-init.js';
import { D } from './i18n.js';
import { layerByKey } from './catalog.js';
import { openDrawer } from './drawer.js';
import { fullWayEnds } from './surface-tiles.js';

/* Same two-gate as the surface skin: configured = artifact exists; available = pmtiles loaded. */
export const routesTilesConfigured = () =>
  typeof window.CC_ROUTES_URL === 'string' && !!window.CC_ROUTES_URL;
export const routesTilesAvailable = () =>
  routesTilesConfigured() && typeof pmtiles !== 'undefined';

export const ROUTES_TILE_SOURCE = 'routes-tiles';

/* Three visual families (icn/ncn, rcn/lcn/other, mtb). Keys are contract
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
/* Knooppunt badges from z10 (contract routes.nodes.minZoom; routes-zooms.test.cjs). */
const BADGE_MIN_ZOOM = 10;
const LINE_PREFIX = 'rttile-';
const KNOOP_PREFIX = 'rtknoop-';
/* Named once so the dim-restore pass puts them back exactly. */
const LINE_WIDTH = ['interpolate', ['linear'], ['zoom'], 8, 1.8, 11, 3.2, 14, 6];
const LINE_OPACITY = ['interpolate', ['linear'], ['zoom'], 8, 0.55, 12, 0.72];
const DIM_OPACITY = 0.14;
const DIM_BADGE = 0.3;

let added = false;
let visible = false;

/* Source-layers per country, same derivation as the surface skin. */
function lineLayers() {
  const ccs = Array.isArray(window.CC_COVERAGE_COUNTRIES) ? window.CC_COVERAGE_COUNTRIES : [];
  return ccs.length ? ccs.map(cc => 'routes_' + String(cc).toLowerCase()) : ['routes'];
}
function knoopLayers() {
  const ccs = Array.isArray(window.CC_COVERAGE_COUNTRIES) ? window.CC_COVERAGE_COUNTRIES : [];
  return ccs.length ? ccs.map(cc => 'knoop_' + String(cc).toLowerCase()) : ['knoop'];
}

/* Above the surface skin, below curated layers. Mounted on demand, so beforeId is required. */
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
          'line-width': LINE_WIDTH,
          'line-opacity': LINE_OPACITY,
        },
      }, under);
      map.on('click', id, e => openRouteDrawer(e.features[0].properties, e.lngLat, e.features[0].geometry, srcLayer));
      map.on('mouseenter', id, () => { map.getCanvas().style.cursor = 'pointer'; });
      map.on('mouseleave', id, () => { map.getCanvas().style.cursor = ''; });
    });

    /* Selected route, one layer per country: colour from the feature's own network. */
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
          'line-width': ['interpolate', ['linear'], ['zoom'], 8, 3.2, 11, 5.5, 14, 9],
          'line-opacity': 1,
        },
      }, under);
      map.on('click', selId, e => openRouteDrawer(e.features[0].properties, e.lngLat, e.features[0].geometry, srcLayer));
      map.on('mouseenter', selId, () => { map.getCanvas().style.cursor = 'pointer'; });
      map.on('mouseleave', selId, () => { map.getCanvas().style.cursor = ''; });
    }
  });

  // Knooppunt badges: circle + symbol (MapLibre text cannot ride a circle layer).
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
  if (!on) clearRouteSelection();   // coming back to a dimmed leftover selection reads as broken
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

/* False for every feature. MapLibre rejects `['boolean', false]` in a filter slot. */
const MATCH_NOTHING = ['==', ['get', 'net'], '\u0000none'];

let selectedRoute = null;   // {net, rr} or null

/**
 * Highlight one signed route and dim the rest. Matched by (net, rr), plus `refs`
 * padded with `|` so "ncn LF1" does not also select "ncn LF10".
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
 * Corridor stretch drawer, opened against the A layer so Improve is the surface wizard.
 */
export function openRouteDrawer(p, lngLat, geometry, sourceLayer) {
  const layer = layerByKey.surface;
  if (!layer) return;
  const rec = [{ label: D.routeNetwork || 'Network', value: netLabel(p.net), method: 'OSM' }];
  if (p.rr) rec.push({ label: D.routeRef || 'Route', value: p.rr, method: 'OSM' });
  if (p.refs) {
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
    segmentEnds: fullWayEnds(ROUTES_TILE_SOURCE, sourceLayer, p.ref, geometry),
    record: rec,
    desc: D.routeSurfaceHint || 'A signed route — help record what is under the tyres. '
      + 'Improve this stretch to add its surface.',
    source: 'OpenStreetMap',
    osmUrl: p.ref ? 'https://www.openstreetmap.org/' + p.ref : undefined,
    osmRef: p.ref,
  });
  /* AFTER openDrawer: it clears the corridor selection on the way in. */
  selectRoute(p.net, p.rr);
  flyToPin([lngLat.lng, lngLat.lat]);
}

/** Knooppunt drawer: info-only — a junction number is not a road with a surface. */
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
