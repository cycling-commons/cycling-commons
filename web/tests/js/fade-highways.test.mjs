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
const { LAND_TONE, holdLandTone } = await import('../../assets/map/map-init.js');
const { toneBasemap, TOWN_LAYER, ROAD_TONE, WATER_TONE, ROAD_EDGE, CAR_ONLY, addRailOn, RAIL_LAYER, LABEL_LAYOUT, setLayoutsOn } = await import('../../assets/map/basemap-tone.js');
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

test('the land takes a field\'s tone, on any map, and a second run sets nothing (owner 2026-10-26: white patches)', () => {
  // The cream liberty draws, with the grass's moss green over it at 24%: what a field looked like.
  const blend = (bg, fg, a) => bg.map((c, i) => Math.round(c * (1 - a) + fg[i] * a));
  const [r, g, b] = blend([248, 244, 240], [156, 174, 102], 0.24);
  assert.ok(LAND_TONE.background['background-color'] !== '#' + [r, g, b].map(c => c.toString(16).padStart(2, '0')).join('').toUpperCase(), 'fresher than a plain blend, which read pale and dead');
  const paints = {};
  const other = {
    getLayer: id => (['background', 'landcover_grass'].includes(id) ? { id } : undefined),
    getPaintProperty: (id, k) => (paints[id] || {})[k],
    setPaintProperty: (id, k, v) => { paints[id] = { ...(paints[id] || {}), [k]: v }; },
  };
  assert.equal(toneBasemap(other), 2, 'the land and the grass, on a map that is not the main one');
  assert.equal(paints.background['background-color'], '#E5EBCF');
  assert.equal(toneBasemap(other), 0, 'a repeat after a style change sets nothing');
});

test('towns stay a light grey and nearly solid at every zoom over the field-toned land (owner 2026-10-26: Hoorn was gone)', () => {
  const paints = {}, ranges = {};
  const town = { id: TOWN_LAYER, maxzoom: 12 };
  const other = {
    getLayer: id => (id === TOWN_LAYER ? town : undefined),
    getPaintProperty: (id, k) => (paints[id] || {})[k],
    setPaintProperty: (id, k, v) => { paints[id] = { ...(paints[id] || {}), [k]: v }; },
    setLayerZoomRange: (id, min, max) => { ranges[id] = [min, max]; town.maxzoom = max; },
  };
  assert.equal(toneBasemap(other), 2, 'the colour, and the range');
  assert.deepEqual(ranges[TOWN_LAYER], [0, 24], 'past liberty\'s zoom 12');
  const stops = paints[TOWN_LAYER]['fill-color'];
  const alpha = s => Number(/,([\d.]+)\)$/.exec(s)[1]);
  assert.ok(alpha(stops[4]) >= 0.85 && alpha(stops[6]) >= 0.9 && alpha(stops[8]) >= 0.9, 'nearly solid, not liberty\'s 49%');
  assert.equal(toneBasemap(other), 0, 'a repeat changes nothing');
});

test('a calmer base: bike roads white, car-only roads yellow, ditches faint, water liberty\'s own (owner 2026-10-26)', () => {
  assert.deepEqual(CAR_ONLY[1], ['==', ['get', 'class'], 'trunk']);
  assert.deepEqual(CAR_ONLY[2], NO_BIKE_FADE[1][2], 'the same roads the fade switch reads as cars only');
  for (const kind of ['road', 'bridge', 'tunnel']) {
    for (const cls of ['secondary_tertiary', 'trunk_primary', 'link']) {
      assert.deepEqual(ROAD_TONE[`${kind}_${cls}`]['line-color'], ['case', CAR_ONLY, '#fea', '#ffffff'], `${kind}_${cls}`);
      const edge = ROAD_TONE[`${kind}_${cls}_casing`]['line-color'];
      assert.equal(edge[0], 'interpolate', 'the edge changes with the zoom');
      assert.deepEqual(edge[6], ['case', CAR_ONLY, '#e9ac77', ROAD_EDGE], 'close in: the small roads\' grey');
      assert.notEqual(edge[4][3], ROAD_EDGE, 'zoomed out: a darker grey, so a white road is not lost');
    }
  }
  assert.equal(SMALL_ROAD_EDGE, ROAD_EDGE, 'one grey edge for every road a bike may ride');
  assert.ok(!Object.keys(ROAD_TONE).some(id => id.includes('motorway')), 'motorways keep their own colour, faded');
  assert.deepEqual(Object.keys(WATER_TONE), ['water', 'waterway_other'], 'rivers keep liberty\'s colour');
  assert.equal(WATER_TONE.water['fill-color'], '#B0CAFF', 'lakes and the sea a little lighter, still blue');
  assert.ok(!('line-color' in WATER_TONE.waterway_other), 'and so do the ditches');
  const op = WATER_TONE.waterway_other['line-opacity'];
  assert.ok(op[4] < 0.3 && op[6] < 0.6, 'a ditch is faint until zoom 13');
});

test('railways from zoom 8 under liberty\'s own, which takes over at 14; secondary roads wider zoomed out', () => {
  const added = [];
  const m = { getLayer: id => (id === 'road_major_rail' ? { id } : undefined), getSource: id => (id === 'openmaptiles' ? {} : undefined), addLayer: (l, before) => added.push([l, before]) };
  assert.equal(addRailOn(m), true);
  const [rail, before] = added[0];
  assert.equal(rail.id, RAIL_LAYER);
  assert.equal(before, 'road_major_rail');
  assert.deepEqual([rail.minzoom, rail.maxzoom], [8, 14]);
  assert.deepEqual(rail.filter, ['all', ['==', ['get', 'class'], 'rail'], ['!=', ['get', 'brunnel'], 'tunnel']]);
  const w = ROAD_TONE.road_secondary_tertiary['line-width'];
  assert.ok(w[4] >= 0.8 && w[6] >= 1.4, 'not liberty\'s half a pixel at zoom 8 to 10');
  assert.equal(w[w.length - 1], 13, 'liberty\'s own width close in');
});

test('village names smaller and dark grey zoomed out, so many more fit; towns a touch smaller (owner 2026-10-26)', () => {
  const v = LABEL_LAYOUT.label_village['text-size'];
  assert.ok(v[3] === 9 && v[4] < 10, 'smaller than liberty\'s 10 px at zoom 9');
  assert.equal(LABEL_LAYOUT.label_village['text-padding'], 1);
  const town = LABEL_LAYOUT.label_town['text-size'];
  assert.ok(town[5] === 9 && town[6] > v[4], 'a town still above a village at zoom 9');
  const layouts = {};
  const m = { getLayer: id => (id === 'label_village' ? { id } : undefined), getLayoutProperty: (id, k) => (layouts[id] || {})[k],
    setLayoutProperty: (id, k, val) => { layouts[id] = { ...(layouts[id] || {}), [k]: val }; } };
  assert.equal(setLayoutsOn(m, LABEL_LAYOUT), 2, 'a map without the town layer sets what it has');
  assert.equal(setLayoutsOn(m, LABEL_LAYOUT), 0, 'a repeat sets nothing');
});

test('road mode holds the land tone: a style change while it is on leaves its own grey', () => {
  layers.push({ id: 'background', type: 'background', paint: { 'background-color': '#EDEDE8' } });
  holdLandTone(true);
  warmGreens();
  assert.equal(byId('background').paint['background-color'], '#EDEDE8', 'road mode keeps its grey');
  holdLandTone(false);
  warmGreens();
  assert.equal(byId('background').paint['background-color'], '#E5EBCF', 'and the land comes back after');
  layers.splice(layers.findIndex(l => l.id === 'background'), 1);
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
