// SPDX-License-Identifier: AGPL-3.0-only
//
// ride-scope.js: which scope a loaded GPX asks for (docs/specs/map-and-search.md
// §4.5). A rider who drops a ride while scoped somewhere else sees a drawn track
// over an empty map, so the ride moves the scope. The interesting case is a ride
// that crosses a border: the scope holds a SET of regions, so all of them come
// along rather than one winning and the rest of the ride going unscoped.
import test from 'node:test';
import assert from 'node:assert/strict';
import { rideScopeFor, scopeKey, scopeCovers } from '../../assets/map/ride-scope.js';

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

/* A planned ride moves the scope only when it leaves it (owner 2026-10-16): a GPX of North Holland opened while the
   map looked at Wallonia showed no places. A ride inside a wider scope (its country, Everywhere) keeps that scope:
   narrowing it to the ride's regions would hide what the rider chose to see. */
test('a scope covers a ride when every region it crosses is inside it', () => {
  const region = ids => ({ kind: 'region', regionIds: ids, countryCode: null });
  assert.equal(scopeCovers(region([11, 12]), [VAUCLUSE, DROME]), true);
  assert.equal(scopeCovers(region([11]), [VAUCLUSE, DROME]), false, 'the ride runs on into a region outside');
  assert.equal(scopeCovers(region([21]), [VAUCLUSE]), false, 'Wallonia does not cover a ride elsewhere');
  assert.equal(scopeCovers({ kind: 'country', regionIds: [], countryCode: 'FR' }, [VAUCLUSE, DROME]), true);
  assert.equal(scopeCovers({ kind: 'country', regionIds: [], countryCode: 'FR' }, [DROME, LIEGE]), false);
  assert.equal(scopeCovers({ kind: 'everywhere', regionIds: [], countryCode: null }, [LIEGE]), true);
  assert.equal(scopeCovers({ kind: 'myArea', regionIds: [21], countryCode: null }, [LIEGE]), true);
  assert.equal(scopeCovers(region([21]), []), true, 'a ride outside every region asks nothing');
  assert.equal(scopeCovers(null, [LIEGE]), true, 'no scope yet: nothing to move');
});
