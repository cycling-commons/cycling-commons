// SPDX-License-Identifier: AGPL-3.0-only
//
// The point a deep link moves the scope to (docs/specs/map-and-search.md §8).
// A route has no point of its own, only a path, so `?route=111` (Liege
// Bastogne Liege) never left a Netherlands scope and the route was not drawn.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { featureLL } from '../../assets/map/util.js';

test('a pool feature and a GeoJSON point give their own point', () => {
  assert.deepEqual(featureLL({ll:[50.5, 5.9]}), [50.5, 5.9]);
  assert.deepEqual(featureLL({geometry:{type:'Point', coordinates:[5.9, 50.5]}}), [50.5, 5.9]);
  assert.deepEqual(featureLL({geom:{ll:[50.5, 5.9]}}), [50.5, 5.9]);
});

test('a route or a surface stretch gives the first point of its path', () => {
  assert.deepEqual(featureLL({geom:{path:[[50.63, 5.57], [50.0, 5.72]]}}), [50.63, 5.57]);
});

test('a climb gives its summit, or its foot without one', () => {
  assert.deepEqual(featureLL({geom:{ll:[50.4, 5.8]}, route:[[50.3, 5.7]]}), [50.4, 5.8]);
  assert.deepEqual(featureLL({route:[[50.3, 5.7], [50.4, 5.8]]}), [50.3, 5.7]);
});

test('nothing to point at gives null', () => {
  assert.equal(featureLL(null), null);
  assert.equal(featureLL({geom:{path:[]}}), null);
});
