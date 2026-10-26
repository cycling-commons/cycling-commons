// SPDX-License-Identifier: AGPL-3.0-only
//
// ride-scope.js: which scope a loaded GPX asks for (docs/specs/map-and-search.md
// §4.5). A rider who drops a ride while scoped somewhere else sees a drawn track
// over an empty map, so the ride moves the scope. The interesting case is a ride
// that crosses a border: the scope holds a SET of regions, so all of them come
// along rather than one winning and the rest of the ride going unscoped.
import test from 'node:test';
import assert from 'node:assert/strict';
import { rideScopeFor, scopeKey, planScopeFor, rideRegions } from '../../assets/map/ride-scope.js';

const VAUCLUSE = { id: 11, slug: 'vaucluse', countryCode: 'FR' };
const DROME = { id: 12, slug: 'drome', countryCode: 'FR' };
const LIEGE = { id: 21, slug: 'liege', countryCode: 'BE' };

test('one region scopes to that region', () => {
  assert.deepEqual(rideScopeFor([VAUCLUSE]), { kind: 'region', regionIds: [11], countryCode: 'FR' });
});

test('several regions in one country all come along, in ride order', () => {
  assert.deepEqual(rideScopeFor([VAUCLUSE, DROME]),
    { kind: 'region', regionIds: [11, 12], countryCode: 'FR' });
});

test('a ride crossing a border keeps every region it crosses, never half the ride', () => {
  // Everywhere is not a place to look at (owner, 2026-09-06): the set of
  // crossed regions is the scope, and with two countries no single one names it.
  assert.deepEqual(rideScopeFor([LIEGE, VAUCLUSE]),
    { kind: 'region', regionIds: [LIEGE.id, VAUCLUSE.id], countryCode: null });
});

test('a ride outside every onboarded region leaves the scope alone', () => {
  assert.equal(rideScopeFor([]), null);
  assert.equal(rideScopeFor(null), null);
  assert.equal(rideScopeFor(undefined), null);
});

test('scopeKey ignores region order, so re-loading the same ride is not a change', () => {
  assert.equal(scopeKey({ kind: 'region', regionIds: [12, 11], countryCode: 'FR' }),
               scopeKey({ kind: 'region', regionIds: [11, 12], countryCode: 'FR' }));
  assert.notEqual(scopeKey({ kind: 'region', regionIds: [11], countryCode: 'FR' }),
                  scopeKey({ kind: 'region', regionIds: [11, 12], countryCode: 'FR' }));
  assert.equal(scopeKey(null), '');
});

/* The planner shows the region of the ride's start, whatever the rider's scope was (owner 2026-10-19: "when opening
   the route planner it still keeps my default openings screen with the circle. ... just load the region our ride is
   in (startpoint of the ride if it spans multiple)"). */
test('the planner scope is the start region, never the rider circle, country or Everywhere', () => {
  const region = ids => ({ kind: 'region', regionIds: ids, countryCode: null });
  const startLiege = { kind: 'region', regionIds: [LIEGE.id], countryCode: LIEGE.countryCode };
  assert.deepEqual(planScopeFor({ kind: 'myArea', regionIds: [LIEGE.id], countryCode: null }, LIEGE), startLiege, 'the circle near Spa goes, even though it holds the ride');
  assert.deepEqual(planScopeFor({ kind: 'country', regionIds: [], countryCode: 'FR' }, VAUCLUSE),
    { kind: 'region', regionIds: [VAUCLUSE.id], countryCode: VAUCLUSE.countryCode });
  assert.deepEqual(planScopeFor({ kind: 'everywhere', regionIds: [], countryCode: null }, LIEGE), startLiege);
  assert.deepEqual(planScopeFor(region([VAUCLUSE.id, DROME.id]), DROME), { kind: 'region', regionIds: [DROME.id], countryCode: DROME.countryCode }, 'a ride over two regions shows its start only');
  assert.deepEqual(planScopeFor(null, LIEGE), startLiege);
});
test('the planner scope moves nothing when it is the start region already, or the start is in no region', () => {
  assert.equal(planScopeFor({ kind: 'region', regionIds: [LIEGE.id], countryCode: null }, LIEGE), null);
  assert.equal(planScopeFor({ kind: 'myArea', regionIds: [LIEGE.id], countryCode: null }, null), null);
  assert.equal(planScopeFor({ kind: 'myArea', regionIds: [LIEGE.id], countryCode: null }, {}), null);
});

/* Owner 2026-10-26: a checkpoint outside the start's region left that region's places unloaded. */
test('the planner\'s scope is every region of the ride, the start\'s first, and nothing new when it already is', () => {
  assert.deepEqual(planScopeFor({ kind: 'region', regionIds: [LIEGE.id], countryCode: null }, [LIEGE, VAUCLUSE, LIEGE]),
    { kind: 'region', regionIds: [LIEGE.id, VAUCLUSE.id], countryCode: null }, 'a region twice counts once, two countries name none');
  assert.equal(planScopeFor({ kind: 'region', regionIds: [VAUCLUSE.id, DROME.id], countryCode: 'FR' }, [DROME, VAUCLUSE]), null, 'the same set in another order is no change');
  assert.equal(planScopeFor(null, []), null);
});

test('a ride\'s regions are its checkpoints\' and those its legs cross, read along the legs', () => {
  // Three regions side by side along a parallel: west of 5.0 E, 5.0 to 5.1, east of 5.1.
  const W = { id: 1, countryCode: 'NL' }, M = { id: 2, countryCode: 'NL' }, E = { id: 3, countryCode: 'NL' };
  const S = {
    liveRegionAt: lng => (lng < 5.0 ? W : lng < 5.1 ? M : E),
    regionOfPoint: () => null,
  };
  const stops = [{ lng: 4.95, lat: 52 }, { lng: 5.15, lat: 52 }];
  // Before routing only the checkpoints count: M lies between them, crossed by no checkpoint.
  assert.deepEqual(rideRegions(stops, [], S).map(x => x.id), [1, 3]);
  // Once routed, the leg across M adds it, in ride order.
  const leg = { routed: true, line: [[4.95, 52], [5.15, 52]] };
  assert.deepEqual(rideRegions(stops, [leg], S).map(x => x.id), [1, 2, 3]);
  // A checkpoint just outside every outline falls back to the bounding box.
  const S2 = { liveRegionAt: () => null, regionOfPoint: () => W };
  assert.deepEqual(rideRegions([{ lng: 4.9, lat: 52 }], [], S2).map(x => x.id), [1]);
});
