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
const shellJs = read('assets/map/shell.js');
const entry = read('assets/map/map.js');
const controller = read('src/Controller/MapController.php');

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

test('shell.js owns the drawer and nothing else drives it', () => {
  assert.ok(/export function initShell\(/.test(shellJs), 'shell.js does not export initShell()');
  assert.ok(entry.includes("from './shell.js'"), 'map.js never imports the shell');
  assert.ok(/initShell\(\)/.test(entry), 'map.js imports initShell but never calls it');
  // The map canvas is a flex sibling of the drawer, so MapLibre has to be told
  // its box changed - after the width transition, not during it.
  assert.ok(shellJs.includes('map.resize'), 'shell.js never resizes the map after the drawer moves');
  assert.ok(shellJs.includes("'Escape'"), 'Escape does not close the drawer');
  assert.ok(shellJs.includes("setAttribute('aria-hidden'"), 'the drawer aria-hidden state is never updated');
  // No module reaches in to open a panel: the rail is the only way in.
  assert.ok(!/window\.CCShell/.test(shellJs), 'shell.js exposes a global; the rail is the only entry point');
});

test('the drawer opens closed and titles itself from the locale bundle', () => {
  // The map is the hero. The prototype opened Layers to demo itself; the real
  // page must not.
  assert.ok(twig.includes('<aside class="dwr" id="dwr" aria-hidden="true">'),
    'the drawer does not ship closed and aria-hidden');
  for (const key of ['railSearch', 'railLayers', 'railTools']) {
    assert.ok(shellJs.includes(key), `shell.js has no ${key} title`);
    assert.ok(controller.includes(`'${key}' =>`), `MapController::mapI18n() does not emit ${key}`);
  }
});

test('search and region read as one panel, in one order', () => {
  // Contract point 3: the heading names the scope, the results follow, the
  // widen ladder is the last results row, and the region grid plus My area sit
  // below them. Asserted by source order inside the panel.
  const s = panelSrc('p-search');
  const order = ['id="searchTitle"', 'id="search"', 'id="searchRes"', 'id="scopeChips"', 'id="areaPromptRow"'];
  let at = -1;
  for (const marker of order) {
    const i = s.indexOf(marker);
    assert.ok(i > at, `${marker} is out of order inside the search panel`);
    at = i;
  }
});

test('the scope heading has exactly one writer', () => {
  // scope-header.js paints it before first paint (the 2026-07-23 flash fix).
  // A second writer would race it and the rider would see one of two answers.
  const modules = fs.readdirSync(path.join(ROOT, 'assets/map'))
    .filter(f => f.endsWith('.js'))
    .filter(f => read('assets/map/' + f).includes('searchTitle'));
  assert.deepEqual(modules, ['scope-header.js'], 'searchTitle is written from more than one module');
});

test('the widen ladder is offered only while the scope can widen', () => {
  const searchUi = read('assets/map/search-ui.js');
  // canWiden() is false at Everywhere, which is what hides the last rung.
  assert.ok(/if\(window\.CCScope && window\.CCScope\.canWiden\(\)\)/.test(searchUi),
    'the widen row is not gated on CCScope.canWiden()');
  assert.ok(searchUi.includes('class="search-widen"'), 'no widen row in the results list');
});

test('Escape reaches the drawer only when nothing nearer owns it', () => {
  // The search dropdown, the feature drawer, the lightbox and the climb
  // profile all bind Escape. Closing a photo must not also close the section
  // the rider was reading.
  assert.ok(shellJs.includes('.cc-lightbox.open'), 'Escape ignores an open lightbox');
  assert.ok(shellJs.includes('.cc-cp.open'), 'Escape ignores an open climb profile');
  assert.ok(shellJs.includes('.cc-drawer.open'), 'Escape ignores an open feature drawer');
  assert.ok(shellJs.includes('searchRes'), 'Escape ignores an open search dropdown');
});

test('the layers panel runs mode then layers then overlays then filters', () => {
  // Contract point 4: everything that can make the map show less, in one
  // panel, in the order a rider would ask the questions.
  const s = panelSrc('p-layers');
  const order = ['id="mode"', 'id="layers"', 'id="ovSurface"', 'id="filters"'];
  let at = -1;
  for (const marker of order) {
    const i = s.indexOf(marker);
    assert.ok(i > at, `${marker} is out of order inside the layers panel`);
    at = i;
  }
  assert.ok(s.includes('id="ovRoutes"'), 'the cycle-route overlay never made it into the layers panel');
  // Sub-headers, not a wall of chips.
  assert.ok((s.match(/class="fh"/g) || []).length >= 5, 'the filters block has no .fh sub-headers');
});

test('every filter group declares how it narrows', () => {
  // The pill and its reset need to know which way each group narrows. All four
  // chip facets share attrMatch()'s rule (render.js): every chip on hides
  // nothing, deselecting one narrows. The preference chip is the opposite -
  // it narrows only while it is ON.
  const s = panelSrc('p-layers');
  for (const id of ['sqf', 'trf', 'effortf', 'accessf']) {
    assert.ok(new RegExp(`class="chips f-match" id="${id}"`).test(s), `#${id} is not marked f-match`);
  }
  assert.ok(/class="chips f-optin"/.test(s), 'the opt-in preference filter is not marked f-optin');
});

test('the overlays left the top-right corner', () => {
  // Surfaces and Cycle routes are layers, so they live with the layers
  // (contract point 7). Their ids do not move, because panels.js binds them.
  const corner = twig.slice(twig.indexOf('<div class="map-ctrl"'), twig.indexOf('contrib-fab'));
  assert.ok(!corner.includes('ovSurface'), 'the Surfaces toggle is still in the top-right corner');
  assert.ok(!corner.includes('ovRoutes'), 'the Routes toggle is still in the top-right corner');
});

test('the top-right corner is two glass icon buttons', () => {
  assert.ok(!twig.includes('class="map-ctrl"'), 'the old .map-ctrl panel is still in the template');
  assert.ok(/<div class="tr"/.test(twig), 'no .tr icon stack in the top-right corner');
  assert.ok(twig.includes('id="tr-base"'), 'no base-map button');
  assert.ok(twig.includes('id="fly-base"'), 'no base-map flyout');
  // The ids the JS binds do NOT move: panels.js drives #baseSeg, mapillary.js
  // drives #ovStreet.
  assert.ok(twig.includes('id="baseSeg"'), '#baseSeg left the page; panels.js binds it');
  assert.ok(twig.includes('id="ovStreet"'), '#ovStreet left the page; mapillary.js binds it');
});

test('the base flyout is a sibling of its button, never a child', () => {
  // A <button> may not contain another <button>: the browser un-nests it and
  // the flyout ends up outside the control, which is exactly the bug the
  // prototype hit first.
  const at = twig.indexOf('id="tr-base"');
  const tail = twig.slice(at);
  const closeBtn = tail.indexOf('</button>');
  const flyAt = tail.indexOf('id="fly-base"');
  assert.ok(flyAt > closeBtn, 'the base flyout is nested inside the base button');
});

test('the flyout is opened by initMapCtrl, not by the old collapse toggle', () => {
  const panels = read('assets/map/panels.js');
  assert.ok(!panels.includes("getElementById('mcToggle')"), 'panels.js still drives the retired .map-ctrl collapse');
  assert.ok(panels.includes("getElementById('fly-base')"), 'panels.js never binds the base flyout');
});

test('the pill and the rail dot both come from one real tally', () => {
  const renderJs = read('assets/map/render.js');
  const panels = read('assets/map/panels.js');
  // The count is produced by the render pass, not estimated beside it.
  assert.ok(/export function hiddenByFilters\(/.test(renderJs), 'render.js does not export hiddenByFilters()');
  assert.ok(renderJs.includes("new CustomEvent('cc:filters'"), 'render.js never announces the tally');
  assert.ok(!/tally\.hidden\+\+/.test(renderJs.slice(0, renderJs.indexOf('export function featureVisible'))),
    'the tally is incremented outside featureVisible');
  // The pill is DOM, so it lives with the other chrome, not in the renderer.
  assert.ok(panels.includes("document.addEventListener('cc:filters'"), 'panels.js never listens for the tally');
  assert.ok(twig.includes('id="fpill"'), 'no filter pill on the map');
  assert.ok(twig.includes('id="fpillReset"'), 'the pill has no reset');
  // Reset reads the markup's own declaration of how each group narrows.
  assert.ok(panels.includes("'#filters .f-match .chip'"), 'the reset does not restore the f-match groups');
  assert.ok(panels.includes("'#filters .f-optin .chip'"), 'the reset does not clear the f-optin groups');
  assert.ok(panels.includes('setFilterDot'), 'the rail dot is never driven');
});

test('the ride tools panel holds the things a rider does with a ride', () => {
  const s = panelSrc('p-tools');
  assert.ok(s.includes('map.ride_check_intro'), 'the ride check has no one-line explainer');
  assert.ok(s.includes("path('scout_review')"), 'no scout row in the tools panel');
  assert.ok(s.includes('id="addClimbHere"'), 'the contribute row left the tools panel');
  // render.js writes this element on every pass; the id must survive the move.
  assert.ok(s.includes('id="count"'), 'the places count did not move into the tools panel');
  assert.ok(!twig.includes('class="rail-foot"'), 'the old .rail-foot is still in the template');
});

test('the zoom hint stays on the map, where a rider zooming can see it', () => {
  // It explains why the map is empty at low zoom, so it has to be readable
  // WHILE zooming - which the drawer, closed by default, is not.
  const tools = panelSrc('p-tools');
  assert.ok(!tools.includes('id="zoomHint"'), 'the zoom hint is buried in the drawer');
  const stage = twig.slice(twig.indexOf('<main class="map-wrap">'), twig.indexOf('</main>'));
  assert.ok(stage.includes('id="zoomHint"'), 'the zoom hint is not on the map stage');
});
