// SPDX-License-Identifier: AGPL-3.0-only
// The pin a new route proposal's photos are uploaded for
// (docs/specs/route-domain.md §4.5): a point ON the track, the middle one,
// never the start or the finish the privacy trim keeps off the server.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { gpxMidpoint } = require('../../assets/contribute/route-photos.js');

test('the middle track point, whatever the attribute order or prefix', () => {
  const gpx = '<gpx><trk><trkseg>'
    + '<trkpt lat="50.0" lon="5.0"><ele>1</ele></trkpt>'
    + '<gpx:trkpt lon="5.1" lat="50.1"/>'
    + "<trkpt lat='50.2' lon='5.2'></trkpt>"
    + '</trkseg></trk></gpx>';
  assert.deepEqual(gpxMidpoint(gpx), { lat: 50.1, lng: 5.1 });
});

test('a route file with no track falls back to its route points', () => {
  assert.deepEqual(gpxMidpoint('<gpx><rte><rtept lat="1" lon="2"/></rte></gpx>'), { lat: 1, lng: 2 });
});

test('nothing usable is null, never a made-up point', () => {
  assert.equal(gpxMidpoint('<gpx></gpx>'), null);
  assert.equal(gpxMidpoint('<gpx><trkpt lat="abc" lon="5"/></gpx>'), null);
  assert.equal(gpxMidpoint(null), null);
});
