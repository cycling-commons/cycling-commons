// SPDX-License-Identifier: AGPL-3.0-only
//
// The map shell: a 48px icon rail plus a 320px drawer that shows ONE section
// at a time (map-and-search.md §4). These are structural pins over the source
// files, the same house pattern map-theme.test.cjs uses: the template is read
// as text and the invariants the JS modules depend on are asserted on it.
//
// What they protect:
//
//  - the four rider sections exist and each still OWNS the ids its module
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

// The four panels are siblings in source order, so a panel's markup is
// everything from its own id up to the next panel's (the last one runs to the
// end of the drawer body).
const PANELS = ['p-search', 'p-layers', 'p-tools', 'p-key'];
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

test('the icon rail carries exactly the four rider sections', () => {
  assert.ok(/<nav class="irail"/.test(twig), 'no <nav class="irail"> in the template');
  const panels = [...twig.matchAll(/<button[^>]*class="ib"[^>]*data-panel="([a-z]+)"/g)].map(m => m[1]);
  assert.deepEqual(panels, ['search', 'layers', 'tools', 'key'],
    'the rail must carry search, layers, tools and key, in that order and no others');
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
  for (const key of ['railSearch', 'railLayers', 'railTools', 'railKey']) {
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

test('the widen ladder ends in "Search everywhere", a reach for this search only', () => {
  const searchUi = read('assets/map/search-ui.js');
  // A country is the top of the scope ladder (owner, 2026-09-06). The last
  // row of the search list is the one place the whole world is offered, and
  // it widens the SEARCH, never the scope: the row is gated on not already
  // searching worldwide, not on canWiden().
  // `!coord` is the one other reason the row is dropped: a pasted coordinate
  // pair is already an exact point (map-and-search.md §7.4).
  assert.ok(/if\(window\.CCScope && !_worldwide && !coord\)/.test(searchUi),
    'the widen row is not gated on the worldwide reach');
  assert.ok(searchUi.includes("I18N.searchEverywhere||'Search everywhere'"), 'no "Search everywhere" rung at the top');
  assert.ok(searchUi.includes('class="search-widen"'), 'no widen row in the results list');
  assert.ok(/setReach\(true\)/.test(searchUi), 'widening at the top must set the search reach, not the scope');
  assert.ok(searchUi.includes("getElementById('searchReach')"), 'the reach has a visible switch beside the search title');
  // Picking a hit the scope does not draw moves the scope to that hit's own
  // REGION, transiently, and puts the rider's scope back when its drawer
  // closes (owner 2026-09-16; map-and-search.md §4.5). The country rung threw
  // away a Friesland rider's scope to show them one town in Belgium.
  assert.ok(searchUi.includes('liftScopeForHit('), 'a hit outside the scope must lift the scope to reach it');
  assert.ok(!searchUi.includes('countryAt('), 'a picked hit must not jump the scope to a whole country');
  const scopeUi = read('assets/map/scope-ui.js');
  assert.ok(!/setEverywhere\(\)/.test(scopeUi), 'the rail and the nudge must not set Everywhere');
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
  // The feature count is GONE, and deliberately (owner, 2026-08-29). It said
  // "N places shown", where N was "Commons features currently drawn": neither
  // the OSM coverage underneath nor everything visible, so the one number a
  // rider could read off the panel was the one thing it did not mean.
  // render.js guards the element rather than assuming it, so a surface that
  // wants a live count can put the id back and get one.
  assert.ok(!s.includes('id="count"'), 'the feature count is back in the tools panel');
  assert.ok(!s.includes('map.places_shown'), 'the "places shown" label is back');
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

test('the account avatar is its own rail button, badge and all', () => {
  // The unread bulb has to be visible ON the rail. Inside the ≡ flyout it
  // would only appear once the rider already opened the menu, which is the
  // one moment the badge has nothing left to tell them.
  const rail = twig.slice(twig.indexOf('<nav class="irail"'), twig.indexOf('</nav>', twig.indexOf('id="railNav"')) + 6);
  const acct = twig.indexOf("_account_chip.html.twig");
  const nav = twig.indexOf('id="railNav"');
  assert.ok(acct > 0, 'the account chip is not on the map page at all');
  assert.ok(acct > twig.indexOf('</nav>', nav), 'the account chip is still inside the ≡ flyout');
  assert.ok(rail.length > 0);
  assert.ok(twig.includes('class="irail-acct"'), 'the avatar has no rail slot of its own');
});

test('the retired rail leaves no rules behind', () => {
  for (const dead of ['.rail{', '.rail-head', '.rail-scroll', '.rail-foot', '.rail-burger', '.map-ctrl', '.theme-toggle', '.export{']) {
    assert.ok(!css.includes(dead), `${dead} is still styled but nothing renders it`);
  }
  for (const dead of ['rail-scroll', 'rail-foot', 'rail-head', 'rail-burger', 'map-ctrl', 'rail-brandrow']) {
    assert.ok(!twig.includes(dead), `${dead} is still in the map template`);
  }
});

test('on a phone the rail stays and the drawer overlays the map', () => {
  // Owner call 2026-08-20: one behaviour at every width. The old phone
  // re-skin (top bar + bottom sheet) is gone rather than maintained beside it.
  const mob = css.slice(css.indexOf('/* ---- PHONES'), css.indexOf('/* ---- PHONES') + 1400);
  assert.ok(mob.length > 0, 'no phone section in map.css');
  assert.ok(/\.dwr\{position:absolute/.test(mob), 'the drawer does not overlay the map on a phone');
  assert.ok(mob.includes('min(320px,85vw)'), 'the phone drawer is not capped at 85vw');
  assert.ok(!css.includes('sheet-open'), 'the old bottom-sheet class is still styled');
});

test('the base picker is offered on the Esri key, not on a layer that does not exist yet', () => {
  // map.on('load') adds the satellite layer; the chrome is built synchronously
  // before that fires, so asking map.getLayer('satellite') always answered
  // "no" and the picker hid itself on every load, even where satellite works
  // (owner-reported 2026-08-20). The key is available from the first line.
  const panels = read('assets/map/panels.js');
  const init = read('assets/map/map-init.js');
  assert.ok(/export const satelliteConfigured = \(\) => !!ESRI_KEY/.test(init),
    'map-init.js does not expose satelliteConfigured()');
  assert.ok(panels.includes('if(!satelliteConfigured())'), 'panels.js does not gate the picker on the key');
  // Only the GATE moves. Flipping visibility still has to check the layer is
  // there, because a rider can press the button before map.on('load') fires.
  assert.ok(!panels.includes("if(!map.getLayer('satellite'))"),
    'panels.js still decides the picker from a layer that is added later');
});

test('the zoom hint sits beside the zoom controls, not on top of them', () => {
  // Stacked above, it landed on the z-level badge that shares MapLibre's
  // bottom-left corner (owner-reported 2026-08-20).
  const rule = css.match(/\.zoom-hint\{([^}]*)\}/);
  assert.ok(rule, 'no .zoom-hint rule');
  assert.ok(/left:3\.5rem/.test(rule[1]), 'the hint does not clear the zoom control column');
  assert.ok(/bottom:1\.1rem/.test(rule[1]), 'the hint is not aligned with the bottom edge');
});

test('curator mode is a curator thing, and each audience gets its own sentence', () => {
  // A rider gets their OWN undecided submissions on the same layer
  // (MapController), so the layer being on screen never meant "moderating".
  // A plain account was wearing the orange curator border (owner-reported
  // 2026-08-20).
  const renderJs = read('assets/map/render.js');
  assert.ok(renderJs.includes("classList.toggle('cc-curator-mode', pendingOn && isCurator)"),
    'the curator border does not check CC_IS_CURATOR');
  assert.ok(renderJs.includes('const isCurator = !!window.CC_IS_CURATOR'),
    'render.js never reads the curator flag');
  assert.ok(renderJs.includes('pendingYoursAnywhere'), 'riders get the curator wording');
  assert.ok(renderJs.includes("layerByKey['pending']"),
    'the pending layer is looked up by a flag the gone ghost layer also carries');
  assert.ok(controller.includes("'pendingYoursAnywhere' =>"),
    'MapController::mapI18n() does not emit the rider string');
});

test('the best-of season and bike pickers live with the other filters', () => {
  // They were in the View mode band: the subtitle read "Best of · Summer ·
  // Road" and the only bike control a rider could find was the profile chip
  // further down (owner-reported 2026-08-20). They narrow what shows, so they
  // belong under FILTERS, and first, because they narrow harder than any chip
  // under them.
  const s = panelSrc('p-layers');
  const filters = s.indexOf('id="filters"');
  const facets = s.indexOf('id="bestFacets"');
  const surface = s.indexOf('id="sqf"');
  assert.ok(facets > filters, 'the best-of facets are outside the filters block');
  assert.ok(facets < surface, 'the best-of facets are not first inside the filters block');
  // Two "season" controls in one block, so they must sit as far apart as it
  // allows: the heatmap's own chips stay last.
  assert.ok(s.indexOf('id="season"') > s.indexOf('id="accessf"'),
    'the heatmap season chips moved next to the best-of season picker');
});

test('the best-of facets narrow by selection, not by deselection', () => {
  // Empty means every season and every bike, so their widest state is EMPTY.
  // Marking them f-match would make the pill's "Show all" tick all four
  // seasons and all eight bikes, which is the opposite of widest.
  const s = panelSrc('p-layers');
  assert.ok(/class="chips f-optin" id="boSeason"/.test(s), 'the season facet is not marked f-optin');
  assert.ok(/class="chips f-optin" id="boBike"/.test(s), 'the bike facet is not marked f-optin');
  // Both are multi-select chip rows now, not single-value dropdowns.
  assert.ok(!/<select id="boSeason"/.test(s) && !/<select id="boBike"/.test(s),
    'a best-of facet is still a single-value select');
  // Clearing them is a server-side change, unlike every other chip here.
  const panels = read('assets/map/panels.js');
  const reset = panels.slice(panels.indexOf('reset.onclick'), panels.indexOf('reset.onclick') + 900);
  assert.ok(reset.includes('refreshBestOf()'), 'the pill reset never re-ranks the best-of facets');
});

test('a filter only appears when its layer can be filtered', () => {
  // Thirty chips in a wall is what a rider reads past to reach the ones that
  // matter (owner 2026-08-20: "still find the filter too crowded"). Climb
  // surface/traffic/effort describe climbs; stay accessibility describes
  // stays. With that layer off, or nothing of it in scope, they can change
  // nothing.
  const s = panelSrc('p-layers');
  const climbs = s.slice(s.indexOf('data-layer="climbs"'), s.indexOf('data-layer="stays"'));
  for (const id of ['sqf', 'trf', 'effortf']) {
    assert.ok(climbs.includes(`id="${id}"`), `#${id} is not inside the climbs filter group`);
  }
  const stays = s.slice(s.indexOf('data-layer="stays"'));
  assert.ok(stays.includes('id="accessf"'), '#accessf is not inside the stays filter group');
  // Header and chips have to hide together, so both live under the wrapper.
  assert.ok(/<div class="fsub" data-layer="climbs" hidden>\s*<h4 class="fh">/.test(s),
    'the climbs filter header is outside its wrapper');

  const panels = read('assets/map/panels.js');
  assert.ok(panels.includes('function syncFilterGroups()'), 'nothing hides the empty filter groups');
  // TOTAL, never shown: `shown` already has the chips applied, so filtering a
  // layer down to nothing would hide the filter that did it, with no way back.
  assert.ok(/layerCounts\(lyr\)\.total > 0/.test(panels),
    'the filter groups are gated on a count the filters themselves change');
  assert.ok(!/layerCounts\(lyr\)\.shown/.test(panels), 'the gate reads `shown` and can trap a rider');
});

test('an overlay row says whether it is on, like every other row in the list', () => {
  // Dimmed swatch, dimmed name, nothing on the right: that reads as disabled,
  // not off (owner-reported 2026-08-20). Every layer row has a count in that
  // slot and the count is what says "this one is alive".
  const s = panelSrc('p-layers');
  for (const id of ['ovSurface', 'ovRoutes']) {
    const row = s.slice(s.indexOf(`id="${id}"`), s.indexOf('</button>', s.indexOf(`id="${id}"`)));
    assert.ok(row.includes('class="ct"'), `#${id} has no state column`);
    assert.ok(row.includes('aria-pressed="false"'), `#${id} does not announce its pressed state`);
  }
  const panels = read('assets/map/panels.js');
  assert.ok(panels.includes('const paintOverlay='), 'the two overlay rows are painted separately and can drift');
});

test('every way into the feature drawer closes the rail panel', () => {
  // Owner-reported 2026-09-16: the rail panel stayed open over a town card for
  // Liege, because a town card writes #drawerBody and opens #drawer itself
  // instead of going through openDrawer(), which is where closeRailPanel()
  // used to sit. showDrawer() in drawer.js is now the one door, so the panel
  // closes for a record, a town or city card, a pasted coordinate, the ride
  // summary and a curator duplicate alike (map-and-search.md §4.0).
  const drawerJs = read('assets/map/drawer.js');
  assert.ok(/export function showDrawer\(/.test(drawerJs), 'drawer.js no longer exports showDrawer()');
  const body = drawerJs.slice(drawerJs.indexOf('export function showDrawer('));
  assert.ok(body.slice(0, body.indexOf('\n}')).includes('closeRailPanel()'),
    'showDrawer() does not close the rail panel');

  // No module may open the drawer behind showDrawer's back.
  const OPENERS = ['drawer.js', 'places.js', 'ride-check.js', 'duplicate-resolve.js', 'coverage.js', 'community.js'];
  for (const f of OPENERS) {
    const src = read(path.join('assets/map', f));
    const rogue = [...src.matchAll(/getElementById\('drawer'\)[^\n]*classList\.add\('open'\)/g)]
      .concat([...src.matchAll(/\bd(?:r|rawer)?\.classList\.add\('open'\)/g)]);
    const allowed = f === 'drawer.js' ? 1 : 0;   // showDrawer() itself
    assert.equal(rogue.length, allowed,
      `${f} opens #drawer without showDrawer(); the rail panel would stay open`);
  }
});

test('a hit frames itself before the scope draws over it', () => {
  // Owner-reported 2026-09-16: "it teleports". A scope draw holds the main
  // thread for about a second (measured 1.2 s here), and an ease started
  // before it loses every frame it had: the clock runs on and the map renders
  // only the final position. So a scope change that a hit asked for neither
  // fits to its own box nor draws until that hit's framing has landed.
  const scopeUi = read('assets/map/scope-ui.js');
  assert.ok(scopeUi.includes('export function keepCameraForNextScope()'),
    'nothing lets an opener claim the camera for its own scope change');
  // applyScope must skip ONLY the fit, not the rest of the scope work.
  assert.match(scopeUi, /if\(!opts \|\| !opts\.keepCamera\)\{[\s\S]{0,200}fitMapTo\(/,
    'the scope fit is not gated on the camera claim');
  assert.match(scopeUi, /fetchCoverageCounts\(\);/, 'a held camera must not also skip the coverage totals');
  // And the draw waits for the framing to land, with a timer for an opener
  // that moves no camera at all.
  assert.match(scopeUi, /map\.on\('moveend', go\)/, 'the draw does not wait for the camera to land');
  assert.match(scopeUi, /const timer = setTimeout\(go, CAMERA_WAIT_MS\)/,
    'an opener that moves no camera would leave its region undrawn');
  assert.match(scopeUi, /if\(!keepCamera\)\{ draw\(\); return; \}/,
    'a scope the rider picked themselves must draw at once, camera and all');
  // The one claimant beside a hit is the ride check, whose own fitBounds is
  // the framing for a loaded GPX (map-and-search.md §9).
  const ride = read('assets/map/ride-check.js');
  assert.ok(ride.includes('keepCameraForNextScope()'), 'the ride check must claim the camera for its own framing');
});
