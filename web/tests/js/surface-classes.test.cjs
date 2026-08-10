// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The pipeline's surface classes and the client's SURFACE_STYLE must be the
// same seven names.
//
// The tile build stamps a `cls` on every line; render.js draws one filtered
// layer per SURFACE_STYLE key. A class the client does not know falls through
// to the default style, so the road renders — in the wrong colour, saying the
// wrong thing about what is under your tyres, with nothing logged. A style key
// the pipeline never emits is a dead layer nobody notices.
//
// This is the same shape as coverage-keys.test.cjs (the toilets layer that was
// counted and never drawn): two lists in two languages that only agree because
// somebody remembered.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const contract = JSON.parse(
  fs.readFileSync(path.join(ROOT, '..', 'pipeline', 'contract', 'coverage-contract.json'), 'utf8'),
);
const render = fs.readFileSync(path.join(ROOT, 'assets/map/render.js'), 'utf8');

/** SURFACE_STYLE's keys, read out of the source (importing it would boot MapLibre). */
function styleKeys() {
  const block = render.match(/export const SURFACE_STYLE\s*=\s*\{([\s\S]*?)\n\};/);
  assert.ok(block, 'SURFACE_STYLE not found in render.js');
  return [...block[1].matchAll(/^\s*([a-z]+)\s*:/gm)].map((m) => m[1]);
}

/** The classes the pipeline can stamp: cycleway + the mapped ones + untagged. */
function contractClasses() {
  const s = contract.surface;
  assert.ok(s, 'the contract has no surface section');
  return [s.cyclewayClass, ...Object.keys(s.classes), s.untaggedClass];
}

test('the pipeline emits exactly the classes the client can draw', () => {
  assert.deepEqual(
    [...contractClasses()].sort(),
    [...styleKeys()].sort(),
    'pipeline surface classes and SURFACE_STYLE have drifted — a class the client ' +
      'does not know renders in the default colour and says the wrong thing',
  );
});

test('the zoom range is a sane line profile', () => {
  const s = contract.surface;
  assert.ok(Number.isInteger(s.minZoom) && Number.isInteger(s.maxZoom));
  assert.ok(s.minZoom < s.maxZoom, 'minZoom must be below maxZoom');
  // z14+ overzooms from z13 for free; building it would multiply the artifact
  // for fidelity no rider can use on a road surface.
  assert.ok(s.maxZoom <= 14, 'building past z14 is a lot of bytes for no visible gain');
});

test('the untagged arm is its own class, not folded into a real one', () => {
  const s = contract.surface;
  assert.ok(
    !Object.keys(s.classes).includes(s.untaggedClass),
    'the untagged class must not also be a mapped surface class — "nobody has said" ' +
      'is not the same claim as any of the six',
  );
});

test('motorways, trunk roads and service roads stay out', () => {
  // Not rideable, or (service) driveways and parking aisles: the single biggest
  // tile-size risk in the selector, and almost none of it riding surface.
  for (const hw of ['motorway', 'trunk', 'motorway_link', 'trunk_link', 'service']) {
    assert.ok(
      !contract.surface.highways.includes(hw),
      `${hw} must not be in the surface selector`,
    );
  }
});
