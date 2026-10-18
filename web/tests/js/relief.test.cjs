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
  assert.match(twig, /\{% if relief_tiles\|default\(false\) %\}\s*<button class="layer ov-row" id="ovRelief"/);
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
