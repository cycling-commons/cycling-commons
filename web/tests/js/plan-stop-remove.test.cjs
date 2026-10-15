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

test('the rider is told, under the stops and on the stop', () => {
  assert.match(twig, /\{\{ 'map\.plan_remove_hint'\|trans \}\}/);
  assert.match(plan, /el\.title = s\.name \+ '\\n' \+ \(P\(\)\.removeHint \|\| ''\);/);
});
