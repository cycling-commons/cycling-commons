// SPDX-License-Identifier: AGPL-3.0-only
//
// Clear puts back the view mode a ride row lifted (docs/specs/map-and-search.md
// §9), the way it already puts back the scope. The rider's own choice made
// while the ride was loaded always stands.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { createRideModeMemo } from '../../assets/map/ride-places.js';

test('nothing lifted: Clear leaves the mode alone', () => {
  const m = createRideModeMemo();
  assert.equal(m.restore('confirmed'), null);
});

test('one lift: Clear goes back to the mode before it', () => {
  const m = createRideModeMemo();
  m.lifted('confirmed', 'all');
  assert.equal(m.restore('all'), 'confirmed');
  assert.equal(m.restore('all'), null);            // said once, then forgotten
});

test('two lifts: Clear goes back to the mode before the first', () => {
  const m = createRideModeMemo();
  m.lifted('curated', 'confirmed');
  m.lifted('confirmed', 'all');
  assert.equal(m.restore('all'), 'curated');
});

test('the rider picking a mode while the ride is loaded keeps their pick', () => {
  const m = createRideModeMemo();
  m.lifted('confirmed', 'all');
  m.riderChose();
  assert.equal(m.restore('curated'), null);
  assert.equal(m.restore('all'), null);
});

test('a mode that moved by some other path is not overwritten', () => {
  const m = createRideModeMemo();
  m.lifted('confirmed', 'all');
  assert.equal(m.restore('curated'), null);
});

test('a lift after the rider chose remembers their pick as the one to return to', () => {
  const m = createRideModeMemo();
  m.lifted('curated', 'all');
  m.riderChose();                                  // rider went to Confirmed themselves
  m.lifted('confirmed', 'all');
  assert.equal(m.restore('all'), 'confirmed');
});
