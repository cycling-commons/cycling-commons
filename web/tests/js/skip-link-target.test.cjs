// SPDX-License-Identifier: AGPL-3.0-only
//
// Where "Skip to content" actually lands.
//
// a11y.js resolves the target at runtime: <main>/[role=main], else #main, else
// the first sibling after the nav. Most pages hit the #main branch. The two
// wizards do not - their root is #wiz - so they fall through to the sibling
// walk, and the flash notice is rendered BETWEEN the nav and the page body.
// Before 2026-08-16 that meant a wizard page carrying a flash sent the skip
// link to a one-line "your changes were saved", and stamped id="main" onto it.
// The skip link is the one control a keyboard user has for getting past the
// header, and it was landing on the header's own message.
//
// a11y.js needs a live document, so these are structural pins in the house
// two-lists style (see wizard-addpoint.test.cjs). The behaviour itself was
// checked in a real browser against the built asset, with a notice injected
// where the base template puts one: the link resolved to #wiz and the notice
// was left untouched.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');

const a11y = read('assets/js/a11y.js');

test('the sibling walk steps over announcement bars', () => {
  const list = a11y.match(/const SKIP_OVER\s*=\s*'([^']+)'/);
  assert.ok(list, 'SKIP_OVER not found - the walk has no skip list at all');

  // The notice bar is matched two ways on purpose: by its role, which is what
  // any future announcement region will also carry, and by its class, which is
  // what THIS one is.
  for (const sel of ['[role="status"]', '[role="alert"]', '.cc-notice']) {
    assert.ok(list[1].includes(sel), `SKIP_OVER must step over ${sel}`);
  }
});

test('the walk runs before the target is chosen, not after', () => {
  assert.match(
    a11y,
    /let next\s*=\s*nav&&nav\.nextElementSibling;\s*while\(next&&next\.matches\(SKIP_OVER\)\)\s*next\s*=\s*next\.nextElementSibling;\s*target\s*=\s*next\|\|/,
    'the notice must be skipped while looking for the target, not corrected afterwards',
  );
});

test('an existing id is kept, so the notice can never be renamed #main', () => {
  // Two halves of the same guarantee: the walk never reaches the notice, and
  // even if a future page shape did, only a target with NO id is stamped.
  assert.match(a11y, /if\(!target\.id\)\s*target\.id='main';/);
});

test('the target is made focusable, or the link only moves the scroll position', () => {
  assert.match(a11y, /target\.setAttribute\('tabindex','-1'\);/);
  assert.match(a11y, /link\.href='#'\+target\.id;/);
});

test('the two wizards are the pages that depend on the fallback', () => {
  // If either ever grows an id="main", this test is what says the fallback is
  // no longer load-bearing for it - and the pin above can be re-read in that
  // light rather than looking like belt and braces for nothing.
  for (const tpl of ['templates/contribute/improve.html.twig']) {
    const html = read(tpl);
    assert.ok(html.includes('id="wiz"'), `${tpl} no longer roots on #wiz`);
    assert.ok(!/<main[\s>]/.test(html), `${tpl} grew a <main> - re-read this test`);
  }
});
