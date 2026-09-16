// SPDX-License-Identifier: AGPL-3.0-only
//
// Which "back" button a place drawer shows (docs/specs/map-and-search.md §6.3,
// §9): a one-step return to the list the place was opened from (a route's
// climbs) while that place is on screen, otherwise the lasting return of a
// loaded ride.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { pickDrawerReturn, drawerPlaceKeys, keepsRouteHold } from '../../assets/map/ride-places.js';

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

test('a place drawer answers to every key it has: its item id and its OSM ref', () => {
  const water = { label: 'Spa · Coo', go: () => {}, forKey: 'B:node/42' };
  assert.equal(pickDrawerReturn(null, water, ['B:node/42']).target, water);
  assert.equal(pickDrawerReturn(null, water, ['B:7', 'B:node/42']).keepHop, true);
  assert.equal(pickDrawerReturn(null, water, ['B:7']).target, null);
});

test('drawerPlaceKeys: letter:id for a commons row, letter:ref for an open coverage point', () => {
  assert.deepEqual(drawerPlaceKeys('B', { id: 7 }), ['B:7']);
  assert.deepEqual(drawerPlaceKeys('B', { osmRef: 'node/42' }), ['B:node/42']);
  assert.deepEqual(drawerPlaceKeys('B', { id: 7, osmRef: 'node/42' }), ['B:7', 'B:node/42']);
  assert.deepEqual(drawerPlaceKeys('B', {}), [], 'a place with neither never matches a hop');
});

test('keepsRouteHold: the route stays for its own drawer and for a place opened from its list', () => {
  const hold = { routeId: 24 };
  const hop = { forKey: 'B:43008', routeId: 24 };
  assert.equal(keepsRouteHold(hold, hop, { routeId: 24, keys: [] }), true, 'back on the same route');
  assert.equal(keepsRouteHold(hold, hop, { routeId: null, keys: ['B:43008'] }), true, 'the place its list opened');
  assert.equal(keepsRouteHold(hold, hop, { routeId: null, keys: ['B:1'] }), false, 'an unrelated place');
  assert.equal(keepsRouteHold(hold, hop, { routeId: 25, keys: [] }), false, 'another route');
  assert.equal(keepsRouteHold(hold, { forKey: 'B:43008', routeId: 25 }, { routeId: null, keys: ['B:43008'] }), false, 'a hop from another route');
  assert.equal(keepsRouteHold(null, hop, { routeId: 24, keys: ['B:43008'] }), false, 'nothing held');
});
