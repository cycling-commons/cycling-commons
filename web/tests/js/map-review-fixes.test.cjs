// SPDX-License-Identifier: AGPL-3.0-only
//
// The map review round of 2026-10-09: layer stacking, pool disc pins below
// their icon zoom, the selection ring on scaled pins, search hits without a
// region, the map hint's close button, the pending climb's steep marker and
// the tile icons' drawn glyphs. Pure parts are lifted and run; wiring is
// pinned where it cannot run without MapLibre.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '..', '..');
const read = rel => fs.readFileSync(path.join(ROOT, rel), 'utf8');
const render = read('assets/map/render.js');
const coverage = read('assets/map/coverage.js');
const pools = read('assets/map/osm-pools.js');
const drawer = read('assets/map/drawer.js');
const fan = read('assets/map/pin-fan.js');
const search = read('assets/map/search-ui.js');
const icons = read('assets/map/icons.js');
const mapCss = read('assets/styles/map.css');

const lift = (src, name) => {
  const m = src.match(new RegExp(`export function ${name}\\([^)]*\\)\\{[\\s\\S]*?\\n\\}`));
  assert.ok(m, `${name}() not found`);
  return m[0].replace('export ', '');
};

test('only coverage icon layers are lifted over the lines, never the Mapillary coverage line', () => {
  const c = {};
  vm.createContext(c);
  vm.runInContext(lift(render, 'coverageIconLayerIds') + '\nthis.f=coverageIconLayerIds;', c);
  const ids = ['experience-1', 'water-be-heat', 'water-be-cov', 'mly-cov', 'mly-img', 'history-cov', 'cov-sel-icon'];
  assert.deepEqual([...c.f(ids)], ['water-be-cov', 'history-cov']);
  assert.match(lift(render, 'liftInfoLayersAboveRoutes'), /coverageIconLayerIds\(map\.getStyle\(\)\.layers\.map\(l=>l\.id\)\)\.forEach/);
});

test('a country mounted later is re-stacked, so its haze stays under the lines', () => {
  assert.match(coverage, /import \{[^}]*liftInfoLayersAboveRoutes[^}]*\} from '\.\/render\.js';/);
  const mount = (coverage.match(/const mount = \(\) => mountInView\([\s\S]*?\n {2}\}\);/) || [''])[0];
  assert.match(mount, /liftInfoLayersAboveRoutes\(\)/);
});

test('a pool disc waits for its icon zoom, unless a list shows it', () => {
  const c = { iconMinZoom: k => (k === 'water' ? 11 : 12), pinClasses: p => (p.custody === 'gross' ? ['disc', 'q'] : ['q']) };
  vm.createContext(c);
  vm.runInContext(lift(pools, 'poolDiscWaits') + '\nthis.f=poolDiscWaits;', c);
  assert.equal(c.f('history', { custody: 'gross' }, 11, false), true);
  assert.equal(c.f('history', { custody: 'gross' }, 11, true), false, 'a listed place always shows');
  assert.equal(c.f('history', { custody: 'gross' }, 12, false), false);
  assert.equal(c.f('history', { custody: 'ours' }, 9, false), false, 'our own pins show at every zoom');
  assert.equal(c.f('water', { custody: 'gross' }, 11, false), false);
  assert.match(pools, /poolDiscWaits\(st\.key, p, map\.getZoom\(\), _listed\.has\(placeKey\(\(st\.layer\|\|\{\}\)\.letter, p\.id\)\)\)\) continue;/);
  assert.match(lift(pools, 'poolPinDrawn'), /poolDiscWaits\(/, 'a waiting disc is not reported as drawn');
});

test('a bubble that holds discs opens at least at their icon zoom', () => {
  assert.match(pools, /getClusterLeaves\(p\.cluster_id, ?\d+, ?0\)/);
  assert.match(pools, /Math\.max\(z, ?iconMinZoom\(st\.key\)\)/);
});

test('the selection ring sits on the pin as drawn at this zoom', () => {
  const c = {};
  vm.createContext(c);
  vm.runInContext(lift(drawer, 'ringOffset') + '\nthis.f=ringOffset;', c);
  assert.deepEqual([...c.f([0, -16], 30, 1)], [0, -15], 'a full teardrop: half its height');
  assert.deepEqual([...c.f([0, -16], 21, 0.7)], [0, -10.5], 'a teardrop at 70%');
  assert.deepEqual([...c.f([0, -16], 0, 0.7)], [0, -11.2], 'no pin on screen: the default offset at the pin scale');
  assert.deepEqual([...c.f([0, 0], 30, 1)], [0, 0], 'a flat dot stays centred');
  assert.match(fan, /export function pinElFor\(key\)\{/);
  assert.match(drawer, /map\.on\('zoom', ?\(\)=>\{ if\(_hl\) placeHighlight\(\); \}\)/);
});

test('a coverage hit that is our item but has no region opens as the OSM point', () => {
  assert.match(search, /go:\(\)=>h\.itemId!=null && h\.rid!=null \? openApiHit\(h\.letter, h\.itemId, h\.rid\) : openCoverageByRef\(/);
});

test('back in the search box with the same query, the list redraws without new requests', () => {
  const reopen = (search.match(/const reopenS=\(\)=>\{[\s\S]*?\};/) || [''])[0];
  assert.match(reopen, /if\(q===_lastQ\)\{ runS\(\); return; \}/);
  assert.match(search, /_lastQ=sBox\.value\.trim\(\);/);
});

test('the hint is rebuilt only when it changes, keeps focus sensible and comes back if the close was refused', () => {
  const hint = (render.match(/export function updateZoomHint\(\)\{[\s\S]*?\n\}/) || [''])[0];
  assert.match(hint, /if\(el\.dataset\.state!==state\)\{/);
  const close = (render.match(/function closeHint\(h\)\{[\s\S]*?\n\}/) || [''])[0];
  assert.match(close, /map\.getCanvas\(\)\.focus\(\)/);
  assert.match(close, /\.then\(r=>\{ if\(!r\.ok\) reopenHint\(h\); \}\)\.catch\(\(\)=>reopenHint\(h\)\)/);
});

test('a curator previewing a pending climb keeps its steep marker zoomed out', () => {
  assert.match(mapCss, /\.cc-z-lt11 \.cc-steep:not\(\.cc-steep-pending\)\{display:none\}/);
});

test('a tile mini icon draws the category drawn glyph, never an emoji, when there is one', () => {
  const mini = (icons.match(/export function miniIcon\([\s\S]*?\n\}/) || [''])[0];
  assert.match(mini, /const drawn = !glyph && TYPE_SVG\(\(layer\|\|\{\}\)\.letter\|\|''\);/);
});

test('the zoom styles are written only when a value changes', () => {
  const sync = (icons.match(/function syncZoomStyles\(\)\{[\s\S]*?\n\}/) || [''])[0];
  assert.match(sync, /if\(key===_zoomStyleKey\) return;/);
});
