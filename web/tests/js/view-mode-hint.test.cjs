// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The view-mode hint describes the mode that is ON, not all three at once.
//
// It used to be one paragraph naming Best of, Confirmed and Everything in
// sequence, sitting under a three-way toggle. A rider reading it had to find
// their own mode inside a description of two others (owner 2026-08-31).
//
// The swap needs no client i18n plumbing: the three strings ride on the element
// as data attributes, keyed by the same values the buttons carry in `data-m`,
// and panels.js copies the matching one into the text. That is three lists that
// have to agree (the buttons, the attributes, the catalogue keys) and this file
// is what stops them drifting apart, because nothing else would notice: a
// missing attribute leaves the previous mode's sentence on screen, which reads
// as correct and is not.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const twig = fs.readFileSync(path.join(ROOT, 'templates/map/index.html.twig'), 'utf8');
const panels = fs.readFileSync(path.join(ROOT, 'assets/map/panels.js'), 'utf8');

/** The mode values the toggle actually offers. */
const modes = [...twig.matchAll(/<button[^>]*data-m="([a-z]+)"/g)].map((m) => m[1]);

test('the toggle offers exactly the three modes', () => {
  assert.deepEqual(modes.sort(), ['all', 'confirmed', 'curated']);
});

test('the hint carries one string per mode, keyed by the same values', () => {
  const hint = twig.match(/<div class="hint" id="modeHint"[\s\S]*?<\/div>/);
  assert.ok(hint, 'the hint element must carry id="modeHint"');

  for (const m of modes) {
    assert.match(
      hint[0],
      new RegExp(`data-${m}="\\{\\{ 'map\\.view_hint_${m}'\\|trans \\}\\}"`),
      `the hint must carry data-${m} from map.view_hint_${m}`,
    );
  }
});

test('the rendered text matches the button that starts on', () => {
  // A mismatch here shows the wrong sentence until the first click, which is
  // the one moment a first-time rider is most likely to read it.
  const on = twig.match(/<button class="on" data-m="([a-z]+)"/);
  assert.ok(on, 'one mode button must start marked `on`');
  const hint = twig.match(/<div class="hint" id="modeHint"[\s\S]*?<\/div>/)[0];
  assert.match(
    hint,
    new RegExp(`>\\{\\{ 'map\\.view_hint_${on[1]}'\\|trans \\}\\}</div>`),
    `the hint must render map.view_hint_${on[1]}, matching the button that starts on`,
  );
});

test('panels.js swaps the text on every mode change', () => {
  assert.match(panels, /getElementById\('modeHint'\)/, 'panels.js must read the hint element');
  assert.match(
    panels,
    /hint\.dataset\[m\]\) hint\.textContent = hint\.dataset\[m\]/,
    'the swap must key on the same `m` the buttons carry, not a second vocabulary',
  );
});

test('the three strings exist in all five locales, and the old one is gone', () => {
  for (const loc of ['en', 'fr', 'nl', 'de', 'es']) {
    const yaml = fs.readFileSync(path.join(ROOT, `translations/messages.${loc}.yaml`), 'utf8');
    for (const m of modes) {
      assert.match(yaml, new RegExp(`^  view_hint_${m}:`, 'm'), `${loc} is missing view_hint_${m}`);
    }
    // The paragraph it replaced. Left behind it would be a dead key that still
    // reads as the live copy to anyone grepping for the wording.
    assert.doesNotMatch(yaml, /^ {2}curated_hint:/m, `${loc} still carries the retired curated_hint`);
  }
});
