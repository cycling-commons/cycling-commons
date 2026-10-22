// SPDX-License-Identifier: AGPL-3.0-only
//
// When the OSM icons start (docs/specs/coverage-provider.md §4). At z9 every
// category popped in at once and buried the map, while between the fading
// haze and the icons the map showed nothing (owner 2026-10-08). Water comes
// first, at z11 where the tiles hold every point; the rest at z12. Each
// category's haze holds until its own icons start and fades under them, so
// the map is never empty. Our own pins are DOM pins and show at every zoom.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'map', 'coverage.js'), 'utf8');
const icons = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'map', 'icons.js'), 'utf8');

function lift() {
  const table = icons.match(/export const COV_ICON_MIN_ZOOM=\{[^}]*\};/);
  const fn = icons.match(/export function iconMinZoom\(key\)\{[^\n]*\}/);
  assert.ok(table && fn, 'COV_ICON_MIN_ZOOM and iconMinZoom() live in icons.js');
  const ctx = {};
  vm.createContext(ctx);
  vm.runInContext(table[0].replace('export ', '') + '\n' + fn[0].replace('export ', '') + '\nthis.iconMinZoom=iconMinZoom;', ctx);
  return ctx.iconMinZoom;
}

test('water icons start at z11, every other category at z12', () => {
  const at = lift();
  assert.equal(at('water'), 11);
  assert.equal(at('history'), 12);
  assert.equal(at('stays'), 12);
});

test('each icon layer starts where its own haze is still there, but thin', () => {
  assert.match(src, /minzoom: iconMinZoom\(key\),/);
  assert.match(src, /maxzoom: iconMinZoom\(key\)\+HEAT_FADE,/);
  assert.match(src, /'heatmap-opacity':\['interpolate',\['linear'\],\['zoom'\],6,0\.38,9,0\.38,iconMinZoom\(key\),HEAT_AT_ICONS,iconMinZoom\(key\)\+HEAT_FADE,0\]/);
  assert.match(src, /const HEAT_FADE=0\.75;/);
  assert.match(src, /const HEAT_AT_ICONS=0\.15;/);
});

test('the haze keeps the z9 look: lighter blobs zoomed out, no denser or wider ones zoomed in', () => {
  assert.match(src, /'heatmap-intensity':\['interpolate',\['linear'\],\['zoom'\],6,0\.3,8,0\.6,9,1\]/);
  assert.match(src, /'heatmap-radius':\['interpolate',\['linear'\],\['zoom'\],6,12,8,18,9,22,12,24\]/);
});
