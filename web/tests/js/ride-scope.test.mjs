// SPDX-License-Identifier: AGPL-3.0-only
//
// ride-scope.js: which scope a loaded GPX asks for (docs/specs/map-and-search.md
// §4.5). A rider who drops a ride while scoped somewhere else sees a drawn track
// over an empty map, so the ride moves the scope. The interesting case is a ride
// that crosses a border: the scope holds a SET of regions, so all of them come
// along rather than one winning and the rest of the ride going unscoped.
import test from 'node:test';
import assert from 'node:assert/strict';
import { rideScopeFor, scopeKey, planScopeFor } from '../../assets/map/ride-scope.js';

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
