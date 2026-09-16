// SPDX-License-Identifier: AGPL-3.0-only
//
// ride-places.js: how a loaded ride check steers the normal map
// (docs/specs/map-and-search.md §9). The ride draws only its track; the places
// it lists are drawn by the map's own renderers. A pool place the ride lists
// is kept out of clustering so it stands as its own leaf pin, and a row's hover
// ring sits on the pin body only when a bottom-anchored pin is really there.
import test from 'node:test';
import assert from 'node:assert/strict';
import { listedPlaceKeys, splitPool, ringOffset, mergeListed, listedLettersChanged } from '../../assets/map/ride-places.js';

const feature = (id, extra = {}) => ({ type: 'Feature', properties: { id, ...extra }, geometry: { type: 'Point', coordinates: [5, 52] } });

test('every listed commons place is keyed letter:id, across groups', () => {
  const keys = listedPlaceKeys([
    { letter: 'B', items: [{ id: 43008 }, { id: 44819 }] },
    { letter: 'G', items: [{ id: 7 }] },
  ]);
  assert.deepEqual([...keys].sort(), ['B:43008', 'B:44819', 'G:7']);
});

test('no ride, or a malformed answer, lists nothing', () => {
  assert.equal(listedPlaceKeys(null).size, 0);
  assert.equal(listedPlaceKeys([{ letter: 'B' }]).size, 0);
  assert.equal(listedPlaceKeys([{ letter: 'B', items: [{ name: 'no id' }] }]).size, 0);
});

test('a listed place leaves the cluster source and stands as a leaf', () => {
  const a = feature(1), b = feature(2), c = feature(3);
  const { cluster, leaves } = splitPool([a, b, c], 'B', new Set(['B:2']), () => true);
  assert.deepEqual(cluster, [a, c]);
  assert.deepEqual(leaves, [b]);
});

test('the view mode still decides: a hidden listed place is neither clustered nor a leaf', () => {
  const a = feature(1, { v: 1 }), b = feature(2);
  const visible = f => !!f.properties.v;
  const { cluster, leaves } = splitPool([a, b], 'B', new Set(['B:2']), visible);
  assert.deepEqual(cluster, [a]);
  assert.deepEqual(leaves, []);
});

test('the key carries the letter, so the same id on another layer still clusters', () => {
  const a = feature(2);
  const { cluster, leaves } = splitPool([a], 'G', new Set(['B:2']), () => true);
  assert.deepEqual(cluster, [a]);
  assert.deepEqual(leaves, []);
});

test('no listed places: the pool clusters exactly as the visible filter says', () => {
  const a = feature(1), b = feature(null);
  const { cluster, leaves } = splitPool([a, b], 'B', new Set(), () => true);
  assert.deepEqual(cluster, [a, b]);
  assert.deepEqual(leaves, []);
});

test('the ring sits on the pin body only when a pin is drawn there', () => {
  assert.deepEqual(ringOffset(true, [0, -16]), [0, -16]);
  assert.deepEqual(ringOffset(false, [0, -16]), [0, 0]);
  assert.deepEqual(ringOffset(true, undefined), [0, 0]);
});

test('two lists at once (a loaded ride and an open route): a place either lists stays a leaf', () => {
  const merged = mergeListed(new Map([['ride', new Set(['B:1', 'B:2'])], ['route', new Set(['B:2', 'O:9'])]]));
  assert.deepEqual([...merged].sort(), ['B:1', 'B:2', 'O:9']);
  assert.equal(mergeListed(new Map()).size, 0);
});

test('only the pools whose listed places changed are re-split, and an unchanged set re-splits none', () => {
  // Each re-split is a setData on a clustered source, and each costs the map a full re-render.
  assert.deepEqual([...listedLettersChanged(new Set(['B:1', 'O:9']), new Set(['B:1', 'O:9']))], []);
  assert.deepEqual([...listedLettersChanged(new Set(), new Set())], []);
  assert.deepEqual([...listedLettersChanged(new Set(['B:1', 'O:9']), new Set(['B:1']))], ['O']);
  assert.deepEqual([...listedLettersChanged(new Set(), new Set(['B:1', 'B:2', 'G:7']))].sort(), ['B', 'G']);
  assert.deepEqual([...listedLettersChanged(new Set(['B:1']), new Set(['B:2']))], ['B']);
});
