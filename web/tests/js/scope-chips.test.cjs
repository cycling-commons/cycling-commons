// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// Node smoke tests for the scope-chip VIEW model (web/assets/map/scope-chips.js).
//
// These PIN TODAY'S BEHAVIOUR. Every expectation below was computed from the real
// scope.js maths against the real bboxes of all 31 onboarded regions, and each one
// that has a browser-verified counterpart in the 2026-07-23 run ledger agrees with
// it. The extraction is behaviour-preserving, so a failure here means the refactor
// moved something — not that the expectation is stale.
//
// scope.js is required as the REAL scopeApi rather than stubbed, so the ranking and
// compass maths are exercised end to end. Same browser-global mocks as scope.test.cjs.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const store = new Map();
globalThis.localStorage = {
  getItem: (k) => (store.has(k) ? store.get(k) : null),
  setItem: (k, v) => { store.set(k, String(v)); },
  removeItem: (k) => { store.delete(k); },
};
globalThis.window = { dispatchEvent: () => {} };
globalThis.CustomEvent = globalThis.CustomEvent
  || class CustomEvent { constructor(type, opts) { this.type = type; this.detail = opts && opts.detail; } };
globalThis.location = { href: 'http://localhost/map', search: '' };
globalThis.history = { replaceState: () => {} };

const CCScope = require('../../assets/map/scope.js');
const { chipModel } = require('../../assets/map/scope-chips.js');

// Real bboxes of every onboarded region (BE 3, NL 12, DE 16), computed from
// tools/divisions/out/region-*.geojson. Ranking and bearing are pure functions of
// these numbers, so synthetic boxes would pin nothing worth pinning.
const RAW = [
  ['DE', 'baden-wurttemberg', [7.5117, 47.5324, 10.4956, 49.7913]],
  ['DE', 'bayern', [8.9764, 47.2701, 13.8396, 50.5647]],
  ['DE', 'berlin', [13.0883, 52.3382, 13.7612, 52.6755]],
  ['DE', 'brandenburg', [11.2658, 51.3591, 14.7658, 53.5591]],
  ['DE', 'bremen', [8.4816, 53.0112, 8.9908, 53.6102]],
  ['BE', 'brussels', [4.2438, 50.7637, 4.4823, 50.9139]],
  ['NL', 'drenthe', [6.1198, 52.6122, 7.0927, 53.2038]],
  ['BE', 'flanders', [2.5414, 50.6874, 5.9111, 51.5051]],
  ['NL', 'flevoland', [5.0604, 52.2495, 6.0173, 52.844]],
  ['NL', 'friesland', [4.8492, 52.7648, 6.4276, 53.5146]],
  ['NL', 'gelderland', [4.9939, 51.7336, 6.8328, 52.522]],
  ['NL', 'groningen', [6.1674, 52.8382, 7.2275, 53.5764]],
  ['DE', 'hamburg', [8.4206, 53.3951, 10.3253, 53.9646]],
  ['DE', 'hessen', [7.7725, 49.3953, 10.2364, 51.6578]],
  ['NL', 'limburg-nl', [5.566, 50.7504, 6.2268, 51.7786]],
  ['DE', 'mecklenburg-vorpommern', [10.5939, 53.1104, 14.4122, 54.6851]],
  ['DE', 'niedersachsen', [6.6435, 51.2951, 11.5981, 53.8922]],
  ['NL', 'noord-brabant', [4.1901, 51.2209, 6.0481, 51.8308]],
  ['NL', 'noord-holland', [4.4937, 52.1659, 5.3773, 53.1894]],
  ['DE', 'nordrhein-westfalen', [5.8663, 50.3227, 9.4617, 52.5315]],
  ['NL', 'overijssel', [5.7778, 52.1181, 7.0728, 52.8542]],
  ['DE', 'rheinland-pfalz', [6.1123, 48.9664, 8.5083, 50.9423]],
  ['DE', 'saarland', [6.3558, 49.112, 7.4048, 49.6394]],
  ['DE', 'sachsen-anhalt', [10.5608, 50.9379, 13.1868, 53.0418]],
  ['DE', 'sachsen', [11.8723, 50.1713, 15.0419, 51.6851]],
  ['DE', 'schleswig-holstein', [7.8648, 53.3598, 11.3127, 55.0586]],
  ['DE', 'thuringen', [9.877, 50.2043, 12.6539, 51.6493]],
  ['NL', 'utrecht', [4.792, 51.8574, 5.6273, 52.3036]],
  ['BE', 'wallonia', [2.842, 49.497, 6.4081, 50.8121]],
  ['NL', 'zeeland', [3.3584, 51.2002, 4.2774, 51.7738]],
  ['NL', 'zuid-holland', [3.7737, 51.6438, 5.0314, 52.3325]],
];
const COUNTRY_LABEL = { BE: 'All Belgium', DE: 'All Germany', NL: 'All Netherlands' };
// label === slug keeps the assertions readable; the label->slug FALLBACK is covered
// separately by the stub test at the bottom.
const REGIONS = RAW.map(([countryCode, slug, bbox], i) => ({
  id: i + 1, slug, countryCode, bbox, label: slug, countryLabel: COUNTRY_LABEL[countryCode],
}));

const bySlug = (s) => REGIONS.find((r) => r.slug === s);
CCScope.init(REGIONS, { kind: 'region', regionIds: [bySlug('wallonia').id], countryCode: 'BE' });

const centreOf = (r) => [(r.bbox[0] + r.bbox[2]) / 2, (r.bbox[1] + r.bbox[3]) / 2];
const slugsOf = (m) => m.rows.reduce((a, row) => a.concat(row.map((c) => c.slug)), []);

// A region scope, laid out the way map.js hands it over: scopeCenter is the region's
// own bbox centre (CCScope.scopeCenter()), NOT the map centre.
function compassFor(slug, extra) {
  const r = bySlug(slug);
  return chipModel(Object.assign({
    scope: { kind: 'region', regionIds: [r.id], countryCode: r.countryCode },
    isDefault: false,
    activeRegions: [r],
    registry: REGIONS,
    inferredCountry: null,
    scopeCenter: centreOf(r),
    mapCenter: [0, 0],
    myArea: null,
  }, extra || {}), CCScope);
}

// ---- country resolution -----------------------------------------------------

test('no country resolvable -> label-sorted country rungs, everything else empty', () => {
  const m = chipModel({
    scope: { kind: 'everywhere', regionIds: [], countryCode: null },
    isDefault: false, activeRegions: [], registry: REGIONS,
    inferredCountry: null, scopeCenter: null, mapCenter: [5, 52], myArea: null,
  }, CCScope);
  assert.equal(m.mode, 'countries');
  assert.deepEqual(m.countries.map((c) => c.label), ['All Belgium', 'All Germany', 'All Netherlands']);
  assert.deepEqual(m.countries.map((c) => c.cc), ['BE', 'DE', 'NL']);
  assert.equal(m.country, null);
  assert.deepEqual(m.chips, []);
  assert.deepEqual(m.rows, []);
  assert.deepEqual(m.overflow, []);
  assert.equal(m.more, false);
});

test('isDefault: the inferred home country beats the startup default country', () => {
  // The regression behind owner fix 1 (2026-07-23): map.js's _defaultScope always
  // carries a countryCode, so before isDefault() a German visitor got Belgian chips.
  const w = bySlug('wallonia');
  const m = chipModel({
    scope: { kind: 'region', regionIds: [w.id], countryCode: 'BE' },
    isDefault: true, activeRegions: [w], registry: REGIONS,
    inferredCountry: 'DE', scopeCenter: null, mapCenter: [10.45, 51.33], myArea: null,
  }, CCScope);
  assert.equal(m.country.cc, 'DE');
  assert.equal(m.country.label, 'All Germany');
});

test('once the rider has chosen, the scope country beats the inferred home', () => {
  const w = bySlug('wallonia');
  const m = chipModel({
    scope: { kind: 'region', regionIds: [w.id], countryCode: 'BE' },
    isDefault: false, activeRegions: [w], registry: REGIONS,
    inferredCountry: 'DE', scopeCenter: null, mapCenter: [10.45, 51.33], myArea: null,
  }, CCScope);
  assert.equal(m.country.cc, 'BE');
});

// ---- linear mode ------------------------------------------------------------

test('linear: Belgium shows all 3 regions and NO More chip', () => {
  const m = chipModel({
    scope: { kind: 'country', regionIds: [], countryCode: 'BE' },
    isDefault: false, activeRegions: [], registry: REGIONS,
    inferredCountry: null, scopeCenter: null, mapCenter: [4.36, 50.84], myArea: null,
  }, CCScope);
  assert.equal(m.mode, 'linear');
  assert.deepEqual(m.chips.map((c) => c.slug), ['brussels', 'flanders', 'wallonia']);
  assert.equal(m.more, false);
  assert.deepEqual(m.country, { cc: 'BE', label: 'All Belgium' });
  assert.deepEqual(m.rows, []);
});

test('linear: Germany caps at 8 of 16 and DOES show the More chip', () => {
  // The dead-overflow bug (fixed 61df4ea): `more` must come from the UNCAPPED total.
  // The set below is also the Duisburg baseline the owner flagged — Hamburg and
  // Thueringen are offered while Dutch Limburg, 63 km away, cannot appear at all,
  // because contextualRegions is country-scoped by construction. Cross-border chips
  // will change this expectation DELIBERATELY; until then it is the contract.
  const m = chipModel({
    scope: { kind: 'country', regionIds: [], countryCode: 'DE' },
    isDefault: false, activeRegions: [], registry: REGIONS,
    inferredCountry: null, scopeCenter: null, mapCenter: [6.76, 51.43], myArea: null,
  }, CCScope);
  assert.deepEqual(m.chips.map((c) => c.slug), [
    'nordrhein-westfalen', 'rheinland-pfalz', 'hessen', 'niedersachsen',
    'saarland', 'bremen', 'hamburg', 'thuringen',
  ]);
  assert.equal(m.more, true);
});

test('linear: a My-area base outranks the map centre as the anchor', () => {
  const base = {
    scope: { kind: 'country', regionIds: [], countryCode: 'NL' },
    isDefault: false, activeRegions: [], registry: REGIONS,
    inferredCountry: null, scopeCenter: null, mapCenter: [4.90, 52.37], myArea: null,
  };
  assert.equal(chipModel(base, CCScope).chips[0].slug, 'noord-holland');
  const withBase = Object.assign({}, base, { myArea: { lat: 50.85, lng: 5.69 } });
  assert.equal(chipModel(withBase, CCScope).chips[0].slug, 'limburg-nl');
});

test('linear: an Everywhere scope has no centre, so the map centre is the anchor', () => {
  const m = chipModel({
    scope: { kind: 'everywhere', regionIds: [], countryCode: null },
    isDefault: false, activeRegions: [], registry: REGIONS,
    inferredCountry: 'NL', scopeCenter: null, mapCenter: [4.90, 52.37], myArea: null,
  }, CCScope);
  assert.equal(m.mode, 'linear');
  assert.equal(m.chips[0].slug, 'noord-holland');
});

// ---- compass mode -----------------------------------------------------------

test('compass: Utrecht offers its true neighbours, not the far north-east', () => {
  // The anchor bug (fixed eeb9b36): ranking from map.getCenter() used the OUTGOING
  // scope, so Utrecht was ranked against Germany's centroid and offered
  // Drenthe/Groningen/Friesland over adjacent Noord-Holland/Zuid-Holland.
  const m = compassFor('utrecht');
  assert.equal(m.mode, 'compass');
  assert.equal(m.rows.length, 3);
  const shown = slugsOf(m);
  ['groningen', 'drenthe', 'friesland'].forEach((s) => {
    assert.ok(!shown.includes(s), `${s} is far NE and must not be offered from Utrecht`);
  });
  assert.deepEqual(m.rows[0].map((c) => c.slug), [null, 'noord-holland', 'flevoland']);
  assert.deepEqual(m.rows[1].map((c) => c.slug), ['zuid-holland', 'utrecht', 'gelderland']);
  assert.deepEqual(m.rows[2].map((c) => c.slug), ['zeeland', 'noord-brabant', 'limburg-nl']);
  assert.deepEqual(m.overflow.map((r) => r.slug), ['overijssel']);
  assert.equal(m.more, true);
});

test('compass: Flanders drops the whole empty N row so the grid top-aligns', () => {
  // Brussels and Wallonia both lie south, so the N row is entirely vacant. Empty
  // cells are visibility:hidden and still reserve height, so a vacant row would
  // leave a band of dead space above the grid (fixed 2ec83c6).
  const m = compassFor('flanders');
  assert.equal(m.rows.length, 2, 'the vacant N row must be dropped, not rendered empty');
  assert.equal(m.rows[0][1].kind, 'center');
  assert.equal(m.rows[0][1].slug, 'flanders');
  assert.deepEqual(m.rows[1].map((c) => c.slug), [null, 'brussels', 'wallonia']);
  assert.deepEqual(m.overflow, []);
  assert.equal(m.more, false);
});

test('compass: Groningen also drops its N row and overflows five regions', () => {
  const m = compassFor('groningen');
  assert.equal(m.rows.length, 2);
  assert.deepEqual(m.overflow.map((r) => r.slug),
    ['flevoland', 'gelderland', 'noord-holland', 'utrecht', 'zuid-holland']);
});

test('compass: Bavaria is a corner region, so far cells stay empty and overflow fills', () => {
  // compassLayout caps displacement at one slot rather than lying about a bearing.
  const m = compassFor('bayern');
  assert.equal(m.rows.length, 3);
  assert.deepEqual(m.overflow.map((r) => r.slug),
    ['saarland', 'sachsen-anhalt', 'nordrhein-westfalen']);
  assert.equal(m.more, true);
  assert.deepEqual(m.country, { cc: 'DE', label: 'All Germany' });
});

test('compass: NRW fills all three rows', () => {
  const m = compassFor('nordrhein-westfalen');
  assert.deepEqual(m.rows[0].map((c) => c.slug), [null, 'bremen', 'niedersachsen']);
  assert.deepEqual(m.rows[1].map((c) => c.slug), [null, 'nordrhein-westfalen', 'thuringen']);
  assert.deepEqual(m.rows[2].map((c) => c.slug), ['saarland', 'rheinland-pfalz', 'hessen']);
  assert.deepEqual(m.overflow.map((r) => r.slug), ['hamburg', 'sachsen-anhalt']);
});

test('compass: ranking ignores the My-area base (preserved asymmetry, map.js:296)', () => {
  // Linear mode prefers the rider's base; compass mode anchors on the scope centre.
  // That asymmetry is TODAY'S behaviour and this extraction keeps it. Pinned so the
  // cross-border change has to be deliberate about it rather than silently altering it.
  const plain = compassFor('utrecht');
  const based = compassFor('utrecht', { myArea: { lat: 50.85, lng: 5.69 } });
  assert.deepEqual(slugsOf(based), slugsOf(plain));
});

// ---- shape contract ---------------------------------------------------------

test('every cell carries the full contract, with nulls rather than undefined', () => {
  const m = compassFor('utrecht');
  const DIRS = ['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw'];
  m.rows.forEach((row) => {
    assert.equal(row.length, 3);
    row.forEach((cell) => {
      assert.ok(['center', 'region', 'empty'].includes(cell.kind), 'unknown cell kind');
      ['slug', 'label', 'dir'].forEach((k) => assert.ok(k in cell, `cell missing ${k}`));
      if (cell.kind === 'empty') {
        assert.equal(cell.slug, null); assert.equal(cell.label, null); assert.equal(cell.dir, null);
      }
      if (cell.kind === 'center') { assert.equal(cell.dir, null); assert.equal(cell.slug, 'utrecht'); }
      if (cell.kind === 'region') { assert.ok(DIRS.includes(cell.dir), `bad dir ${cell.dir}`); }
    });
  });
});

test('label falls back to slug, and the country rung falls back to "All <cc>"', () => {
  // Injected stub rather than the real registry: no onboarded region is missing a
  // label or a countryLabel, so this path is unreachable through CCScope.
  const stub = {
    contextualRegions: () => [{ id: 99, slug: 'no-label', countryCode: 'XX', bbox: [0, 0, 1, 1] }],
    compassLayout: () => ({ n: null, ne: null, e: null, se: null, s: null, sw: null, w: null, nw: null, overflow: [] }),
  };
  const m = chipModel({
    scope: { kind: 'country', regionIds: [], countryCode: 'XX' },
    isDefault: false, activeRegions: [], registry: REGIONS,
    inferredCountry: null, scopeCenter: null, mapCenter: [0, 0], myArea: null,
  }, stub);
  assert.equal(m.chips[0].label, 'no-label');
  assert.deepEqual(m.country, { cc: 'XX', label: 'All XX' });
});
