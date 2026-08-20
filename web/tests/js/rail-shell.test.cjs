// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The map shell: a 48px icon rail plus a 320px drawer that shows ONE section
// at a time (map-and-search.md §4). These are structural pins over the source
// files, the same house pattern map-theme.test.cjs uses: the template is read
// as text and the invariants the JS modules depend on are asserted on it.
//
// What they protect:
//
//  - the three rider sections exist and each still OWNS the ids its module
//    binds to. panels.js, scope-ui.js, search-ui.js and render.js all reach
//    for these by id, so the markup may MOVE between panels but may never be
//    renamed or dropped;
//  - ids stay unique. The refactor moves large blocks of markup between
//    parents, and a copy that leaves the original behind is invisible in the
//    browser while quietly breaking every getElementById on the page;
//  - the drawer chrome the shell drives (title, close, aria-hidden) is there
//    to be driven.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');

const twig = read('templates/map/index.html.twig');
const css = read('assets/styles/map.css');

// The three panels are siblings in source order, so a panel's markup is
// everything from its own id up to the next panel's (the last one runs to the
// end of the drawer body).
const PANELS = ['p-search', 'p-layers', 'p-tools'];
function panelSrc(id) {
  const at = twig.indexOf(`id="${id}"`);
  assert.ok(at >= 0, `no <section id="${id}"> in the template`);
  const next = PANELS.slice(PANELS.indexOf(id) + 1)
    .map(p => twig.indexOf(`id="${p}"`))
    .filter(i => i > at);
  const end = next.length ? Math.min(...next) : twig.indexOf('</aside>', at);
  assert.ok(end > at, `cannot find the end of ${id}`);
  return twig.slice(at, end);
}

test('the icon rail carries exactly the three rider sections', () => {
  assert.ok(/<nav class="irail"/.test(twig), 'no <nav class="irail"> in the template');
  const panels = [...twig.matchAll(/<button[^>]*class="ib"[^>]*data-panel="([a-z]+)"/g)].map(m => m[1]);
  assert.deepEqual(panels, ['search', 'layers', 'tools'],
    'the rail must carry search, layers and tools, in that order and no others');
  assert.ok(twig.includes('id="ib-theme"'), 'the rail has no theme button');
  assert.ok(twig.includes('id="ib-layers"'), 'the layers button needs its id for the filter dot');
});

test('the drawer shell is there for the shell script to drive', () => {
  assert.ok(/<aside class="dwr" id="dwr"/.test(twig), 'no <aside class="dwr" id="dwr">');
  assert.ok(twig.includes('id="dwr-title"'), 'the drawer header has no title element');
  assert.ok(twig.includes('id="dwr-x"'), 'the drawer header has no close button');
  for (const p of PANELS) {
    assert.ok(new RegExp(`<section class="panel" id="${p}"`).test(twig), `no <section class="panel" id="${p}">`);
  }
});

test('each panel still owns the ids its module binds to', () => {
  // scope-ui.js renders the region chips into #scopeChips; search-ui.js owns
  // the input and result list. One panel, because a region is where you search
  // (owner 2026-08-20).
  const search = panelSrc('p-search');
  for (const id of ['scopeChips', 'search', 'searchRes', 'myAreaBtn']) {
    assert.ok(search.includes(`id="${id}"`), `#${id} left the search panel`);
  }
  // panels.js initViewMode/initLayerList/initBestOf; view mode is a filter too,
  // so it shares the panel with the layers and the chip groups.
  const layers = panelSrc('p-layers');
  for (const id of ['mode', 'layers', 'bestFacets', 'sqf', 'trf', 'effortf', 'accessf', 'heattoggle', 'season']) {
    assert.ok(layers.includes(`id="${id}"`), `#${id} left the layers panel`);
  }
  // ride-check.js binds the file input and its status line.
  const tools = panelSrc('p-tools');
  for (const id of ['rcFile', 'rcPick', 'rcRadius', 'rcStatus']) {
    assert.ok(tools.includes(`id="${id}"`), `#${id} left the tools panel`);
  }
});

test('the old always-open filter rail is gone', () => {
  assert.ok(!/<aside class="rail"/.test(twig), 'the 340px <aside class="rail"> wrapper is still in the template');
  assert.ok(!twig.includes('class="rail-scroll"'), '.rail-scroll is still in the template');
});

test('no id is rendered twice', () => {
  const seen = new Map();
  for (const m of twig.matchAll(/\sid="([A-Za-z][A-Za-z0-9_-]*)"/g)) {
    seen.set(m[1], (seen.get(m[1]) || 0) + 1);
  }
  const dupes = [...seen].filter(([, n]) => n > 1).map(([id]) => id);
  assert.deepEqual(dupes, [], 'duplicate ids in the map template: ' + dupes.join(', '));
});

test('the shell styles read chrome tokens and band the groups', () => {
  assert.ok(/\.irail\{/.test(css), 'map.css has no .irail rule');
  assert.ok(/\.dwr\{/.test(css), 'map.css has no .dwr rule');
  assert.ok(/\.dwr\.open\{width:320px\}/.test(css), 'the drawer does not open to 320px');
  // Zebra bands + hairlines (design contract point 8): every second group on a
  // whisper of fg tint, a 1px rule between consecutive groups.
  assert.ok(css.includes('.panel .grp:nth-of-type(even)'), 'no zebra band rule for the panel groups');
  assert.ok(/\.panel \.grp \+ \.grp\{border-top:1px solid rgb\(var\(--chrome-fg\) \/ \.14\)\}/.test(css),
    'no 1px hairline between consecutive groups');
  // Nothing in the shell may hardcode a brand literal: the chrome themes by
  // token or it stays dark in light mode (map-theme.test.cjs guards the rest).
  const shell = css.slice(css.indexOf('/* ---------- ICON RAIL'), css.indexOf('/* ---------- RAIL ----------'));
  assert.ok(shell.length > 0, 'no ICON RAIL section in map.css');
  assert.equal((shell.match(/#EFE6D4|#101E16/g) || []).length, 0,
    'the shell hardcodes a brand literal instead of a --chrome-* token');
});
