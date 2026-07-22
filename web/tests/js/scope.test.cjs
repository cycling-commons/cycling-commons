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

// A registry variant that ALSO covers a non-BE country, for the myArea widen
// "ambiguous -> everywhere" case (both derived ccs present in the registry).
const REGIONS_NL = REGIONS.concat([
  { id: 50, slug: 'holland', countryCode: 'NL', bbox: [4.0, 51.0, 6.0, 53.0] },
]);

// Fresh-page init: set the URL + storage a browser would arrive with, wipe the
// event/persist capture and window.CC_MY_AREA (Task 6 payload) + the anon
// circle's localStorage entry, then re-run init exactly like the map page does.
function boot({ search = '', ls = null, myArea = null, defaultScope = DEFAULT, areaLs = null, regionsList = REGIONS } = {}) {
  globalThis.location = { href: `http://localhost/map${search}`, search };
  store.clear();
  if (ls !== null) store.set('cc-scope', ls);
  if (areaLs !== null) store.set('cc-my-area', areaLs);
  globalThis.window.CC_MY_AREA = myArea;
  events = [];
  replacedUrls = [];
  return CCScope.init(regionsList, defaultScope);
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

test('coverageParams: region sends sorted rids only; country adds cc; everywhere sends neither', () => {
  boot();
  // A single named region: its id only, no cc (region is narrower than country).
  CCScope.setRegion('flanders');
  assert.deepEqual(CCScope.coverageParams(), { rids: [24], cc: null });
  // A country scope: all its region ids (SORTED for a stable cache key) AND cc,
  // so the server's OR arm also catches unsplit rows (region_id NULL, cc set).
  CCScope.setCountry('BE');
  assert.deepEqual(CCScope.coverageParams(), { rids: [1, 23, 24], cc: 'BE' });
  // Everywhere: no params, so the URL + shared HTTP-cache key stay scope-free.
  CCScope.setEverywhere();
  assert.deepEqual(CCScope.coverageParams(), { rids: null, cc: null });
});

// A tiny MapLibre-expression evaluator for the subset coverageTileFilter uses
// (any/all/==/in/coalesce/get), so the filter can be asserted against real
// feature props — the map.js hazard/coverage scope logic that had zero tests.
function evalExpr(e, props) {
  if (!Array.isArray(e)) return e;
  const [op, ...a] = e;
  switch (op) {
    case 'get': return props[a[0]];
    case 'coalesce': { for (const x of a) { const v = evalExpr(x, props); if (v !== undefined && v !== null) return v; } return null; }
    case '==': return evalExpr(a[0], props) === evalExpr(a[1], props);
    case 'in': { const needle = evalExpr(a[0], props), hay = evalExpr(a[1], props); return typeof hay === 'string' ? hay.includes(needle) : false; }
    case 'all': return a.every((x) => evalExpr(x, props));
    case 'any': return a.some((x) => evalExpr(x, props));
    default: throw new Error('unhandled op ' + op);
  }
}
const shows = (filter, props) => filter === null ? true : evalExpr(filter, props);

test('coverageTileFilter: Everywhere returns null (no filter)', () => {
  boot();
  CCScope.setEverywhere();
  assert.equal(CCScope.coverageTileFilter(), null);
});

test('coverageTileFilter: region scope shows in-region tokens, hides others, renders prop-less', () => {
  boot();
  CCScope.setRegion('wallonia');   // regionIds [1]
  const f = CCScope.coverageTileFilter();
  assert.equal(shows(f, { ridtok: '|1|', cctok: '|BE|' }), true, 'in-region icon shows');
  assert.equal(shows(f, { ridtok: '|24|', cctok: '|BE|' }), false, 'out-of-region icon hides');
  // A cluster bubble whose UNIONed ridtok includes region 1 shows (finding 2).
  assert.equal(shows(f, { ridtok: '|24||1||24|', cctok: '|BE|' }), true, 'bubble with a region-1 member shows');
  assert.equal(shows(f, { ridtok: '|24||23|', cctok: '|BE|' }), false, 'bubble with no region-1 member hides');
  // Prop-less (both tokens empty) renders — the weekly-rebuild fallback.
  assert.equal(shows(f, { ridtok: '', cctok: '' }), true, 'prop-less renders');
  // cc-bearing rid-less row hides under a REGION scope (finding 5).
  assert.equal(shows(f, { ridtok: '', cctok: '|BE|' }), false, 'cc-only row hides under region scope');
  // No false positive: region 1 must not match region 21's token.
  assert.equal(shows(f, { ridtok: '|21|', cctok: '|BE|' }), false, 'delimiters prevent |1| matching |21|');
});

test('coverageTileFilter: country scope admits cc-bearing rid-less rows (finding 5)', () => {
  boot();
  CCScope.setCountry('BE');   // regionIds [1,24,23], cc BE
  const f = CCScope.coverageTileFilter();
  assert.equal(shows(f, { ridtok: '|24|', cctok: '|BE|' }), true, 'stamped BE row shows');
  assert.equal(shows(f, { ridtok: '', cctok: '|BE|' }), true, 'cc-only BE row shows under country scope');
  assert.equal(shows(f, { ridtok: '', cctok: '|NL|' }), false, 'a foreign cc row hides');
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

// ---- myArea kind (Phase 4, region-scoping-design.md §9.1) -----------------

test('myArea resolves from CC_MY_AREA and serializes as bare token', () => {
  boot({ myArea: { lat: 50.45, lng: 4.85, radiusKm: 40, place: 'Namur', regionIds: [1, 24], countryCodes: ['BE'] } });
  CCScope.setMyArea();
  const s = CCScope.get();
  assert.equal(s.kind, 'myArea');
  assert.deepEqual(s.regionIds, [1, 24]);
  assert.match(replacedUrls.at(-1), /scope=myarea/);
  assert.doesNotMatch(replacedUrls.at(-1), /50\.4|4\.8/); // no coords in URL, ever
  assert.equal(store.get('cc-scope'), 'myarea'); // no coords in the 'cc-scope' LS entry either
});

test('myArea default beats localStorage named scope; URL still wins', () => {
  const myArea = { lat: 50.45, lng: 4.85, radiusKm: 40, place: 'Namur', regionIds: [1, 24], countryCodes: ['BE'] };
  const s1 = boot({ ls: 'region:flanders', myArea, defaultScope: { kind: 'myArea' } });
  assert.equal(s1.kind, 'myArea');
  assert.deepEqual(s1.regionIds, [1, 24]);

  const s2 = boot({ search: '?scope=region:flanders', ls: 'region:flanders', myArea, defaultScope: { kind: 'myArea' } });
  assert.deepEqual(s2, { kind: 'region', regionIds: [24], countryCode: 'BE' });
});

test('anonymous circle derives ids from registry bboxes, capped', () => {
  boot();
  // A circle centred near Brussels overlaps both the Wallonia and Flanders bboxes.
  CCScope.setAnonCircle(50.8467, 4.3499, 50);
  const s = CCScope.get();
  assert.equal(s.kind, 'myArea');
  assert.ok(s.regionIds.includes(1), 'wallonia derived');
  assert.ok(s.regionIds.includes(24), 'flanders derived');
  assert.ok(s.regionIds.length <= 8, 'capped at 8');
  assert.equal(s.myArea.anon, true);
  assert.equal(s.myArea.place, null);
});

test('myArea widen goes to single country then everywhere', () => {
  // Exactly one derived country present in the registry -> widen to it.
  boot({ myArea: { lat: 50.45, lng: 4.85, radiusKm: 40, regionIds: [1, 24], countryCodes: ['BE'] } });
  CCScope.setMyArea();
  assert.deepEqual(CCScope.nextWider(), { kind: 'country', regionIds: [1, 24, 23], countryCode: 'BE' });
  assert.deepEqual(CCScope.widen(), { kind: 'country', regionIds: [1, 24, 23], countryCode: 'BE' });

  // A second cc absent from the registry doesn't change the "exactly one" outcome.
  boot({ myArea: { lat: 50.45, lng: 4.85, radiusKm: 40, regionIds: [1, 24], countryCodes: ['BE', 'NL'] } });
  CCScope.setMyArea();
  assert.deepEqual(CCScope.nextWider(), { kind: 'country', regionIds: [1, 24, 23], countryCode: 'BE' });

  // Both derived ccs present in the registry -> ambiguous -> everywhere.
  boot({ myArea: { lat: 50.45, lng: 4.85, radiusKm: 40, regionIds: [1, 24], countryCodes: ['BE', 'NL'] }, regionsList: REGIONS_NL });
  CCScope.setMyArea();
  assert.deepEqual(CCScope.nextWider(), { kind: 'everywhere', regionIds: [], countryCode: null });
  assert.deepEqual(CCScope.widen(), { kind: 'everywhere', regionIds: [], countryCode: null });
  assert.equal(CCScope.canWiden(), false);
});

test('myArea photonParams has circle bbox and NO countrycode gate', () => {
  boot({ myArea: { lat: 50.45, lng: 4.85, radiusKm: 40, regionIds: [1, 24], countryCodes: ['BE'] } });
  CCScope.setMyArea();
  const p = CCScope.photonParams();
  assert.equal(p.countrycode, null);
  assert.deepEqual(p.bbox, CCScope.bbox());
  const b = p.bbox;
  assert.ok(b[0] < 4.85 && b[2] > 4.85 && b[1] < 50.45 && b[3] > 50.45, 'bbox is the circle, not a region union');
});

test('myArea coverageParams is rid-only', () => {
  boot({ myArea: { lat: 50.45, lng: 4.85, radiusKm: 40, regionIds: [24, 1], countryCodes: ['BE'] } });
  CCScope.setMyArea();
  assert.deepEqual(CCScope.coverageParams(), { rids: [1, 24], cc: null });
});

test('coverageTileFilter: myArea scope has a ridtok arm only, no cctok arm', () => {
  boot({ myArea: { lat: 50.45, lng: 4.85, radiusKm: 40, regionIds: [1], countryCodes: ['BE'] } });
  CCScope.setMyArea();
  const f = CCScope.coverageTileFilter();
  assert.equal(shows(f, { ridtok: '|1|', cctok: '|BE|' }), true, 'in-region icon shows');
  assert.equal(shows(f, { ridtok: '|24|', cctok: '|BE|' }), false, 'out-of-region icon hides');
  assert.equal(shows(f, { ridtok: '', cctok: '|BE|' }), false, 'cc-only row hides under myArea scope (rid-only)');
  assert.equal(shows(f, { ridtok: '', cctok: '' }), true, 'prop-less renders');
});

test('coverageParams: myArea with zero derived regions returns the empty-set sentinel (map-and-search.md §4.5)', () => {
  // All stale ids -> the derived regionIds set is empty, but this is still a
  // myArea scope ("in scope: nothing"), not Everywhere ("no scope").
  boot({ myArea: { lat: 50.45, lng: 4.85, radiusKm: 40, regionIds: [999], countryCodes: ['BE'] } });
  CCScope.setMyArea();
  assert.deepEqual(CCScope.get().regionIds, []);
  // rids:[] (an array) is distinct from Everywhere's rids:null — callers
  // (map.js fetchCoverageCounts/runCoverageSearch) use this to skip a fetch
  // that would otherwise silently fall back to GLOBAL results.
  assert.deepEqual(CCScope.coverageParams(), { rids: [], cc: null });
});

test('coverageTileFilter: myArea with zero derived regions still renders only prop-less rows', () => {
  boot({ myArea: { lat: 50.45, lng: 4.85, radiusKm: 40, regionIds: [999], countryCodes: ['BE'] } });
  CCScope.setMyArea();
  const f = CCScope.coverageTileFilter();
  assert.equal(shows(f, { ridtok: '', cctok: '' }), true, 'prop-less still renders (weekly-rebuild fallback)');
  assert.equal(shows(f, { ridtok: '|1|', cctok: '|BE|' }), false, 'a stamped row has nothing to match, hides');
  assert.equal(shows(f, { ridtok: '', cctok: '|BE|' }), false, 'cc-only row hides too (myArea has no cc arm)');
});

test('stale CC_MY_AREA region ids are dropped against the registry', () => {
  boot({ myArea: { lat: 50.45, lng: 4.85, radiusKm: 40, regionIds: [1, 999], countryCodes: ['BE'] } });
  CCScope.setMyArea();
  const s = CCScope.get();
  assert.equal(s.kind, 'myArea');
  assert.deepEqual(s.regionIds, [1]);
});

test('scope=myarea in URL without any source falls back', () => {
  assert.deepEqual(boot({ search: '?scope=myarea' }), DEFAULT);
});

test('myAreaAvailable reflects CC_MY_AREA / anon-circle presence', () => {
  boot();
  assert.equal(CCScope.myAreaAvailable(), false);
  boot({ myArea: { lat: 50.45, lng: 4.85, radiusKm: 40, regionIds: [1], countryCodes: ['BE'] } });
  assert.equal(CCScope.myAreaAvailable(), true);
  boot();
  CCScope.setAnonCircle(50.45, 4.85, 40);
  assert.equal(CCScope.myAreaAvailable(), true);
});

test('setAnonCircle rounds to 2 decimals, sets myArea scope, and marks anon:true', () => {
  boot();
  CCScope.setAnonCircle(50.456789, 4.851234, 40);
  const raw = JSON.parse(store.get('cc-my-area'));
  assert.deepEqual(raw, { lat: 50.46, lng: 4.85, radiusKm: 40 });
  const s = CCScope.get();
  assert.equal(s.kind, 'myArea');
  assert.equal(s.myArea.anon, true);
  assert.equal(s.myArea.place, null);
});

test('clearAnonCircle removes the stored circle; myArea becomes unavailable', () => {
  boot();
  CCScope.setAnonCircle(50.45, 4.85, 40);
  assert.equal(CCScope.myAreaAvailable(), true);
  CCScope.clearAnonCircle();
  assert.equal(store.get('cc-my-area'), undefined);
  assert.equal(CCScope.myAreaAvailable(), false);
});

test('bestOfRegionIds: single named region, myArea derived set, else null', () => {
  boot();
  CCScope.setRegion('wallonia');
  assert.deepEqual(CCScope.bestOfRegionIds(), [1]);
  CCScope.setCountry('BE');
  assert.equal(CCScope.bestOfRegionIds(), null);
  CCScope.setEverywhere();
  assert.equal(CCScope.bestOfRegionIds(), null);

  boot({ myArea: { lat: 50.45, lng: 4.85, radiusKm: 40, regionIds: [24, 1], countryCodes: ['BE'] } });
  CCScope.setMyArea();
  assert.deepEqual(CCScope.bestOfRegionIds(), [1, 24]);
});

// ---- inferHomeCountry (Task 2, 2026-07-22-scope-selector-scale-design.md §D) -
//
// Each of these tests needs its OWN registry (a different country mix than the
// shared BE fixture above), so they run against a fully isolated module
// instance rather than the shared `CCScope`/`boot()` used everywhere else:
// fresh window/localStorage mocks + a cache-busted re-require, so no state
// (registry, scope, __ccTz) leaks between them or back into the tests above.
function freshScope(regionsList) {
  const freshStore = new Map();
  globalThis.localStorage = {
    getItem: (k) => (freshStore.has(k) ? freshStore.get(k) : null),
    setItem: (k, v) => { freshStore.set(k, String(v)); },
    removeItem: (k) => { freshStore.delete(k); },
  };
  globalThis.window = { dispatchEvent: () => {} };
  globalThis.location = { href: 'http://localhost/map', search: '' };
  globalThis.history = { replaceState: () => {} };
  delete globalThis.__ccTz;
  const modPath = require.resolve('../../assets/map/scope.js');
  delete require.cache[modPath];
  const S = require(modPath);
  S.init(regionsList);
  return S;
}

// Renamed from the brief's literal working title ('My-area country wins over
// timezone') — its own assertions don't test precedence: countryCodes:['BE']
// against a DE-only registry means BE is NOT onboarded, so the My-area branch
// falls through and this actually exercises the timezone fallthrough. The
// real precedence case is the next test.
test('inferHomeCountry: falls through to timezone when the My-area country is not onboarded', () => {
  const S = freshScope([{ id: 1, slug: 'bayern', countryCode: 'DE', bbox: [10, 48, 12, 50] }]);
  globalThis.window.CC_MY_AREA = { lat: 50.8, lng: 4.3, radiusKm: 40, countryCodes: ['BE'] };
  // BE not in this registry -> falls through; DE via a DE timezone
  globalThis.__ccTz = 'Europe/Berlin';
  assert.equal(S.inferHomeCountry(), 'DE'); // BE not onboarded here, tz DE is
});

test('inferHomeCountry: My-area country wins over timezone when both are onboarded', () => {
  const S = freshScope([
    { id: 1, slug: 'bayern', countryCode: 'DE', bbox: [10, 48, 12, 50] },
    { id: 2, slug: 'wallonia', countryCode: 'BE', bbox: [2.84, 49.45, 6.41, 50.85] },
  ]);
  globalThis.window.CC_MY_AREA = { lat: 50.8, lng: 4.3, radiusKm: 40, countryCodes: ['BE'] };
  // DE is ALSO onboarded here (unlike the test above) -> My-area must still win.
  globalThis.__ccTz = 'Europe/Berlin';
  assert.equal(S.inferHomeCountry(), 'BE');
});

test('inferHomeCountry: anonymous circle country wins over timezone', () => {
  const S = freshScope([
    { id: 1, slug: 'bayern', countryCode: 'DE', bbox: [10, 48, 12, 50] },
    { id: 2, slug: 'noord-holland', countryCode: 'NL', bbox: [4, 52, 5, 53] },
  ]);
  // No CC_MY_AREA payload -> falls to the anon circle, centred inside the NL bbox.
  globalThis.localStorage.setItem('cc-my-area', JSON.stringify({ lat: 52.5, lng: 4.5, radiusKm: 40 }));
  globalThis.__ccTz = 'Europe/Berlin'; // DE also onboarded, but the anon circle wins
  assert.equal(S.inferHomeCountry(), 'NL');
});

test('inferHomeCountry: timezone maps to onboarded country', () => {
  const S = freshScope([{ id: 1, slug: 'noord-holland', countryCode: 'NL', bbox: [4, 52, 5, 53] }]);
  delete globalThis.window.CC_MY_AREA;
  globalThis.__ccTz = 'Europe/Amsterdam';
  assert.equal(S.inferHomeCountry(), 'NL');
});

test('inferHomeCountry: unknown/none -> null', () => {
  const S = freshScope([{ id: 1, slug: 'bayern', countryCode: 'DE', bbox: [10, 48, 12, 50] }]);
  delete globalThis.window.CC_MY_AREA;
  globalThis.__ccTz = 'America/New_York'; // US not onboarded
  assert.equal(S.inferHomeCountry(), null);
});

// ---- searchScopes (Task 3, 2026-07-22-scope-selector-scale-design.md §A) --

test('searchScopes: matches region by label and slug', () => {
  const S = freshScope([
    { id: 1, slug: 'bayern', countryCode: 'DE', bbox: [10, 48, 12, 50], label: 'Bavaria', countryLabel: 'All Germany' },
    { id: 2, slug: 'wallonia', countryCode: 'BE', bbox: [4, 49, 6, 51], label: 'Wallonia', countryLabel: 'All Belgium' },
  ]);
  assert.deepEqual(S.searchScopes('bav').map((r) => r.slug), ['bayern']); // label prefix
  assert.deepEqual(S.searchScopes('bayern').map((r) => r.slug), ['bayern']); // slug
  assert.equal(S.searchScopes('  ').length, 0); // blank -> none
});

test('searchScopes: country rung ranks above its regions on a country hit', () => {
  const S = freshScope([
    { id: 1, slug: 'bayern', countryCode: 'DE', bbox: [10, 48, 12, 50], label: 'Bavaria', countryLabel: 'All Germany' },
  ]);
  const r = S.searchScopes('germany');
  assert.equal(r[0].kind, 'country');
  assert.equal(r[0].cc, 'DE');
});

// The two tests above pass even under a REVERSED sort comparator: the first
// only ever has one match, and the second's single region ('bayern'/'Bavaria')
// never matches 'germany' at all, so there is nothing for the country rung to
// out-rank in practice — neither pins the ordering guarantee. This test
// forces a genuine 3-way tie/rank spread (country + a same-rank region "on a
// tie" + a lower-rank substring-only region) so a reversed or dropped -0.5
// nudge, or a reversed sort direction, actually fails the assertion.
test('searchScopes: full ranking — prefix before substring, country before its region on a tie, non-matches excluded', () => {
  const S = freshScope([
    { id: 1, slug: 'bayern', countryCode: 'DE', bbox: [10, 48, 12, 50], label: 'Bavaria', countryLabel: 'Germany' },
    // Ties the DE country rung at rank 0 (prefix) on the query below.
    { id: 2, slug: 'germany-alps', countryCode: 'DE', bbox: [10, 48, 12, 50], label: 'Germany Alps', countryLabel: 'Germany' },
    // Only a substring match (rank 1) -> must sort AFTER the rank-0 tier.
    { id: 3, slug: 'east-germany-trail', countryCode: 'DE', bbox: [10, 48, 12, 50], label: 'East Germany Trail', countryLabel: 'Germany' },
    // Unrelated country/region -> must not appear at all.
    { id: 4, slug: 'wallonia', countryCode: 'BE', bbox: [4, 49, 6, 51], label: 'Wallonia', countryLabel: 'All Belgium' },
  ]);
  const r = S.searchScopes('germany');
  assert.deepEqual(
    r.map((x) => `${x.kind}:${x.slug || x.cc}`),
    ['country:DE', 'region:germany-alps', 'region:east-germany-trail'],
  );
  for (const x of r) assert.equal('_s' in x, false, 'internal _s score must not leak');
});

test('searchScopes: limit caps the result count to the top-ranked entries', () => {
  const S = freshScope([
    { id: 1, slug: 'germany-alps', countryCode: 'DE', bbox: [10, 48, 12, 50], label: 'Germany Alps', countryLabel: 'Germany' },
    { id: 2, slug: 'east-germany-trail', countryCode: 'DE', bbox: [10, 48, 12, 50], label: 'East Germany Trail', countryLabel: 'Germany' },
  ]);
  const full = S.searchScopes('germany');
  assert.equal(full.length, 3); // country:DE + both regions
  const capped = S.searchScopes('germany', 1);
  assert.equal(capped.length, 1);
  assert.deepEqual(capped[0], full[0]); // the cap keeps the highest-ranked entry, not an arbitrary one
});

test('inferHomeCountry is compute-only: writes nothing to localStorage/URL/history (2026-07-22-scope-selector-scale-design.md §F rule 1)', () => {
  const S = freshScope([{ id: 1, slug: 'bayern', countryCode: 'DE', bbox: [10, 48, 12, 50] }]);
  globalThis.window.CC_MY_AREA = { lat: 50.8, lng: 4.3, radiusKm: 40, countryCodes: ['DE'] };
  globalThis.__ccTz = 'Europe/Berlin';
  let historyWrites = 0;
  globalThis.history.replaceState = () => { historyWrites += 1; };
  const before = new Map(freshStoreSnapshot());
  S.inferHomeCountry();
  assert.equal(historyWrites, 0);
  assert.deepEqual(new Map(freshStoreSnapshot()), before);

  function freshStoreSnapshot() {
    // globalThis.localStorage is the fresh mock from freshScope(); read back
    // via getItem for the one key this suite ever touches (cc-my-area) plus
    // the scope key, so a stray setItem anywhere would be caught.
    return [
      ['cc-scope', globalThis.localStorage.getItem('cc-scope')],
      ['cc-my-area', globalThis.localStorage.getItem('cc-my-area')],
    ];
  }
});
