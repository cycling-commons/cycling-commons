// SPDX-License-Identifier: AGPL-3.0-only
//
// Which "back" button a place drawer shows (docs/specs/map-and-search.md §6.3,
// §9): a one-step return to the list the place was opened from (a route's
// climbs) while that place is on screen, otherwise the lasting return of a
// loaded ride.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { pickDrawerReturn } from '../../assets/map/ride-places.js';

const ride = { label: 'Ride summary', go: () => {} };
const route = { label: 'Liege Bastogne Liege', go: () => {}, forKey: 'N:43008' };

test('a place opened from a route shows the way back to that route', () => {
  assert.equal(pickDrawerReturn(null, route, 'N:43008').target, route);
  assert.equal(pickDrawerReturn(ride, route, 'N:43008').target, route, 'the nearest step back wins over the ride');
  assert.equal(pickDrawerReturn(ride, route, 'N:43008').keepHop, true);
});

test('any other place drops the route step and falls back to the ride', () => {
  const other = pickDrawerReturn(ride, route, 'B:44871');
  assert.equal(other.target, ride);
  assert.equal(other.keepHop, false);
  const none = pickDrawerReturn(null, route, 'B:44871');
  assert.equal(none.target, null);
  assert.equal(none.keepHop, false);
});

test('without a route step the ride return stands, or nothing', () => {
  assert.deepEqual(pickDrawerReturn(ride, null, 'N:1'), { target: ride, keepHop: false });
  assert.deepEqual(pickDrawerReturn(null, null, 'N:1'), { target: null, keepHop: false });
});
