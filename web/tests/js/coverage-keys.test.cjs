// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// Every bulk-OSM pool that HAS coverage tiles must have a coverage LAYER.
//
// catalog.js's LETTER_KEY is the list of letters served from the coverage
// tiles; coverage.js's COVERAGE_KEYS is the list the map actually builds icon
// layers for. If a letter is in the first and not the second, the rail still
// reads its count out of the database and draws nothing — "0 / 583" forever,
// at every zoom, in every country.
//
// That is not hypothetical. Public toilets (C, then lettered M) shipped as a category in the
// catalog, the rail, the contribute hub and the harvest — the tiles have
// carried its source-layer (c_<cc> today) for weeks — and COVERAGE_KEYS was never extended, so the
// layer did not exist. Nothing errored. The count was right. It was reported
// by somebody looking at Wallonia and wondering where the toilets were
// (2026-08-09).
//
// The modules cannot be require()d here: coverage.js imports map-init.js,
// which constructs MapLibre. So this reads the two declarations out of the
// source, which is enough — both are single-line literals and the point is to
// compare their contents, not to execute them.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ASSETS = path.join(__dirname, '..', '..', 'assets', 'map');
const read = f => fs.readFileSync(path.join(ASSETS, f), 'utf8');

/** `export const LETTER_KEY={B:'water',…}` -> { B: 'water', … } */
function letterKey() {
  const m = read('catalog.js').match(/export const LETTER_KEY\s*=\s*\{([^}]*)\}/);
  assert.ok(m, 'LETTER_KEY not found in catalog.js — did it move or change shape?');
  const out = {};
  for (const pair of m[1].matchAll(/([A-Z])\s*:\s*'([^']+)'/g)) out[pair[1]] = pair[2];
  return out;
}

/** `export const COVERAGE_KEYS=[['water','c'],…]` -> [['water','c'], …] */
function coverageKeys() {
  const m = read('coverage.js').match(/export const COVERAGE_KEYS\s*=\s*\[(.*?)\];/s);
  assert.ok(m, 'COVERAGE_KEYS not found in coverage.js — did it move or change shape?');
  return [...m[1].matchAll(/\[\s*'([^']+)'\s*,\s*'([^']+)'\s*\]/g)].map(x => [x[1], x[2]]);
}

test('every coverage-backed letter has a coverage layer', () => {
  const expected = Object.values(letterKey()).sort();
  const actual = coverageKeys().map(([key]) => key).sort();

  assert.deepEqual(actual, expected,
    'COVERAGE_KEYS and LETTER_KEY disagree. A letter in LETTER_KEY but not '
    + 'COVERAGE_KEYS renders NOTHING while the rail still shows its count — '
    + 'silently, which is how Public toilets went unnoticed. Add the missing '
    + "[key, lowercase letter] pair to COVERAGE_KEYS, and its letter to "
    + 'COV_UTILITY if it is infrastructure rather than a rider pick.');
});

test('each coverage key carries the lowercase form of its own letter', () => {
  const byKey = letterKey();
  const inverse = Object.fromEntries(Object.entries(byKey).map(([l, k]) => [k, l]));

  for (const [key, letter] of coverageKeys()) {
    // The letter IS the tile source-layer prefix ('c' -> 'c_be'), so a typo
    // here points every layer of that pool at source-layers that do not
    // exist — which renders empty rather than throwing.
    assert.equal(letter, inverse[key].toLowerCase(),
      `COVERAGE_KEYS entry for '${key}' says letter '${letter}', catalog says '${inverse[key]}'`);
  }
});

test('every coverage pool has a source note for the drawer', () => {
  const src = read('coverage.js').match(/export const COV_SRC\s*=\s*\{(.*?)\n\};/s);
  assert.ok(src, 'COV_SRC not found in coverage.js');
  const noted = new Set([...src[1].matchAll(/^\s*(\w+)\s*:/gm)].map(m => m[1]));

  for (const [key] of coverageKeys()) {
    // water is the documented exception: waterDrawer owns its own wording.
    if (key === 'water') continue;
    assert.ok(noted.has(key),
      `COV_SRC has no entry for '${key}' — the drawer's Source line would be blank`);
  }
});
