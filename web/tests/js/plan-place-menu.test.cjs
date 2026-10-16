// SPDX-License-Identifier: AGPL-3.0-only
//
// A place on the map goes into the ride by a right-click, or a click with the
// command key on a Mac, as "Add to ride" in its drawer does, and comes out the
// same way (owner 2026-10-15; route-planner.md section 4.3). One rule says
// what a place is to the ride (drawer.js planPlace), and one event carries it
// from a pin to the planner (`cc:place-menu`), so the drawer button and the
// pins cannot drift apart.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const drawer = read('assets/map/drawer.js');
const render = read('assets/map/render.js');
const coverage = read('assets/map/coverage.js');
const plan = read('assets/map/plan.js');
const twig = read('templates/map/index.html.twig');

test('one rule says what a place is to the ride, and the drawer button uses it', () => {
  assert.match(drawer, /export function planPlace\(layer, f\)\{/);
  assert.match(drawer, /const pp = planPlace\(layer, f\);/, 'the Add to ride button builds from planPlace');
});

test('a pin drawn on the page offers itself on a right-click or a command-click', () => {
  assert.match(render, /el\.addEventListener\('contextmenu', e=>\{ if\(offerToRide\(planPlace\(layer,f\)\)\)\{ e\.preventDefault\(\); e\.stopPropagation\(\); \} \}\);/,
    'taken, the click stops at the pin, so a tile icon under it is not offered too');
  assert.match(render, /if\(e\.metaKey && offerToRide\(planPlace\(layer,f\)\)\)\{ e\.preventDefault\(\); return; \}/,
    'a command-click on a pin adds it instead of opening the drawer');
});

test('a coverage icon offers itself the same way', () => {
  assert.match(coverage, /map\.on\('contextmenu', e=>\{ const f0=covHit\(e, 10\); if\(!f0\) return;/, 'a right-click on the tip of a drop misses the pin');
  assert.match(coverage, /const at=pad \? \[\[e\.point\.x-pad, e\.point\.y-pad\], \[e\.point\.x\+pad, e\.point\.y\+pad\]\] : e\.point;/);
  // The coordinates popup gives way to a right-click the planner took (owner 2026-10-16).
  assert.match(read('assets/map/map-init.js'), /setTimeout\(\(\)=>\{ if\(!\(e\.originalEvent && e\.originalEvent\.ccTaken\)\) showCoords\(e\); \}, 0\);/, 'MapLibre cancels every right-click itself, so only our own mark says the planner took it');
  assert.match(coverage, /e\.originalEvent\.preventDefault\(\); e\.originalEvent\.ccTaken = true;/);
  assert.match(coverage, /if\(e\.originalEvent && e\.originalEvent\.metaKey && offerToRide\(coveragePlace\(f0\)\)\) return;/,
    'a command-click on an icon adds it instead of opening the drawer');
});

test('the offer is one synchronous event the planner answers', () => {
  const util = read('assets/map/util.js');
  assert.match(util, /export function offerToRide\(place\)\{/);
  assert.match(util, /new CustomEvent\('cc:place-menu', \{detail\}\)/);
  const m = plan.match(/document\.addEventListener\('cc:place-menu', e => \{([\s\S]*?)\}\);/);
  assert.ok(m, 'the planner does not listen for cc:place-menu');
  assert.match(m[1], /if\(!planning\(\) \|\| !e\.detail\.place\) return;/, 'outside planning a right-click keeps its usual meaning');
  assert.match(m[1], /togglePlace\(e\.detail\.place\);/, 'in, or out again when it is in the ride');
  assert.match(m[1], /e\.detail\.handled = true;/);
});

test('the rider is told under the stops, in all five languages', () => {
  assert.match(twig, /\{\{ 'map\.plan_place_hint'\|trans \}\}/);
  for (const l of ['en', 'fr', 'nl', 'de', 'es']) {
    assert.match(read(`translations/messages.${l}.yaml`), /^ {2}plan_place_hint: "/m, `messages.${l}.yaml lacks map.plan_place_hint`);
  }
});
