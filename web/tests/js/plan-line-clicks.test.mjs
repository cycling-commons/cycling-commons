// SPDX-License-Identifier: AGPL-3.0-only
//
// While planning, a click on a line or an area of ours is a stop, and its own
// drawer stands aside; a point of ours stays a click on that place
// (docs/specs/route-planner.md section 3, owner 2026-10-17: the road under a
// surface line took no stop).
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { setPlanning, isLineOrArea, plannerTakesLineClick } from '../../assets/map/plan-state.js';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = rel => fs.readFileSync(path.join(ROOT, rel), 'utf8');
const feature = type => ({ geometry: { type } });

test('lines and areas are lines; points are not', () => {
  for (const t of ['LineString', 'MultiLineString', 'Polygon', 'MultiPolygon']) assert.equal(isLineOrArea(feature(t)), true, t);
  for (const t of ['Point', 'MultiPoint']) assert.equal(isLineOrArea(feature(t)), false, t);
  assert.equal(isLineOrArea(null), false);
});

test('a line click stands aside only while planning', () => {
  const onLine = { features: [feature('LineString')] }, onPoint = { features: [feature('Point')] };
  setPlanning(false);
  assert.equal(plannerTakesLineClick(onLine), false, 'not planning: the line opens its drawer');
  setPlanning(true);
  assert.equal(plannerTakesLineClick(onLine), true, 'planning: the line click is a stop');
  assert.equal(plannerTakesLineClick(onPoint), false, 'a point is still the place');
  assert.equal(plannerTakesLineClick({ features: [] }), false);
  setPlanning(false);
});

test('every line and area click handler asks the planner first, and the planner skips only points', () => {
  const guarded = {
    'assets/map/routes-tiles.js': 2, 'assets/map/surface-tiles.js': 3, 'assets/map/render.js': 2,
    'assets/map/traffic-layer.js': 1, 'assets/map/mapillary.js': 1,
  };
  for (const [file, n] of Object.entries(guarded)) {
    const src = read(file);
    assert.equal((src.match(/plannerTakesLineClick\(e\)/g) || []).length, n, file);
  }
  const plan = read('assets/map/plan.js');
  assert.match(plan, /f\.source !== 'plan-straight' && !isLineOrArea\(f\)\);/, 'the planner still skips a click on a line');
});
