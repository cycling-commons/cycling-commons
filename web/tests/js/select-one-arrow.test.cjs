// SPDX-License-Identifier: AGPL-3.0-only
//
// Every dropdown shows ONE arrow.
//
// The site's `select` rule (atlas.css) draws the native select's arrow as a
// background image. select-box.js copies every `select` rule onto the button
// that stands in for the select, and the button draws its own arrow
// (`.cc-sel-btn::after`), so the copied image put a second arrow beside it on
// every dropdown on the site ("Drinking tap ⌄ ⌄", owner 2026-10-01). Nothing
// errors; it only looks wrong. select-box.css drops the button's background
// image, and this pins that the drop, the drawn arrow and the cause are all
// still where this says they are.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8');
const box = read('assets/styles/select-box.css');
const atlas = read('assets/styles/atlas.css');
const mirror = read('assets/js/select-box.js');

test('the site select rule draws its arrow as a background image', () => {
  const rule = atlas.match(/(?:^|\n)select\{([^}]*)\}/);
  assert.ok(rule, 'atlas.css has no bare select rule');
  assert.match(rule[1], /background-image:url\(/, 'the cause this test guards against is gone; revisit the drop');
});

test('the mirror copies select rules onto the button', () => {
  assert.match(mirror, /\.cc-sel-btn/);
  assert.match(mirror, /cssText/);
});

test('the button draws its own arrow and drops the copied one', () => {
  assert.match(box, /\.cc-sel-btn::after\{content:""/, 'the drawn arrow is gone');
  assert.match(box, /\.cc-sel-btn\{background-image:none!important\}/, 'a copied background arrow would show beside the drawn one');
});
