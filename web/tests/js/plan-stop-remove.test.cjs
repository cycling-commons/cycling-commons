// SPDX-License-Identifier: AGPL-3.0-only
//
// A stop on the map comes out by a right-click, or a click with the command key
// on a Mac (owner 2026-10-15; route-planner.md section 3). A click on a stop
// must never also reach the map, where it would add a stop on top of it.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const plan = fs.readFileSync(path.join(ROOT, 'assets/map/plan.js'), 'utf8');
const twig = fs.readFileSync(path.join(ROOT, 'templates/map/index.html.twig'), 'utf8');

test('right-click on a stop removes it, without the browser menu', () => {
  assert.match(plan, /el\.addEventListener\('contextmenu', e => \{ e\.preventDefault\(\); e\.stopPropagation\(\); removeStop\(i\); \}\);/);
});

test('a command-click removes it; a plain click does not', () => {
  assert.match(plan, /el\.addEventListener\('click', e => \{ if\(!e\.metaKey\) return; e\.preventDefault\(\); e\.stopPropagation\(\); removeStop\(i\); \}\);/);
});

test('a click on a stop never adds a stop', () => {
  const m = plan.match(/map\.on\('click', e => \{([\s\S]*?)\n {2}\}\);/);
  assert.ok(m, 'the map click handler was not found');
  assert.match(m[1], /t\.closest\('\.cc-plan-mk'\)\) return;/);
  assert.ok(m[1].indexOf('cc-plan-mk') < m[1].indexOf('addStop('), 'the guard has to come before the add');
});

/* A coverage pin clicked while planning moved the finish onto it (owner
   2026-10-15). map.js adds the pin layers at once when the style has already
   loaded, so a list of "basemap sources" taken at the planner's own start held
   the pins too, and a pin click read as an empty map click. The list is taken
   where the map is made, on every style load, before any module adds a source. */
test('the basemap sources are recorded where the map is made, not when the planner starts', () => {
  const load = fs.readFileSync(path.join(ROOT, 'assets/map/catalog-load.js'), 'utf8');
  assert.match(load, /window\.__ccMapInstance = new maplibregl\.Map\(window\.__ccMapOpts\);\n\s*recordBaseSources\(window\.__ccMapInstance\);/,
    'the listener has to be on the map before any other module can add a source');
  assert.match(load, /map\.on\('style\.load', \(\) => \{ window\.__ccBaseSources = new Set\(Object\.keys\(\(map\.getStyle\(\) \|\| \{\}\)\.sources \|\| \{\}\)\); \}\);/);
  const hit = plan.match(/function hitsFeature\(e\)\{([\s\S]*?)\n\}/);
  assert.ok(hit, 'hitsFeature not found');
  assert.match(hit[1], /const baseSources = window\.__ccBaseSources;/, 'read live, so a later style load is followed');
  assert.doesNotMatch(plan, /baseSources = new Set\(Object\.keys/, 'the planner must not take its own snapshot');
});

test('the rider is told, under the stops and on the stop', () => {
  assert.match(twig, /\{\{ 'map\.plan_remove_hint'\|trans \}\}/);
  assert.match(plan, /el\.title = s\.name \+ '\\n' \+ \(P\(\)\.removeHint \|\| ''\);/);
});
