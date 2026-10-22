// SPDX-License-Identifier: AGPL-3.0-only
//
// The compass (map-and-search.md section 4, owner 2026-10-19: "can we add a compass when you click it the map always
// rotates North up"): MapLibre's own, beside the zoom buttons. Its needle shows where north is; a click turns the map
// north up and leaves the tilt as it is (the 3D relief keeps its view).
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = f => fs.readFileSync(path.join(ROOT, f), 'utf8');

test('the zoom buttons carry the compass, and a click turns only the bearing', () => {
  const init = read('assets/map/map-init.js');
  assert.match(init, /new maplibregl\.NavigationControl\(\{showCompass:true, visualizePitch:false\}\)/);
});

test('the compass says what it does in the page language', () => {
  assert.match(read('src/Controller/MapController.php'), /'NavigationControl\.ResetBearing' => \$t->trans\('map\.north_up'\)/);
  for (const l of ['en', 'fr', 'nl', 'de', 'es']) {
    assert.match(read(`translations/messages.${l}.yaml`), /^ {2}north_up: "/m, l);
  }
});

test('the folded filter "!" sits above the taller button stack, clear of the zoom number', () => {
  const css = read('assets/styles/map.css');
  assert.match(css, /\.fpill\{position:absolute;left:\.75rem;bottom:13\.2rem;/);
  assert.match(css, /\.map-wrap\.cc-strip-on \.fpill\{bottom:calc\(140px \+ 13\.2rem\)\}/);
});

test('a figure\'s info bubble is as wide as the panel, never cut at its edge, in the sentence type', () => {
  const css = read('assets/styles/map.css');
  assert.match(css, /\.cc-plan-figs\{position:relative\}/, 'the bubble hangs from the whole row of figures');
  assert.match(css, /\.cc-plan-fig \.cc-info-wrap\{position:static;/);
  assert.match(css, /\.cc-plan-fig \.cc-info-pop\{left:0;right:0;width:auto;[^}]*text-transform:none/);
});

test('a scale bar sits above the attribution, in the rider\'s distance unit', () => {
  const init = read('assets/map/map-init.js');
  assert.match(init, /new maplibregl\.ScaleControl\(\{maxWidth:100, unit: uDistUnit\(\)==='mi' \? 'imperial' : 'metric'\}\),'bottom-right'\)/);
  assert.ok(init.indexOf('ScaleControl') > init.indexOf('AttributionControl'), 'added before the attribution, the scale would sit under it');
  assert.match(read('assets/styles/map.css'), /\.maplibregl-ctrl-scale\{background:rgb\(var\(--chrome-glass\) \/ \.9\)/);
});
