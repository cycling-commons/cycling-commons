// SPDX-License-Identifier: AGPL-3.0-only
//
// "Shown anyway" (docs/specs/map-and-search.md §4.3, §9): a place opened from a
// ride row that the rider's own filter chips hide is drawn for as long as its
// drawer is up. One place at a time, never a change to the chips, released when
// the drawer moves on or the ride is cleared.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { placeKey, createShownAnyway } from '../../assets/map/filters.js';

test('a place is named letter:id, and a place with no id has no name', () => {
  assert.equal(placeKey('O', 812), 'O:812');
  assert.equal(placeKey('O', '812'), 'O:812');
  assert.equal(placeKey('O', null), null);
  assert.equal(placeKey('', 4), null);
});

test('one place at a time: showing a second releases the first', () => {
  const s = createShownAnyway();
  assert.equal(s.has('O:1'), false);
  assert.equal(s.show('O:1'), true);
  assert.equal(s.has('O:1'), true);
  assert.equal(s.show('O:1'), false);           // already shown: nothing changes
  assert.equal(s.show('N:9'), true);
  assert.equal(s.has('O:1'), false);
  assert.equal(s.has('N:9'), true);
  assert.equal(s.show(null), false);             // no id, nothing to exempt
  assert.equal(s.has('N:9'), true);
});

test('opening the same place keeps it; opening anything else releases it', () => {
  const s = createShownAnyway();
  s.show('O:1');
  assert.equal(s.releaseUnless('O:1'), false);
  assert.equal(s.has('O:1'), true);
  assert.equal(s.releaseUnless('B:7'), true);
  assert.equal(s.has('O:1'), false);
  assert.equal(s.releaseUnless('B:7'), false);   // nothing left to release
});

test('a place with no id (a ride summary, a closed drawer) releases it', () => {
  const s = createShownAnyway();
  s.show('O:1');
  assert.equal(s.releaseUnless(null), true);
  assert.equal(s.has('O:1'), false);
  assert.equal(s.has(null), false);
});
