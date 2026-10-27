// SPDX-License-Identifier: AGPL-3.0-only
// Reviewing a road stretch turns Surfaces on through the switch itself (moderation-and-contribution.md section 5.2c):
// OSM's lines and our own roads together. The skin alone left our roads off, so an approved road drew nothing over
// OSM's red "surface not recorded" line until a reload (owner 2026-10-27). Pinned in the source, as the map tests do.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const drawer = fs.readFileSync(path.join(__dirname, '../../assets/map/drawer.js'), 'utf8');
const panels = fs.readFileSync(path.join(__dirname, '../../assets/map/panels.js'), 'utf8');

test('a road card turns Surfaces on by clicking the switch, not the skin alone', () => {
  const at = drawer.indexOf("if(f.pending.letter === 'A' && surfaceTilesConfigured() && !surfaceTilesVisible()){");
  assert.ok(at > 0, 'the road card still turns Surfaces on');
  const block = drawer.slice(at, drawer.indexOf('}', drawer.indexOf('btn.click()', at)) + 1);
  assert.match(block, /if\(btn && !btn\.hidden\) btn\.click\(\); else setSurfaceTiles\(true\);/);
  assert.ok(!/classList\.add\('on'\)/.test(block), 'no half-on button');
});

test('the switch turns our roads on with the skin', () => {
  assert.match(panels, /const on=setSurfaceTiles\(!surfaceTilesVisible\(\)\);/);
  assert.match(panels, /if\(on\) active\.add\('surface'\); else active\.delete\('surface'\);\n      render\(\);/);
});
