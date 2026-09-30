// SPDX-License-Identifier: AGPL-3.0-only
//
// Where a list row's hover ring lands (docs/specs/map-and-search.md §9): on the
// place's pin. A climb's pin stands at its foot, the first point of its line,
// while its stored point can sit kilometres away (Côte de Mont-le-Soie: about
// 3 km). The ride check's rows ring the item-index entry's point, so that point
// is the pin's.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { pinPoint } from '../../assets/map/util.js';

// Côte de Mont-le-Soie as the catalog serves it: stored point plus its line, foot first.
const monteLeSoie = { id: 3130, geom: { ll: [50.31372, 5.95981] }, route: [[50.325189, 5.922111], [50.323033, 5.959601]] };

test('a climb pins at its foot, not its stored point', () => {
  assert.deepEqual(pinPoint(monteLeSoie), [50.325189, 5.922111]);
});

test('anything without a line pins at its own point', () => {
  assert.deepEqual(pinPoint({ geom: { ll: [50.4, 5.8] } }), [50.4, 5.8]);
  assert.deepEqual(pinPoint({ geom: { path: [[50.63, 5.57], [50.0, 5.72]] } }), [50.63, 5.57]);
});

test('the item index places a catalog feature where its pin stands', () => {
  const src = readFileSync(new URL('../../assets/map/item-index.js', import.meta.url), 'utf8');
  assert.match(src, /ll:pinPoint\(f\), id:f\.id,/, 'a catalog entry\'s ll is the pin point');
  assert.doesNotMatch(src, /featurePoint/, 'the stored point would put a climb\'s ring away from its pin');
});

test('a ride-check row rings the item-index entry\'s point, else the row\'s own', () => {
  const src = readFileSync(new URL('../../assets/map/listed-place.js', import.meta.url), 'utf8');
  assert.match(src, /b\.onmouseenter = \(\) => highlightAt\(\(entry && entry\.ll\) \|\| it\.ll,/);
});
