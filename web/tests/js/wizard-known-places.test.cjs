// SPDX-License-Identifier: AGPL-3.0-only
//
// The wizard's known-places overlay must read the coordinates the server
// actually sends.
//
// `/map/coverage/nearby` emits one point as `ll: [lat, lng]`
// (CoverageRepository::entry). improve.js read `p.lng` / `p.lat`, which are
// not keys of that object, so every marker was created at
// `[undefined, undefined]` and none of them ever appeared. Nothing errored:
// MapLibre does not raise on that, the fetch succeeded, the count was right,
// and the map was simply blank. It shipped that way and was reported by the
// owner looking at Hoorn and seeing none of the 25 water points around the pin
// (2026-09-12).
//
// A unit test cannot catch it — the module builds a MapLibre map on import —
// so this reads the marker call out of the source and checks it against the
// PHP that produces the payload. Two files, one contract, and the test fails
// if either side moves.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const improve = fs.readFileSync(path.join(ROOT, 'assets', 'contribute', 'improve.js'), 'utf8');
const repository = fs.readFileSync(path.join(ROOT, 'src', 'Coverage', 'CoverageRepository.php'), 'utf8');

test('the server still sends the point as ll: [lat, lng]', () => {
  const m = repository.match(/\$entry\['ll'\]\s*=\s*\[\(float\)\s*\$row\['(\w+)'\],\s*\(float\)\s*\$row\['(\w+)'\]\]/);
  assert.ok(m, "CoverageRepository::entry() no longer writes 'll' the way this test reads it");
  assert.deepEqual([m[1], m[2]], ['lat', 'lng'], "'ll' order changed: the overlay's [ll[1], ll[0]] is now wrong");
});

test('the overlay places its markers from ll, in lng/lat order', () => {
  const m = improve.match(/\.setLngLat\(\[p\.([\w[\]]+),\s*p\.([\w[\]]+)\]\)/);
  assert.ok(m, 'the known-places marker no longer calls setLngLat([...]) the way this test reads it');
  assert.deepEqual(
    [m[1], m[2]],
    ['ll[1]', 'll[0]'],
    'the overlay must read ll, longitude first: p.lng and p.lat do not exist on this payload',
  );
});

test('a point with no coordinates is skipped rather than placed at undefined', () => {
  assert.match(
    improve,
    /if \(!p\.ll \|\| 2 !== p\.ll\.length\) return;/,
    'the overlay must refuse a point it cannot place',
  );
});

test('our own rows are drawn differently from OpenStreetMap records', () => {
  // The distinction is the point of the overlay: adding on top of one of ours
  // is a duplicate, adding beside an OSM record often is not.
  assert.match(improve, /p\.curated \? 'cc-cov-dot cc-cov-dot--ours' : 'cc-cov-dot'/);
  assert.match(
    fs.readFileSync(path.join(ROOT, 'templates', 'contribute', 'improve.html.twig'), 'utf8'),
    /\.cc-cov-dot--ours\{/,
    'the ours modifier has no style, so both kinds look identical',
  );
});
