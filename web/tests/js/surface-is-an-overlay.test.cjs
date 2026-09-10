// SPDX-License-Identifier: AGPL-3.0-only
//
// Road surface is ONE control, and it is the overlay switch.
//
// It used to be two: a "Road surface" row in Data layers and a "Surfaces"
// switch in Map overlays, one word apart, in two different groups. They were
// not independent either. The skin hides itself wherever a catalog item exists
// for that way, so the two handed work to each other through a dedupe neither
// of them mentioned, and any rule that dropped our line while leaving the skin
// hidden did not fall back to OSM: it left the road blank and the basemap
// showed through. That is what a rider saw as a road "going orange" after a
// third confirmation (owner-reported 2026-08-31).
//
// So the agreement is: the skin honours neither the view-mode rungs nor the
// region scope, and neither do our items. Two absent gates, not a second
// dedupe list, because a list computed on the client could only name the way
// each segment is filed under and never the ways it spans: it would trade a
// vanishing road for a doubled one.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const catalog = fs.readFileSync(path.join(ROOT, 'assets/map/catalog.js'), 'utf8');
const render = fs.readFileSync(path.join(ROOT, 'assets/map/render.js'), 'utf8');
const panels = fs.readFileSync(path.join(ROOT, 'assets/map/panels.js'), 'utf8');

/** The body of renderSurfaceLayer, where the gates would live. */
const surfaceRender = (() => {
  const at = render.indexOf('export function renderSurfaceLayer');
  assert.notEqual(at, -1, 'renderSurfaceLayer must still exist');
  return render.slice(at, render.indexOf('\nexport ', at + 10));
})();

test('surface is flagged as an overlay and kept out of the data layers', () => {
  assert.match(catalog, /key:'surface'[^}]*overlay:true/, 'the surface entry must carry overlay:true');
  assert.match(
    catalog,
    /catalogUtility = \(\) => CATALOG\.filter\(l => [^)]*!l\.overlay\)/,
    'catalogUtility must exclude overlays, or the row returns to Data layers',
  );
});

test('the overlay switch drives our items too, so the pair cannot be half on', () => {
  assert.match(panels, /active\.add\('surface'\); else active\.delete\('surface'\)/,
    'the Surfaces switch must turn our items on and off with the skin');
  assert.match(panels, /if\(!surfaceTilesVisible\(\)\) active\.delete\('surface'\)/,
    'the switch starts off, so the items must start off with it');
});

test('our items honour neither the rungs nor the scope, matching the skin', () => {
  // The skin has no rung gate and no region gate. Ours must not either: a gate
  // on one side and not the other is exactly how a road goes blank.
  assert.doesNotMatch(surfaceRender, /modeShows\(/,
    'a rung gate here hides our line while the skin stays hidden: the road goes blank');
  assert.doesNotMatch(surfaceRender, /inScope\(/,
    'a scope gate here does the same thing, more quietly');
});

test('select all cannot reach the overlay', () => {
  // It walks every layer that has a ROW. Walking the whole catalogue would turn
  // our surface items on while the Surfaces switch still read Off: the half-on
  // state this whole change exists to make impossible, reached by the one
  // control that was never meant to touch it.
  assert.match(catalog, /catalogRows = \(\) => CATALOG\.filter\(l => !l\.overlay\)/,
    'catalogRows names the layers that have a row');
  assert.doesNotMatch(panels, /const allOn=CATALOG\.every/,
    'select-all must read the rows, not the whole catalogue');
  assert.match(panels, /const allOn=catalogRows\(\)\.every/);
  assert.match(panels, /catalogRows\(\)\.forEach\(l=>\{ if\(allOn\)/);
});

test('the skin still hides itself where we hold an item', () => {
  const tiles = fs.readFileSync(path.join(ROOT, 'assets/map/surface-tiles.js'), 'utf8');
  assert.match(tiles, /CC_CURATED_REFS/,
    'without the dedupe every corrected road draws twice, ours over OSM');
});
