// SPDX-License-Identifier: AGPL-3.0-only
/* Motorways and trunk roads are faded on the basemap (map-and-search.md section 4.1, owner 2026-10-17): every liberty
   motorway layer at HIGHWAY_FADE, the shared trunk and primary layers only where the class is trunk. */
import test from 'node:test';
import assert from 'node:assert/strict';

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
globalThis.window = { __ccMapInstance: {
  getLayersOrder: () => layers.map(l => l.id),
  getLayer: id => { const l = byId(id); return l && { id: l.id, type: l.type, source: l.source, sourceLayer: l['source-layer'] }; },
  getPaintProperty: (id, k) => (byId(id).paint || {})[k],
  getStyle: () => { throw new Error('getStyle serialises the whole style: not on every style change'); },
  setPaintProperty: (id, k, v) => { set[id] = [k, v]; const l = byId(id); l.paint = { ...(l.paint || {}), [k]: v }; },
  addLayer(){}, addSource(){}, on(){},
} };
globalThis.document = { documentElement: { lang: 'en' } };
const { fadeHighways, HIGHWAY_FADE, NO_BIKE_FADE } = await import('../../assets/map/map-init.js');

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
