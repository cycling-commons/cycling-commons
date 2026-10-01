// SPDX-License-Identifier: AGPL-3.0-only
//
// share-links.js townFromQuery(): what map.js reads out of a `?town=` link
// before it opens a town's card (docs/specs/map-and-search.md §8).
//
// The link an approved town text's message carries. The message's own
// reference (`SUB-134`) names no place on the map, so the link names the town
// by its OSM ref and its point. The parser is the gate between a query string
// anybody can type and openPlace(), so it takes a well-formed OSM ref and a
// real point, and nothing else.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { townFromQuery } from '../../assets/map/share-links.js';

const q = s => new URLSearchParams(s);

test('a town link opens the card the town search would open', () => {
  assert.deepEqual(
    townFromQuery(q('town=relation/2422528&ll=50.492000,5.863600&name=Spa&msg=127')),
    { name: 'Spa', ll: [50.492, 5.8636], osm: 'relation/2422528' });
});

test('an encoded slash and a name with accents read back the same', () => {
  assert.deepEqual(
    townFromQuery(q('town=node%2F42&ll=50.1,-4.25&name=Li%C3%A8ge')),
    { name: 'Liège', ll: [50.1, -4.25], osm: 'node/42' });
});

test('without a name the card is titled by its ref', () => {
  assert.equal(townFromQuery(q('town=way/7&ll=1,2')).name, 'way/7');
  assert.equal(townFromQuery(q('town=way/7&ll=1,2&name=%20%20')).name, 'way/7');
});

test('no town, or a ref that is not an OSM element, opens nothing', () => {
  assert.equal(townFromQuery(q('ll=50,5&name=Spa')), null);
  assert.equal(townFromQuery(q('town=SUB-134&ll=50,5')), null);
  assert.equal(townFromQuery(q('town=relation/abc&ll=50,5')), null);
  assert.equal(townFromQuery(q('town=area/1&ll=50,5')), null);
  assert.equal(townFromQuery(q('town=node/12345678901234567&ll=50,5')), null, 'at most 16 digits');
  assert.equal(townFromQuery(q('town=node/1/spa&ll=50,5')), null);
});

test('a point that is missing or off the Earth opens nothing', () => {
  assert.equal(townFromQuery(q('town=node/1')), null);
  assert.equal(townFromQuery(q('town=node/1&ll=50')), null);
  assert.equal(townFromQuery(q('town=node/1&ll=91,5')), null);
  assert.equal(townFromQuery(q('town=node/1&ll=50,181')), null);
  assert.equal(townFromQuery(q('town=node/1&ll=NaN,5')), null);
  assert.equal(townFromQuery(q('town=node/1&ll=1e2,5')), null);
  assert.equal(townFromQuery(q('town=node/1&ll=50,5,7')), null);
});

test('a long name is cut, and no params at all is no town', () => {
  assert.equal(townFromQuery(q('town=node/1&ll=0,0&name=' + 'a'.repeat(300))).name.length, 200);
  assert.equal(townFromQuery(q('')), null);
  assert.equal(townFromQuery(null), null);
});
