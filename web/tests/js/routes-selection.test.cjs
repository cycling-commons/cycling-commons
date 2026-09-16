// SPDX-License-Identifier: AGPL-3.0-only
//
// Clicking a signed route lights the WHOLE route and dims the rest
// (owner 2026-08-17, looking at a North Holland screen where a hundred
// overlapping purple lines made "where does this one go" unanswerable).
//
// Four things here are correctness rather than polish, and three of them were
// live bugs during the build:
//
// 1. The match is padded with its delimiter. Without it "ncn LF1" also selects
//    "ncn LF10", and a rider is shown a route that is not the one they clicked.
// 2. The restore uses the SHARED paint constants. Retyping the opacity at the
//    restore site is how a dim quietly becomes permanent the next time somebody
//    tunes the corridor.
// 3. selectRoute() runs AFTER openDrawer(), because openDrawer clears the
//    selection on its way in - correctly, so a climb's drawer does not leave a
//    route lit. Setting it first wiped it one line later.
// 4. A route with no code selects nothing, rather than guessing and dimming the
//    map for a highlight that never appears.
//
// MapLibre needs a live GL context, so these are structural pins in the house
// two-lists style. The behaviour was checked in a real browser over North
// Holland: clicking EuroVelo 12 lit 285 segments from Den Helder to Haarlem
// and dimmed the network behind it, and closing the drawer put the corridor
// opacity back to its exact interpolate expression.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');

const routes = read('assets/map/routes-tiles.js');
const drawer = read('assets/map/drawer.js');

test('the whole route is matched, not the clicked way', () => {
  // A way id would be the fragment under the cursor. (net, rr) is the route.
  assert.match(routes, /\['==', \['get', 'net'\], net\], \['==', \['get', 'rr'\], rr\]/);
  assert.doesNotMatch(routes, /selectRoute[\s\S]{0,400}\['get', 'ref'\]/,
    'selecting by way ref would highlight one stretch and call it a route');
});

test('ways carrying several routes still count, and LF1 does not select LF10', () => {
  const m = routes.match(/const key = ([\s\S]*?)\n  eachRouteLayer/);
  assert.ok(m, 'the selection filter was not found');
  assert.match(m[1], /\['in', '\|' \+ key \+ '\|', \['concat', '\|', \['coalesce', \['get', 'refs'\], ''\], '\|'\]\]/,
    'the refs arm must be delimiter-padded on BOTH sides, or it prefix-matches');
});

test('the dim is restored from the shared constants, never retyped', () => {
  assert.match(routes, /const LINE_OPACITY = \['interpolate'/);
  assert.match(routes, /const LINE_WIDTH = \['interpolate'/);
  // The corridor layer paints from them...
  assert.match(routes, /'line-width': LINE_WIDTH,\n\s*'line-opacity': LINE_OPACITY,/);
  // ...and the clear puts that same expression back.
  assert.match(routes, /clearRouteSelection[\s\S]*?setPaintProperty\(id, 'line-opacity', LINE_OPACITY\)/);
});

test('the highlight is set after the drawer opens, not before', () => {
  const fn = routes.match(/export function openRouteDrawer\(([\s\S]*?)\n\}/);
  assert.ok(fn, 'openRouteDrawer not found');
  const openAt = fn[1].indexOf('openDrawer(layer, {');
  const selectAt = fn[1].indexOf('selectRoute(p.net, p.rr)');
  assert.ok(openAt >= 0 && selectAt >= 0, 'both calls must be in openRouteDrawer');
  assert.ok(selectAt > openAt,
    'openDrawer clears the selection on its way in, so setting it first is wiped one line later');
});

test('every teardown path drops the highlight', () => {
  // Closing the drawer.
  assert.match(drawer, /clearRouteSelection\(\);\s*\/\/ the OSM corridor highlight/);
  // Opening a DIFFERENT drawer, so two stories are never on screen at once.
  assert.match(drawer, /clearRouteHighlight\(\);\n\s*clearRouteSelection\(\);/);
  // Switching the whole network off.
  assert.match(routes, /if \(!on\) clearRouteSelection\(\);/);
});

test('a route with no code selects nothing and dims nothing', () => {
  assert.match(routes, /if \(!added \|\| !net \|\| !rr\) \{ clearRouteSelection\(\); return false; \}/,
    'a half-identified route must leave the map alone rather than dim it for a highlight that never comes');
});

test('the selection layer is walked with the others, so no country is missed', () => {
  // One walk over the live style, rather than a list of ids built at add time:
  // a country whose layers arrive later is covered by construction.
  assert.match(routes, /function eachRouteLayer\(fn\)/);
  for (const kind of ["'sel'", "'line'", "'disc'", "'nr'"]) {
    assert.ok(routes.includes(kind), `eachRouteLayer must tag ${kind}`);
  }
  // And the visibility toggle already covers it, because the id shares the
  // corridor prefix.
  assert.match(routes, /const selId = LINE_PREFIX \+ 'sel-' \+ cc;/);
});

test('a K route is selected even before its line is drawn', () => {
  // Owner-reported 2026-09-16: a route reached from outside the rider's scope
  // opened with every line the same salmon and the same width, the picked one
  // included. Its layer did not exist yet at that moment (the scope lifts to
  // the route's region, §4.5, and the region's layers are drawn after), and
  // openDrawer only selected when `map.getLayer()` already answered. Selecting
  // regardless records which route is picked; render()'s tail re-applies the
  // emphasis the moment the line exists.
  const render = read('assets/map/render.js');
  assert.match(drawer, /const i=layer\.features\.indexOf\(f\);\n(?:\s*\/\*[\s\S]*?\*\/\n)?\s*if\(i>=0\) highlightRoute\('experience-'\+i\);/,
    'openDrawer must select the route without asking whether its layer is drawn');
  assert.doesNotMatch(drawer, /if\(i>=0 && map\.getLayer\('experience-'\+i\)\) highlightRoute/,
    'gating the selection on the drawn layer loses the emphasis for an out-of-scope route');
  // The other half of the contract: render() re-applies whatever is selected.
  assert.match(render, /if\(selectedRouteLayerId && map\.getLayer\(selectedRouteLayerId\)\) highlightRoute\(selectedRouteLayerId\);/,
    'render() must re-apply the selection after it rebuilds the dynamic layers');
  // highlightRoute records the pick before it touches any layer, so a pick
  // made with nothing drawn is still the pick.
  assert.match(render, /export function highlightRoute\(selId\)\{\n\s*selectedRouteLayerId=selId;/);
});
