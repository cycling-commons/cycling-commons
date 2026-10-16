// SPDX-License-Identifier: AGPL-3.0-only
//
// A water stop the planner cannot add says so in the middle of the screen, not in a line in the drawer
// (owner 2026-10-16: "show it in a popup in the center of the screen. not somewhere small in the drawer").
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const twig = read('templates/map/index.html.twig');
const plan = read('assets/map/plan.js');
const css = read('assets/styles/map.css');
const controller = read('src/Controller/MapController.php');
const body = name => (plan.match(new RegExp(`(?:async )?function ${name}\\([^)]*\\)\\{([\\s\\S]*?)\\n\\}`)) || [])[1] || '';

test('the notice is a modal dialog in the middle of the screen, with a heading, the reason and one OK', () => {
  const dlg = (twig.match(/<dialog class="cc-plan-dlg" id="planNotice"[\s\S]*?<\/dialog>/) || [''])[0];
  assert.match(dlg, /aria-labelledby="planNoticeH"/);
  assert.match(dlg, /<h3 id="planNoticeH"><\/h3>/);
  assert.match(dlg, /<p id="planNoticeText"><\/p>/);
  assert.match(dlg, /<form method="dialog" class="cc-plan-dlg-acts">[\s\S]*<button value="ok" class="cc-ride-btn cc-plan-go" autofocus>\{\{ 'map\.plan_notice_ok'\|trans \}\}<\/button><\/form>/);
  assert.match(twig, /<dialog class="cc-plan-dlg" id="planReplace"/, 'the two questions share one look');
  assert.match(css, /\.cc-plan-dlg\{[^}]*margin:auto;/, 'the global reset takes a dialog\'s centring away');
});

test('every water stop that is not added opens the notice; one that is added stays a line in the drawer', () => {
  const fill = body('fillWater'), check = body('checkFill');
  assert.match(fill, /const none = \(\) => notice\(P\(\)\.fillWaterH \|\| 'No water stop added', tpl\(P\(\)\.fillWaterNone/);
  assert.match(fill, /notice\(P\(\)\.fillWaterH \|\| 'No water stop added', P\(\)\.fillWaterUseless/);
  assert.match(fill, /notice\(P\(\)\.fillWaterH \|\| 'No water stop added', tpl\(P\(\)\.fillWaterAlready/);
  assert.match(check, /notice\(P\(\)\.fillWaterH \|\| 'No water stop added', tpl\(P\(\)\.fillWaterUndone/);
  assert.match(check, /setStatus\(tpl\(P\(\)\.fillWaterDone/, 'success keeps its line');
  assert.doesNotMatch(fill + check, /setStatus\(tpl\(P\(\)\.fillWater(None|Undone)|setStatus\(P\(\)\.fillWaterUseless/);
  assert.match(controller, /'fillWaterH' => \$t->trans\('map\.plan_fill_water_h'\)/);
  assert.match(controller, /'fillWaterAlready' => \$t->trans\('map\.plan_fill_water_already'\)/);
});

test('Escape closes either dialog and nothing behind it; undo waits while one is open', () => {
  assert.match(plan, /for\(const id of \['planReplace', 'planNotice'\]\)/);
  assert.match(plan, /if\(e\.key !== 'Escape'\) return; const open = \['planReplace', 'planNotice'\]\.map\(\$\)\.find\(d => d && d\.open\);/);
  assert.match(plan, /if\(\['planReplace', 'planNotice'\]\.some\(id => \{ const d = \$\(id\); return d && d\.open; \}\)\) return;/);
});

test('the notice copy is in every language', () => {
  for (const l of ['en', 'fr', 'nl', 'de', 'es']) {
    const y = read(`translations/messages.${l}.yaml`);
    for (const k of ['plan_notice_ok', 'plan_fill_water_h', 'plan_fill_water_already']) assert.match(y, new RegExp(`^ {2}${k}: "`, 'm'), `${l} ${k}`);
  }
});

/* "Who is Koning. the name of the waterstop. where is it." (owner 2026-10-16): the notice says what the place is and
   where, and shows it on the map. */
test('a water stop that is not added is named with its kind and where it lies, and can be shown on the map', () => {
  const where = body('fillWhere');
  assert.match(where, /kind: \(P\(\)\.waterKinds \|\| \{\}\)\[waterKind\(\{ food: s\.food, potable: s\.potable \}\)\]/);
  assert.match(where, /off: uKm\(s\.distM \/ 1000, 1\), at: uKm\(s\.alongM \/ 1000, 0\)/);
  assert.match(body('fillWater'), /tpl\(P\(\)\.fillWaterAlready[^)]*, fillWhere\(stop\)\), stop\)/);
  assert.match(body('checkFill'), /fillWhere\(p\.stop\)\), p\.stop\)/);
  assert.match(twig, /<button type="button" class="cc-ride-btn" id="planNoticeShow" hidden>\{\{ 'map\.plan_notice_show'\|trans \}\}<\/button>/);
  const show = body('notice');
  assert.match(show, /\$\('planNoticeShow'\)\.hidden = !place;/);
  assert.match(plan, /flyToPin\(\[noticePlace\.lng, noticePlace\.lat\]\); highlightAt\(\[noticePlace\.lat, noticePlace\.lng\], \[0, 0\], null\);/);
  assert.match(controller, /'waterKinds' => \[/);
  for (const l of ['en', 'fr', 'nl', 'de', 'es']) {
    const y = read(`translations/messages.${l}.yaml`);
    for (const k of ['plan_fill_water_undone', 'plan_fill_water_already']) {
      const line = (y.match(new RegExp(`^ {2}${k}: "([^"]*)"`, 'm')) || [])[1] || '';
      for (const ph of ['{name}', '{kind}', '{off}', '{at}']) assert.ok(line.includes(ph), `${l} ${k} lacks ${ph}`);
    }
    assert.match(y, /^ {2}plan_notice_show: "/m, `${l} plan_notice_show`);
  }
});
