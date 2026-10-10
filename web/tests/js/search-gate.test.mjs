// SPDX-License-Identifier: AGPL-3.0-only
//
// With the reach off, no source of search rows lists a place outside the
// scope; with the reach on (Everywhere), every source does
// (docs/specs/map-and-search.md §4.5, §7). Owner-reported 2026-10-11: a search
// in North Holland listed Spa, in Belgium, with the reach still off.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const store = new Map();
globalThis.localStorage = {
  getItem: (k) => (store.has(k) ? store.get(k) : null),
  setItem: (k, v) => { store.set(k, String(v)); },
  removeItem: (k) => { store.delete(k); },
};
globalThis.window = { dispatchEvent: () => {} };
globalThis.CustomEvent = globalThis.CustomEvent || class CustomEvent { constructor(type) { this.type = type; } };
globalThis.location = { href: 'http://localhost/map', search: '' };
globalThis.history = { replaceState: () => {} };

const require = createRequire(import.meta.url);
const CCScope = require('../../assets/map/scope.js');
const { searchGate } = await import('../../assets/map/search-gate.js');

const WALLONIA = 1, NORTH_HOLLAND = 60;
const REGIONS = [
  { id: WALLONIA, slug: 'wallonia', label: 'Wallonia', countryCode: 'BE', bbox: [2.84, 49.45, 6.41, 50.85] },
  { id: 24, slug: 'flanders', label: 'Flanders', countryCode: 'BE', bbox: [2.54, 50.68, 5.92, 51.51] },
  { id: NORTH_HOLLAND, slug: 'north-holland', label: 'North Holland', countryCode: 'NL', bbox: [4.49, 52.16, 5.37, 53.18] },
];
const boot = (scope) => { store.clear(); CCScope.init(REGIONS, scope); return CCScope; };
const inNorthHolland = () => boot({ kind: 'region', regionIds: [NORTH_HOLLAND], countryCode: 'NL' });

// One row from each source: a Photon town on each side of the border.
const photonSpa = { properties: { name: 'Spa', countrycode: 'BE' } };
const photonSpaarndam = { properties: { name: 'Spaarndam', countrycode: 'NL' } };

test('reach off in North Holland: the map\'s own items keep to the region', () => {
  const g = searchGate(inNorthHolland(), false);
  assert.equal(g.item(NORTH_HOLLAND), true);
  assert.equal(g.item(WALLONIA), false, 'a Spa water tap is not listed');
});

test('reach off in North Holland: Photon is asked for the Netherlands and Spa is dropped', () => {
  const g = searchGate(inNorthHolland(), false);
  assert.equal(g.photon.countrycode, 'nl');
  assert.deepEqual(g.photon.bbox, [4.49, 52.16, 5.37, 53.18]);
  assert.equal(g.photonHit(photonSpa), false);
  assert.equal(g.photonHit(photonSpaarndam), true);
});

test('reach off in North Holland: the coverage search sends the scope, the server search is not asked', () => {
  const g = searchGate(inNorthHolland(), false);
  assert.equal(g.coverageScoped, true);
  assert.equal(g.askServer, false);
});

test('reach off in North Holland: a Belgian region is not offered as a scope row', () => {
  const s = inNorthHolland();
  const g = searchGate(s, false);
  assert.deepEqual(s.searchScopes('wallonia').filter(g.scopeHit), []);
  assert.deepEqual(s.searchScopes('north').filter(g.scopeHit).map((h) => h.slug), ['north-holland']);
});

test('reach on in North Holland: every source looks everywhere', () => {
  const s = inNorthHolland();
  const g = searchGate(s, true);
  assert.equal(g.item(WALLONIA), true);
  assert.deepEqual(g.photon, { bbox: null, countrycode: null });
  assert.equal(g.photonHit(photonSpa), true);
  assert.equal(g.coverageScoped, false);
  assert.equal(g.askServer, true);
  assert.deepEqual(s.searchScopes('wallonia').filter(g.scopeHit).map((h) => h.slug), ['wallonia']);
});

test('reach off in Wallonia: Spa is listed and Spaarndam is not', () => {
  const g = searchGate(boot({ kind: 'region', regionIds: [WALLONIA], countryCode: 'BE' }), false);
  assert.equal(g.item(WALLONIA), true);
  assert.equal(g.item(NORTH_HOLLAND), false);
  assert.equal(g.photonHit(photonSpa), true);
  assert.equal(g.photonHit(photonSpaarndam), false);
});

test('reach off in My area: scope rows keep to the countries the area touches', () => {
  const myArea = {
    get: () => ({ kind: 'myArea', regionIds: [NORTH_HOLLAND], countryCode: null, myArea: { countryCodes: ['NL'] } }),
    photonParams: () => ({ bbox: [4.6, 52.2, 5.2, 52.6], countrycode: null }),
  };
  const g = searchGate(myArea, false);
  assert.equal(g.scopeHit({ cc: 'NL' }), true);
  assert.equal(g.scopeHit({ cc: 'BE' }), false);
  assert.equal(g.item(WALLONIA), false);
});

test('the search box has no row source of its own outside the gate', () => {
  const ui = fs.readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'map', 'search-ui.js'), 'utf8');
  // No list of its own and no scope test of its own.
  assert.doesNotMatch(ui, /CITIES|inScope\(|photonParams\(|countrycode\)?\s*===|ccs\.indexOf/);
  // Each source reads the gate.
  assert.match(ui, /if\(!g\.item\(it\.rid\)\) continue;/, 'the map\'s own items');
  assert.match(ui, /const g=gate\(\), pp=g\.photon;/, 'the Photon request');
  assert.match(ui, /\.filter\(g\.photonHit\)/, 'the Photon answer');
  assert.match(ui, /const sq=gate\(\)\.coverageScoped \? covScopeQuery\(\) : '';/, 'the coverage search');
  assert.match(ui, /if\(!gate\(\)\.askServer \|\| coordPoint\(q\) \|\| q\.length<3\)/, 'the server search request');
  assert.match(ui, /if\(g\.askServer && _apiQ===q\) _apiHits\.forEach\(/, 'the server search rows');
  assert.match(ui, /searchScopes\(sBox\.value\) : \[\]\)\.filter\(g\.scopeHit\);/, 'the scope rows');
});
