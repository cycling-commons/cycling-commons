// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The zoom at which the gap grid hands over to the to-do lines is agreed by
// three parties, and all three have to say the same number.
//
//   * the BUILD decides where tiles exist: surface-todo.pmtiles starts at
//     contract surface.todo.minZoom, surface-gaps.pmtiles stops at
//     surface.gaps.maxZoom;
//   * the CLIENT decides where layers draw: TODO_MIN_ZOOM in surface-tiles.js
//     is the lines' `minzoom` and the grid's `maxzoom`.
//
// Disagree by one and a rider who ticked "surface not recorded" gets a band of
// zoom showing nothing at all — the grid already gone, the lines not yet
// started, no error anywhere. Nothing else in the map fails that quietly,
// because nothing else answers one question out of two artifacts.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const contract = JSON.parse(
  fs.readFileSync(path.join(ROOT, '..', 'pipeline', 'contract', 'coverage-contract.json'), 'utf8'),
);
const source = fs.readFileSync(path.join(ROOT, 'assets/map/surface-tiles.js'), 'utf8');

/** TODO_MIN_ZOOM read out of the source (importing it would boot MapLibre). */
function clientHandover() {
  const m = source.match(/^const TODO_MIN_ZOOM\s*=\s*(\d+);/m);
  assert.ok(m, 'TODO_MIN_ZOOM not found in surface-tiles.js');
  return Number(m[1]);
}

test('the client hands over at the zoom the build hands over at', () => {
  const { todo, gaps } = contract.surface;
  assert.equal(clientHandover(), todo.minZoom,
    'the to-do lines would be asked for at zooms the artifact has no tiles for');
  assert.equal(clientHandover(), gaps.maxZoom,
    'the grid would keep drawing over the roads it summarises, or stop before they start');
});

test('the to-do arm is a subset of the extracted network', () => {
  // It is filtered out of the classified pass rather than selected separately,
  // so naming a highway nobody extracts would promise an always-empty layer.
  const { todo, highways } = contract.surface;
  assert.ok(todo.highways.length > 0);
  for (const hw of todo.highways) {
    assert.ok(highways.includes(hw), `${hw} is in todo.highways but not extracted`);
  }
});

test('the grid ships the properties its drawer reads', () => {
  // km / pct / n are the three rows of openGapsDrawer. A property the build
  // stops emitting turns into a drawer row reading "0 km" — a confident,
  // wrong answer rather than a missing one.
  for (const prop of ['km', 'pct', 'n']) {
    assert.ok(contract.surface.gaps.tileProps.includes(prop),
      `the grid drawer reads ${prop}, which the contract does not promise`);
    // Either spelling the client uses: `p.km` in the drawer, `['get','pct']`
    // in a paint expression.
    assert.ok(source.includes(`p.${prop}`) || source.includes(`'${prop}'`),
      `${prop} is promised but never read by the client`);
  }
});

test('the classified skin has a zoom floor, and the map says so below it', () => {
  // Weight, not taste (owner-reported 2026-08-20: "for default wallonia view
  // it is about 16MB"). The artifact is built z8-13 with
  // --no-tile-size-limit, so one z8 tile over Wallonia measures 1119 KB
  // against 93 KB at z10, and a screen is roughly sixteen tiles either way.
  // The layer's own paint draws 0.6 px at half opacity at z8.
  const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/map/surface-tiles.js'), 'utf8');
  assert.match(src, /const CLASSIFIED_MIN_ZOOM = 10;/, 'the classified skin has no zoom floor');
  assert.match(src, /minzoom: CLASSIFIED_MIN_ZOOM,/,
    'the floor is declared but never applied, so MapLibre still fetches the low-zoom tiles');
  // Cross-language pin: the build stops where the client starts. A client
  // floor above the build's would fetch nothing at the gap; a build floor
  // above the client's would leave the client asking for tiles that do not
  // exist, which is silent rather than loud.
  assert.equal(contract.surface.minZoom, 10,
    'the surface build no longer starts where the client does');
  // A control that changes nothing when pressed has to say why.
  const render = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/map/render.js'), 'utf8');
  assert.match(render, /surfaceBelowFloor/, 'nothing explains an empty skin below the floor');
  assert.match(render, /zoomForSurfaces/, 'the below-floor hint has no string');
  // Read from the button: surface-tiles.js imports render.js, so render.js
  // must not import it back (map-and-search.md §4.1).
  assert.doesNotMatch(render, /from '\.\/surface-tiles\.js'/, 'render.js imports surface-tiles and closes a cycle');
  // AHEAD of the pending line. Both are true at once for anyone with something
  // waiting and a region scope, which is most curators and many riders, and
  // the pending line was winning every time (owner-reported 2026-08-20: "I do
  // not see that"). The pending line is a standing explanation; this one
  // answers a control pressed a second ago and clears itself on zoom.
  assert.match(render, /const msg = surfaceBelowFloor \?/,
    'the pending hint outranks the one the rider just asked for');
});
