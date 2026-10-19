// SPDX-License-Identifier: AGPL-3.0-only
/* Motorways and trunk roads are faded on the basemap (map-and-search.md section 4.1, owner 2026-10-17): every liberty
   motorway layer at HIGHWAY_FADE, the shared trunk and primary layers only where the class is trunk. */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const set = {};
const T = { type: 'line', source: 'openmaptiles', 'source-layer': 'transportation' };
const layers = [
  { id: 'road_motorway', ...T },
  { id: 'bridge_motorway_link_casing', ...T },
  { id: 'road_trunk_primary', ...T },
  { id: 'road_secondary_tertiary', ...T },
  { id: 'road_path_pedestrian', ...T, paint: { 'line-opacity': ['interpolate', ['linear'], ['zoom'], 14, 0, 15, 1] } },
  { id: 'waterway_river', type: 'line', source: 'openmaptiles', 'source-layer': 'waterway' },
  { id: 'highway-shield-non-us', type: 'symbol', source: 'openmaptiles', 'source-layer': 'transportation_name' },
  { id: 'plan-line', type: 'line', source: 'plan' },
];
const byId = id => layers.find(x => x.id === id);
globalThis.localStorage = { _m: {}, getItem(k){ return this._m[k] ?? null; }, setItem(k, v){ this._m[k] = String(v); } };
globalThis.window = { __ccMapInstance: {
  getLayersOrder: () => layers.map(l => l.id),
  getLayer: id => { const l = byId(id); return l && { id: l.id, type: l.type, source: l.source, sourceLayer: l['source-layer'] }; },
  getPaintProperty: (id, k) => (byId(id).paint || {})[k],
  getStyle: () => { throw new Error('getStyle serialises the whole style: not on every style change'); },
  setPaintProperty: (id, k, v) => { set[id] = [k, v]; const l = byId(id); l.paint = { ...(l.paint || {}), [k]: v }; },
  addLayer(layer, before){ added.push([layer, before]); }, addSource(id, src){ sources[id] = src; }, on(){},
  setLayoutProperty(id, k, v){ const l = byId(id); l.layout = { ...(l.layout || {}), [k]: v }; }, getLayoutProperty(id, k){ return (byId(id).layout || {})[k]; },
  getSource: id => (id === 'openmaptiles' ? { url: 'https://tiles.openfreemap.org/planet' } : sources[id]),
} };
const added = [];
const sources = {};
globalThis.document = { documentElement: { lang: 'en' } };
const { fadeHighways, HIGHWAY_FADE, NO_BIKE_FADE, addCycleways, CYCLEWAY_LAYER, CYCLEWAY_FILTER, setHighwayFade, highwayFadeOn, showSmallRoads, SMALL_ROADS, SMALL_ROAD_EDGE, warmGreens, WARM_GREENS } = await import('../../assets/map/map-init.js');

test('motorways fade whole; trunk roads and roads with no bikes fade inside the other road layers; the rest stays', () => {
  assert.equal(fadeHighways(), 4);
  assert.deepEqual(set.road_motorway, ['line-opacity', HIGHWAY_FADE]);
  assert.deepEqual(set.bridge_motorway_link_casing, ['line-opacity', HIGHWAY_FADE]);
  assert.deepEqual(set.road_trunk_primary, ['line-opacity', NO_BIKE_FADE]);
  assert.deepEqual(set.road_secondary_tertiary, ['line-opacity', NO_BIKE_FADE], 'an N road with bicycle=no or use_sidepath fades');
  assert.deepEqual(NO_BIKE_FADE[1], ['any', ['==', ['get', 'class'], 'trunk'], ['match', ['get', 'bicycle'], ['no', 'use_sidepath'], true, false]]);
  assert.equal(set.road_path_pedestrian, undefined, 'a zoom-driven opacity of its own stays');
  assert.equal(set.waterway_river, undefined, 'only roads');
  assert.equal(HIGHWAY_FADE, 0.1, 'owner 2026-10-18: fainter still than 20 %');
  assert.equal(fadeHighways(), 0, 'a second run after a style change sets nothing again');
  assert.equal(set['highway-shield-non-us'], undefined, 'the shields stay');
  assert.equal(set['plan-line'], undefined, 'our own lines are not the basemap');
});

test('cycle paths get a clear green line of ours, under the basemap labels, once (owner 2026-10-18)', () => {
  assert.equal(addCycleways(), true);
  const [layer, before] = added.at(-1);
  assert.equal(layer.id, CYCLEWAY_LAYER);
  assert.equal(layer['source-layer'], 'transportation');
  assert.deepEqual(layer.filter, CYCLEWAY_FILTER);
  assert.deepEqual(CYCLEWAY_FILTER, ['all', ['==', ['get', 'class'], 'path'], ['any', ['==', ['get', 'subclass'], 'cycleway'], ['==', ['get', 'bicycle'], 'designated']]]);
  assert.equal(before, 'highway-shield-non-us', 'not under the first basemap label');
  layers.push({ id: CYCLEWAY_LAYER, type: 'line', source: 'openmaptiles', 'source-layer': 'transportation' });
  assert.equal(addCycleways(), false, 'a second call adds nothing');
});

test('the rider turns the fade off and on: off gives each road layer its own opacity back (owner 2026-10-18)', () => {
  assert.equal(highwayFadeOn(), true, 'on unless turned off');
  assert.equal(setHighwayFade(false), false);
  assert.equal(localStorage.getItem('cc-fade-roads'), 'off');
  assert.deepEqual(set.road_motorway, ['line-opacity', undefined], 'the motorway gets liberty\'s own opacity back');
  assert.equal(fadeHighways(), 0, 'off: a style change fades nothing');
  assert.equal(setHighwayFade(true), true);
  assert.deepEqual(set.road_motorway, ['line-opacity', HIGHWAY_FADE]);
});

test('small roads are seen from zoom 12, white with a grey edge; tracks stay the basemap\'s (owner 2026-10-18)', () => {
  layers.push({ id: 'road_minor_casing', ...T, paint: { 'line-color': '#cfcdca', 'line-opacity': ['interpolate', ['linear'], ['zoom'], 12, 0, 12.5, 1] } });
  layers.push({ id: 'road_minor', ...T, paint: { 'line-color': '#fff' } });
  layers.push({ id: 'road_service_track', ...T, paint: { 'line-color': '#fff' } });
  const before = added.length;
  const n = showSmallRoads();
  assert.ok(n >= 4, `set ${n}`);
  const w = id => byId(id).paint['line-width'];
  assert.equal(w('road_minor')[4], 1.2, 'a small road has no white at zoom 12');
  assert.equal(byId('road_minor_casing').paint['line-color'], SMALL_ROAD_EDGE);
  assert.deepEqual(byId('road_minor_casing').paint['line-opacity'], ['interpolate', ['linear'], ['zoom'], 11.5, 0, 12, 1], 'the edge still waits for zoom 12.5, or is a plain number the fade rewrites');
  assert.equal(byId('road_service_track').paint['line-width'], undefined, 'a track is widened: the surfaces show those');
  assert.deepEqual(Object.keys(SMALL_ROADS), ['road_minor_casing', 'road_minor']);
  assert.equal(added.length, before, 'a layer or source of ours is added: MapLibre 6 refuses a vector source of other than 512 px tiles');
  assert.equal(showSmallRoads(), 0, 'a second run after a style change sets everything again');
});
test('the small roads and the fade agree: after one round a style change sets nothing, or the map restyles every frame (owner 2026-10-18: "drawing Wallonia takes ages")', () => {
  setHighwayFade(true);
  showSmallRoads(); fadeHighways();
  assert.equal(showSmallRoads(), 0, 'the small roads undo the fade');
  assert.equal(fadeHighways(), 0, 'the fade undoes the small roads');
});

test('the small roads have no switch: always on from zoom 12 (owner 2026-10-18)', () => {
  const tpl = readFileSync(new URL('../../templates/map/index.html.twig', import.meta.url), 'utf8');
  assert.doesNotMatch(tpl, /ovSmallRoads/);
});

test('the greens are warmer and deeper than liberty\'s, and a second run sets nothing (owner 2026-10-19: "a bit more mossy")', () => {
  layers.push({ id: 'landcover_grass', type: 'fill', source: 'openmaptiles', paint: { 'fill-color': 'rgba(176, 213, 154, 1)', 'fill-opacity': 0.3 } });
  layers.push({ id: 'landcover_wood', type: 'fill', source: 'openmaptiles', paint: { 'fill-color': 'hsla(98,61%,72%,0.7)', 'fill-opacity': 0.4 } });
  layers.push({ id: 'park', type: 'fill', source: 'openmaptiles', paint: { 'fill-color': '#d8e8c8', 'fill-opacity': 0.7 } });
  assert.equal(warmGreens(), 3);
  for(const [id, paint] of Object.entries(WARM_GREENS)) assert.equal(byId(id).paint['fill-color'], paint['fill-color'], id);
  assert.equal(byId('landcover_grass').paint['fill-opacity'], 0.3, 'the opacity stays liberty\'s');
  // Hue of the grass: liberty's 98, ours lower, so warmer.
  const hue = ([r, g, b]) => { const mx = Math.max(r, g, b), mn = Math.min(r, g, b); return mx === g ? 60 * ((b - r) / (mx - mn) + 2) : NaN; };
  assert.ok(hue([156, 174, 102]) < hue([176, 213, 154]) - 20);
  assert.ok(156 + 174 + 102 < 176 + 213 + 154 - 90, 'deeper: darker than liberty\'s grass');
  assert.equal(warmGreens(), 0);
});
