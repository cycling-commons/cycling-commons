// SPDX-License-Identifier: AGPL-3.0-only
//
// Several rides at once (docs/specs/traffic-measurements.md §3.2): one total,
// the period the rides cover, a breakdown per year, and the roads to draw,
// each stretch once however often it was ridden.
import test from 'node:test';
import assert from 'node:assert/strict';
import { createBatch } from '../../assets/lib/ride-batch.js';

// Day numbers are days since 1970-01-01: 20454 is 2026-01-01.
const DAY_2025_06_01 = 20240;
const DAY_2026_03_12 = 20524;
const DAY_2026_09_28 = 20724;

const line = (day, over = {}) => ({ day, distanceM: 1000, passes: 0, nearby: 0, ...over });
const ride = (lines, matched = [], unmatchedLines = []) => ({
  lines,
  cars: lines.reduce((n, l) => n + l.passes, 0),
  nearby: lines.reduce((n, l) => n + l.nearby, 0),
  matchedKm: lines.reduce((m, l) => m + l.distanceM, 0) / 1000,
  matched,
  unmatchedLines,
});

test('totals, files and the period come from the rides themselves', () => {
  const batch = createBatch();
  batch.file();
  batch.add(ride([line(DAY_2026_03_12, { passes: 3 }), line(DAY_2026_03_12, { nearby: 2 })]));
  batch.file();
  batch.add(ride([line(DAY_2026_09_28, { passes: 1 })]));
  batch.add(ride([line(DAY_2025_06_01)]));
  const s = batch.summary();
  assert.equal(s.files, 2);
  assert.equal(s.rides, 3);
  assert.equal(s.km, 4);
  assert.equal(s.cars, 4);
  assert.equal(s.nearby, 2);
  assert.equal(s.fromDay, DAY_2025_06_01);
  assert.equal(s.toDay, DAY_2026_09_28);
});

test('the breakdown per year counts each ride in the year it was ridden', () => {
  const batch = createBatch();
  batch.add(ride([line(DAY_2025_06_01, { passes: 5 })]));
  batch.add(ride([line(DAY_2026_03_12, { passes: 1, nearby: 4 })]));
  batch.add(ride([line(DAY_2026_09_28, { distanceM: 2500 })]));
  assert.deepEqual(batch.summary().years, [
    { year: 2026, rides: 2, km: 3.5, cars: 1, nearby: 4 },
    { year: 2025, rides: 1, km: 1, cars: 5, nearby: 0 },
  ]);
});

test('a stretch ridden on many rides is drawn once', () => {
  const part = { id: 7, label: 'p', coords: [[5.0, 52.0], [5.001, 52.001]] };
  const other = { id: 8, label: 'r', coords: [[5.1, 52.1], [5.101, 52.101]] };
  const batch = createBatch();
  batch.add(ride([line(DAY_2026_03_12)], [part], [[[4.9, 51.9], [4.91, 51.91]]]));
  batch.add(ride([line(DAY_2026_09_28)], [{ ...part, coords: part.coords.map(c => [...c]) }, other], [[[4.9, 51.9], [4.91, 51.91]]]));
  const draw = batch.drawing();
  assert.deepEqual(draw.matched.map(p => p.id), [7, 8]);
  assert.equal(draw.unmatchedLines.length, 1);
  assert.deepEqual(draw.bounds, [[4.9, 51.9], [5.101, 52.101]]);
});

test('a ride without lines still counts as read but adds no period', () => {
  const batch = createBatch();
  batch.add(ride([]));
  const s = batch.summary();
  assert.equal(s.rides, 1);
  assert.equal(s.fromDay, null);
  assert.deepEqual(s.years, []);
});
