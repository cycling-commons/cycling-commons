// SPDX-License-Identifier: AGPL-3.0-only
//
// The ride planner's wiring (docs/specs/route-planner.md): structural pins over
// the source files, the pattern rail-shell.test.cjs uses. The browser never
// talks to the routing engine, the map page hands the script its endpoints
// and tokens, the drawer writes the Add to ride button, and the scope click
// stands aside while a ride is planned.
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const twig = read('templates/map/index.html.twig');
const plan = read('assets/map/plan.js');
const model = read('assets/map/plan-model.js');
const drawer = read('assets/map/drawer.js');
const scopeUi = read('assets/map/scope-ui.js');
const entry = read('assets/map/map.js');
const controller = read('src/Controller/MapController.php');

test('the planner never names the routing engine: every leg goes through our server', () => {
  for (const src of [plan, model]) {
    assert.ok(!/valhalla|:8002|openrouteservice|osrm/i.test(src), 'a planner module names a routing engine');
  }
  assert.ok(plan.includes('CC_PLAN.leg'), 'plan.js does not post legs to the server endpoint');
  assert.ok(plan.includes('CC_PLAN.along'), 'plan.js does not ask the server what is along the ride');
  assert.ok(plan.includes("'X-CC-Token'"), 'plan.js sends no stateless token');
});

test('the map page hands the script its endpoints and tokens, and a visitor gets no save endpoint', () => {
  assert.ok(twig.includes('window.CC_PLAN ='), 'no CC_PLAN blob in the map template');
  for (const needle of ["path('map_plan_leg')", "csrf_token('plan-leg')", "path('map_plan_along')", "csrf_token('plan-along')"]) {
    assert.ok(twig.includes(needle), `the CC_PLAN blob lacks ${needle}`);
  }
  const start = twig.indexOf('window.CC_PLAN =');
  const blob = twig.slice(start, twig.indexOf('</script>', start));
  assert.ok(/merge\(app\.user \? \{[\s\S]*map_plan_save/.test(blob), 'the save endpoint is not behind app.user');
});

test('the model is a leaf and the entry wires the planner', () => {
  assert.ok(!/^import /m.test(model), 'plan-model.js imports something: it must stay a leaf for node tests');
  assert.ok(entry.includes("from './plan.js'") && /initPlan\(\)/.test(entry), 'map.js does not wire initPlan()');
});

test('the drawer writes Add to ride for a place with a point, and the scope click stands aside while planning', () => {
  assert.ok(drawer.includes('data-plan-add'), 'drawer.js writes no Add to ride button');
  assert.ok(/layer\.key !== 'experience' && layer\.key !== 'surface'/.test(drawer), 'a route or a surface stretch must never become a stop');
  assert.ok(scopeUi.includes("from './plan-state.js'") && /if\(isPlanning\(\)\) return;/.test(scopeUi), 'click-to-scope ignores planning');
});

test('the drawer repaint writes a button only when its label changes, or the observer loops forever', () => {
  // paintDrawerButtons runs from a MutationObserver on the drawer. Setting
  // textContent is itself a childList mutation, so an unconditional write
  // re-triggers the observer without end and the page hangs (owner-reported
  // 2026-10-14 on a climb's drawer).
  const fn = plan.slice(plan.indexOf('function paintDrawerButtons'), plan.indexOf('\n}', plan.indexOf('function paintDrawerButtons')));
  assert.ok(/if\(b\.textContent !== label\) b\.textContent = label;/.test(fn), 'the label is written without checking it changed');
  assert.ok(!/b\.textContent = inRide \?/.test(fn), 'the label is written unconditionally');
});

test('every stop row, a map point too, takes the map to its stop when its name is clicked', () => {
  // Owner 2026-10-14: a click on a stop in the list pans and zooms to it.
  assert.ok(plan.includes('<b class="lnk" data-fly="1">'), 'the stop name is not a fly-to link for every stop');
  assert.ok(!plan.includes(`<b\${s.item ? ' class="lnk" data-fly="1"' : ''}>`), 'only place stops fly to their point');
  assert.ok(/data-fly[\s\S]*flyToPin\(\[s\.lng, s\.lat\]\)/.test(plan), 'the click does not fly to the stop');
});

test('the height profile also runs along the foot of the map, and the panel keeps its own', () => {
  // Owner 2026-10-14: a wide strip at the bottom of the map, the panel's profile kept for comparison.
  assert.ok(twig.includes('id="planStrip"') && twig.includes('id="planStripSvg"'), 'no profile strip in the map template');
  assert.ok(twig.includes('id="planProfSvg"'), 'the panel profile is gone');
  assert.ok(plan.includes("$('planStripSvg')") && plan.includes("$('planProfSvg')"), 'plan.js does not draw both profiles');
});

test('the toughness is explained by a graphic under its tile', () => {
  // Owner 2026-10-15: an infographic of the toughness from all its elements.
  assert.ok(twig.includes('id="planToughSvg"'), 'no toughness graphic in the map template');
  assert.ok(plan.includes("$('planToughSvg')") && plan.includes('toughnessBars('), 'plan.js does not draw the toughness graphic');
});

test('the controller hands every planner string to the script', () => {
  assert.ok(controller.includes("'railPlan' => $t->trans('map.rail_plan')"), 'the rail title is not translated');
  for (const key of ['plan_no_road', 'plan_max_stops', 'plan_already', 'plan_rate_limited', 'plan_engine_down', 'plan_saved', 'plan_too_many', 'plan_bar_stops', 'plan_leave']) {
    assert.ok(controller.includes(`'map.${key}'`), `the controller does not translate map.${key}`);
  }
  assert.ok(controller.includes("'planAdd' => 'd_plan_add'"), 'the drawer button label is not in the drawer dictionary');
});
