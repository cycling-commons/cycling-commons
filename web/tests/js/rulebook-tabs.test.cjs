// SPDX-License-Identifier: AGPL-3.0-only
//
// assets/js/rulebook-tabs.js: the curator rulebook's tabs. A #hash naming a
// section (the takedowns desk links /moderate/rulebook#takedowns) opens the
// panel that holds it; otherwise ?tab=<key> picks the tab; otherwise the
// first tab. Choosing a tab leaves ?tab=<key> in the address, or the bare
// path for the first tab.
//
// The pure helpers are called with `document` undefined, the way
// translate-marks.test.cjs reaches translate-mode.js; the wiring (hashchange,
// the hidden list, the template's section ids) is pinned structurally.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const src = fs.readFileSync(path.join(ROOT, 'assets/js/rulebook-tabs.js'), 'utf8');
const tpl = fs.readFileSync(path.join(ROOT, 'templates/moderate/rulebook.html.twig'), 'utf8');

const mod = { exports: {} };
new Function('module', 'exports', 'window', 'document', src)(mod, mod.exports, undefined, undefined);
const { pickTab, urlFor } = mod.exports;

const KEYS = ['start', 'places', 'routes', 'photos', 'desks', 'account'];

/* Which panel holds which id, read from the template itself. */
function panelMap() {
  const map = {};
  const re = /<div class="rb-panel" id="rbp-([a-z]+)"[\s\S]*?<\/div>\{# \/#rbp-\1 #\}/g;
  let m;
  while ((m = re.exec(tpl))) {
    map['rbp-' + m[1]] = m[1];
    for (const s of m[0].matchAll(/<section id="([a-z-]+)">/g)) map[s[1]] = m[1];
  }
  return map;
}
const MAP = panelMap();
const keyOf = id => MAP[id] || null;

test('the template has six panels, in tab order, holding all nineteen sections', () => {
  const panels = [...tpl.matchAll(/<div class="rb-panel" id="rbp-([a-z]+)"/g)].map(m => m[1]);
  assert.deepEqual(panels, KEYS);
  const sections = Object.keys(MAP).filter(k => !k.startsWith('rbp-'));
  assert.equal(sections.length, 19);
  assert.equal(MAP.takedowns, 'photos');
});

test('a section hash opens the panel that holds it', () => {
  assert.equal(pickTab('#takedowns', '', keyOf, KEYS), 'photos');
  assert.equal(pickTab('#reports', '', keyOf, KEYS), 'photos');
  assert.equal(pickTab('#routes', '', keyOf, KEYS), 'routes');
  assert.equal(pickTab('#account', '', keyOf, KEYS), 'account');
  assert.equal(pickTab('#deep-rules', '', keyOf, KEYS), 'start');
  assert.equal(pickTab('#markers', '', keyOf, KEYS), 'places');
});

test('a panel id in the hash opens that panel', () => {
  assert.equal(pickTab('#rbp-desks', '', keyOf, KEYS), 'desks');
});

test('the hash wins over ?tab=', () => {
  assert.equal(pickTab('#takedowns', '?tab=routes', keyOf, KEYS), 'photos');
});

test('without a hash that lands in a panel, ?tab= decides', () => {
  assert.equal(pickTab('', '?tab=routes', keyOf, KEYS), 'routes');
  assert.equal(pickTab('#main', '?tab=desks', keyOf, KEYS), 'desks');
  assert.equal(pickTab('#nowhere', '?x=1&tab=account', keyOf, KEYS), 'account');
});

test('an unknown hash or tab falls back to the first tab', () => {
  assert.equal(pickTab('', '', keyOf, KEYS), 'start');
  assert.equal(pickTab('#nowhere', '?tab=nope', keyOf, KEYS), 'start');
  assert.equal(pickTab('#%E0%A4%A', '?tab=%E0%A4%A', keyOf, KEYS), 'start');
});

test('choosing a tab leaves ?tab=<key>, and the bare path for the first', () => {
  assert.equal(urlFor('start', KEYS, '/en/moderate/rulebook'), '/en/moderate/rulebook');
  assert.equal(urlFor('photos', KEYS, '/en/moderate/rulebook'), '/en/moderate/rulebook?tab=photos');
});

test('the script follows every hash change and shows the hidden list', () => {
  assert.match(src, /addEventListener\('hashchange', follow\)/);
  assert.match(src, /list\.hidden = false/);
  assert.match(src, /scrollIntoView\(\)/);
  assert.match(tpl, /role="tablist"[^>]*data-rb-tabs hidden>/);
  assert.match(tpl, /asset\('js\/rulebook-tabs\.js'\)/);
});
