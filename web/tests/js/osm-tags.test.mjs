// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// osm-tags.js: the scenic-view facts OSM holds and the drawer now shows
// (docs/specs/coverage-provider.md §5). Both helpers exist because OSM values
// are free text: `ele` is usually "484" but sometimes "484 m" or "1200 ft",
// and `direction` is either a bearing or a compass point. A wrong guess would
// print a confident number that is not what the mapper wrote, so anything not
// plainly metres is handed back untouched instead.
import test from 'node:test';
import assert from 'node:assert/strict';
import { osmMetres, viewDirection } from '../../assets/map/osm-tags.js';

test('metres are read from the shapes OSM actually writes', () => {
  assert.equal(osmMetres('484'), 484);        // Costo Liso, node 12969271187
  assert.equal(osmMetres(' 484 m'), 484);
  assert.equal(osmMetres('1084.5'), 1084.5);
  assert.equal(osmMetres('-3'), -3);          // below sea level is a real elevation
});

test('a value that is not plainly metres is refused, never guessed', () => {
  assert.equal(osmMetres('1200 ft'), null);
  assert.equal(osmMetres('about 500'), null);
  assert.equal(osmMetres(''), null);
});

test('a bearing becomes a compass point and keeps its degrees', () => {
  assert.equal(viewDirection('225'), 'SW · 225°');
  assert.equal(viewDirection('0'), 'N · 0°');
  assert.equal(viewDirection('360'), 'N · 0°');
  assert.equal(viewDirection('370'), 'N · 10°');   // wraps, never "370°"
  assert.equal(viewDirection('-90'), 'W · 270°');
});

test('a compass point stays itself, and prose stays prose', () => {
  assert.equal(viewDirection('sw'), 'SW');
  assert.equal(viewDirection('N'), 'N');
  assert.equal(viewDirection('towards the lake'), 'towards the lake');
});
