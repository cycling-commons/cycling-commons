// SPDX-License-Identifier: AGPL-3.0-only
// The full climb profile keeps one slope scale: a gentle climb draws a lower
// silhouette instead of filling the chart (climb-elevation.md §6c).
import test from 'node:test';
import assert from 'node:assert/strict';

import { verticalSpan, FULL_HEIGHT_GRADIENT } from '../../assets/map/profile-scale.js';

test('a 12 % average fills the chart', () => {
  assert.equal(FULL_HEIGHT_GRADIENT, 0.12);
  assert.equal(verticalSpan(1200, 10000), 1200);
});

test('a gentler climb over the same distance keeps the 12 % span, so it draws lower', () => {
  // Furka: about 650 m over 11 km fills about half the chart.
  const span = verticalSpan(650, 11000);
  assert.equal(span, 11000 * 0.12);
  assert.ok(650 / span > 0.45 && 650 / span < 0.55);
});

test('a steeper climb fills the chart', () => {
  assert.equal(verticalSpan(400, 2000), 400);
});

test('a short riser still gets 60 m', () => {
  assert.equal(verticalSpan(20, 300), 60);
});
