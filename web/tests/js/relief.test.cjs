// SPDX-License-Identifier: AGPL-3.0-only
//
// 3D relief (docs/specs/map-and-search.md section 4.2c): the public Terrain
// Tiles, fetched by our own nginx and kept there, so no rider's browser ever
// contacts the store; the Layers switch only where that proxy exists.
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = rel => fs.readFileSync(path.join(ROOT, rel), 'utf8');
const relief = read('assets/map/relief.js');
const twig = read('templates/map/index.html.twig');
const panels = read('assets/map/panels.js');
const nginx = read('../developers/docker/nginx/app.conf');

test('the browser asks our own server for relief tiles, never the store', () => {
  assert.match(relief, /tiles: \[location\.origin \+ '\/tiles\/relief\/\{z\}\/\{x\}\/\{y\}\.png'\]/);
  assert.match(relief, /encoding: 'terrarium'/);
  assert.ok(!/amazonaws|elevation-tiles-prod|s3\./.test(relief), 'relief.js names the store');
  assert.match(relief, /map\.setTerrain\(\{ source: RELIEF\.terrain, exaggeration: RELIEF\.exaggeration \}\)/);
  assert.match(relief, /map\.setTerrain\(null\)/, 'switched off, the ground lies flat again');
});

test('the switch shows only where the relief tiles are proxied', () => {
  assert.match(twig, /\{% if relief_tiles\|default\(false\) %\}\s*\{#[^#]*#\}\s*<button class="layer ov-row" id="ovHillshade"[^\n]*\n\s*<button class="layer ov-row" id="ovRelief"/);
  assert.match(panels, /if\(reliefBtn\) reliefBtn\.onclick=\(\)=>paintOverlay\(reliefBtn, setRelief\(!reliefVisible\(\)\)\);/);
  assert.match(read('config/services.yaml'), /env\(RELIEF_TILES\): '0'/, 'off unless an environment turns it on');
});

test('the dev proxy passes nothing of the rider on and keeps the tiles', () => {
  const loc = nginx.slice(nginx.indexOf('location ~ "^/tiles/relief/'), nginx.indexOf('}', nginx.indexOf('add_header Cache-Control', nginx.indexOf('location ~ "^/tiles/relief/'))));
  for (const h of ['Cookie ""', 'Authorization ""', 'Referer ""', 'Accept-Language ""', 'X-Forwarded-For ""', 'User-Agent "CyclingCommons relief proxy"']) {
    assert.ok(loc.includes('proxy_set_header ' + h), 'the proxy forwards ' + h.split(' ')[0]);
  }
  assert.match(loc, /proxy_cache relief;/);
  assert.match(loc, /access_log off;/, 'a tile names where a rider looks: never logged');
  assert.match(loc, /proxy_pass https:\/\/elevation-tiles-prod\.s3\.amazonaws\.com\/terrarium\/\$relief_z\/\$relief_x\/\$relief_y\.png;/);
});

/* Owner 2026-10-19: "Add option for hillshading also in flat view". The shading alone: no raised ground, no tilt.
   Both switches share the one shading layer; it stays while either is on. */
test('the slopes can be shaded on the flat map, and the shading stays while either switch is on', () => {
  assert.match(relief, /export function setHillshade\(next\)/);
  assert.match(relief, /const want = shadeOn \|\| on;/);
  const from = relief.indexOf('export function setHillshade');
  const shadeFn = relief.slice(from, relief.indexOf('\n}', from));
  assert.doesNotMatch(shadeFn, /setTerrain|easeTo|pitch/, 'the flat shading itself neither raises the ground nor tilts the map');
  assert.match(twig, /\{% if relief_tiles\|default\(false\) %\}[\s\S]*id="ovHillshade"[\s\S]*id="ovRelief"/);
  assert.match(panels, /if\(shadeBtn\) shadeBtn\.onclick=\(\)=>\{\s*paintOverlay\(shadeBtn, setHillshade\(!hillshadeVisible\(\)\)\);/);
  for (const l of ['en', 'fr', 'nl', 'de', 'es']) {
    const y = read(`translations/messages.${l}.yaml`);
    assert.match(y, /^ {2}relief_shade: "/m, `${l} relief_shade`);
    assert.match(y, /^ {2}relief_shade_hint: "/m, `${l} relief_shade_hint`);
  }
});

test('the shading is half as strong again as the first trial (owner 2026-10-19)', () => {
  assert.match(relief, /shading: 0\.525,/);
  assert.match(relief, /'hillshade-exaggeration': RELIEF\.shading/);
});

/* Owner 2026-10-19: "when clicking Hillshade deselect 3d terrain and undo the map tilt". */
test('switching Hillshade on switches the 3D relief off, so the map lies flat again', () => {
  const start = relief.indexOf('export function setHillshade');
  const shadeFn = relief.slice(start, relief.indexOf('\n}', start));
  assert.match(shadeFn, /if\(shadeOn && on\) setRelief\(false\);/);
  assert.match(relief, /map\.setTerrain\(null\);\s*if\(map\.getPitch\(\) > 0\) map\.easeTo\(\{ pitch: 0/, 'the relief going off lays the map flat');
  assert.match(panels, /if\(reliefBtn\) paintOverlay\(reliefBtn, reliefVisible\(\)\);/, 'the 3D row shows it is off');
});
