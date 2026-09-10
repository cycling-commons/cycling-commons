// SPDX-License-Identifier: AGPL-3.0-only
//
// osm-tags.js: the scenic-view facts OSM holds and the drawer now shows
// (docs/specs/coverage-provider.md §5). Both helpers exist because OSM values
// are free text: `ele` is usually "484" but sometimes "484 m" or "1200 ft",
// and `direction` is either a bearing or a compass point. A wrong guess would
// print a confident number that is not what the mapper wrote, so anything not
// plainly metres is handed back untouched instead.
import test from 'node:test';
import assert from 'node:assert/strict';
import { osmMetres, viewDirection, osmRefUrl, OSM_REF } from '../../assets/map/osm-tags.js';

test('metres are read from the shapes OSM actually writes', () => {
  assert.equal(osmMetres('484'), 484);        // Costo Liso, node 12969271187
  assert.equal(osmMetres(' 484 m'), 484);
  assert.equal(osmMetres('1084.5'), 1084.5);
  assert.equal(osmMetres('-3'), -3);          // below sea level is a real elevation
});

test('a value that is not plainly metres is refused, never guessed', () => {
  assert.equal(osmMetres('1200 ft'), null);
  assert.equal(osmMetres('about 500'), null);
  assert.equal(osmMetres(''), null);
});

test('a bearing becomes a compass point and keeps its degrees', () => {
  assert.equal(viewDirection('225'), 'SW · 225°');
  assert.equal(viewDirection('0'), 'N · 0°');
  assert.equal(viewDirection('360'), 'N · 0°');
  assert.equal(viewDirection('370'), 'N · 10°');   // wraps, never "370°"
  assert.equal(viewDirection('-90'), 'W · 270°');
});

test('a compass point stays itself, and prose stays prose', () => {
  assert.equal(viewDirection('sw'), 'SW');
  assert.equal(viewDirection('N'), 'N');
  assert.equal(viewDirection('towards the lake'), 'towards the lake');
});

// osmRefUrl: the id behind a shareable coverage POI. Thousands of scenic views
// are named "Viewpoint", so ?feature=<name> was one link for all of them and
// the OSM source link sent a rider to a coordinate query. Both now use the ref,
// which means the guard has two jobs at once: it decides what goes in an href,
// and it decides what /map/coverage/poi/{node|way}/{id} will accept. Letting a
// third shape through would mint share links that resolve to nothing.
test('a served ref becomes the exact OSM page', () => {
  assert.equal(osmRefUrl('node/462149319'), 'https://www.openstreetmap.org/node/462149319');
  assert.equal(osmRefUrl('way/12345'), 'https://www.openstreetmap.org/way/12345');
});

test('anything the poi endpoint would refuse is refused here too', () => {
  assert.equal(osmRefUrl('relation/7'), null);   // the route requires node|way
  assert.equal(osmRefUrl('node/'), null);
  assert.equal(osmRefUrl('node/12a'), null);
  assert.equal(osmRefUrl('pivot/abc'), null);    // Tourisme Wallonie rows are not OSM
  assert.equal(osmRefUrl(''), null);
  assert.equal(osmRefUrl(null), null);
  assert.equal(osmRefUrl(undefined), null);
});

test('no ref can smuggle a scheme or a host into the href', () => {
  assert.equal(osmRefUrl('node/1 javascript:alert(1)'), null);
  assert.equal(osmRefUrl('https://evil.example/node/1'), null);
  assert.equal(osmRefUrl('node/1/../../evil'), null);
  assert.equal(osmRefUrl('node/1\nnode/2'), null);   // anchored, so no second line
});

test('OSM_REF is not sticky or global, so repeated tests never alternate', () => {
  assert.equal(OSM_REF.flags, '');
  assert.equal(OSM_REF.test('node/1'), true);
  assert.equal(OSM_REF.test('node/1'), true);
});
