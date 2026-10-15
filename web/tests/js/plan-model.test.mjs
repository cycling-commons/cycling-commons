// SPDX-License-Identifier: AGPL-3.0-only
//
// plan-model.js: the ride planner's pure model (docs/specs/route-planner.md
// sections 4 and 6.3). Stops and legs, where a place goes in the ride, the
// figures, the profile bins, and the GPX writer. A leaf: no map, no DOM.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  MAX_STOPS, WORK_KEEP_MS, workFresh, stopKey, legKeys, nearLine, insertIndex, figures, profileRuns, pointAt, gpxText, barText, createHistory, tickStepKm, surfaceMix,
  PACE, flatSpeed, rideTime, surfaceBars, gradientBars, roundedHm, steepestKm, longestClimb, waterGap, sunTimes, windShares,
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
  const fmt = { km: m => (m / 1000).toFixed(1) + ' km', m: m => Math.round(m) + ' m', stops: n => n + ' stops' };
  assert.equal(barText({ distM: 42300, upM: 610.4 }, 3, fmt), '42.3 km · 610 m ↑ · 3 stops');
  assert.equal(barText({ distM: 42300, upM: 610.4 }, 3, fmt, '2 h 05'), '42.3 km · 610 m ↑ · 2 h 05 · 3 stops');
});

test('the history keeps the stops before each change and hands them back one step at a time', () => {
  const h = createHistory(3);
  assert.equal(h.undo([]), null);
  h.push([{ lat: 1, lng: 1, name: 'a' }]);
  h.push([{ lat: 1, lng: 1, name: 'a' }, { lat: 2, lng: 2, name: 'b' }]);
  assert.equal(h.size(), 2);
  const back = h.undo([]);
  assert.equal(back.length, 2);
  assert.equal(back[1].name, 'b');
  assert.equal(h.undo(back).length, 1);
  assert.equal(h.undo([]), null);
});

test('the history is bounded and hands back copies, never the array it was given', () => {
  const h = createHistory(2);
  const stops = [{ lat: 1, lng: 1, name: 'a' }];
  h.push(stops);
  h.push([]);
  h.push([{ lat: 3, lng: 3, name: 'c' }]);
  assert.equal(h.size(), 2);
  stops[0].name = 'changed after push';
  assert.equal(h.undo([])[0].name, 'c');
  assert.deepEqual(h.undo([]), []);
});

/* Redo (owner 2026-10-15): what undo took comes back, at most five steps,
   and a new change to the stops forgets it, as in any editor. */
const st = i => [{ lat: i, lng: i, name: 's' + i }];
test('redo hands back what undo took, one step at a time', () => {
  const h = createHistory(30, 5);
  h.push(st(0));                       // the ride was s0, is now s1
  h.push(st(1));                       // is now s2
  let cur = st(2);
  cur = h.undo(cur); assert.equal(cur[0].name, 's1');
  cur = h.undo(cur); assert.equal(cur[0].name, 's0');
  assert.equal(h.redoSize(), 2);
  cur = h.redo(cur); assert.equal(cur[0].name, 's1');
  cur = h.redo(cur); assert.equal(cur[0].name, 's2');
  assert.equal(h.redo(cur), null);
  assert.equal(h.undo(cur)[0].name, 's1', 'a redone step can be undone again');
});

test('redo keeps the five steps nearest the ride, never more', () => {
  const h = createHistory(30, 5);
  for (let i = 0; i < 7; i++) h.push(st(i));   // the ride is s7
  let cur = st(7);
  for (let i = 0; i < 7; i++) cur = h.undo(cur);
  assert.equal(cur[0].name, 's0');
  assert.equal(h.redoSize(), 5);
  const names = [];
  for (let n = h.redo(cur); n; n = h.redo(cur)) { names.push(n[0].name); cur = n; }
  assert.deepEqual(names, ['s1', 's2', 's3', 's4', 's5']);
});

test('a new change forgets what could be redone', () => {
  const h = createHistory(30, 5);
  h.push(st(0));
  const back = h.undo(st(1));
  assert.equal(h.redoSize(), 1);
  h.push(back);                        // the rider changes the ride instead
  assert.equal(h.redoSize(), 0);
  assert.equal(h.redo(st(9)), null);
});

test('redo hands back copies, never the array it was given', () => {
  const h = createHistory(30, 5);
  h.push(st(0));
  const now = st(1);
  h.undo(now);
  now[0].name = 'changed after undo';
  assert.equal(h.redo(st(0))[0].name, 's1');
});

/* Toughness (route-planner.md section 4.4): the routes' length bands give a
   base, the metres climbed per km add to it, and the level reads in the
   routes' own difficulty words. */


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

/* The surface mix (route-planner.md section 4.4a): metres on paved, gravel and
   rough roads over the routed legs, read by the day's model as rolling resistance. */
test('surfaceMix sums the legs and leaves what the engine did not say as unknown', () => {
  const mix = surfaceMix([
    { routed: true, distM: 3000, surfaces: { paved: 2000, gravel: 800, rough: 200, unknown: 0 } },
    { routed: true, distM: 1000, surfaces: null },
    { routed: false },
  ]);
  assert.deepEqual(mix, { paved: 2000, gravel: 800, rough: 200, unknown: 1000 });
});


/* The toughness graphic (route-planner.md section 4.4): the distance bar's
   surface segments as shares of the ride, in a fixed order, zero widths left out. */

/* The day (route-planner.md section 4.4): one reference rider per bike type,
   the riding time from the gradient of every step and the rolling resistance
   of the leg's surfaces, the flat equivalent, and what makes it tougher. */
const kmh = ms => ms * 3.6;
// A leg of `km` kilometres at a constant gradient in percent, sampled every 30 m, with an optional surface mix.
const slope = (km, grad, surfaces = null) => {
  const n = Math.round(km * 1000 / 30);
  return { routed: true, distM: km * 1000, heights: Array.from({ length: n + 1 }, (_, i) => [i * 30, 200 + i * 30 * grad / 100]), surfaces, line: [] };
};
// Legs in a row, each starting at the height the one before ended on, as a routed ride's do.
const chain = (...legs) => { let h = 200; return legs.map(l => { const off = h - l.heights[0][1]; l.heights = l.heights.map(([d, e]) => [d, e + off]); h = l.heights[l.heights.length - 1][1]; return l; }); };

test('every bike type has a reference rider', () => {
  for (const bike of ['Road', 'Gravel', 'MTB', 'E-bike', 'Handbike', 'Recumbent', 'Trike', 'Tandem']) {
    const r = PACE[bike];
    assert.ok(r && r.watts > 0 && r.cda > 0 && r.kg > 0 && r.crr > 0 && r.capKmh > 0, `${bike} has no complete reference rider`);
  }
});

test('the flat speed of the road rider at 150 W is about 29 km/h, and the E-bike stops being helped at 25 km/h', () => {
  const road = kmh(flatSpeed('Road'));
  assert.ok(road > 27 && road < 31, `road flat speed ${road}`);
  assert.ok(kmh(flatSpeed('MTB')) < road, 'knobby tyres and an upright rider are slower');
  assert.ok(Math.abs(kmh(flatSpeed('E-bike')) - 25) < 0.5, `e-bike flat speed ${kmh(flatSpeed('E-bike'))}`);
});

test('ride time: a 6 % climb is ridden at about 9 km/h, a descent at the cap, and the flat at the flat speed', () => {
  const climb = rideTime([slope(5, 6)], 'Road');
  assert.ok(kmh(5000 / climb.seconds) > 8 && kmh(5000 / climb.seconds) < 10.5, `climb speed ${kmh(5000 / climb.seconds)}`);
  const down = rideTime([slope(5, -6)], 'Road');
  assert.ok(Math.abs(kmh(5000 / down.seconds) - PACE.Road.capKmh) < 0.5, `descent speed ${kmh(5000 / down.seconds)}`);
  const flat = rideTime([slope(10, 0)], 'Road');
  assert.ok(Math.abs(10000 / flat.seconds - flatSpeed('Road')) < 0.05);
  assert.ok(climb.parts.climb > 0 && climb.parts.flat === 0, 'a climb counts as climbing, not as flat');
  assert.ok(Math.abs(climb.parts.paved - climb.seconds) < 1e-6, 'and its minutes are on asphalt too: surface and gradient are two splits of one time');
  assert.ok(down.parts.descend > 0);
});

test('ride time: gravel is slower than asphalt, a leg without heights is flat, and a wall is walked at 4 km/h', () => {
  const asphalt = rideTime([slope(10, 0, { paved: 10000, gravel: 0, rough: 0, unknown: 0 })], 'Gravel');
  const gravel = rideTime([slope(10, 0, { paved: 0, gravel: 10000, rough: 0, unknown: 0 })], 'Gravel');
  assert.ok(gravel.seconds > asphalt.seconds * 1.1, 'gravel rolls harder');
  assert.ok(gravel.parts.gravel > 0 && gravel.parts.paved === 0);
  const blind = rideTime([{ routed: true, distM: 10000, heights: null, line: [] }], 'Gravel');
  assert.ok(Math.abs(blind.seconds - asphalt.seconds) < 1, 'no heights means flat');
  const wall = rideTime([slope(1, 25)], 'Road');
  assert.ok(Math.abs(kmh(1000 / wall.seconds) - 4) < 0.05, `walking speed ${kmh(1000 / wall.seconds)}`);
});

test('ride time: the flat equivalent is the distance on a flat ride and more with climbing; the owner\'s example lands near 125 flat km', () => {
  const flat = rideTime([slope(40, 0)], 'Road');
  assert.ok(Math.abs(flat.flatKm - 40) < 0.5, `flat ${flat.flatKm}`);
  // 85 km, 1,200 m up on 20 km at 6 %, 20 km back down, 20 km of gravel, gravel bike.
  const ride = rideTime(chain(slope(25, 0), slope(20, 6), slope(20, -6), slope(20, 0, { paved: 0, gravel: 20000, rough: 0, unknown: 0 })), 'Gravel');
  assert.ok(ride.flatKm > 105 && ride.flatKm < 145, `feels like ${ride.flatKm} flat km`);
  assert.ok(ride.seconds > 4 * 3600 && ride.seconds < 5.5 * 3600, `${ride.seconds / 3600} h`);
  assert.equal(rideTime([], 'Road'), null);
});

test('the two bars split one time: by surface and by gradient, each in a fixed order, empty parts left out', () => {
  const parts = { paved: 1200, gravel: 0, rough: 60, climb: 600, flat: 600, descend: 60 };
  assert.deepEqual(surfaceBars(parts).map(b => b.cls), ['paved', 'rough']);
  assert.deepEqual(gradientBars(parts).map(b => b.cls), ['climb', 'flat', 'descend']);
  for (const bars of [surfaceBars(parts), gradientBars(parts)]) assert.ok(Math.abs(bars.reduce((t, b) => t + b.share, 0) - 1) < 1e-9);
  assert.deepEqual(surfaceBars({ paved: 0, gravel: 0, rough: 0 }), []);
});

test('gravel on a climb is gravel time too: the owner\'s leg from Thier Antoine (dev engine, 2026-10-15)', () => {
  // 4.9 km, 955 m gravel and 854 m rough, mostly climbing: the old single bar said 0.5 min of gravel.
  const leg = slope(4.9, 5, { paved: 3078, gravel: 955, rough: 854, unknown: 0 });
  const t = rideTime([leg], 'Gravel');
  const share = t.parts.gravel / t.seconds;
  assert.ok(share > 0.15 && share < 0.25, `gravel is ${(share * 100).toFixed(0)} % of the time, its share of the leg is 20 %`);
  assert.ok(Math.abs(t.parts.paved + t.parts.gravel + t.parts.rough - t.seconds) < 1e-6);
  assert.ok(Math.abs(t.parts.climb + t.parts.flat + t.parts.descend - t.seconds) < 1e-6);
});

test('a time rounds to five minutes as hours and minutes', () => {
  assert.deepEqual(roundedHm(17040), { h: 4, m: 45 });
  assert.deepEqual(roundedHm(3540), { h: 1, m: 0 });
  assert.deepEqual(roundedHm(1500), { h: 0, m: 25 });
});

test('the steepest kilometre and the longest climb are read from the profile', () => {
  // 10 km flat, 6 km at 6 % with a 200 m dip in it, 1 km at 9 %, 5 km down.
  const dip = slope(6, 6); const n = Math.round(3000 / 30); for (let i = 0; i < 7; i++) dip.heights[n + i][1] -= 3 * (i + 1);
  const legs = chain(slope(10, 0), dip, slope(1, 9), slope(5, -6));
  const steep = steepestKm(legs);
  assert.ok(steep.grad > 7.5 && steep.grad < 9.5, `steepest ${steep.grad}`);
  assert.ok(steep.atKm > 15.5 && steep.atKm < 17.5, `at ${steep.atKm}`);
  const climb = longestClimb(legs);
  assert.ok(climb.km > 6.5 && climb.km < 7.5, `climb ${climb.km} km`);
  assert.ok(climb.upM > 400 && climb.upM < 480, `climb ${climb.upM} m`);
  assert.equal(steepestKm([slope(10, 2)]), null, 'below 4 % there is no steep kilometre');
  assert.equal(longestClimb([slope(10, 0)]), null);
});

test('the longest stretch without water or food reads the letter B rows along the ride', () => {
  const rows = [{ letter: 'B', alongKm: 20 }, { letter: 'D', alongKm: 45 }, { letter: 'B', alongKm: 50 }];
  assert.equal(waterGap(rows, 90000), 40);
  assert.equal(waterGap([], 90000), 90);
  assert.equal(waterGap([{ letter: 'B', alongKm: 20, truncated: true }], 90000), null, 'a capped list may hide a nearer place');
});

test('sunrise and sunset in Brussels on both solstices, to a few minutes', () => {
  const june = sunTimes(50.85, 4.35, '2026-06-21');
  const dec = sunTimes(50.85, 4.35, '2026-12-21');
  const hm = d => d.getUTCHours() * 60 + d.getUTCMinutes();
  assert.ok(Math.abs(hm(june.sunrise) - (3 * 60 + 28)) <= 4, `june sunrise ${june.sunrise.toISOString()}`);   // 05:28 CEST
  assert.ok(Math.abs(hm(june.sunset) - (19 * 60 + 59)) <= 4, `june sunset ${june.sunset.toISOString()}`);    // 21:59 CEST
  assert.ok(Math.abs(hm(dec.sunrise) - (7 * 60 + 43)) <= 4, `december sunrise ${dec.sunrise.toISOString()}`); // 08:43 CET
  assert.ok(Math.abs(hm(dec.sunset) - (15 * 60 + 39)) <= 4, `december sunset ${dec.sunset.toISOString()}`);   // 16:39 CET
  assert.ok(june.daylightS > 16 * 3600 && dec.daylightS < 8.5 * 3600);
  assert.equal(sunTimes(80, 20, '2026-12-21'), null, 'a polar night has no times');
});

test('wind shares: a line ridden east under an east wind is all headwind, under a west wind all tailwind, under a north wind neither', () => {
  const legs = [{ routed: true, distM: 2130, line: east(5.80, 5.83) }];
  const head = windShares(legs, 90), tail = windShares(legs, 270), side = windShares(legs, 0);
  assert.ok(head.headM > 2000 && head.tailM === 0);
  assert.ok(tail.tailM > 2000 && tail.headM === 0);
  assert.ok(side.headM === 0 && side.tailM === 0);
});

/* The map's own surface lines win where they are loaded (owner 2026-10-15, option 2):
   the engine names a surface for every road, a guess where OpenStreetMap has none. */
import { tileSurfaceClass, mergeSurfaces } from '../../assets/map/plan-model.js';

test('a tile class reads as the planner\'s surface, and a road with no recorded surface reads as unknown', () => {
  assert.equal(tileSurfaceClass('paved'), 'paved');
  assert.equal(tileSurfaceClass('gravel'), 'gravel');
  for (const c of ['pave', 'dirt', 'rock']) assert.equal(tileSurfaceClass(c), 'rough');
  assert.equal(tileSurfaceClass('unverified'), 'unknown');
  assert.equal(tileSurfaceClass('other'), null, 'an unusual recorded value keeps the engine\'s word');
});

test('the tiles\' metres replace the engine\'s for the part they cover; the rest keeps the engine\'s split', () => {
  // The owner's leg through Chevrouheid: 2.15 km the engine called 853 m paved and 1295 m rough, none of it recorded in OSM.
  const engine = { paved: 853, gravel: 0, rough: 1295, unknown: 0, busy: 0 };
  const all = mergeSurfaces(engine, { unknown: 2150 }, 2148);
  assert.equal(Math.round(all.unknown), 2148);
  assert.equal(Math.round(all.paved + all.rough), 0);
  const half = mergeSurfaces(engine, { unknown: 1000, paved: 74 }, 2148);
  assert.ok(Math.abs(half.unknown - 1000) < 1 && half.paved > 74 && Math.abs(half.paved + half.gravel + half.rough + half.unknown - 2148) < 1);
  assert.deepEqual(mergeSurfaces(engine, {}, 2148), engine, 'nothing loaded leaves the engine\'s answer');
  assert.equal(mergeSurfaces(null, {}, 100), null);
});

test('surface not recorded is its own part of the surface bar', () => {
  assert.deepEqual(surfaceBars({ paved: 60, gravel: 0, rough: 0, unknown: 40 }).map(b => b.cls), ['paved', 'unknown']);
  const t = rideTime([slope(2, 0, { paved: 0, gravel: 0, rough: 0, unknown: 2000 })], 'Road');
  assert.ok(Math.abs(t.parts.unknown - t.seconds) < 1e-6 && t.parts.paved === 0);
});

/* Street names (owner 2026-10-15: "what about street names, I miss that info"). */
import { streetAt, stopStreet } from '../../assets/map/plan-model.js';
const named = [
  { routed: true, distM: 300, streets: [[0, 'Place Royale'], [120, 'Rue Collin Leloup']] },
  { routed: true, distM: 500, streets: [[0, 'Rue Collin Leloup'], [200, null], [350, 'Avenue Reine Astrid']] },
];

test('the street at a distance along the ride, across legs; an unnamed road is no name', () => {
  assert.equal(streetAt(named, 50), 'Place Royale');
  assert.equal(streetAt(named, 299), 'Rue Collin Leloup');
  assert.equal(streetAt(named, 300 + 250), null);
  assert.equal(streetAt(named, 300 + 400), 'Avenue Reine Astrid');
  assert.equal(streetAt([], 10), null);
});

test('a stop\'s street: where its next leg starts, the last stop where the last leg ends', () => {
  assert.equal(stopStreet(named, 0), 'Place Royale');
  assert.equal(stopStreet(named, 1), 'Rue Collin Leloup');
  assert.equal(stopStreet(named, 2), 'Avenue Reine Astrid');
  assert.equal(stopStreet([{ routed: false }], 0), null);
});

import { roadAt } from '../../assets/map/plan-model.js';
test('an unnamed road still says what it is: the kind at a distance along the ride', () => {
  const legs = [{ routed: true, distM: 800, streets: [[0, 'Thier de Coo', 'road'], [643, null, 'road'], [700, null, 'track']] }];
  assert.equal(roadAt(legs, 10), 'road');
  assert.equal(roadAt(legs, 750), 'track');
  assert.equal(streetAt(legs, 750), null);
});

test('the day split by distance as well as by time: both splits sum to the ride', () => {
  const t = rideTime(chain(slope(5, 6, { paved: 4000, gravel: 1000, rough: 0, unknown: 0 }), slope(5, 0)), 'Gravel');
  const m = t.metres;
  assert.ok(Math.abs(m.paved + m.gravel + m.rough + m.unknown - 10000) < 1);
  assert.ok(Math.abs(m.climb + m.flat + m.descend - 10000) < 1);
  assert.ok(Math.abs(m.climb - 5000) < 200, `climb ${m.climb} m`);
  assert.ok(Math.abs(m.gravel - 1000) < 1);
});

test('pointAt walks the line in metres, not in vertices: a dense village and a sparse road (owner 2026-10-15, Coo)', () => {
  // 1 km due east: the first 100 m drawn with 10 vertices, the last 900 m with one segment.
  const kx = 111320 * Math.cos(50.4 * Math.PI / 180);
  const line = [];
  for (let i = 0; i <= 10; i++) line.push([5.8 + (i * 10) / kx, 50.4]);
  line.push([5.8 + 1000 / kx, 50.4]);
  const legs = [{ routed: true, distM: 1000, line }];
  const at500 = pointAt(legs, 500);
  const metresEast = (at500[0] - 5.8) * kx;
  assert.ok(Math.abs(metresEast - 500) < 5, `the point at 500 m sits ${metresEast.toFixed(0)} m along`);
});

/* The browser keeps a ride's work, the draft and its undo and redo steps,
   seven days after the last change (owner 2026-10-15): a closed tab loses
   nothing, and nothing stays longer; a ride to keep is a file the rider saves. */
test('work is kept seven days after the last change, and not a moment longer', () => {
  const now = Date.UTC(2026, 9, 15, 12, 0, 0);
  assert.equal(WORK_KEEP_MS, 7 * 24 * 3600 * 1000);
  assert.equal(workFresh(now - 1000, now), true);
  assert.equal(workFresh(now - WORK_KEEP_MS + 1000, now), true);
  assert.equal(workFresh(now - WORK_KEEP_MS, now), false, 'day seven is the last');
  assert.equal(workFresh(now + 60000, now), false, 'a stamp from the future is not trusted');
  assert.equal(workFresh(undefined, now), false);
  assert.equal(workFresh('yesterday', now), false);
});

test('the history hands out its steps as copies and takes them back, within its bounds', () => {
  const h = createHistory(3, 2);
  h.push(st(0)); h.push(st(1));
  h.undo(st(2));
  const saved = h.state();
  assert.deepEqual(saved.past.map(x => x[0].name), ['s0']);
  assert.deepEqual(saved.future.map(x => x[0].name), ['s2']);
  saved.past[0][0].name = 'changed outside';
  assert.equal(h.state().past[0][0].name, 's0', 'state() is a copy');

  const h2 = createHistory(3, 2);
  h2.restore({ past: [st(0), st(1), st(2), st(3), st(4)], future: [st(7), st(8), st(9)] });
  assert.equal(h2.size(), 3, 'restore keeps the newest undo steps within the limit');
  assert.equal(h2.redoSize(), 2, 'and the redo steps nearest the ride');
  assert.equal(h2.undo(st(5))[0].name, 's4');
  assert.equal(h2.redo(st(4))[0].name, 's5');
  const h3 = createHistory(3, 2);
  h3.restore(null);
  assert.equal(h3.size(), 0);
  h3.restore({ past: 'junk', future: 7 });
  assert.equal(h3.size() + h3.redoSize(), 0, 'junk from storage restores nothing');
});
