// SPDX-License-Identifier: AGPL-3.0-only
//
// Where a clicked spot lands: in the part of the map the drawer leaves free
// (docs/specs/map-and-search.md §6).
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { pinOffset } from '../../assets/map/util.js';

test('on a desktop the spot moves left, clear of the right-hand drawer', () => {
  assert.deepEqual(pinOffset(1920, 1080, 0, 1080), [-150, 0]);
  assert.deepEqual(pinOffset(821, 900, 0, 900), [-150, 0]);
});

test('on a phone the spot moves up into the half above the sheet', () => {
  // A 400 x 1000 phone, map from the top: the free half is 0..500, its middle 250,
  // and the map middle is 500, so the spot moves 250 px up.
  assert.deepEqual(pinOffset(400, 1000, 0, 1000), [0, -250]);
  // A map that starts 10 px down: free middle (10 + 500) / 2 = 255, map middle 10 + 495 = 505.
  assert.deepEqual(pinOffset(400, 1000, 10, 990), [0, -250]);
  assert.deepEqual(pinOffset(820, 900, 0, 900), [0, -225]);
});
