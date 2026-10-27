// SPDX-License-Identifier: AGPL-3.0-only
//
// Click-to-scope leaves a click on a clickable layer alone (docs/specs/map-and-search.md §4.5): a click on an
// OpenStreetMap road opens its card and never also switches the region, which zoomed the map out to the whole
// region when the scope was My area. Structural pins over the source files, the pattern plan-wiring.test.cjs uses.
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const MAP = path.join(__dirname, '..', '..', 'assets', 'map');
const read = f => fs.readFileSync(path.join(MAP, f), 'utf8');

test('every layer click on the main map is bound through onLayerClick()', () => {
  const direct = [];
  for (const f of fs.readdirSync(MAP).filter(n => n.endsWith('.js') && n !== 'map-init.js')) {
    // map.on('click', <layer>, fn): a layer-bound click. map.on('click', fn) is a map-wide handler.
    for (const m of read(f).matchAll(/\bmap\.on\(\s*'click'\s*,\s*([^\s,)]+)\s*,/g)) {
      if (!/^(e|async)\b/.test(m[1])) direct.push(`${f}: ${m[0]}`);
    }
  }
  assert.deepEqual(direct, [], 'bind with onLayerClick(), so click-to-scope knows the layer');
});

test('the OSM road, gap, route and traffic layers are bound through it', () => {
  for (const f of ['surface-tiles.js', 'routes-tiles.js', 'traffic-layer.js', 'render.js', 'mapillary.js']) {
    assert.match(read(f), /\bonLayerClick\(/, f);
  }
  assert.match(read('surface-tiles.js'), /onLayerClick\(GAPS_FILL,/);
  assert.match(read('render.js'), /onLayerClick\('surface-q',/);
});

test('click-to-scope skips every layer on the list', () => {
  const scopeUi = read('scope-ui.js');
  const body = scopeUi.slice(scopeUi.indexOf('function selectableLayers'), scopeUi.indexOf("map.on('click', async e=>"));
  assert.match(body, /\.\.\.clickLayerIds\(\)/);
  assert.match(read('render.js'), /'click'===type \? offLayerClick\(id,fn\)/, 'an unbound layer leaves the list');
});

test('an empty click inside the scope does nothing, also in a country or My area', () => {
  const scopeUi = read('scope-ui.js');
  const handler = scopeUi.slice(scopeUi.indexOf("map.on('click', async e=>"));
  assert.match(handler, /if\(\(curScope\(\)\.regionIds\|\|\[\]\)\.includes\(r\.id\)\) return;/);
  assert.ok(!/s\.kind==='region' && s\.regionIds\[0\]===r\.id/.test(handler), 'not only a single region');
});
