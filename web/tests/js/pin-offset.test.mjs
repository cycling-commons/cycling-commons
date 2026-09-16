// SPDX-License-Identifier: AGPL-3.0-only
//
// Where a clicked spot lands: in the part of the map the drawer leaves free
// (docs/specs/map-and-search.md §6).
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { pinOffset, drawerFitPadding, pathBounds } from '../../assets/map/util.js';

test('on a desktop the spot moves left, clear of the right-hand drawer', () => {
  // Half the room the drawer takes on the right (drawerFitPadding), so the spot
  // lands in the middle of what is left, not under the panel.
  assert.deepEqual(pinOffset(1920, 1080, 0, 1080), [-200, 0]);
  assert.deepEqual(pinOffset(821, 900, 0, 900), [-200, 0]);
});

test('on a phone the spot moves up into the half above the sheet', () => {
  // A 400 x 1000 phone, map from the top: the free half is 0..500, its middle 250,
  // and the map middle is 500, so the spot moves 250 px up.
  assert.deepEqual(pinOffset(400, 1000, 0, 1000), [0, -250]);
  // A map that starts 10 px down: free middle (10 + 500) / 2 = 255, map middle 10 + 495 = 505.
  assert.deepEqual(pinOffset(400, 1000, 10, 990), [0, -250]);
  assert.deepEqual(pinOffset(820, 900, 0, 900), [0, -225]);
});

// Framing a shape (a route, a ride, a town and its neighbours) keeps it clear
// of the drawer: the right-hand panel on a desktop, the bottom sheet that opens
// at half the screen on a phone (docs/specs/map-and-search.md §6, §8).
test('a framed shape keeps clear of the desktop drawer', () => {
  assert.deepEqual(drawerFitPadding(1400, 900), {top:70, bottom:70, left:70, right:400});
});

test('on a phone a framed shape sits above the half-screen sheet', () => {
  assert.deepEqual(drawerFitPadding(400, 1000), {top:70, bottom:516, left:70, right:70});
  // A short screen never gets less than the 300 px the sheet needs.
  assert.deepEqual(drawerFitPadding(400, 500), {top:70, bottom:300, left:70, right:70});
});

test('a path of [lat, lng] points gives [[west, south], [east, north]]', () => {
  // Route 111 Liege Bastogne Liege runs from Liège (50.63, 5.57) down to Bastogne (50.00, 5.72).
  assert.deepEqual(pathBounds([[50.63, 5.57], [50.0, 5.72], [50.3, 5.9]]), [[5.57, 50.0], [5.9, 50.63]]);
  assert.equal(pathBounds([]), null);
  assert.equal(pathBounds(undefined), null);
  assert.equal(pathBounds([[50, 'x']]), null);
});
