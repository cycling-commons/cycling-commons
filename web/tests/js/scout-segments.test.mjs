// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// The pure half of scout plan task 6: cutting the ridden line between a
// stretch's two taps, and the device→map-class tables the colours hang on.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DEVICE_CLASS, DEVICE_DECLARABLE, MAX_SEGMENT_POINTS, cutTrack, downsample,
  nearestTrackIndex, sliceTrack } from '../../assets/map/scout-segments.js';

const at = s => new Date('2026-08-15T10:00:00Z'.replace('00:00', String(s).padStart(2, '0') + ':00'));
const track = [
  { lat: 50.0, lng: 6.00, at: at(0) },
  { lat: 50.1, lng: 6.01, at: at(1) },
  { lat: 50.2, lng: 6.02, at: at(2) },
  { lat: 50.3, lng: 6.03, at: at(3) },
  { lat: 50.4, lng: 6.04, at: at(4) },
];

test('every device surface type has a map class and a declarable label', () => {
  for (const type of [1, 2, 3, 4, 5, 6, 7, 8]) {
    assert.ok(DEVICE_CLASS[type], `class for type ${type}`);
    assert.ok(DEVICE_DECLARABLE[type], `label for type ${type}`);
  }
});

test('the taps bound the line and the samples fill the middle', () => {
  const seg = {
    startTime: at(1), endTime: at(3),
    startLat: 50.15, startLon: 6.015, endLat: 50.25, endLon: 6.025,
  };
  const g = cutTrack(track, seg);
  assert.ok(g);
  // Exact tap points win the endpoints - they are where the rider SAID the
  // surface changes; the device samples merely fill in between.
  assert.deepEqual(g.a, [6.015, 50.15]);
  assert.deepEqual(g.b, [6.025, 50.25]);
  assert.deepEqual(g.line[0], g.a);
  assert.deepEqual(g.line[g.line.length - 1], g.b);
  // The three in-window samples (minutes 1..3) sit inside.
  assert.equal(g.line.length, 5);
});

test('coordinates only - no timestamps ride along', () => {
  const seg = { startTime: at(0), endTime: at(4), startLat: 50.0, startLon: 6.0, endLat: 50.4, endLon: 6.04 };
  const g = cutTrack(track, seg);
  for (const p of g.line) {
    assert.equal(p.length, 2, 'a [lng, lat] pair and nothing more');
    assert.equal(typeof p[0], 'number');
    assert.equal(typeof p[1], 'number');
  }
});

test('an unterminated stretch runs to the last sample', () => {
  const seg = { startTime: at(2), endTime: at(4), startLat: 50.2, startLon: 6.02, endLat: null, endLon: null };
  const g = cutTrack(track, seg);
  assert.ok(g);
  // No END tap to trust: the last in-window sample becomes b.
  assert.deepEqual(g.b, [6.04, 50.4]);
});

test('a window with no usable line yields null, not a two-point lie', () => {
  const seg = { startTime: at(9), endTime: at(9), startLat: 51.0, startLon: 7.0, endLat: null, endLon: null };
  assert.equal(cutTrack(track, seg), null);
});

test('downsampling keeps both endpoints and respects the cap', () => {
  const long = Array.from({ length: 10000 }, (_, i) => [i, i]);
  const out = downsample(long, MAX_SEGMENT_POINTS);
  assert.equal(out.length, MAX_SEGMENT_POINTS);
  assert.deepEqual(out[0], [0, 0]);
  assert.deepEqual(out[out.length - 1], [9999, 9999]);
});

test('a dragged endpoint snaps to the nearest track point', () => {
  assert.equal(nearestTrackIndex(track, { lng: 6.012, lat: 50.12 }), 1);
  assert.equal(nearestTrackIndex(track, { lng: 6.05, lat: 50.5 }), 4);
});

test('slicing by index keeps track points as the endpoints', () => {
  const g = sliceTrack(track, 1, 3);
  assert.ok(g);
  assert.deepEqual(g.a, [6.01, 50.1]);
  assert.deepEqual(g.b, [6.03, 50.3]);
  assert.equal(g.line.length, 3);
});

test('a slice needs at least two points and stays inside the ride', () => {
  assert.equal(sliceTrack(track, 2, 2), null);
  assert.equal(sliceTrack(track, 3, 9), null);
  assert.equal(sliceTrack(track, -1, 2), null);
});
