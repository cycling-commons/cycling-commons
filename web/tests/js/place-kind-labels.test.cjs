// SPDX-License-Identifier: AGPL-3.0-only
//
// Until the pipeline stamps `kind` on a P or Q tile, its `t` label already
// names the kind ("Castle": the contract's selector label is the kind label,
// CoverageContractTest). The tile icon, the overlay and the drawer read it, and
// a search row shows the kind's glyph, not the category's temple front
// (osm-data-architecture.md §5a). The OSM discs are a few pixels larger.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const read = rel => fs.readFileSync(path.join(__dirname, '..', '..', rel), 'utf8');
const icons = read('assets/map/icons.js');
const coverage = read('assets/map/coverage.js');
const search = read('assets/map/search-ui.js');
const page = read('templates/map/index.html.twig');

function lift(src, name) {
  const m = src.match(new RegExp(`export function ${name}\\([^)]*\\)\\{[\\s\\S]*?\\n\\}`));
  assert.ok(m, `${name}() not found`);
  return m[0].replace('export ', '');
}

test('the page carries the kind labels, and placeKind falls back to a tile label', () => {
  assert.match(page, /window\.CC_PLACE_KIND_LABELS = /);
  const ctx = {};
  vm.createContext(ctx);
  vm.runInContext(
    "const KIND_ICONS = {Q: {castle: {}, ruins: {}}};\n"
    + "const kindDef = (letter, kind) => (KIND_ICONS[letter] || {})[kind] || null;\n"
    + "const PLACE_KIND_LABELS = {Q: {Castle: 'castle', Ruins: 'ruins'}};\n"
    + icons.match(/export const PLACE_LETTER=[^;]+;/)[0].replace('export ', '') + '\n'
    + lift(icons, 'kindOfLabel') + '\n' + lift(icons, 'placeKind'),
    ctx,
  );
  const placeKind = (key, p) => vm.runInContext('placeKind', ctx)(key, p);
  assert.equal(placeKind('history', { t: 'Castle' }), 'castle');
  assert.equal(placeKind('history', { t: 'Something else' }), null);
  assert.equal(placeKind('history', { kind: 'ruins', t: 'Castle' }), 'ruins', 'a stamped kind wins');
});

test('the tile icon matches the kind, else the kind its label names', () => {
  assert.match(coverage, /\['coalesce',\['get','kind'\],\['match',\['get','t'\],/);
  assert.match(lift(coverage, 'covProps'), /if\(PLACE_LETTER\[key\] && !p\.type && tp\.t\)/);
});

test('a scenic or history search row shows the kind glyph', () => {
  assert.match(icons, /export function kindGlyphSvg\(letter, kind, color, size\)\{/);
  assert.match(search, /\(h\.kind && kindGlyphSvg\(h\.letter, h\.kind, layer\.color, 15\)\) \|\| layerGlyph\(layer\)/);
});

test('the water drop rides about a tenth above the disc ramp', () => {
  // The drop is narrower than a disc in the same 24-box, so a slightly larger
  // ramp keeps the two the same height; marker-grammar pins the disc itself.
  const ramp = name => JSON.parse(icons.match(new RegExp('export const ' + name + '=(\\[[^\\]]+\\])'))[1]);
  const disc = ramp('DISC_SIZES'), drop = ramp('DROP_SIZES');
  disc.forEach((d, i) => assert.ok(drop[i] / d >= 1.05 && drop[i] / d <= 1.15, `stop ${i}: drop ${drop[i]} vs disc ${d}`));
});
