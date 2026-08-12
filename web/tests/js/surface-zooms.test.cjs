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
