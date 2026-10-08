// SPDX-License-Identifier: AGPL-3.0-only
//
// What the map shows as it zooms (owner 2026-10-08):
// - an OpenStreetMap place waits for its category's icon zoom, also when it
//   is one of our pool rows drawn as a small disc pin;
// - the coverage icons sit above the route lines;
// - the climb's summit chip shows from z11;
// - our own teardrops are smaller zoomed out and grow as the map zooms in.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '..', '..');
const read = rel => fs.readFileSync(path.join(ROOT, rel), 'utf8');
const icons = read('assets/map/icons.js');
const pools = read('assets/map/osm-pools.js');
const render = read('assets/map/render.js');
const pins = read('assets/styles/pins.css');
const mapCss = read('assets/styles/map.css');

function liftPinScale() {
  const fn = icons.match(/export function pinScale\(zoom\)\{[\s\S]*?\n\}/);
  assert.ok(fn, 'pinScale() lives in icons.js');
  const c = {};
  vm.createContext(c);
  vm.runInContext((icons.match(/const PIN_SIZES=[^;]*;/) || [''])[0] + '\n' + fn[0].replace('export ', '') + '\nthis.pinScale=pinScale;', c);
  return c.pinScale;
}

test('a disc pin of an OpenStreetMap place waits for its category icon zoom', () => {
  assert.match(pools, /import \{[^}]*\biconMinZoom\b[^}]*\} from '\.\/icons\.js';/);
  assert.match(pools, /if\(!p\.cluster && map\.getZoom\(\) < iconMinZoom\(st\.key\) && pinClasses\(p\)\.includes\('disc'\)\) continue;/);
});

test('the coverage icons are lifted above every line, the selected icon on top', () => {
  const lift = (render.match(/export function liftInfoLayersAboveRoutes\(\)\{[\s\S]*?\n\}/) || [''])[0];
  assert.match(lift, /\.filter\(id=>\/-cov\$\/\.test\(id\)\)\.forEach\(id=>map\.moveLayer\(id\)\)/);
  assert.match(lift, /if\(map\.getLayer\('cov-sel-icon'\)\) map\.moveLayer\('cov-sel-icon'\);\s*\n\}$/);
});

test('the summit chip shows from z11', () => {
  assert.match(icons, /const SUMMIT_MIN_ZOOM=11;/);
  assert.match(icons, /classList\.toggle\('cc-z-lt11', z < SUMMIT_MIN_ZOOM\)/);
  assert.match(mapCss, /\.cc-z-lt11 \.cc-summit,/);
});

test('our teardrops grow from 70% at z8 to full size at z14', () => {
  const at = liftPinScale();
  assert.equal(at(6), 0.7);
  assert.equal(at(8), 0.7);
  assert.ok(Math.abs(at(11) - 0.85) < 1e-9);
  assert.equal(at(14), 1);
  assert.equal(at(17), 1);
  assert.match(icons, /setProperty\('--pin-s', pinScale\(z\)\.toFixed\(3\)\)/);
  assert.match(pins, /\.cc-pin\{--p:var\(--pin-s, ?1\);[^}]*width:calc\(30px \* var\(--p\)\);height:calc\(30px \* var\(--p\)\)/);
  assert.match(pins, /\.cc-pin\.q::after\{[^}]*width:calc\(15px \* var\(--p\)\)/);
});

test('the gradient chip hides with the summit chip, below z11', () => {
  assert.match(mapCss, /\.cc-z-lt11 \.cc-summit,\s*\.cc-z-lt11 \.cc-steep\{display:none\}/);
});

test('a shelter glyph is white on its purple', () => {
  const util = read('assets/map/util.js');
  const c = {};
  vm.createContext(c);
  vm.runInContext((util.match(/export function txtOn\(hex\)\{[\s\S]*?\n\}/) || [''])[0].replace('export ', '') + '\nthis.txtOn=txtOn;', c);
  const colour = (read('assets/map/catalog.js').match(/key:'shelter'[^}]*color:'(#[0-9A-Fa-f]{6})'/) || [])[1];
  assert.equal(c.txtOn(colour), '#fff', `shelter ${colour}`);
  assert.match(read('src/Api/V1/CategoryTable.php'), new RegExp("'key' => 'shelter'[^\\]]*'color' => '" + colour + "'"), 'the server table carries the same colour');
});
