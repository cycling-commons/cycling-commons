// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The "Add a point" mode button is the TOUCH route to segment control
// points; right-click stays the pointer route. Two invariants matter and
// both die silently if broken:
//
// 1. While armed, a map tap must NEVER fall through to placeAt() - that
//    would let one stray thumb wipe the rider's whole shaped stretch.
// 2. The button, its armed help text and its label must exist in the twig
//    dict and all five locales, or the wizard renders a dead control.
//
// improve.js has no unit harness (it needs a live MapLibre map), so these
// are structural pins in the house two-lists style, plus the real-browser
// note from A-road-surface.md: synthetic events verify listeners, feel is
// checked in a real browser.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');

const js = read('assets/contribute/improve.js');
const twig = read('templates/contribute/improve.html.twig');
const LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

test('an armed tap adds a control point and never reaches placeAt', () => {
  const handler = js.match(/wmap\.on\('click', function \(e\) \{([\s\S]*?)\}\);/);
  assert.ok(handler, 'map click handler not found');
  assert.match(handler[1], /if \(armed\) \{ rightClickAt\(e\.lngLat, e\.point\); return; \}/,
    'the armed guard must return before placeAt - a missed tap while armed may not restart the stretch');
  assert.match(handler[1], /placeAt\(e\.lngLat\);/);
});

test('an armed tap on a control point removes it', () => {
  assert.match(js, /if \(!armed\) return;[\s\S]{0,120}removeCtrl\(m\);/,
    'the marker element needs the armed click-to-remove path');
});

test('the button disarms itself when the line goes away', () => {
  assert.match(js, /var usable = placed\.length === 2 && !confirmView;/);
  assert.match(js, /if \(!usable && armed\) setArmed\(false\);/);
});

test('the twig mounts the button and the JS dict carries both help strings', () => {
  assert.match(twig, /id="wzAddPt" aria-pressed="false" disabled/);
  for (const key of ['ctrl_point_btn', 'ctrl_point_help', 'ctrl_point_armed']) {
    assert.match(twig, new RegExp(`improve\\.step1\\.${key}`), `twig is missing ${key}`);
  }
});

test('the strings exist in all five locales', () => {
  for (const l of LOCALES) {
    const y = read(`translations/messages.${l}.yaml`);
    for (const key of ['ctrl_point_btn', 'ctrl_point_armed']) {
      assert.match(y, new RegExp(`^\\s*${key}: `, 'm'), `messages.${l}.yaml is missing ${key}`);
    }
  }
});
