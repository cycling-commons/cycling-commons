// SPDX-License-Identifier: AGPL-3.0-only
// Settings, Public profile: the line under the switch says what Save profile
// will do while the switch differs from the saved state, and shows the saved
// line again when it matches (assets/settings/public-profile.js).
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const src = fs.readFileSync(path.join(__dirname, '../../assets/settings/public-profile.js'), 'utf8');
const twig = fs.readFileSync(path.join(__dirname, '../../templates/settings/index.html.twig'), 'utf8');

function page(savedOn) {
  const el = () => ({ hidden: false });
  const nodes = { saved: el(), on: el(), off: el() };
  nodes.on.hidden = true;
  nodes.off.hidden = true;
  let onChange = null;
  const toggle = { checked: savedOn, addEventListener: (t, f) => { if (t === 'change') onChange = f; } };
  const view = {
    getAttribute: (a) => (a === 'data-saved' ? (savedOn ? 'on' : 'off') : null),
    querySelector: (s) => (s === '[data-public-saved]' ? nodes.saved
      : s === '[data-public-pending="on"]' ? nodes.on
        : s === '[data-public-pending="off"]' ? nodes.off : null),
  };
  const document = { querySelector: (s) => (s === '[data-public-toggle]' ? toggle : s === '[data-public-view]' ? view : null) };
  vm.runInNewContext(src, { document });
  return { nodes, flip(v) { toggle.checked = v; onChange(); } };
}

test('saved off, switched on: the line asks for Save profile to publish', () => {
  const p = page(false);
  assert.equal(p.nodes.saved.hidden, false);
  p.flip(true);
  assert.equal(p.nodes.saved.hidden, true);
  assert.equal(p.nodes.on.hidden, false);
  assert.equal(p.nodes.off.hidden, true);
  p.flip(false);
  assert.equal(p.nodes.saved.hidden, false, 'back to what is saved');
  assert.equal(p.nodes.on.hidden, true);
});

test('saved on, switched off: the link gives way to the hide note', () => {
  const p = page(true);
  p.flip(false);
  assert.equal(p.nodes.saved.hidden, true);
  assert.equal(p.nodes.off.hidden, false);
  assert.equal(p.nodes.on.hidden, true);
});

test('the template carries the hooks the script reads, and loads it', () => {
  assert.match(twig, /'data-public-toggle': ''/);
  assert.match(twig, /data-public-view data-saved=/);
  assert.match(twig, /data-public-pending="on" hidden/);
  assert.match(twig, /data-public-pending="off" hidden/);
  assert.match(twig, /asset\('settings\/public-profile\.js'\)/);
});
