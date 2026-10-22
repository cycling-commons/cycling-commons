// SPDX-License-Identifier: AGPL-3.0-only
//
// The layer list's "shown/total" (map-and-search.md section 4.3): shown is what the rider sees.
// A layer that is off counts 0; our own pool pins count only when the chips let them through,
// the rule they are drawn by; and the coverage counts request carries its answer's shape, so a
// browser never reuses a cached answer without the stays numbers the chips narrow to.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = f => fs.readFileSync(path.join(ROOT, f), 'utf8');

test('a switched-off layer counts nothing shown', () => {
  assert.match(read('assets/map/render.js'), /const shown=!active\.has\(layer\.key\) \? 0 : /);
});

test('pool pins count only when the layer is on and the chips let them through', () => {
  const pools = read('assets/map/osm-pools.js');
  assert.match(pools, /if\(!st \|\| !active\.has\(key\)\) return 0;/);
  assert.match(pools, /poolVisible\(f, st\.layer\) && poolChipsPass\(st\.layer, key, f\.properties \|\| \{\}\)/);
});

test('the counts request names its answer shape, so an old cached answer is never read', () => {
  const cov = read('assets/map/coverage.js');
  assert.match(cov, /const COUNTS_SHAPE=2;/);
  assert.match(cov, /fetch\('\/map\/coverage\/counts\?shape='\+COUNTS_SHAPE\+/);
});
