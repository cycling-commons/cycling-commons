// SPDX-License-Identifier: AGPL-3.0-only
//
// The drawer names where a row came from, not where its layer usually comes
// from. The Matterhorn, a Wikidata row in the scenic layer, read "Source ·
// OpenStreetMap (tourism=viewpoint / natural=peak / waterway=waterfall)",
// because the layer's OSM citation won over the row's own source.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { drawerSource, drawerOrigin } from '../../assets/map/origin.js';

const LABELS = { osm: 'OpenStreetMap', wikidata: 'Wikidata' };
const label = (raw) => LABELS[raw] || null;
const SCENIC_OSM = 'OpenStreetMap (tourism=viewpoint / natural=peak / waterway=waterfall)';

test('a Wikidata row in an OSM layer says Wikidata', () => {
  assert.equal(drawerSource('wikidata', SCENIC_OSM, label), 'Wikidata');
  assert.equal(drawerOrigin('wikidata', label), 'Wikidata');
});

test('an OSM row keeps its layer citation, tags and all', () => {
  assert.equal(drawerSource('osm', SCENIC_OSM, label), SCENIC_OSM);
  assert.equal(drawerOrigin('osm', label), 'OSM');
});

test('a coverage point with no source type is OSM, as the tiles are', () => {
  assert.equal(drawerSource(undefined, SCENIC_OSM, label), SCENIC_OSM);
  assert.equal(drawerSource(undefined, '', label), 'OpenStreetMap');
  assert.equal(drawerOrigin(undefined, label), 'OSM');
});

test('a source type without a label falls back to the layer citation', () => {
  assert.equal(drawerSource('something-new', SCENIC_OSM, label), SCENIC_OSM);
});
