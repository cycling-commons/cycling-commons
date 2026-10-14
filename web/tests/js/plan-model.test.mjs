// SPDX-License-Identifier: AGPL-3.0-only
//
// plan-model.js: the ride planner's pure model (docs/specs/route-planner.md
// sections 4 and 6.3). Stops and legs, where a place goes in the ride, the
// figures, the profile bins, and the GPX writer. A leaf: no map, no DOM.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  MAX_STOPS, stopKey, legKeys, nearLine, insertIndex, figures, profileRuns, pointAt, gpxText, barText, createHistory, toughness, tickStepKm, surfaceMix, toughnessBars,
} from '../../assets/map/plan-model.js';

// A straight 2 km leg east along lat 50.4 (0.001 deg lng is about 71 m), 30 m samples.
const east = (fromLng, toLng) => [[fromLng, 50.4], [(fromLng + toLng) / 2, 50.4], [toLng, 50.4]];
const flat = n => Array.from({ length: n }, (_, i) => [i * 30, 100]);
const stops = [
  { lat: 50.4, lng: 5.80, name: 'Start' },
  { lat: 50.4, lng: 5.83, name: 'Middle' },
  { lat: 50.4, lng: 5.86, name: 'End' },
];
const legs = [
  { key: 'a', routed: true, line: east(5.80, 5.83), distM: 2130, heights: flat(72) },
  { key: 'b', routed: true, line: east(5.83, 5.86), distM: 2130, heights: flat(72) },
];

test('a stop is keyed by its place when it has one, else by its rounded point', () => {
  assert.equal(stopKey({ lat: 50.4, lng: 5.8, item: 'B:11007' }), 'B:11007');
  assert.equal(stopKey({ lat: 50.400001, lng: 5.800004 }), '50.40000,5.80000');
});

test('leg keys change with the bike, so a bike switch re-routes every leg', () => {
  assert.deepEqual(legKeys(stops, 'Road').map(k => k.endsWith('|Road')), [true, true]);
  assert.notDeepEqual(legKeys(stops, 'Road'), legKeys(stops, 'Gravel'));
  assert.equal(legKeys([stops[0]], 'Road').length, 0);
});

test('nearLine measures metres off and metres along a line', () => {
  // 0.00045 deg lat north of the line is about 50 m; a third of the way along the first leg.
  const n = nearLine(east(5.80, 5.83), [5.81, 50.40045]);
  assert.ok(n.distM > 40 && n.distM < 60, `off ${n.distM}`);
  assert.ok(n.alongM > 650 && n.alongM < 770, `along ${n.alongM}`);
});

test('a place near the second leg is inserted before the last stop', () => {
  const place = { lat: 50.4004, lng: 5.845, item: 'B:1', name: 'Tap' };
  assert.equal(insertIndex(stops, legs, place), 2);
});

test('a place near the first leg is inserted after the start', () => {
  assert.equal(insertIndex(stops, legs, { lat: 50.4004, lng: 5.815, item: 'B:2', name: 'Tap' }), 1);
});

test('with fewer than two stops a place goes at the end, and a place already in the ride is refused', () => {
  assert.equal(insertIndex([stops[0]], [], { lat: 50.4, lng: 5.9, item: 'B:3' }), 1);
  assert.equal(insertIndex(stops, legs, { lat: 50.4, lng: 5.845, item: 'B:1' }), 2);
  assert.equal(insertIndex([...stops, { lat: 50.4, lng: 5.845, item: 'B:1' }], legs, { lat: 50.4, lng: 5.845, item: 'B:1' }), -1);
});

test('the ride holds at most the spec cap of stops', () => {
  assert.equal(MAX_STOPS, 25);
});

test('figures: distance is the routed legs, climbed and descended come from smoothed heights', () => {
  const up = Array.from({ length: 11 }, (_, i) => [i * 30, 100 + i * 10]);   // 100 m climbed over 300 m
  const down = Array.from({ length: 11 }, (_, i) => [i * 30, 200 - i * 10]);
  const f = figures([
    { routed: true, line: east(5.80, 5.81), distM: 300, heights: up },
    { routed: true, line: east(5.81, 5.82), distM: 300, heights: down },
    { routed: false },
  ]);
  assert.equal(f.distM, 600);
  assert.ok(Math.abs(f.upM - 100) < 7, `up ${f.upM}`);
  assert.ok(Math.abs(f.downM - 100) < 7, `down ${f.downM}`);
});

test('a leg without heights leaves a gap in the profile and counts no climbing', () => {
  const runs = profileRuns([
    { routed: true, line: east(5.80, 5.81), distM: 300, heights: flat(11) },
    { routed: true, line: east(5.81, 5.82), distM: 300, heights: null },
    { routed: true, line: east(5.82, 5.83), distM: 300, heights: flat(11) },
  ], 100);
  assert.equal(runs.length, 2);
  assert.equal(runs[0][0].d0, 0);
  assert.ok(runs[1][0].d0 >= 600, `second run starts at ${runs[1][0].d0}`);
  assert.equal(figures([{ routed: true, line: [], distM: 300, heights: null }]).upM, 0);
});

test('profile bins carry a gradient in percent', () => {
  const up = Array.from({ length: 11 }, (_, i) => [i * 30, 100 + i * 3]);   // 10 percent
  const runs = profileRuns([{ routed: true, line: east(5.80, 5.81), distM: 300, heights: up }], 100);
  assert.equal(runs.length, 1);
  for (const bin of runs[0]) assert.ok(Math.abs(bin.grad - 10) < 0.5, `grad ${bin.grad}`);
});

test('pointAt finds the point on the line at a distance along the ride', () => {
  const p = pointAt(legs, 2130 + 1065);
  assert.ok(Math.abs(p[0] - 5.845) < 0.002, `lng ${p[0]}`);
  assert.equal(p[1], 50.4);
  assert.equal(pointAt(legs, 99999), null);
});

test('the GPX carries one track with elevations and one waypoint per stop', () => {
  const ride = { name: 'Spa loop', stops: [{ ...stops[0], item: 'B:11007' }, stops[1], stops[2]], legs };
  const xml = gpxText(ride, { itemUrl: item => `https://example.test/map?item=${item}` });
  assert.match(xml, /^<\?xml version="1.0" encoding="UTF-8"\?>\n<gpx version="1.1"/);
  assert.equal((xml.match(/<trkpt /g) || []).length, 5);   // the shared point between the legs is written once
  assert.equal((xml.match(/<wpt /g) || []).length, 3);
  assert.match(xml, /<wpt lat="50.400000" lon="5.800000"><name>Start<\/name><link href="https:\/\/example.test\/map\?item=B:11007"\/>/);
  assert.match(xml, /<ele>100<\/ele>/);
  assert.match(xml, /<name>Spa loop<\/name>/);
  assert.doesNotMatch(xml, /<link[^\n]*Middle/);   // a map point carries no link
});

test('the GPX escapes a name with markup in it', () => {
  const xml = gpxText({ name: 'a <b> & "c"', stops: [stops[0], stops[2]], legs: [legs[0]] }, {});
  assert.match(xml, /<name>a &lt;b&gt; &amp; &quot;c&quot;<\/name>/);
});

test('the ride bar reads distance, climbed and the stop count', () => {
  const text = barText({ distM: 42300, upM: 610.4 }, 3, { km: m => (m / 1000).toFixed(1) + ' km', m: m => Math.round(m) + ' m', stops: n => n + ' stops' });
  assert.equal(text, '42.3 km · 610 m ↑ · 3 stops');
});

test('the history keeps the stops before each change and hands them back one step at a time', () => {
  const h = createHistory(3);
  assert.equal(h.pop(), null);
  h.push([{ lat: 1, lng: 1, name: 'a' }]);
  h.push([{ lat: 1, lng: 1, name: 'a' }, { lat: 2, lng: 2, name: 'b' }]);
  assert.equal(h.size(), 2);
  const back = h.pop();
  assert.equal(back.length, 2);
  assert.equal(back[1].name, 'b');
  assert.equal(h.pop().length, 1);
  assert.equal(h.pop(), null);
});

test('the history is bounded and hands back copies, never the array it was given', () => {
  const h = createHistory(2);
  const stops = [{ lat: 1, lng: 1, name: 'a' }];
  h.push(stops);
  h.push([]);
  h.push([{ lat: 3, lng: 3, name: 'c' }]);
  assert.equal(h.size(), 2);
  stops[0].name = 'changed after push';
  assert.equal(h.pop()[0].name, 'c');
  assert.deepEqual(h.pop(), []);
});

/* Toughness (route-planner.md section 4.4): the routes' length bands give a
   base, the metres climbed per km add to it, and the level reads in the
   routes' own difficulty words. */
test('toughness: a short flat ride is easy, and climbing per km raises the level', () => {
  const easy = toughness(30000, 120);
  assert.equal(easy.level, 1); assert.equal(easy.terrain, 'flat'); assert.equal(easy.perKm, 4);
  assert.equal(toughness(46000, 977).level, 3);          // the owner's Spa ride: 21 m/km, hilly, short
  assert.equal(toughness(46000, 977).terrain, 'hilly');
  assert.equal(toughness(80000, 2400).level, 5);         // 30 m/km on a medium ride: very hard
  assert.equal(toughness(80000, 2400).terrain, 'mountainous');
});

test('toughness: distance alone climbs the ladder by the routes\' bands, and the level never passes 5', () => {
  assert.equal(toughness(49000, 0).level, 1);
  assert.equal(toughness(51000, 0).level, 2);
  assert.equal(toughness(120000, 0).level, 3);
  assert.equal(toughness(200000, 0).level, 4);
  assert.equal(toughness(200000, 8000).level, 5);
  assert.equal(toughness(0, 0), null);
});

test('the distance axis keeps about ten ticks whatever the length', () => {
  assert.equal(tickStepKm(30), 2);
  assert.equal(tickStepKm(46), 5);
  assert.equal(tickStepKm(120), 10);
  assert.equal(tickStepKm(863), 100);
  assert.equal(tickStepKm(8), 1);
  for (const km of [12, 46, 120, 300, 863]) {
    const n = Math.floor(km / tickStepKm(km));
    assert.ok(n >= 4 && n <= 15, `${km} km gives ${n} ticks`);
  }
});

/* The surface mix (route-planner.md section 4.4): metres on paved, gravel and
   rough roads over the routed legs, and what they do to the toughness. */
test('surfaceMix sums the legs and leaves what the engine did not say as unknown', () => {
  const mix = surfaceMix([
    { routed: true, distM: 3000, surfaces: { paved: 2000, gravel: 800, rough: 200, unknown: 0 } },
    { routed: true, distM: 1000, surfaces: null },
    { routed: false },
  ]);
  assert.deepEqual(mix, { paved: 2000, gravel: 800, rough: 200, unknown: 1000 });
});

test('gravel and rough kilometres weigh more than paved ones in the toughness', () => {
  // 40 km flat on asphalt is easy; with 30 km of gravel it counts as 49 km, still short; all rough it counts as 64 km: a medium ride.
  assert.equal(toughness(40000, 100, { paved: 40000, gravel: 0, rough: 0, unknown: 0 }).level, 1);
  assert.equal(toughness(40000, 100, { paved: 10000, gravel: 30000, rough: 0, unknown: 0 }).level, 1);
  assert.equal(toughness(40000, 100, { paved: 0, gravel: 0, rough: 40000, unknown: 0 }).level, 2);
  assert.equal(toughness(40000, 100, { paved: 0, gravel: 0, rough: 40000, unknown: 0 }).effectiveKm, 64);
  // Unknown surface counts as paved, and no mix at all means the plain distance.
  assert.equal(toughness(40000, 100, { paved: 0, gravel: 0, rough: 0, unknown: 40000 }).effectiveKm, 40);
  assert.equal(toughness(40000, 100).effectiveKm, 40);
});

/* The toughness graphic (route-planner.md section 4.4): the distance bar's
   surface segments as shares of the ride, in a fixed order, zero widths left out. */
test('toughnessBars gives the surface segments of the distance bar as shares', () => {
  assert.deepEqual(toughnessBars({ paved: 30000, gravel: 10000, rough: 0, unknown: 0 }), [
    { cls: 'paved', share: 0.75 }, { cls: 'gravel', share: 0.25 },
  ]);
  assert.deepEqual(toughnessBars({ paved: 0, gravel: 0, rough: 0, unknown: 5000 }), [{ cls: 'unknown', share: 1 }]);
  assert.deepEqual(toughnessBars({ paved: 0, gravel: 0, rough: 0, unknown: 0 }), []);
});
