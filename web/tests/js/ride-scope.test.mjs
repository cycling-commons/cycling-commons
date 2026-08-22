// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// ride-scope.js: which scope a loaded GPX asks for (docs/specs/map-and-search.md
// §4.5). A rider who drops a ride while scoped somewhere else sees a drawn track
// over an empty map, so the ride moves the scope. The interesting case is a ride
// that crosses a border: the scope holds a SET of regions, so all of them come
// along rather than one winning and the rest of the ride going unscoped.
import test from 'node:test';
import assert from 'node:assert/strict';
import { rideScopeFor, scopeKey } from '../../assets/map/ride-scope.js';

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

test('a ride crossing a border widens to everywhere, never half the ride', () => {
  assert.deepEqual(rideScopeFor([LIEGE, VAUCLUSE]),
    { kind: 'everywhere', regionIds: [], countryCode: null });
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
