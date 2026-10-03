// SPDX-License-Identifier: AGPL-3.0-only
// The full climb profile places the steepest 250 m and the rider's steepest
// point at their distance along the climb (climb-elevation.md §6d).
import test from 'node:test';
import assert from 'node:assert/strict';

import { metresAlong, windowSpan } from '../../assets/map/profile-marks.js';

// Three points due north, about 1 km apart: foot, middle, summit.
const route = [[46.0, 8.0], [46.009, 8.0], [46.018, 8.0]];
const KM = 1000.754; // 0.009 degrees of latitude in metres, on this haversine

test('a point on the line reads its metres from the foot', () => {
  assert.equal(metresAlong(route, [46.0, 8.0]), 0);
  assert.ok(Math.abs(metresAlong(route, [46.018, 8.0]) - 2*KM) < 1);
  assert.ok(Math.abs(metresAlong(route, [46.0135, 8.0]) - 1.5*KM) < 1);
});

test('a point beside the line reads the nearest spot on it', () => {
  // 20 m east of the middle point.
  assert.ok(Math.abs(metresAlong(route, [46.009, 8.00026]) - KM) < 1);
});

test('a missing route or point places nothing', () => {
  assert.equal(metresAlong(undefined, [46, 8]), null);
  assert.equal(metresAlong([[46, 8]], [46, 8]), null);
  assert.equal(metresAlong(route, undefined), null);
  assert.equal(metresAlong(route, ['x', 8]), null);
});

test('the 250 m window is centred on its spot', () => {
  assert.deepEqual(windowSpan(1000, 250, 2000), [875, 1125]);
});

test('a window at the foot or the summit stays inside the climb', () => {
  assert.deepEqual(windowSpan(50, 250, 2000), [0, 250]);
  assert.deepEqual(windowSpan(1990, 250, 2000), [1750, 2000]);
});

test('a climb shorter than the window is all window', () => {
  assert.deepEqual(windowSpan(100, 250, 200), [0, 200]);
});
