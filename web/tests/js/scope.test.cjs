// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// Node smoke tests for the map scope model (web/assets/map/scope.js) — the
// suite the module's dual-export guard exists for (07-20 review finding 8:
// the guard predated the tests; now they are real). No dependencies: node:test
// ships with Node ≥18 — run with `node --test web/tests/js` (make scope-test).
//
// scope.js is a browser IIFE; it reads window/location/localStorage/history
// defensively (every touch is try/caught or typeof-guarded), so the mocks
// below are installed on globalThis BEFORE the require and swapped per test.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

// ---- browser-global mocks ---------------------------------------------------
const store = new Map();
globalThis.localStorage = {
  getItem: (k) => (store.has(k) ? store.get(k) : null),
  setItem: (k, v) => { store.set(k, String(v)); },
  removeItem: (k) => { store.delete(k); },
};
let events = [];
globalThis.window = {
  dispatchEvent: (e) => { events.push(e); },
};
globalThis.CustomEvent = globalThis.CustomEvent
  || class CustomEvent { constructor(type, opts) { this.type = type; this.detail = opts && opts.detail; } };
globalThis.location = { href: 'http://localhost/map', search: '' };
let replacedUrls = [];
globalThis.history = { replaceState: (_s, _t, url) => { replacedUrls.push(String(url)); } };

const CCScope = require('../../assets/map/scope.js');

// Registry fixture — ids/slugs mirror the Belgium seed; order = registry order
// (area DESC), which byCountry preserves for country scopes.
const REGIONS = [
  { id: 1, slug: 'wallonia', countryCode: 'BE', bbox: [2.84, 49.45, 6.41, 50.85] },
  { id: 24, slug: 'flanders', countryCode: 'BE', bbox: [2.54, 50.68, 5.92, 51.51] },
  { id: 23, slug: 'brussels', countryCode: 'BE', bbox: [4.24, 50.76, 4.48, 50.91] },
];
const DEFAULT = { kind: 'region', regionIds: [1], countryCode: 'BE' };

// Fresh-page init: set the URL + storage a browser would arrive with, wipe the
// event/persist capture, then re-run init exactly like the map page does.
function boot({ search = '', ls = null } = {}) {
  globalThis.location = { href: `http://localhost/map${search}`, search };
  store.clear();
  if (ls !== null) store.set('cc-scope', ls);
  events = [];
  replacedUrls = [];
  return CCScope.init(REGIONS, DEFAULT);
}

test('module exports the API (dual-export guard)', () => {
  assert.equal(typeof CCScope.init, 'function');
  assert.equal(CCScope, globalThis.window.CCScope);
});

test('init: no URL, no storage -> the provided default scope', () => {
  assert.deepEqual(boot(), DEFAULT);
});

test('init: URL ?scope= wins over localStorage', () => {
  const s = boot({ search: '?scope=region:flanders', ls: 'everywhere' });
  assert.deepEqual(s, { kind: 'region', regionIds: [24], countryCode: 'BE' });
});

test('init: localStorage wins over the default when no URL param', () => {
  const s = boot({ ls: 'country:BE' });
  assert.deepEqual(s, { kind: 'country', regionIds: [1, 24, 23], countryCode: 'BE' });
});

test('init: a stale stored slug (deleted region) falls back to the default', () => {
  assert.deepEqual(boot({ ls: 'region:ghost' }), DEFAULT);
});

test('init: a malformed URL token falls back cleanly', () => {
  assert.deepEqual(boot({ search: '?scope=bogus' }), DEFAULT);
  assert.deepEqual(boot({ search: '?scope=country:XX' }), DEFAULT);
});

test('multi-region URL token collapses to its first resolvable region (07-20 finding 10)', () => {
  const s = boot({ search: '?scope=region:wallonia,flanders' });
  assert.deepEqual(s, { kind: 'region', regionIds: [1], countryCode: 'BE' });
  // First token stale -> the first RESOLVABLE one wins, not null.
  const s2 = boot({ search: '?scope=region:ghost,brussels' });
  assert.deepEqual(s2, { kind: 'region', regionIds: [23], countryCode: 'BE' });
});

test('set: sanitize drops unknown ids; an all-stale scope is refused, state unchanged', () => {
  boot();
  const before = CCScope.get();
  assert.deepEqual(CCScope.set({ kind: 'region', regionIds: [999], countryCode: null }), before);
});

test('get returns a copy — mutating it never changes internal state', () => {
  boot();
  const got = CCScope.get();
  got.regionIds.push(999); got.kind = 'everywhere';
  assert.deepEqual(CCScope.get(), DEFAULT);
});

test('widen ladder: region -> its country -> everywhere; canWiden tracks it', () => {
  boot();
  CCScope.setRegion('wallonia');
  assert.equal(CCScope.canWiden(), true);
  assert.deepEqual(CCScope.widen(), { kind: 'country', regionIds: [1, 24, 23], countryCode: 'BE' });
  assert.deepEqual(CCScope.widen(), { kind: 'everywhere', regionIds: [], countryCode: null });
  assert.equal(CCScope.canWiden(), false);
  assert.deepEqual(CCScope.widen(), { kind: 'everywhere', regionIds: [], countryCode: null });
});

test('nextWider previews without applying', () => {
  boot();
  CCScope.setRegion('brussels');
  assert.deepEqual(CCScope.nextWider(), { kind: 'country', regionIds: [1, 24, 23], countryCode: 'BE' });
  assert.deepEqual(CCScope.get(), { kind: 'region', regionIds: [23], countryCode: 'BE' });   // unchanged
  CCScope.setCountry('BE');
  assert.deepEqual(CCScope.nextWider(), { kind: 'everywhere', regionIds: [], countryCode: null });
  CCScope.setEverywhere();
  assert.equal(CCScope.nextWider(), null);
});

test('bbox: single region = its own; country = the union; everywhere = null', () => {
  boot();
  CCScope.setRegion('wallonia');
  assert.deepEqual(CCScope.bbox(), [2.84, 49.45, 6.41, 50.85]);
  CCScope.setCountry('BE');
  assert.deepEqual(CCScope.bbox(), [2.54, 49.45, 6.41, 51.51]);
  CCScope.setEverywhere();
  assert.equal(CCScope.bbox(), null);
});

test('bestOfRegionParam: only a single named region qualifies', () => {
  boot();
  CCScope.setRegion('wallonia');
  assert.equal(CCScope.bestOfRegionParam(), 1);
  CCScope.setCountry('BE');
  assert.equal(CCScope.bestOfRegionParam(), null);
  CCScope.setEverywhere();
  assert.equal(CCScope.bestOfRegionParam(), null);
});

test('photonParams: scoped bbox + lowercase country gate; everywhere drops both', () => {
  boot();
  CCScope.setRegion('flanders');
  assert.deepEqual(CCScope.photonParams(), { bbox: [2.54, 50.68, 5.92, 51.51], countrycode: 'be' });
  CCScope.setEverywhere();
  assert.deepEqual(CCScope.photonParams(), { bbox: null, countrycode: null });
});

test('set persists slug-serialized to storage + URL; persist:false leaves both alone', () => {
  boot();
  CCScope.setRegion('flanders');
  assert.equal(store.get('cc-scope'), 'region:flanders');
  assert.match(replacedUrls[replacedUrls.length - 1], /scope=region%3Aflanders/);
  const persisted = store.get('cc-scope');
  const urlWrites = replacedUrls.length;
  // The deep-link transient widen (map.js) must never clobber the saved scope.
  CCScope.set({ kind: 'everywhere', regionIds: [], countryCode: null }, { persist: false });
  assert.equal(CCScope.get().kind, 'everywhere');
  assert.equal(store.get('cc-scope'), persisted);
  assert.equal(replacedUrls.length, urlWrites);
});

test('every applied set emits cc:scopechange with a detached detail copy', () => {
  boot();
  CCScope.setRegion('brussels');
  const e = events[events.length - 1];
  assert.equal(e.type, 'cc:scopechange');
  assert.deepEqual(e.detail, { kind: 'region', regionIds: [23], countryCode: 'BE' });
  e.detail.regionIds.push(999);
  assert.deepEqual(CCScope.get().regionIds, [23]);
});

test('regions() resolves the active scope to registry objects', () => {
  boot();
  CCScope.setCountry('BE');
  assert.deepEqual(CCScope.regions().map((r) => r.slug), ['wallonia', 'flanders', 'brussels']);
});
