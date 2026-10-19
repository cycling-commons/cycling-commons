// SPDX-License-Identifier: AGPL-3.0-only
//
// The planner's legs kept in this browser (route-planner.md section 6.0): what a kept leg holds, and which go first.
import test from 'node:test';
import assert from 'node:assert/strict';
import { legRecord, forgetLeg, keptLegUsable, KEEP_LEGS, LEG_DB } from '../../assets/map/leg-store.js';
import { WORK_KEEP_MS } from '../../assets/map/plan-model.js';

test('a kept leg holds the engine\'s answer and the repeat verdict, nothing the page adds', () => {
  const leg = { key: 'a|b|Road', routed: true, line: [[5, 52], [5.1, 52]], distM: 7000, heights: [[0, 1]], surfaces: { paved: 7000 }, streets: [], runs: [[0, 'paved']], busyRuns: [], otherWay: true, repeatChecked: 'x', hovered: true, el: {} };
  const rec = legRecord(leg, 1000);
  assert.equal(rec.at, 1000);
  assert.equal(rec.otherWay, true);
  assert.equal(rec.repeatChecked, 'x', 'the repeat check would ask again on every visit');
  assert.equal('hovered' in rec, false);
  assert.equal('el' in rec, false);
});

test('a kept leg goes after seven days, or oldest first past the cap', () => {
  const now = 10 * WORK_KEEP_MS;
  assert.equal(forgetLeg(now - 1000, 0, now), false, 'a fresh leg within the cap goes');
  assert.equal(forgetLeg(now - WORK_KEEP_MS, 0, now), true, 'a leg past seven days stays');
  assert.equal(forgetLeg(now - 1000, 1, now), true, 'the cap is not kept');
  assert.equal(forgetLeg(now + 1000, 0, now), true, 'a stamp from the future is trusted');
  assert.equal(KEEP_LEGS, 500);
  assert.equal(LEG_DB, 'cc-plan');
});

/* A leg kept before the server sent the road detail and where it lies (route-planner.md section 4.4a, owner
   2026-10-19: "where do I see the data?", "I can't hover it") has no `detailRuns`: it is asked again, or the ride shows
   no smoothness, track grade or speed limit to point at for a week. An answer whose walk found nothing carries
   `detailRuns: null` and is kept as it is. */
test('a kept leg from before the road detail is asked again', () => {
  const now = Date.now();
  assert.equal(keptLegUsable({ routed: true, at: now, surfaces: {} }, now), false);
  assert.equal(keptLegUsable({ routed: true, at: now, speeds: { 50: 100 } }, now), false, 'totals without their stretches');
  assert.equal(keptLegUsable({ routed: true, at: now, speeds: { 50: 100 }, detailRuns: { speeds: [[0, 100, '50']] } }, now), true);
  assert.equal(keptLegUsable({ routed: true, at: now, speeds: null, detailRuns: null }, now), true);
  assert.equal(keptLegUsable({ routed: true, at: now - WORK_KEEP_MS - 1, detailRuns: {} }, now), false, 'past its seven days');
  assert.equal(keptLegUsable({ routed: false, at: now, detailRuns: {} }, now), false);
  assert.equal(keptLegUsable(null, now), false);
});
