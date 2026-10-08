// SPDX-License-Identifier: AGPL-3.0-only
//
// The pending note closes once read, for a curator ("pins waiting for review
// follow your areas") and for a rider ("your pins show wherever you added
// them"), each under its own name (owner 2026-10-08). The answer lives on the account (window.CC_HINTS,
// POST /map/hint/{hint}/close), so the note stays closed on every device.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const render = fs.readFileSync(path.join(ROOT, 'assets', 'map', 'render.js'), 'utf8');
const css = fs.readFileSync(path.join(ROOT, 'assets', 'styles', 'map.css'), 'utf8');
const twig = fs.readFileSync(path.join(ROOT, 'templates', 'map', 'index.html.twig'), 'utf8');
const hint = (render.match(/export function updateZoomHint\(\)\{[\s\S]*?\n\}/) || [''])[0];

test('a closed pending note is not shown again, to a curator or a rider', () => {
  assert.match(render, /const HINT_PENDING='pending_follows_areas', HINT_PENDING_MINE='pending_yours_anywhere';/);
  assert.match(hint, /const hintKey = isCurator \? HINT_PENDING : HINT_PENDING_MINE;/);
  assert.match(hint, /pendingOn && !hintClosed\(hintKey\)/);
});

test('the note carries a close button only when the page can store the answer', () => {
  assert.match(hint, /const closable = pendingShown && !!\(window\.CC_HINTS\|\|\{\}\)\.url;/);
  assert.match(hint, /el\.classList\.toggle\('has-x', closable\)/);
  assert.match(css, /\.zoom-hint \.zoom-hint-x\{[^}]*position:absolute;top:[^;]+;right:/, 'the close sits in the top-right corner');
  assert.match(css, /\.zoom-hint\.has-x\{padding-right:/, 'the text keeps clear of it');
  assert.match(hint, /onclick=\(\)=>closeHint\(hintKey\)/);
  assert.match(css, /\.zoom-hint \.zoom-hint-x\{[^}]*pointer-events:auto/, 'the hint ignores the pointer; its button must not');
});

test('closing hides the note at once and stores it with the CSRF header', () => {
  const close = (render.match(/function closeHint\(h\)\{[\s\S]*?\n\}/) || [''])[0];
  assert.match(close, /updateZoomHint\(\)/);
  assert.match(close, /fetch\(H\.url\.replace\('HINT', h\), \{method:'POST', headers:\{'X-CSRF-Token':H\.token\}\}\)/);
});

test('the page hands the closed list, and the endpoint only to a logged-in person', () => {
  assert.match(twig, /window\.CC_HINTS = \{\{ \(\{closed: map_hints \?\? \[\]\}\|merge\(app\.user \?/);
});
