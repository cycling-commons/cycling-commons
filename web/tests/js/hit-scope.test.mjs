// SPDX-License-Identifier: AGPL-3.0-only
//
// hit-scope.js: which scope a search hit or a deep link asks for
// (docs/specs/map-and-search.md §4.5, §8). Owner-reported 2026-09-16: a rider
// scoped to Friesland clicked the city card for Liege and was moved to All
// Belgium, which threw away their own scope to reach one town. The smallest
// area that shows the hit is the hit's own region.
import test from 'node:test';
import assert from 'node:assert/strict';
import { hitScopeFor } from '../../assets/map/hit-scope.js';

const FRIESLAND = { kind: 'region', regionIds: [28], countryCode: 'NL' };
const WALLONIA = { id: 1, countryCode: 'BE' };
const ALL_BELGIUM = { kind: 'country', regionIds: [1, 24, 23], countryCode: 'BE' };

test('a hit the scope does not draw moves the scope to that hit own region, never to its country', () => {
  assert.deepEqual(hitScopeFor(FRIESLAND, WALLONIA),
    { kind: 'region', regionIds: [1], countryCode: 'BE' });
});

test('a scope that already holds the region is already looking there', () => {
  assert.equal(hitScopeFor({ kind: 'region', regionIds: [1], countryCode: 'BE' }, WALLONIA), null);
  // A country scope holds every region it covers, so a hit inside it moves nothing.
  assert.equal(hitScopeFor(ALL_BELGIUM, WALLONIA), null);
});

test('Everywhere already draws everything', () => {
  assert.equal(hitScopeFor({ kind: 'everywhere', regionIds: [], countryCode: null }, WALLONIA), null);
});

test('a target outside every onboarded region leaves the scope alone', () => {
  assert.equal(hitScopeFor(FRIESLAND, null), null);
  assert.equal(hitScopeFor(FRIESLAND, {}), null);
  assert.equal(hitScopeFor(FRIESLAND, { id: null, countryCode: 'BE' }), null);
});

test('a myArea scope whose derived regions miss the hit lifts like any other', () => {
  assert.deepEqual(hitScopeFor({ kind: 'myArea', regionIds: [28, 33], countryCode: null }, WALLONIA),
    { kind: 'region', regionIds: [1], countryCode: 'BE' });
});

test('a region with no country still answers a usable scope', () => {
  assert.deepEqual(hitScopeFor(FRIESLAND, { id: 7 }),
    { kind: 'region', regionIds: [7], countryCode: null });
});

test('no scope yet is still a lift: the hit names where to look', () => {
  assert.deepEqual(hitScopeFor(null, WALLONIA), { kind: 'region', regionIds: [1], countryCode: 'BE' });
});
