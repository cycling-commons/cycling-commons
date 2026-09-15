// SPDX-License-Identifier: AGPL-3.0-only
//
// A coverage point opened from ?ref=, search or a ride row names its type the
// way a click on its icon does: from the tile's own `t` label
// (docs/specs/map-and-search.md §8, docs/specs/coverage-provider.md §4).
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { tileTypeLabel, coverageSourceLayers } from '../../assets/map/osm-tags.js';

const feat = props => ({ properties: props });

test('the label of the feature with that ref', () => {
  const fs = [
    feat({ ref: 'node/47229891', t: 'Train station', n: 'Enkhuizen' }),
    feat({ ref: 'way/1078891286', t: 'Ferry', n: 'Enkhuizen - Medemblik' }),
  ];
  assert.equal(tileTypeLabel(fs, 'way/1078891286'), 'Ferry');
  assert.equal(tileTypeLabel(fs, 'node/47229891'), 'Train station');
});

test('no match, no label, or no features give null', () => {
  assert.equal(tileTypeLabel([feat({ ref: 'node/1', t: 'Ferry' })], 'node/2'), null);
  assert.equal(tileTypeLabel([feat({ ref: 'node/1' })], 'node/1'), null);
  assert.equal(tileTypeLabel([feat({ ref: 'node/1', t: '  ' })], 'node/1'), null);
  assert.equal(tileTypeLabel([], 'node/1'), null);
  assert.equal(tileTypeLabel(null, 'node/1'), null);
  assert.equal(tileTypeLabel([null, {}], 'node/1'), null);
});

test('a later duplicate (a feature cut across two tiles) still answers', () => {
  const fs = [feat({ ref: 'way/5' }), feat({ ref: 'way/5', t: 'Ferry' })];
  assert.equal(tileTypeLabel(fs, 'way/5'), 'Ferry');
});

test('source layers: the country first, then the unstamped bucket', () => {
  const ccs = ['be', 'nl', 'zz'];
  assert.deepEqual(coverageSourceLayers('f', ccs, 'NL'), ['f_nl', 'f_zz']);
  // Country unknown or not in the tiles: every per-country layer.
  assert.deepEqual(coverageSourceLayers('f', ccs, null), ['f_be', 'f_nl', 'f_zz']);
  assert.deepEqual(coverageSourceLayers('f', ccs, 'JP'), ['f_be', 'f_nl', 'f_zz']);
  // Tiles from before the per-country split: one plain layer.
  assert.deepEqual(coverageSourceLayers('f', [null], 'NL'), ['f']);
});
