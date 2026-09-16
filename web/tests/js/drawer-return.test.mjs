// SPDX-License-Identifier: AGPL-3.0-only
//
// Which "back" button a place drawer shows (docs/specs/map-and-search.md §6.3,
// §9): a one-step return to the list the place was opened from (a route's
// climbs) while that place is on screen, otherwise the lasting return of a
// loaded ride.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { pickDrawerReturn, drawerPlaceKeys, keepsRouteHold } from '../../assets/map/ride-places.js';

const ride = { label: 'Ride summary', go: () => {} };
const route = { label: 'Liege Bastogne Liege', go: () => {}, forKey: 'N:43008' };

test('a place opened from a route shows the way back to that route', () => {
  assert.equal(pickDrawerReturn(null, route, 'N:43008').target, route);
  assert.equal(pickDrawerReturn(ride, route, 'N:43008').target, route, 'the nearest step back wins over the ride');
  assert.equal(pickDrawerReturn(ride, route, 'N:43008').keepHop, true);
});

test('any other place drops the one-step hop and falls back to the lasting return', () => {
  // The base is whatever still has the rider's focus: drawer.js passes the
  // route it is holding, else a loaded ride's summary (map-and-search.md §6.3).
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

test('keepsRouteHold: a picked route stays picked behind whatever the rider opens next', () => {
  // Owner 2026-09-16: "A selected route should always stay active also when a
  // user clicks on a spot in the map themselves. The drawers only drop route
  // focus when they close the drawer by hand with the X button." Closing by
  // hand is closeDrawer()'s letRouteGo() and never reaches here, so the only
  // thing this may answer false to is another route taking the focus over.
  const hold = { routeId: 24 };
  assert.equal(keepsRouteHold(hold, { routeId: 24 }), true, 'back on the same route');
  assert.equal(keepsRouteHold(hold, { routeId: null }), true, 'a place the route listed');
  assert.equal(keepsRouteHold(hold, { routeId: null }), true, 'a pin the rider clicked on the map');
  assert.equal(keepsRouteHold(hold, { routeId: 25 }), false, 'another route takes the focus over');
  assert.equal(keepsRouteHold(null, { routeId: 24 }), false, 'nothing held');
  assert.equal(keepsRouteHold(hold, null), false, 'no drawer to judge');
});

test('only the hand-closed drawer drops the route focus, and a place opened over it offers the way back', () => {
  // Structural pins over drawer.js: MapLibre needs a live GL context, so the
  // wiring is asserted on the source, the house pattern.
  const src = readFileSync(new URL('../../assets/map/drawer.js', import.meta.url), 'utf8');
  // openDrawer asks about the route alone: no hop, no keys, so no place can
  // fail the test by not being on the route's own lists.
  assert.match(src, /const keepRoute = keepsRouteHold\(_routeHold,\n\s*\{routeId: layer\.key==='experience' \? \(f\.id!=null \? f\.id : ''\) : null\}\);/,
    'openDrawer must judge the hold on the route alone');
  assert.doesNotMatch(src, /keepsRouteHold\(_routeHold, _drawerHop/,
    'judging the hold on the list a place came from drops the route for a pin the rider clicked');
  // closeDrawer is the one place the focus goes, together with the lifted scope.
  const close = src.slice(src.indexOf('export function closeDrawer()'));
  const body = close.slice(0, close.indexOf('\n}'));
  assert.ok(body.includes('letRouteGo();'), 'closing by hand must drop the route');
  assert.ok(body.includes('restoreHitScope();'), 'closing by hand must give the rider their scope back');
  // And a place opened while a route is held shows the way back to it.
  assert.match(src, /function routeHoldReturn\(layer, f\)/, 'no way back to the route being held');
  assert.match(src, /pickDrawerReturn\(routeHoldReturn\(layer, f\) \|\| _drawerReturn, _drawerHop,/,
    'the held route must be the lasting return for a place opened over it');
  assert.match(src, /holdRoute\(f\.id, releaseRouteList, f\.name\)/, 'the hold must carry the route name for that button');
});
