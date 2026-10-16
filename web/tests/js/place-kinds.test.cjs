// SPDX-License-Identifier: AGPL-3.0-only
//
// P and Q kinds (docs/specs/osm-data-architecture.md §5a): one OSM tag per
// kind, one glyph per kind. An OSM waterfall and our own waterfall draw the
// same pin. icons.js imports map-init.js, which constructs MapLibre, so
// placeKind() is lifted out of the source and evaluated on its own.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const read = rel => fs.readFileSync(path.join(__dirname, '..', '..', rel), 'utf8');
const icons = read('assets/map/icons.js');
const coverage = read('assets/map/coverage.js');

function lift(src, name) {
  const m = src.match(new RegExp(`export function ${name}\\([^)]*\\)\\{[\\s\\S]*?\\n\\}`));
  assert.ok(m, `${name}() not found`);
  return m[0].replace('export ', '');
}
const ctx = {};
vm.createContext(ctx);
vm.runInContext(
  "const KIND_ICONS = {P: {waterfall: {}, viewpoint: {}}, Q: {castle: {}}};\n"
  + "const kindDef = (letter, kind) => (KIND_ICONS[letter] || {})[kind] || null;\n"
  + "const PLACE_KIND_LABELS = {};\n"
  + icons.match(/export const PLACE_LETTER=[^;]+;/)[0].replace('export ', '') + '\n'
  + lift(icons, 'kindOfLabel') + '\n' + lift(icons, 'placeKind'),
  ctx,
);
const placeKind = (key, p) => vm.runInContext('placeKind', ctx)(key, p);

test('a catalog item names its kind as `type`, a coverage point as `kind`', () => {
  assert.equal(placeKind('scenic', { type: 'waterfall' }), 'waterfall');
  assert.equal(placeKind('history', { kind: 'castle' }), 'castle');
  assert.equal(placeKind('scenic', { type: 'castle' }), null, 'a castle is no scenic kind');
  assert.equal(placeKind('scenic', {}), null, 'no kind: the category glyph');
  assert.equal(placeKind('water', { type: 'waterfall' }), null, 'other categories have their own rules');
});

test('the DOM pin and the selected overlay draw the kind', () => {
  assert.match(lift(icons, 'pinEl'), /placeKind\(layer\.key, props\)/);
  // The pin is already the category disc: the glyph alone, or the kind's own disc shrinks it to a dot.
  assert.match(lift(icons, 'pinEl'), /kindGlyphSvg\(PLACE_LETTER\[layer\.key\], place, layer\.color, 17\)/);
  assert.match(lift(icons, 'coverageIconId'), /kindImageId\(PLACE_LETTER\[key\], place, badge\)/);
});

test('the panel names the kind in the rider\'s language, not the raw OSM label', () => {
  const drawer = read('assets/map/drawer.js');
  assert.match(drawer, /const typeLbl = kindLbl \|\| placeLbl \|\| p\.t \|\| lbl;/);
  assert.match(drawer, /\(typeField\.choices \|\| \{\}\)\[p\.type\]/);
});

test('the tile layer matches the kind, and the drawer reads it as the Type', () => {
  assert.match(coverage, /PLACE_LETTER\[key\]/);
  assert.match(coverage, /\['match', placeLabels\.length \? \['coalesce',\['get','kind'\],\['match',\['get','t'\], \.\.\.placeLabels, ''\]\] : \['get','kind'\], \.\.\.placeKinds/);
  assert.match(lift(coverage, 'covProps'), /if\(PLACE_LETTER\[key\] && tp\.kind\) p\.type=tp\.kind;/);
  assert.match(lift(coverage, 'covProps'), /if\(PLACE_LETTER\[key\] && d\.kind\) p\.type=d\.kind;/);
});

test('a pin with no glyph of its own draws the drawn category icon, never the emoji', () => {
  // A shelter pin showed the coloured ⛑ emoji (owner 2026-10-08); type icons are drawn SVG.
  const pin = lift(icons, 'pinEl');
  assert.match(pin, /const svc = layer\.key==='services' \? serviceGlyph\(props\) : '';/);
  assert.match(pin, /color:\$\{white\?'#fff':'#14160e'\}">\$\{layerGlyph\(layer, 15\)\}/);
});

test('a bike service draws one glyph per kind on every pin: ours, the OSM disc and the selected one', () => {
  // Our bike shop drew the cog and the OSM bike shop beside it the plain wrench (owner 2026-10-16):
  // the tile rule had its own list. Now the tile layer, the selected overlay and the DOM pin read SERVICE_GLYPH.
  const fn = lift(icons, 'serviceIconId');
  assert.match(fn, /Object\.hasOwn\(SERVICE_GLYPH, kind\) \? miniIcon\('services', SERVICE_GLYPH\[kind\], kind, badge\) : miniIcon\('services', undefined, undefined, badge\)/);
  assert.match(lift(icons, 'coverageIconId'), /if\(key==='services'\) return serviceIconId\(tp\.kind, badge\);/);
  assert.match(coverage, /\['match',\['get','kind'\], \.\.\.Object\.keys\(SERVICE_GLYPH\)\.flatMap\(k => \[k, servicePair\(k\)\]\), servicePair\(null\)\]/);
  assert.doesNotMatch(coverage, /'shop', miniPair\(\)/, 'a bike shop disc draws the shop glyph, not the bare category');
  assert.match(icons, /return k && Object\.hasOwn\(SERVICE_GLYPH, k\) \? SERVICE_GLYPH\[k\] : '';/);
});
