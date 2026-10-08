// SPDX-License-Identifier: AGPL-3.0-only
//
// A count bubble stands for three places or more (map-and-search.md §5).
// A "2" beside single pins of other categories read as a bug (owner
// 2026-10-08): two pins take the same room as the bubble, so a pair shows as
// its two pins and pin-fan.js spreads them when they share a point.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'map', 'osm-pools.js'), 'utf8');

test('a pool never folds a pair into a count bubble', () => {
  const add = (src.match(/map\.addSource\(srcId,\{type:'geojson', cluster:true[^}]*\}/) || [''])[0];
  assert.match(add, /clusterMinPoints:3\b/);
});
