// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The basemap asks its sprite for an image per OSM point class, and the
// sprite lacks most of them: one console warning per class, nothing drawn.
// catalog-load.js answers MapLibre's styleimagemissing event the moment the
// map exists, before the first tile asks: the classes
// in the basemap icon registry are minted from their paths, every other
// missing name gets one blank image, and the console stays quiet
// (docs/specs/map-and-search.md §4.7).
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const init = fs.readFileSync(path.join(ROOT, 'assets', 'map', 'catalog-load.js'), 'utf8');
const twig = fs.readFileSync(path.join(ROOT, 'templates', 'map', 'index.html.twig'), 'utf8');
const php = fs.readFileSync(path.join(ROOT, 'src', 'Catalog', 'BasemapIcons.php'), 'utf8');

test('the map answers styleimagemissing, once, the moment it is born', () => {
  const m = init.match(/map\.on\('styleimagemissing'/g) || [];
  assert.equal(m.length, 1, 'exactly one handler');
  assert.ok(init.indexOf('new maplibregl.Map(') < init.indexOf('answerMissingImages(window.__ccMapInstance)'), 'attached right after the constructor');
  assert.ok(!/styleimagemissing/.test(fs.readFileSync(path.join(ROOT, 'assets', 'map', 'map-init.js'), 'utf8')), 'not a second time in map-init.js');
});

test('the registry comes from the server, never a JS constant', () => {
  assert.match(init, /window\.CC_BASEMAP_ICONS/);
  assert.match(twig, /window\.CC_BASEMAP_ICONS = /);
  assert.ok(!/bollard|cycle_barrier/.test(init), 'no class name is hard-coded in the map');
});

test('an unknown name gets a blank image so the warning never fires again', () => {
  const fn = init.match(/map\.on\('styleimagemissing'[\s\S]*?\n {4}\}\);/);
  assert.ok(fn, 'handler body found');
  assert.match(fn[0], /hasImage\(/, 'never adds twice');
  assert.match(fn[0], /new Uint8Array\(4\)|width:\s*1,\s*height:\s*1/, 'a 1x1 transparent image for the rest');
});

test('the registry carries the four classes a rider reads in passing', () => {
  for (const id of ['bollard', 'gate', 'bicycle_parking', 'cycle_barrier']) {
    assert.match(php, new RegExp(`'${id}' => \\['paths'`), `${id} is in BasemapIcons::set()`);
  }
});
