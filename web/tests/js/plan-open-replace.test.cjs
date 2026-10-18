// SPDX-License-Identifier: AGPL-3.0-only
//
// Opening a ride file (docs/specs/route-planner.md section 6.1): the button
// is there from the empty planner on, and a file opened over a ride being
// planned asks first, because it replaces that ride (owner 2026-10-16).
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const twig = read('templates/map/index.html.twig');
const plan = read('assets/map/plan.js');

function block(src, startRe, endRe) {
  const s = src.search(startRe);
  assert.ok(s >= 0, `${startRe} not found`);
  const rest = src.slice(s);
  const e = rest.search(endRe);
  return e < 0 ? rest : rest.slice(0, e);
}

test('Open a GPX stands in the start group, so an empty planner can open a file', () => {
  const start = block(twig, /<div class="grp cc-plan" id="planStart">/, /\n      <\/div>\n/);
  assert.match(start, /id="planOpen"/);
  assert.match(start, /<input type="file" id="planOpenFile" accept="\.gpx,application\/gpx\+xml" hidden>/);
  // The ride file in one place (owner 2026-10-17): Download GPX and Clear share the row with Open a GPX.
  assert.match(start, /id="planGpx"/);
  assert.match(start, /id="planClear"/);
  assert.equal((twig.match(/id="planOpen"/g) || []).length, 1, 'one Open button, not two');
});

test('the replace question is a modal dialog: keep the ride, download it first, or open the file', () => {
  const dlg = block(twig, /<dialog class="cc-plan-dlg" id="planReplace"/, /<\/dialog>/);
  assert.match(dlg, /aria-labelledby="planReplaceH"/);
  assert.match(dlg, /<form method="dialog"/);
  assert.match(dlg, /id="planReplaceFile"/);
  assert.match(dlg, /<button type="button" class="cc-ride-btn" id="planReplaceGpx">/);
  assert.match(dlg, /<button value="keep" class="cc-ride-btn cc-plan-quiet" autofocus>/, 'the safe answer has the focus');
  assert.match(dlg, /<button value="open" class="cc-ride-btn cc-plan-go">/);
});

test('a file opened over a ride being planned asks first; an empty planner opens it straight away', () => {
  const open = block(plan, /function openRideFile\(file\)\{/, /\n\}\n/);
  assert.match(open, /if\(!ride\.stops\.length\)\{ applyRideFile\(r, file\.name\); return; \}/);
  assert.match(open, /askReplace\(file\.name\)\.then\(ok => \{ if\(ok\) applyRideFile\(r, file\.name\); \}\);/);
  // A refused file is said before anything is asked: no question for a file that cannot open.
  assert.ok(open.indexOf('if(r.error)') < open.indexOf('askReplace('), 'the refusal comes before the question');
});

test('the question answers by the dialog close value; Escape and the backdrop keep the ride', () => {
  const ask = block(plan, /function askReplace\(fileName\)\{/, /\n\}\n/);
  assert.match(ask, /dlg\.returnValue === 'open'/);
  assert.match(ask, /dlg\.showModal\(\);/);
  assert.match(ask, /\$\('planReplaceGpx'\)/);
  assert.match(plan, /if\(e\.target === d\) d\.close\('keep'\);/);
  // Caught first, as the guide's Escape is, so the panel's own Escape does not close the planner under it.
  assert.match(plan, /if\(open\)\{ e\.preventDefault\(\); e\.stopImmediatePropagation\(\); open\.close\('keep'\); \}/);
  assert.match(plan, /const g = \$\('planReplaceGpx'\); if\(g\) g\.addEventListener\('click', downloadGpx\);/);
});

test('undo and redo stay off while the question is open', () => {
  assert.match(plan, /if\(\['planReplace', 'planNotice'\]\.some\(id => \{ const d = \$\(id\); return d && d\.open; \}\)\) return;/);
});
