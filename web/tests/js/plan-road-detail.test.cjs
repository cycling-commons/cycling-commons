// SPDX-License-Identifier: AGPL-3.0-only
//
// What OpenStreetMap records along a planned ride (route-planner.md section 4.4a, owner 2026-10-19: "why more
// detailed surfaces in bikerouter?", "can we also get max speeds for roads"). The server reads each way's recorded
// surface, smoothness and tracktype from the surface pipeline's table, and the engine's speed limit; a leg read that
// way is not corrected again by the map's lines, and the fields survive the browser's leg store.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = f => fs.readFileSync(path.join(ROOT, f), 'utf8');
const plan = read('assets/map/plan.js'), store = read('assets/map/leg-store.js'), tpl = read('templates/map/index.html.twig');

test('a leg read from the recorded surfaces is not corrected by the map lines', () => {
  assert.match(plan, /leg && leg\.routed && !leg\.recorded \? \{ \.\.\.leg, surfaces: mergeSurfaces\(/);
  assert.match(plan, /surfaceSpans\(ride\.legs, cls, leg => leg\.recorded \? null : tileSeen\.get\(leg\.key\), SAMPLE_M\)/);
});

test('both leg answers keep recorded, quality, speeds and their stretches, and so does the leg store', () => {
  const kept = plan.match(/recorded: !!(d|l)\.recorded, quality: \1\.quality \|\| null, speeds: \1\.speeds \|\| null, detailRuns: \1\.detailRuns \|\| null/g) || [];
  assert.equal(kept.length, 2, 'the one-leg and the many-legs answers');
  for (const f of ['recorded', 'quality', 'speeds', 'detailRuns']) assert.ok(store.includes(`'${f}'`), `the leg store drops ${f}`);
});

/* Owner 2026-10-19: "But what part of the road is that about? I can't hover it". */
test('every road detail line lights its stretches on the map, as the surface lines do', () => {
  assert.match(plan, /data-part="\$\{kind\}:\$\{k\}" tabindex="0"/);
  assert.match(plan, /if\(\/\^\(sm\|tt\|sp\):\/\.test\(cls\)\) return detailSpans\(ride\.legs, cls\.slice\(0, 2\), cls\.slice\(3\)\);/);
});

test('the detail sits under the day bars and every text is in all five languages', () => {
  assert.match(tpl, /<div class="cc-plan-daybars" id="planDaySvg"><\/div>\s*\{#[^#]*#\}\s*<div class="cc-plan-detail" id="planDetail" hidden><\/div>/);
  const keys = ['plan_detail_h', 'plan_detail_smooth', 'plan_detail_track', 'plan_detail_speed', 'plan_speed_unset',
    'plan_sm_excellent', 'plan_sm_good', 'plan_sm_intermediate', 'plan_sm_bad', 'plan_sm_very_bad',
    'plan_tt_grade1', 'plan_tt_grade2', 'plan_tt_grade3', 'plan_tt_grade4', 'plan_tt_grade5'];
  for (const l of ['en', 'fr', 'nl', 'de', 'es']) {
    const y = read(`translations/messages.${l}.yaml`);
    for (const k of keys) assert.match(y, new RegExp(`^ {2}${k}: "`, 'm'), `${l} ${k}`);
  }
});
