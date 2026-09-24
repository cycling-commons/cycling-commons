// SPDX-License-Identifier: AGPL-3.0-only
//
// tile-sources.js: which per-country archive serves a layer, and which
// archives a viewport needs (docs/specs/coverage-provider.md §4).
import test from 'node:test';
import assert from 'node:assert/strict';

globalThis.window = { CC_TILES: {
  surface: {
    be: { tiles: { classified: 'https://t/s/be/c.pmtiles' }, bounds: [2.5, 49.4, 6.4, 51.5], stamp: '20260924-0312' },
    nz: { tiles: { classified: 'https://t/s/nz/c.pmtiles' }, bounds: [166.3, -47.3, 178.6, -34.3], stamp: '20260920-0300' },
  },
  routes: { '*': { tiles: { routes: 'https://t/r/all.pmtiles' }, bounds: [-180, -85.0511, 180, 85.0511], stamp: '20260816-2223' } },
  coverage: {},
} };
const ts = await import('../../assets/map/tile-sources.js');

test('a country entry serves its own layers', () => {
  assert.equal(ts.sourceIdFor('surface', 'classified', 'BE'), 'surface-classified-be');
  assert.equal(ts.sourceIdFor('surface', 'classified', 'fr'), null);
});

test('star entry feeds every country', () => {
  assert.equal(ts.sourceIdFor('routes', 'routes', 'be'), 'routes-routes-all');
  assert.equal(ts.sourceIdFor('routes', 'routes', null), 'routes-routes-all');
});

test('configured means some entry has the arm', () => {
  assert.equal(ts.familyConfigured('surface', 'classified'), true);
  assert.equal(ts.familyConfigured('surface', 'todo'), false);
  assert.equal(ts.familyConfigured('coverage', 'points'), false);
});

test('source layer names carry their country', () => {
  assert.equal(ts.ccOfSourceLayer('surface_be'), 'be');
  assert.equal(ts.ccOfSourceLayer('b_zz'), 'zz');
  assert.equal(ts.ccOfSourceLayer('b'), null);
});

test('a view over Belgium mounts Belgium only', () => {
  const boxes = ts.viewBoxes(4.0, 50.5, 4.6, 50.9);
  assert.deepEqual(ts.keysInView(window.CC_TILES.surface, boxes), ['be']);
});

test('wrapped view still finds New Zealand', () => {
  // MapLibre reports a pan east past 180 as east > 180.
  const boxes = ts.viewBoxes(170.0, -46.0, 190.0, -40.0);
  assert.deepEqual(ts.keysInView(window.CC_TILES.surface, boxes), ['nz']);
  const west = ts.viewBoxes(-195.0, -46.0, -175.0, -40.0);
  assert.deepEqual(ts.keysInView(window.CC_TILES.surface, west), ['nz']);
});

test('a view wider than the world is the world', () => {
  assert.deepEqual(ts.viewBoxes(-400, -60, 400, 60), [[-180, -60, 180, 60]]);
});

test('newest stamp across a family', () => {
  assert.equal(ts.newestStamp('surface'), '20260924-0312');
  assert.equal(ts.newestStamp('coverage'), '');
});

test('mountInView adds each source once and hands it over', () => {
  const sources = {};
  const map = {
    getZoom: () => 11,
    getBounds: () => ({ getWest: () => 4.0, getSouth: () => 50.5, getEast: () => 4.6, getNorth: () => 50.9 }),
    getSource: id => sources[id],
    addSource: (id, spec) => { sources[id] = spec; },
  };
  const added = [];
  ts.mountInView(map, 'surface', 'classified', 10, (key, id) => added.push([key, id]));
  ts.mountInView(map, 'surface', 'classified', 10, (key, id) => added.push([key, id]));
  assert.deepEqual(added, [['be', 'surface-classified-be']]);
  assert.equal(sources['surface-classified-be'].url, 'pmtiles://https://t/s/be/c.pmtiles');
});

test('below the floor nothing mounts', () => {
  const map = { getZoom: () => 3, getBounds: () => { throw new Error('not read'); } };
  ts.mountInView(map, 'surface', 'classified', 10, () => assert.fail('mounted'));
});
