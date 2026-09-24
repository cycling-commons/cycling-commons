// SPDX-License-Identifier: AGPL-3.0-only
//
// Per-country tile mounting in the map modules. coverage.js, surface-tiles.js
// and routes-tiles.js import the live map (map-init.js), so they cannot be
// imported under node:test; these tests read their source text instead and
// pin the shape of the code paths a late-mounted country goes through.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = f => readFileSync(new URL('../../assets/map/' + f, import.meta.url), 'utf8');
const coverage = read('coverage.js');
const surface = read('surface-tiles.js');
const routes = read('routes-tiles.js');
const panels = read('panels.js');

/** The text of one top-level function, from its header to the closing brace at column 0. */
function body(src, header) {
  const start = src.indexOf(header);
  assert.notEqual(start, -1, `${header} not found`);
  const end = src.indexOf('\n}\n', start);
  return src.slice(start, end + 2);
}

test('the stays access facet reaches every mounted country on each scope refresh', () => {
  const f = body(coverage, 'export function updateCoverageScopeFilter(){');
  assert.match(f, /^\s*applyStaysAccessFilter\(\);/m, 'called once, on its own line, unconditionally');
  assert.doesNotMatch(f, /COVERAGE_CCS\[0\]/);
  assert.match(f, /key!=='stays' && map\.getLayer\(id\)\) map\.setFilter\(id, icon/, 'stays stays out of the per-layer icon filter loop');
});

test('one coverage handler set, bound once, acting on the first rendered hit', () => {
  assert.doesNotMatch(coverage, /map\.on\('(click|mousemove|mouseenter|mouseleave)',\s*ids/);
  const layers = body(coverage, 'function addCoverageLayers(cc, src){');
  assert.doesNotMatch(layers, /bindCoverageHandlers/);
  assert.match(coverage, /queryRenderedFeatures\(e\.point,\s*\{layers/);
  const add = body(coverage, 'export function addCoverage(){');
  assert.match(add, /bindCoverageHandlers\(\);/);
});

test('late-mounted coverage layers go under the selected-POI overlay', () => {
  const layers = body(coverage, 'function addCoverageLayers(cc, src){');
  assert.match(layers, /map\.addLayer\(heatSpec,\s*COV_SEL_LAYER\)/);
  assert.match(layers, /\]\}\},\s*COV_SEL_LAYER\);/, 'the icon layer too');
  const add = body(coverage, 'export function addCoverage(){');
  assert.ok(add.indexOf("map.addSource('cov-sel'") < add.indexOf('mount();'),
    'the overlay exists before the first mount');
});

test('late-mounted surface skin layers go under our first overlay layer', () => {
  const layers = body(surface, 'function addClassifiedLayers(srcLayer, src, under) {');
  const adds = layers.match(/map\.addLayer\(/g) || [];
  const under = layers.match(/\},\s*under\);/g) || [];
  assert.equal(adds.length, 3);
  assert.equal(under.length, adds.length, 'every class, casing and tick layer takes the beforeId');
  const add = body(surface, 'export function addSurfaceTiles() {');
  assert.match(add, /const under = belowOurLayers\(\{ skin: true \}\);/);
});

test('a surface toggle set to off mounts nothing', () => {
  const f = body(surface, 'export function setSurfaceTiles(on) {');
  assert.match(f, /if \(on && !added\) addSurfaceTiles\(\);/);
  assert.doesNotMatch(f, /if \(!added\) addSurfaceTiles\(\);/);
});

test('the rail count falls back to the scoped /counts total while nothing is mounted', () => {
  const f = body(coverage, 'export function covShownCount(key){');
  assert.match(f, /if\(!mounted\) return covKeyShown\(key\) \? coverageTotal\(KEY_LETTER\[key\]\) : 0;/);
  const sync = body(coverage, 'export function syncCoverageLayers(){');
  assert.match(sync, /covKeyShown\(key\)/, 'one rule decides both');
});

test('filters on per-country surface and routes layers go in unvalidated', () => {
  const vis = body(surface, 'function applyClassVisibility() {');
  const calls = vis.match(/map\.setFilter\([^;]*;/g) || [];
  assert.ok(calls.length >= 3);
  calls.forEach(c => assert.match(c, /NO_VALIDATE\);$/, c));
  const sel = [body(routes, 'export function selectRoute(net, rr) {'),
    body(routes, 'export function clearRouteSelection() {')].join('\n');
  const rcalls = sel.match(/map\.setFilter\([^;]*;/g) || [];
  assert.equal(rcalls.length, 2);
  rcalls.forEach(c => assert.match(c, /NO_VALIDATE\);$/, c));
});

test('the data-version readout names its placeholder once', () => {
  const f = body(panels, 'export function initRailChrome(){');
  assert.match(f, /const NO_STAMP='\u2014';/);
  assert.match(f, /return m\?m\[1\]:NO_STAMP;/);
});
