// SPDX-License-Identifier: AGPL-3.0-only
// Settings, release list: the "how often" radios follow the switch above them
// (assets/settings/updates-cadence.js).
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const src = fs.readFileSync(path.join(__dirname, '../../assets/settings/updates-cadence.js'), 'utf8');
const twig = fs.readFileSync(path.join(__dirname, '../../templates/settings/index.html.twig'), 'utf8');

function page(on) {
  const choices = { hidden: false };
  let onChange = null;
  const toggle = { checked: on, addEventListener: (t, f) => { if (t === 'change') onChange = f; } };
  const document = {
    querySelector: (s) => (s === '[data-updates-toggle]' ? toggle : s === '[data-updates-cadence]' ? choices : null),
  };
  vm.runInNewContext(src, { document });
  return { choices, flip(v) { toggle.checked = v; onChange(); } };
}

test('switch on: the radios show, and hide when it goes off', () => {
  const p = page(true);
  assert.equal(p.choices.hidden, false);
  p.flip(false);
  assert.equal(p.choices.hidden, true);
  p.flip(true);
  assert.equal(p.choices.hidden, false);
});

test('switch off on load: the radios start hidden', () => {
  const p = page(false);
  assert.equal(p.choices.hidden, true);
});

test('a page without the controls is left alone', () => {
  assert.doesNotThrow(() => vm.runInNewContext(src, { document: { querySelector: () => null } }));
});

test('the template carries the hooks the script reads, and loads it', () => {
  assert.match(twig, /'data-updates-toggle': ''/);
  assert.match(twig, /<fieldset class="updates-cadence" data-updates-cadence>/);
  assert.doesNotMatch(twig, /data-updates-cadence hidden/, 'shown without script');
  assert.match(twig, /asset\('settings\/updates-cadence\.js'\)/);
});
