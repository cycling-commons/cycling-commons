// SPDX-License-Identifier: AGPL-3.0-only
//
// The map holds only the regions it shows (docs/specs/catalog-data-model.md
// §9.1): catalog-load.js reads the region stamps, fetches the slices of the
// rider's scope, and asks for the worldwide document only when the map needs
// every region. These run the real loader against a stubbed browser and
// record what it fetches.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '..', '..');
const src = fs.readFileSync(path.join(ROOT, 'assets', 'map', 'catalog-load.js'), 'utf8');

const layer = (features) => ({ type: 'FeatureCollection', features });
const tap = (rid, id, name) => ({ type: 'Feature', properties: { rid, id, n: name }, geometry: { type: 'Point', coordinates: [5, 50] } });

// A document in the payload's shapes, water taps only.
function doc(taps, extra) {
  const d = { A: [], N: [], R: [], O: { osm: layer([]), authority: layer([]) }, refs: [], providers: {} };
  ['B', 'C', 'D', 'E', 'F', 'G', 'P', 'Q'].forEach((L) => { d[L] = layer([]); });
  d.B = layer(taps);
  return Object.assign(d, extra);
}

const flush = async () => { for (let i = 0; i < 30; i++) await new Promise((r) => setImmediate(r)); };

function boot({ scope, stamps, docs, bootHint }) {
  const fetched = [];
  const listeners = {};
  const window = {
    CC_CATALOG_URL: '/map/catalog.json?v=a1',
    CC_CATALOG_BOOT: bootHint || { regions: [], worldwide: false },
    CCScope: { get: () => scope },
    addEventListener: (type, fn) => { (listeners[type] = listeners[type] || []).push(fn); },
  };
  const fetch = (url) => {
    fetched.push(url);
    const body = url === '/map/catalog/stamps.json' ? stamps : docs[url];
    return Promise.resolve({ ok: body !== undefined, status: body === undefined ? 404 : 200, json: () => Promise.resolve(JSON.parse(JSON.stringify(body))) });
  };
  const document = { getElementById: () => null, addEventListener: () => {}, visibilityState: 'visible' };
  vm.runInNewContext(src, { window, document, fetch, console, Date, Promise, setTimeout, Number, String, Object });
  // Copied out of the sandbox: its arrays carry the sandbox's own prototype.
  const plain = (v) => JSON.parse(JSON.stringify(v));
  const names = () => (window.CC_WATER_OSM ? plain(window.CC_WATER_OSM.features.map((f) => f.properties.n).sort()) : null);
  const refs = () => plain(window.CC_CURATED_REFS).sort();
  const setScope = (next) => { scope = next; (listeners['cc:scopechange'] || []).forEach((fn) => fn()); };
  return { window, fetched, names, refs, setScope };
}

const STAMPS = { 0: 's0', 24: 's24', 26: 's26', 27: 's27' };
const DOCS = {
  '/map/catalog/region/24.json?v=s24': doc([tap(24, 1, 'Tap 24')], { rid: 24, stamp: 's24', refs: ['node/24'] }),
  '/map/catalog/region/26.json?v=s26': doc([tap(26, 2, 'Tap 26')], { rid: 26, stamp: 's26', refs: ['node/26'] }),
  '/map/catalog/region/27.json?v=s27': doc([tap(27, 3, 'Tap 27')], { rid: 27, stamp: 's27' }),
  '/map/catalog.json?v=a1': doc([tap(24, 1, 'Old tap 24'), tap(26, 2, 'Tap 26'), tap(27, 3, 'Tap 27'), tap(undefined, 4, 'Regionless tap')],
    { stamps: { 0: 's0', 24: 'old24', 26: 's26', 27: 's27' }, refs: ['node/world'] }),
};

test('a rider scoped to two regions downloads those two and never the world', async () => {
  const m = boot({ scope: { kind: 'country', regionIds: [24, 26] }, stamps: STAMPS, docs: DOCS });
  await flush();
  assert.deepEqual(m.fetched.sort(), ['/map/catalog/region/24.json?v=s24', '/map/catalog/region/26.json?v=s26', '/map/catalog/stamps.json']);
  assert.equal(m.window.CC_CATALOG_STATE, 'ok');
  assert.deepEqual(m.names(), ['Tap 24', 'Tap 26']);
  assert.deepEqual(m.refs(), ['node/24', 'node/26'], 'tile dedupe covers every held region');
  assert.equal(m.window.CCCatalog.readout(), 'area 2');
});

test('a region with no stamp never held a row, so nothing is fetched for it', async () => {
  const m = boot({ scope: { kind: 'region', regionIds: [99] }, stamps: STAMPS, docs: DOCS });
  await flush();
  assert.deepEqual(m.fetched, ['/map/catalog/stamps.json']);
  assert.equal(m.window.CC_CATALOG_STATE, 'ok');
  assert.deepEqual(m.names(), []);
});

test('a link into another region brings that region along at boot', async () => {
  const m = boot({ scope: { kind: 'region', regionIds: [24] }, stamps: STAMPS, docs: DOCS, bootHint: { regions: [27], worldwide: false } });
  await flush();
  assert.ok(m.fetched.includes('/map/catalog/region/27.json?v=s27'));
  assert.ok(!m.fetched.some((u) => u.startsWith('/map/catalog.json')));
  assert.deepEqual(m.names(), ['Tap 24', 'Tap 27']);
});

test('a new scope fetches its own regions once, and keeps the ones already held', async () => {
  const m = boot({ scope: { kind: 'region', regionIds: [24] }, stamps: STAMPS, docs: DOCS });
  await flush();
  m.setScope({ kind: 'region', regionIds: [26] });
  m.setScope({ kind: 'region', regionIds: [26] });
  await flush();
  assert.equal(m.fetched.filter((u) => u.startsWith('/map/catalog/region/26.json')).length, 1, 'one request however many callers');
  assert.equal(m.fetched.filter((u) => u === '/map/catalog/stamps.json').length, 1, 'the stamps read is shared');
  assert.deepEqual(m.names(), ['Tap 24', 'Tap 26']);
});

test('the Everywhere scope boots from the worldwide document', async () => {
  const m = boot({ scope: { kind: 'everywhere', regionIds: [] }, stamps: STAMPS, docs: DOCS });
  await flush();
  assert.ok(m.fetched.includes('/map/catalog.json?v=a1'));
  assert.ok(m.names().includes('Regionless tap'));
  assert.equal(m.window.CCCatalog.readout(), 'world a1');
});

test('a link by name, or to a place no region holds, boots from the worldwide document', async () => {
  const m = boot({ scope: { kind: 'region', regionIds: [26] }, stamps: STAMPS, docs: DOCS, bootHint: { regions: [], worldwide: true } });
  await flush();
  assert.ok(m.fetched.includes('/map/catalog.json?v=a1'));
  assert.ok(m.names().includes('Regionless tap'));
});

test('the worldwide document on demand keeps the newer slice the rider already holds', async () => {
  const m = boot({ scope: { kind: 'region', regionIds: [24] }, stamps: STAMPS, docs: DOCS });
  await flush();
  const ok = await m.window.CCCatalog.ensureWorldwide();
  await flush();
  assert.equal(ok, true);
  assert.equal(m.fetched.filter((u) => u === '/map/catalog.json?v=a1').length, 1);
  const names = m.names();
  assert.ok(names.includes('Tap 24'), 'the live slice stays');
  assert.ok(!names.includes('Old tap 24'), 'the hour-cached copy of a held region does not come back');
  assert.ok(names.includes('Regionless tap') && names.includes('Tap 27'), 'everything else arrives');
  assert.deepEqual(m.refs(), ['node/24', 'node/world']);
  await m.window.CCCatalog.ensureWorldwide();
  assert.equal(m.fetched.filter((u) => u === '/map/catalog.json?v=a1').length, 1, 'once per page');
});

test('the page preloads what the loader reads first, and the loader asks for it the same way', () => {
  const twig = fs.readFileSync(path.join(ROOT, 'templates', 'map', 'index.html.twig'), 'utf8');
  assert.match(twig, /<link rel="preload" as="fetch" href="\{\{ path\('map_catalog_stamps'\) \}\}" crossorigin>/);
  // A preload is reused only by a request made the same way: no extra headers.
  assert.match(src, /fetch\('\/map\/catalog\/stamps\.json'\)/);
});
