// SPDX-License-Identifier: AGPL-3.0-only
//
// plan-model.js: the ride planner's pure model (docs/specs/route-planner.md
// sections 4 and 6.3). Stops and legs, where a place goes in the ride, the
// figures, the profile bins, and the GPX writer. A leaf: no map, no DOM.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const HEIGHTS = JSON.parse(readFileSync(new URL('./fixtures/plan-heights.json', import.meta.url), 'utf8'));
import {
  MAX_STOPS, WORK_KEEP_MS, workFresh, rideFromGpx, simplifyTrack, SHAPE_M, thinLine, ALONG_MAX_POINTS, waterGapSpan, bestWaterStop, legGoesRound, whyThisWay, sliceAlong, stopIndexAt, stopRows, stopKey, legKeys, nearLine, insertIndex, figures, cleanHeights, HEIGHT_NOISE_M, profileRuns, pointAt, gpxText, barText, createHistory, tickStepKm, surfaceMix, roadDetail, detailSpans, avgKmh,
  PACE, flatSpeed, rideTime, surfaceBars, gradientBars, roundedHm, steepestKm, longestClimb, waterGap, sunTimes, windShares,
  durationText, TOUGHER, tougherLines, forecastPageUrl, FORECAST, forecastPoint, heightTicks,
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
  assert.equal(MAX_STOPS, 50, 'owner 2026-10-16: 50, so a long ride from another app keeps its roads');
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

test('a flat polder climbs tens of metres, not hundreds, and is ridden flat', () => {
  // Real legs around Hoorn (fixtures/plan-heights.json): whole metres between -4 and 6 over 53 km.
  const legs = HEIGHTS.polder.map(l => ({ routed: true, line: [], ...l }));
  const f = figures(legs);
  assert.ok(f.upM < 60, `polder climbed ${Math.round(f.upM)} m, the old rule said 245`);
  const t = rideTime(legs, 'Road');
  assert.ok(t.parts.climb < 5 * 60, `polder climbing ${Math.round(t.parts.climb)} s`);
});

test('hills keep most of their climbing through the noise rule', () => {
  // Real legs Stavelot-Spa (fixtures/plan-heights.json): the old rule said 1159 m.
  const f = figures(HEIGHTS.ardennes.map(l => ({ routed: true, line: [], ...l })));
  assert.ok(f.upM > 900 && f.upM < 1100, `ardennes climbed ${Math.round(f.upM)} m`);
});

test('a bump under the noise threshold is not climbed, one over it is', () => {
  const bump = rise => [10, 10, 10, 10 + rise, 10 + rise, 10 + rise, 10, 10, 10].map((h, i) => [i * 30, h]);
  assert.equal(HEIGHT_NOISE_M, 5);
  assert.equal(figures([{ routed: true, line: [], distM: 240, heights: bump(4) }]).upM, 0);
  assert.ok(figures([{ routed: true, line: [], distM: 240, heights: bump(8) }]).upM >= 5);
  assert.equal(cleanHeights(bump(2)).length, 9);
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

/* The GPX is the ride file (owner 2026-10-15, route-planner.md section 6.1):
   the rider keeps it, and opening it again rebuilds the ride. Nothing of a
   ride stays on our server, so the file carries what the planner needs: the
   bike and which place of the Commons each stop is; the day stays with the
   draft, because a kept ride is ridden on many days. */
test('the GPX carries the bike and each stop\'s place, for opening it again, and not the day', () => {
  const ride = { name: 'Spa loop', bike: 'Gravel', stops: [{ ...stops[0], item: 'B:11007', letter: 'B' }, stops[1], stops[2]], legs };
  const xml = gpxText(ride, { itemUrl: item => `https://example.test/map?item=${item}` });
  assert.match(xml, /<gpx version="1\.1" creator="Cycling Commons" xmlns="http:\/\/www\.topografix\.com\/GPX\/1\/1" xmlns:cc="https:\/\/cyclingcommons\.org\/xmlns\/ride\/1">/);
  assert.match(xml, /<metadata><name>Spa loop<\/name><extensions><cc:bike>Gravel<\/cc:bike><\/extensions><\/metadata>/);
  assert.doesNotMatch(xml, /cc:date|<time>/, 'no day in the file');
  assert.match(xml, /<name>Start<\/name><link href="[^"]+"\/><extensions><cc:item>B:11007<\/cc:item><cc:letter>B<\/cc:letter><\/extensions><\/wpt>/);
  assert.match(xml, /<wpt lat="50\.400000" lon="5\.830000"><name>Middle<\/name><\/wpt>/, 'a map point carries no extension');
});

test('opening the ride file gives back the ride as it was written', () => {
  const ride = { name: 'a <b> & "c"', bike: 'MTB', stops: [{ ...stops[0], item: 'B:11007', letter: 'B' }, stops[1], { ...stops[2], name: "L'Amblève" }], legs };
  const back = rideFromGpx(gpxText(ride, {}));
  assert.equal(back.error, undefined);
  assert.equal(back.name, 'a <b> & "c"');
  assert.equal(back.bike, 'MTB');
  assert.deepEqual(back.stops, [
    { lat: 50.4, lng: 5.8, name: 'Start', item: 'B:11007', letter: 'B' },
    { lat: 50.4, lng: 5.83, name: 'Middle' },
    { lat: stops[2].lat, lng: stops[2].lng, name: "L'Amblève" },
  ]);
});

test('a GPX the planner wrote before the extensions still opens, without bike or places', () => {
  const old = '<?xml version="1.0" encoding="UTF-8"?>\n<gpx version="1.1" creator="Cycling Commons" xmlns="http://www.topografix.com/GPX/1/1">\n'
    + '<metadata><name>Old</name></metadata>\n<wpt lat="50.400000" lon="5.800000"><name>Start</name><link href="https://x.test/map?item=B:1"/></wpt>\n'
    + "<wpt lat='50.410000' lon='5.810000'><name>End</name></wpt>\n<trk><name>Old</name><trkseg></trkseg></trk>\n</gpx>\n";
  const back = rideFromGpx(old);
  assert.equal(back.name, 'Old');
  assert.equal(back.bike, null);
  assert.deepEqual(back.stops.map(s => [s.lat, s.lng, s.name, s.item]), [[50.4, 5.8, 'Start', undefined], [50.41, 5.81, 'End', undefined]]);
});

test('opening refuses what is not a ride file, and says why', () => {
  assert.equal(rideFromGpx('hello').error, 'not_gpx');
  assert.equal(rideFromGpx('<gpx version="1.1" creator="Another app"><trk><trkseg><trkpt lat="95" lon="5"/></trkseg></trk></gpx>').error, 'no_stops', 'no usable point');
  assert.equal(rideFromGpx('<gpx version="1.1" creator="Cycling Commons"><trk><trkseg></trkseg></trk></gpx>').error, 'no_stops');
  const many = Array.from({ length: MAX_STOPS + 1 }, (_, i) => `<wpt lat="50.${i}" lon="5.1"><name>s${i}</name></wpt>`).join('');
  assert.equal(rideFromGpx(`<gpx version="1.1" creator="Cycling Commons">${many}</gpx>`).error, 'too_many');
  assert.equal(rideFromGpx('<gpx version="1.1" creator="Cycling Commons"><wpt lat="95" lon="5"><name>x</name></wpt></gpx>').error, 'bad_point');
  assert.equal(rideFromGpx('<gpx version="1.1" creator="Cycling Commons"><wpt lat="50" lon="abc"><name>x</name></wpt></gpx>').error, 'bad_point');
});

/* A GPX from another app is converted (owner 2026-10-16: "You need to convert it then"): its track becomes
   checkpoints the planner routes again for the rider's bike, MAX_STOPS at most. */
const trackGpx = (pts, extra = '') => `<?xml version="1.0"?><gpx version="1.1" creator="Some Other App" xmlns="http://www.topografix.com/GPX/1/1">${extra}`
  + `<trk><name>Hautes Fagnes</name><trkseg>${pts.map(([lat, lng], i) => i % 2 ? `<trkpt lat="${lat}" lon="${lng}"><ele>500</ele><time>2026-10-01T10:00:00Z</time></trkpt>` : `<trkpt lon="${lng}" lat="${lat}"/>`).join('')}</trkseg></trk></gpx>`;
// An L: 600 points east along lat 50.4, then 600 points north; about 21 km and 33 km.
const ell = [...Array.from({ length: 600 }, (_, i) => [50.4, 5.8 + i * 0.0005]), ...Array.from({ length: 600 }, (_, i) => [50.4 + (i + 1) * 0.0005, 5.8 + 599 * 0.0005])];

test('a track from another app becomes checkpoints along it: first and last kept, the corner kept, in order', () => {
  const r = rideFromGpx(trackGpx(ell));
  assert.equal(r.error, undefined);
  assert.equal(r.name, 'Hautes Fagnes', 'the track\'s name names the ride');
  assert.equal(r.bike, null, 'the rider\'s own bike stays');
  assert.deepEqual(r.converted, { from: 'track', points: 1200 });
  assert.equal(r.stops.length, MAX_STOPS, 'a long track uses every checkpoint the ride holds');
  assert.deepEqual([r.stops[0].lat, r.stops[0].lng], ell[0]);
  assert.deepEqual([r.stops.at(-1).lat, r.stops.at(-1).lng], ell.at(-1));
  assert.ok(r.stops.some(s => s.lat === ell[599][0] && s.lng === ell[599][1]), 'the corner is a checkpoint');
  const at = r.stops.map(s => ell.findIndex(p => p[0] === s.lat && p[1] === s.lng));
  assert.ok(at.every(i => i >= 0), 'every checkpoint is a point of the track');
  assert.deepEqual(at, [...at].sort((a, b) => a - b), 'the checkpoints follow the track');
  assert.ok(r.stops.every(s => s.name === '' && !s.item), 'a converted point is a map point');
});

test('a straight stretch is not left to the engine: the checkpoints spread along it', () => {
  const line = Array.from({ length: 2000 }, (_, i) => [50.4, 5.8 + i * 0.0003]);
  const kept = simplifyTrack(line.map(([lat, lng]) => ({ lat, lng })), MAX_STOPS);
  assert.equal(kept.length, MAX_STOPS);
  const idx = kept.map(k => line.findIndex(p => p[1] === k.lng));
  const gaps = idx.slice(1).map((v, i) => v - idx[i]);
  assert.ok(Math.max(...gaps) <= 2 * (line.length / (MAX_STOPS - 1)), `a gap of ${Math.max(...gaps)} points is left between two checkpoints`);
});

test('the shape comes first: a wiggle off the line gets a checkpoint before any straight part is split', () => {
  const line = Array.from({ length: 1001 }, (_, i) => ({ lat: 50.4 + (i === 500 ? 0.002 : 0), lng: 5.8 + i * 0.0003 }));
  const kept = simplifyTrack(line, 3);
  assert.deepEqual(kept.map(p => line.indexOf(p)), [0, 500, 1000]);
  assert.ok(SHAPE_M > 0 && SHAPE_M < 100);
});

test('a loop keeps its start and its end and reaches its far side', () => {
  const loop = Array.from({ length: 721 }, (_, i) => { const a = i * Math.PI / 360; return [50.4 + 0.05 * Math.sin(a), 5.8 + 0.08 * (1 - Math.cos(a))]; });
  const r = rideFromGpx(trackGpx(loop));
  assert.equal(r.stops.length, MAX_STOPS);
  assert.deepEqual([r.stops[0].lat, r.stops[0].lng], loop[0]);
  assert.deepEqual([r.stops.at(-1).lat, r.stops.at(-1).lng], loop.at(-1));
  assert.ok(r.stops.some(s => Math.abs(s.lng - (5.8 + 0.16)) < 0.001), 'the far side of the loop is a checkpoint');
});

test('a short track keeps its points, without a point repeated where the device stood still', () => {
  const pts = [[50.4, 5.8], [50.4, 5.8], [50.41, 5.81], [50.41, 5.81000001], [50.42, 5.83]];
  const r = rideFromGpx(trackGpx(pts));
  assert.deepEqual(r.stops.map(s => [s.lat, s.lng]), [[50.4, 5.8], [50.41, 5.81], [50.42, 5.83]]);
});

test('another app\'s route keeps its own points and their names; a track wins over waypoints beside it', () => {
  const rte = '<gpx version="1.1" creator="Planner X"><metadata><name>Col tour</name></metadata><rte><name>ignored</name>'
    + '<rtept lat="50.4" lon="5.8"><name>Spa</name></rtept><rtept lat="50.45" lon="5.9"><name>Col</name></rtept><rtept lat="50.5" lon="6.0"/></rte></gpx>';
  const r = rideFromGpx(rte);
  assert.equal(r.name, 'Col tour', 'the file\'s own name first');
  assert.deepEqual(r.converted, { from: 'route', points: 3 });
  assert.deepEqual(r.stops.map(s => [s.lat, s.lng, s.name]), [[50.4, 5.8, 'Spa'], [50.45, 5.9, 'Col'], [50.5, 6.0, '']]);
  const both = rideFromGpx(trackGpx(ell, '<wpt lat="50.3" lon="5.7"><name>Café</name></wpt>'));
  assert.equal(both.converted.from, 'track');
  const marks = rideFromGpx('<gpx version="1.1" creator="Y"><wpt lat="50.4" lon="5.8"><name>A</name></wpt><wpt lat="50.5" lon="5.9"><name>B</name></wpt></gpx>');
  assert.deepEqual(marks.converted, { from: 'waypoints', points: 2 });
  assert.deepEqual(marks.stops.map(s => s.name), ['A', 'B']);
});

/* The along request takes 5,000 points at most (PlanController::MAX_LINE_POINTS); a ride past about 110 km has
   more, and its "Along your ride" stayed empty. The line is thinned to fit, its first and last points kept. */
test('a long ride\'s line is thinned to what the along request takes, its ends kept', () => {
  const line = Array.from({ length: 12345 }, (_, i) => [5.8 + i * 0.0001, 50.4]);
  const thin = thinLine(line, ALONG_MAX_POINTS);
  assert.ok(thin.length <= ALONG_MAX_POINTS && thin.length > ALONG_MAX_POINTS / 2, `${thin.length} points`);
  assert.equal(thin[0], line[0]);
  assert.equal(thin.at(-1), line.at(-1));
  assert.deepEqual(thin.map(p => line.indexOf(p)), thin.map(p => line.indexOf(p)).sort((a, b) => a - b));
  const short = line.slice(0, 100);
  assert.equal(thinLine(short, ALONG_MAX_POINTS), short, 'a line that fits goes as it is');
  for (const n of [5001, 5002, 9999, 10000, 10001]) assert.ok(thinLine(line.slice(0, n), ALONG_MAX_POINTS).length <= ALONG_MAX_POINTS, `${n} points`);
});

test('a long name is cut to the planner\'s 80 characters', () => {
  const back = rideFromGpx(`<gpx version="1.1" creator="Cycling Commons"><metadata><name>${'x'.repeat(120)}</name></metadata><wpt lat="50" lon="5"><name>${'y'.repeat(120)}</name></wpt></gpx>`);
  assert.equal(back.name.length, 80);
  assert.equal(back.stops[0].name.length, 80);
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
   of the leg's surfaces, the flat equivalent, and what makes it tough. */
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

// Owner 2026-10-19: "at 150w make it more like 28 km an hour for flat".
test('the flat speed of the road rider at 150 W is 28 km/h, and the E-bike stops being helped at 25 km/h', () => {
  const road = kmh(flatSpeed('Road'));
  assert.ok(Math.abs(road - 28) < 0.15, `road flat speed ${road}`);
  assert.ok(kmh(flatSpeed('Gravel')) < road, 'a gravel bike is slower on the flat');
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

/* Fill the water gap in one click (owner 2026-10-15, route-planner.md section
   4.4): the longest stretch without water or food has a start and an end, and
   the water stop nearest its middle comes from our own data. */
test('the longest stretch without water has its start and end along the ride', () => {
  const rows = [{ letter: 'B', alongKm: 5 }, { letter: 'B', alongKm: 12 }, { letter: 'C', alongKm: 30 }, { letter: 'B', alongKm: 40 }];
  assert.deepEqual(waterGapSpan(rows, 60000), { fromKm: 12, toKm: 40, km: 28 });
  assert.deepEqual(waterGapSpan([], 42000), { fromKm: 0, toKm: 42, km: 42 }, 'no water at all: the whole ride');
  assert.deepEqual(waterGapSpan([{ letter: 'B', alongKm: 3 }], 50000), { fromKm: 3, toKm: 50, km: 47 }, 'the last stretch counts');
  assert.deepEqual(waterGapSpan([{ letter: 'B', alongKm: 30 }], 35000), { fromKm: 0, toKm: 30, km: 30 }, 'so does the first');
  assert.equal(waterGapSpan([{ letter: 'B', alongKm: 3, truncated: true }], 50000), null, 'a capped list cannot say where the gap is');
  assert.equal(waterGap(rows, 60000), 28, 'waterGap reads the same span');
});

test('the water stop that leaves the shortest dry stretch wins, ours or OpenStreetMap, with where along it lies', () => {
  // A 20 km stretch from km 10 to km 30, sampled every 2 km along a line north.
  const pts = Array.from({ length: 11 }, (_, k) => ({ lat: 50 + k * 0.018, lng: 5.0, alongM: 10000 + k * 2000 }));
  const groups = [
    { letter: 'C', items: [{ ref: 'node/9', ll: [50.09, 5.0], curated: false }] },
    { letter: 'B', items: [
      { ref: 'node/12', n: 'Tap near the end', ll: [50.18, 5.001], curated: false },     // at km 30, 70 m off: leaves 20 km dry
      { ref: 'B-77', itemId: 77, n: 'Fontaine', ll: [50.09, 5.012], curated: true },     // at km 20, 860 m off: leaves about 11.7 km
    ] },
  ];
  const best = bestWaterStop(groups, pts, 10000, 30000);
  assert.equal(best.item, 'B:77', 'the middle one wins over the nearer one at the end; our row is named by its id');
  assert.equal(best.alongM, 20000);
  assert.ok(best.leftM > 11000 && best.leftM < 12000, `leaves ${best.leftM} m dry, what the planner weighs against the stretch`);
  assert.ok(best.distM > 800 && best.distM < 900, `${best.distM} m off`);
  assert.equal(best.letter, 'B');
  assert.equal(bestWaterStop([{ letter: 'C', items: [] }], pts, 10000, 30000), null);
  assert.equal(bestWaterStop(null, pts, 10000, 30000), null);
  assert.equal(bestWaterStop(groups, [], 10000, 30000), null);
});

/* Why this way? (owner 2026-10-15, route-planner.md section 4.2a): a leg that
   clearly goes round offers the question; the answer compares it with the
   shortest way the same bike may ride and names the main reason. */
const A0 = { lat: 50.4765, lng: 5.9107 }, B0 = { lat: 50.4475, lng: 5.8764 };   // about 4.0 km apart
test('only a leg that clearly goes round offers "Why this way?"', () => {
  assert.equal(legGoesRound({ routed: true, distM: 7560 }, A0, B0), true, 'this morning\'s leg: 7.6 km for 4 km');
  assert.equal(legGoesRound({ routed: true, distM: 4800 }, A0, B0), false, 'a normal leg');
  assert.equal(legGoesRound({ routed: true, distM: 700 }, { lat: 50, lng: 5 }, { lat: 50.003, lng: 5 }), false, 'under 500 m longer is not worth asking');
  assert.equal(legGoesRound({ routed: false }, A0, B0), false);
});

const leg = (distM, surfaces, heights) => ({ routed: true, distM, surfaces: { paved: 0, gravel: 0, rough: 0, unknown: 0, busy: 0, cycle: 0, signals: 0, ...surfaces }, heights: heights || null });
test('the cycle path metres and the traffic lights ride along when the tiles refine the surfaces', () => {
  const merged = mergeSurfaces({ paved: 3000, gravel: 0, rough: 0, unknown: 1000, busy: 200, cycle: 2500, signals: 4 }, { paved: 500 }, 4000);
  assert.equal(merged.cycle, 2500);
  assert.equal(merged.signals, 4);
  assert.equal(merged.busy, 200);
});
test('the answer names gravel or dirt the bike avoids', () => {
  const why = whyThisWay(leg(7560, { paved: 7560 }), leg(4420, { paved: 1340, rough: 2600, gravel: 480 }));
  assert.deepEqual(why, { kind: 'surface', curM: 7560, altM: 4420, unpavedM: 3080 });
});
test('or the climbing it saves', () => {
  const flat = Array.from({ length: 21 }, (_, i) => [i * 300, 100]);
  const steep = Array.from({ length: 21 }, (_, i) => [i * 150, 100 + (i <= 10 ? i : 20 - i) * 15]);   // up to 250 m and down
  const why = whyThisWay(leg(6000, { paved: 6000 }, flat), leg(3000, { paved: 3000 }, steep));
  assert.equal(why.kind, 'climb');
  assert.ok(why.upM >= 100, `climbs ${why.upM} m more`);
});
test('or the busy roads it keeps off', () => {
  assert.deepEqual(whyThisWay(leg(6000, { paved: 6000 }), leg(4000, { paved: 4000, busy: 2500 })), { kind: 'busy', curM: 6000, altM: 4000, busyM: 2500 });
});
/* Owner 2026-10-16, a leg through Hoorn: "that is nonsense that is asphalt vs asphalt. One is through the city with
   traffic and lots of crossings and traffic lights." The bike's way kept 4.0 of its 5.5 km on cycle paths, the
   shortest way 0.7 of 4.8. */
test('or the cycle paths it keeps to, away from cars', () => {
  assert.deepEqual(whyThisWay(leg(5516, { paved: 5516, cycle: 3973 }), leg(4808, { paved: 4808, cycle: 686 })),
    { kind: 'cycle', curM: 5516, altM: 4808, curCycleM: 3973, altCycleM: 686 });
  assert.equal(whyThisWay(leg(5516, { paved: 5516, cycle: 1000 }), leg(4808, { paved: 4808, cycle: 700 })).kind, 'other', 'a few hundred metres more is no reason');
});
test('or the traffic lights it keeps clear of', () => {
  assert.deepEqual(whyThisWay(leg(6000, { paved: 6000, signals: 1 }), leg(4000, { paved: 4000, signals: 9 })),
    { kind: 'signals', curM: 6000, altM: 4000, curSignals: 1, altSignals: 9 });
  assert.equal(whyThisWay(leg(6000, { paved: 6000, signals: 1 }), leg(4000, { paved: 4000, signals: 3 })).kind, 'other');
});
test('a way that is already about the shortest says so, and an unknown reason is plain', () => {
  assert.deepEqual(whyThisWay(leg(4500, { paved: 4500 }), leg(4420, { paved: 4420 })), { kind: 'shortest' });
  assert.deepEqual(whyThisWay(leg(6000, { paved: 6000 }), leg(4000, { paved: 4000 })), { kind: 'other', curM: 6000, altM: 4000 });
  assert.equal(whyThisWay(leg(6000, {}), { routed: false }), null, 'no comparison, no answer');
});

/* Hovering the water-gap line shows that stretch on the map (owner 2026-10-15):
   the part of the ride between two distances, measured as pointAt measures. */
test('sliceAlong cuts the ride between two distances along it, across legs', () => {
  const part = sliceAlong(legs, 1000, 3000);
  assert.ok(part.length >= 3, 'the cut keeps the vertices between its ends');
  assert.ok(Math.abs(part[0][0] - (5.80 + 0.03 * 1000 / 2130)) < 0.0005, `starts at ${part[0][0]}`);
  assert.ok(Math.abs(part[part.length - 1][0] - (5.83 + 0.03 * 870 / 2130)) < 0.0005, `ends at ${part[part.length - 1][0]}`);
  for (let i = 1; i < part.length; i++) assert.ok(part[i][0] >= part[i - 1][0], 'in riding order');
  assert.deepEqual(sliceAlong(legs, 5000, 6000), [], 'past the end there is nothing to show');
  assert.equal(sliceAlong(legs, 0, 4260).length >= 2, true);
});

/* The water stop goes into the ride where the dry stretch is (owner
   2026-10-15): the stop index after the leg that holds a distance along. */
test('stopIndexAt names the stop after the leg that holds a distance along the ride', () => {
  assert.equal(stopIndexAt(legs, 100), 1);
  assert.equal(stopIndexAt(legs, 2130), 1);
  assert.equal(stopIndexAt(legs, 2131), 2);
  assert.equal(stopIndexAt(legs, 4260), 2);
  assert.equal(stopIndexAt(legs, 9999), null);
  assert.equal(stopIndexAt([null, legs[1]], 100), 2, 'an unrouted leg holds no distance');
});

/* Ride the shortest way (owner 2026-10-15): a leg can take the shortest way
   of its bike; the stop it arrives at says so, the leg key carries it, and the
   ride file keeps it. */
test('a leg ridden the shortest way has its own key and survives the ride file', () => {
  const st = [{ lat: 50.4, lng: 5.8, name: 'A' }, { lat: 50.4, lng: 5.83, name: 'B', way: 'shortest' }, { lat: 50.4, lng: 5.86, name: 'C' }];
  const keys = legKeys(st, 'Road');
  assert.match(keys[0], /\|shortest$/);
  assert.doesNotMatch(keys[1], /shortest/);
  const xml = gpxText({ name: 'x', bike: 'Road', stops: st, legs }, {});
  assert.match(xml, /<name>B<\/name><extensions><cc:way>shortest<\/cc:way><\/extensions><\/wpt>/);
  assert.deepEqual(rideFromGpx(xml).stops.map(x => x.way), [undefined, 'shortest', undefined]);
});

/* A water stop in the ride counts where the ride reaches it (owner 2026-10-15):
   its pin can lie farther than 250 m from the road its leg ends on, and then the
   along list leaves it out and the dry stretch did not shrink. */
test('the ride\'s own place stops count at the distance the ride reaches them', () => {
  const st = [{ lat: 50.4, lng: 5.8, name: 'S' }, { lat: 50.4, lng: 5.83, name: 'Tap', item: 'osm:node/1', letter: 'B' }, { lat: 50.4, lng: 5.86, name: 'E' }];
  assert.deepEqual(stopRows(st, legs), [{ letter: 'B', alongKm: 2.13 }]);
  assert.deepEqual(waterGapSpan(stopRows(st, legs), 4260), { fromKm: 0, toKm: 2.13, km: 2.13 }, 'the tap splits the ride');
  assert.deepEqual(stopRows(st, [legs[0], null]), [{ letter: 'B', alongKm: 2.13 }]);
  assert.deepEqual(stopRows(st, [null, legs[1]]), [], 'a stop after an unrouted leg has no distance yet');
});

/* A water stop that did not help is taken back without a trace (owner 2026-10-16): through undo it sat in the redo
   steps, and Ctrl+Shift+Z put the stop that did not help back in. */
test('discard takes the newest step back without a redo step', () => {
  const h = createHistory(30, 5);
  h.push([{ n: 'a' }]);
  h.push([{ n: 'a' }, { n: 'b' }]);
  assert.deepEqual(h.discard(), [{ n: 'a' }, { n: 'b' }]);
  assert.equal(h.size(), 1);
  assert.equal(h.redoSize(), 0, 'nothing to redo');
  assert.deepEqual(h.discard(), [{ n: 'a' }]);
  assert.equal(h.discard(), null, 'nothing left');
});

/* The chosen water stop carries what the place is, so the planner can name it (owner 2026-10-16: "Who is Koning?"). */
test('the best water stop carries whether it is food and whether its water is drinkable', () => {
  const groups = [{ letter: 'B', items: [{ ref: 'node/1', n: 'Koning', ll: [50.4005, 5.81], food: true }, { ref: 'node/2', n: 'Kraan', ll: [50.43, 5.9], potable: 'yes' }] }];
  const points = [0, 1, 2, 3].map(i => ({ lat: 50.4, lng: 5.8 + i * 0.01, alongM: i * 700 }));
  const best = bestWaterStop(groups, points, 0, 2100);
  assert.equal(best.name, 'Koning');
  assert.equal(best.food, true);
  assert.equal(best.potable, null);
});

/* ---------- durations and what makes it tough (route-planner.md section 4.4) ---------- */
const EN = { hm: '{h}h {m}m', h: '{h}h', min: '{m}m' };
const FR = { hm: '{h} h {m} min', h: '{h} h', min: '{m} min' };

test('a duration reads "3h 50m", "50m" under an hour and "3h" on the hour, in the page\'s words', () => {
  assert.equal(durationText(3 * 3600 + 50 * 60, EN, 5), '3h 50m');
  assert.equal(durationText(3 * 3600 + 52 * 60, EN, 5), '3h 50m', 'the riding time rounds to five minutes');
  assert.equal(durationText(50 * 60, EN, 5), '50m');
  assert.equal(durationText(3 * 3600 + 60, EN, 5), '3h');
  assert.equal(durationText(3 * 3600 + 5 * 60, EN, 5), '3h 5m', 'the minutes are not padded');
  assert.equal(durationText(3 * 3600 + 50 * 60, FR, 5), '3 h 50 min');
  assert.equal(durationText(50 * 60, FR, 5), '50 min');
  assert.equal(durationText(59 * 60 + 40, EN, 1), '1h', '59.7 minutes is an hour, never "0h 60m" or "60m"');
  assert.equal(durationText(3600 + 59 * 60 + 50, EN, 1), '2h');
  assert.equal(durationText(3600 + 7 * 60, EN, 1), '1h 7m');
  assert.equal(durationText(3 * 3600 + 50 * 60, {}, 5), '3h 50m', 'without words it falls back to English');
  // The daylight reads in the same words, to the minute (owner 2026-10-17).
  assert.equal(durationText(10 * 3600 + 32 * 60, EN, 1), '10h 32m');
  assert.equal(durationText(10 * 3600 + 32 * 60, FR, 1), '10 h 32 min');
  assert.equal(durationText(10 * 3600 + 32 * 60, { hm: '{h} Std. {m} Min.' }, 1), '10 Std. 32 Min.');
});

// An ordinary ride: under every baseline, so nothing out of the ordinary.
const ordinary = () => ({
  distM: 60000, steep: { grad: 5, atKm: 12 }, climb: { km: 2, upM: 120, grad: 6 }, roughM: 1000, waterKm: 23, busyM: 2000,
  sun: { daylightS: 10 * 3600 + 32 * 60, sunrise: new Date(0), sunset: new Date(0) }, rideS: 3 * 3600 + 50 * 60,
  forecast: { date: '2026-10-17', windKmh: 22, gustKmh: 40, windFromDeg: 0, tempMaxC: 16, rainMm: 0.4 },
});
const kinds = o => tougherLines(o).map(x => x.kind);

test('an ordinary ride, every factor under its baseline, is nothing out of the ordinary', () => {
  assert.deepEqual(kinds(ordinary()), []);
  assert.deepEqual(kinds({}), [], 'no figures, no lines');
  // The owner's 17 October ride: 23 km dry, daylight 10h 32m for 3h 50m, 22 km/h of wind; only its 4 mm of rain stays.
  assert.deepEqual(kinds(Object.assign(ordinary(), { forecast: { ...ordinary().forecast, gustKmh: null, rainMm: 4 } })), ['rain']);
});

test('each factor gets its line only past its baseline', () => {
  const at = (patch) => kinds(Object.assign(ordinary(), patch));
  assert.deepEqual(at({ distM: 100000 }), [], '100 km is not longer than 100 km');
  assert.deepEqual(at({ distM: 100100 }), ['long']);
  assert.deepEqual(at({ steep: { grad: 7.9, atKm: 3 } }), []);
  assert.deepEqual(at({ steep: { grad: 8, atKm: 3 } }), ['steep']);
  assert.deepEqual(at({ steep: null }), []);
  assert.deepEqual(at({ climb: { km: 4.9, upM: 299, grad: 6 } }), []);
  assert.deepEqual(at({ climb: { km: 5, upM: 150, grad: 3 } }), ['climb'], 'a 5 km climb counts');
  assert.deepEqual(at({ climb: { km: 3, upM: 300, grad: 10 } }), ['climb'], 'so does 300 m up');
  assert.deepEqual(at({ roughM: 4999 }), []);
  assert.deepEqual(at({ roughM: 5000 }), ['rough']);
  assert.deepEqual(at({ waterKm: 40 }), [], '40 km is not longer than 40 km');
  assert.deepEqual(at({ waterKm: 40.1 }), ['water']);
  assert.deepEqual(at({ waterKm: null }), [], 'a capped list gives no water line');
  assert.deepEqual(at({ busyM: 4999 }), []);
  assert.deepEqual(at({ busyM: 5000 }), ['busy']);
  assert.deepEqual(at({ forecast: { ...ordinary().forecast, windKmh: 29.9 } }), []);
  assert.deepEqual(at({ forecast: { ...ordinary().forecast, windKmh: 30 } }), ['wind']);
  assert.deepEqual(at({ forecast: { ...ordinary().forecast, gustKmh: 49.9 } }), []);
  assert.deepEqual(at({ forecast: { ...ordinary().forecast, gustKmh: 50 } }), ['wind'], 'gusts alone bring the wind line');
  assert.deepEqual(at({ forecast: { ...ordinary().forecast, gustKmh: null, windKmh: 25 } }), [], 'no gusts in the answer, the speed decides');
  assert.deepEqual(at({ forecast: { ...ordinary().forecast, tempMaxC: 29.9 } }), []);
  assert.deepEqual(at({ forecast: { ...ordinary().forecast, tempMaxC: 30 } }), ['heat']);
  assert.deepEqual(at({ forecast: { ...ordinary().forecast, rainMm: 0.9 } }), []);
  assert.deepEqual(at({ forecast: { ...ordinary().forecast, rainMm: 1 } }), ['rain']);
  assert.deepEqual(at({ forecast: null }), []);
});

test('daylight: shown only when more than an hour is ridden in the dark', () => {
  const sun = s => ({ daylightS: s, sunrise: new Date(0), sunset: new Date(0) });
  const day = (daylightS, rideS) => tougherLines(Object.assign(ordinary(), { sun: sun(daylightS), rideS })).find(x => x.kind === 'daylight') || null;
  assert.equal(day(11 * 3600, 11 * 3600), null, 'a ride as long as the day fits');
  assert.equal(day(11 * 3600, 12 * 3600), null, 'exactly an hour in the dark is not tough');
  assert.equal(day(11 * 3600, 12 * 3600 + 60).over, 'over');
  assert.equal(day(12 * 3600, 12 * 3600 + 60 * 60 + 1).darkS, 3601);
  assert.equal(day(10 * 3600 - 60, 3 * 3600), null, 'a short winter day is not tough by itself');
});

test('the lines come in the panel\'s order, with what each line says', () => {
  const all = tougherLines({
    distM: 120000, steep: { grad: 11, atKm: 31 }, climb: { km: 6.2, upM: 410, grad: 6.6 }, roughM: 7000, waterKm: 45, busyM: 6000,
    sun: { daylightS: 7 * 3600, sunrise: new Date(0), sunset: new Date(0) }, rideS: 8.5 * 3600,
    forecast: { date: '2026-10-17', windKmh: 35, gustKmh: 62, windFromDeg: 225, tempMaxC: 31, rainMm: 6 },
  });
  assert.deepEqual(all.map(x => x.kind), ['long', 'steep', 'climb', 'rough', 'water', 'busy', 'daylight', 'wind', 'heat', 'rain']);
  assert.deepEqual(all.find(x => x.kind === 'wind'), { kind: 'wind', windKmh: 35, gustKmh: 62, fromDeg: 225 });
  assert.equal(all.find(x => x.kind === 'daylight').over, 'over');
  assert.equal(TOUGHER.longKm, 100);
  assert.equal(TOUGHER.windKmh, 30);
});

test('the forecast credit links Open-Meteo\'s page for the rounded point the server asked for, and that day', () => {
  const url = forecastPageUrl({ date: '2026-10-17', lat: 50.5, lng: 5.9 });
  assert.ok(url.startsWith('https://open-meteo.com/en/docs?latitude=50.5&longitude=5.9&start_date=2026-10-17&end_date=2026-10-17&'), url);
  assert.match(url, /&timezone=auto$/);
  assert.equal(forecastPageUrl({ date: '2026-10-17', lat: -0.1, lng: 120 }).includes('latitude=-0.1&longitude=120.0'), true);
  assert.equal(forecastPageUrl({ date: '2026-10-17' }), null, 'no rounded point, no link');
  assert.equal(forecastPageUrl(null), null);
});

// Forecast point (route-planner.md section 4.4). At lat 50.4, 10 km is 0.1408 deg of longitude and 0.0905 deg of latitude.
const leg10 = (from, to) => ({ routed: true, line: [from, to], distM: 10000 });
const HOME = [5.80, 50.4], EAST = [5.80 + 0.1408, 50.4], NORTH = [5.80, 50.4 + 0.0905];

test('a ride under 20 km asks no forecast', () => {
  assert.equal(FORECAST.minRideKm, 20);
  assert.equal(forecastPoint([{ ...leg10(HOME, EAST), distM: 19999 }]), null);
  assert.equal(forecastPoint([]), null);
});

test('a straight ride asks at its middle, rounded to a tenth of a degree', () => {
  const far = [5.80 + 0.5634, 50.4];               // 40 km east
  assert.deepEqual(forecastPoint([{ routed: true, line: [HOME, far], distM: 40000 }]), [6.1, 50.4]);
});

test('an out-and-back asks at the turn, far from home', () => {
  assert.deepEqual(forecastPoint([leg10(HOME, EAST), leg10(EAST, HOME)]), [5.9, 50.4]);
});

test('a loop that passes home halfway asks in its middle third, away from home', () => {
  // East and back, then north and back: the halfway point is home itself.
  const legs = [leg10(HOME, EAST), leg10(EAST, HOME), leg10(HOME, NORTH), leg10(NORTH, HOME)];
  const at = forecastPoint(legs);
  assert.notEqual(at, null);
  assert.notDeepEqual(at, [5.8, 50.4], 'never the cell of the start');
  // About 6.7 km out on either arm, the farthest the middle third gets from home.
  assert.ok([[5.9, 50.4], [5.8, 50.5]].some(c => c[0] === at[0] && c[1] === at[1]), String(at));
});

test('laps of a short circuit near home ask nothing', () => {
  const lap = [5.80 + 0.0141, 50.4];                // 1 km out, 1 km back, twelve times: 24 km
  const legs = [];
  for(let i = 0; i < 12; i++) legs.push({ routed: true, line: [HOME, lap], distM: 1000 }, { routed: true, line: [lap, HOME], distM: 1000 });
  assert.equal(forecastPoint(legs), null);
});

test("the height scale takes round steps in the rider's unit, about four lines", () => {
  assert.deepEqual(heightTicks(-2, 58), [0, 20, 40]);          // near Hoorn, metres
  assert.deepEqual(heightTicks(210, 480), [250, 300, 350, 400, 450]);
  assert.deepEqual(heightTicks(0, 3500, 4), [0, 1000, 2000, 3000]);
  assert.deepEqual(heightTicks(656, 1312), [800, 1000, 1200]);   // the same 200 to 400 m, in feet
  assert.deepEqual(heightTicks(5, 5), []);
});

import { coverPadding } from '../../assets/map/plan-model.js';
test('a fitted ride keeps clear of the key in its corner and the strip at the foot, and of nothing else', () => {
  const box = { left: 360, top: 0, right: 2000, bottom: 1350 };
  const pad = coverPadding(box, [
    { left: 1720, top: 860, right: 1990, bottom: 1300 },   // the key, bottom right: cheaper on the right
    { left: 360, top: 1355, right: 2000, bottom: 1490 },  // the strip, below the map box: covers nothing
    { left: 370, top: 10, right: 600, bottom: 100 },      // the area card, top left: cheaper on top
    { left: 0, top: 0, right: 355, bottom: 1490 },        // the drawer beside the map, not over it
  ], 30);
  assert.equal(pad.right, 2000 - 1720 + 30);
  assert.equal(pad.top, 100 + 30);
  assert.equal(pad.left, 30, 'the drawer beside the map takes no room from the ride');
  assert.equal(pad.bottom, 30);
});
test('a drawer over the map pushes the ride right, and a small map keeps half of itself', () => {
  const box = { left: 0, top: 0, right: 1000, bottom: 800 };
  assert.equal(coverPadding(box, [{ left: 0, top: 0, right: 380, bottom: 800 }], 30).left, 410);
  const tight = coverPadding({ left: 0, top: 0, right: 400, bottom: 800 }, [{ left: 0, top: 0, right: 380, bottom: 800 }], 30);
  assert.ok(tight.left + tight.right <= 200 + 1e-9, `kept ${400 - tight.left - tight.right} px`);
});

import { rideHours, defaultStartMin, tougherLines as tougherAt } from '../../assets/map/plan-model.js';
test('the forecast reads the hours ridden: highest wind and gust, rain summed, wind direction by speed', () => {
  const hours = Array.from({ length: 24 }, (_, h) => ({ windKmh: h === 10 ? 40 : 10, gustKmh: h === 18 ? 70 : 20, fromDeg: h === 10 ? 90 : 0, tempC: 10 + h / 2, rainMm: h >= 9 && h <= 11 ? 0.5 : 0 }));
  const fc = { date: '2026-10-18', windKmh: 40, gustKmh: 70, windFromDeg: 0, tempMaxC: 21.5, rainMm: 1.5, hours };
  const w = rideHours(fc, 9 * 60, 2.5 * 3600);   // 09:00 to 11:30
  assert.equal(w.fromH, 9); assert.equal(w.toH, 11);
  assert.equal(w.windKmh, 40, 'the 10:00 hour is ridden');
  assert.equal(w.gustKmh, 20, 'the 18:00 gust is not');
  assert.equal(w.rainMm, 1.5);
  assert.equal(w.tempMaxC, 15.5);
  assert.ok(w.windFromDeg > 45 && w.windFromDeg < 90, `the strong east wind weighs most: ${w.windFromDeg}`);
  assert.equal(rideHours({ ...fc, hours: null }, 540, 3600).windKmh, 40, 'without hours the day stands');
  assert.equal(rideHours(fc, 23 * 60, 4 * 3600).toH, 23, 'past midnight reads to the last hour');
});
test('the start offered is 09:00, or the next half hour later today, never past 22:00', () => {
  assert.equal(defaultStartMin(null), 540);
  assert.equal(defaultStartMin(7 * 60), 540);
  assert.equal(defaultStartMin(13 * 60 + 39), 14 * 60);
  assert.equal(defaultStartMin(14 * 60), 14 * 60 + 30);
  assert.equal(defaultStartMin(23 * 60), 22 * 60);
});
test('with a start time, the daylight line says the rider starts or finishes more than an hour in the dark', () => {
  const sun = { sunrise: new Date('2026-10-18T06:12:00Z'), sunset: new Date('2026-10-18T16:52:00Z'), daylightS: 38400 };
  const at = (iso, rideS = 3 * 3600) => tougherAt({ distM: 0, roughM: 0, busyM: 0, sun, rideS, startAt: new Date(iso) }).find(x => x.kind === 'daylight');
  assert.equal(at('2026-10-18T05:00:00Z').over, 'darkStart', '72 minutes before sunrise');
  assert.equal(at('2026-10-18T05:30:00Z'), undefined, '42 minutes before sunrise is not tough');
  assert.equal(at('2026-10-18T15:00:00Z').over, 'darkEnd', '68 minutes after sunset');
  assert.equal(at('2026-10-18T14:30:00Z'), undefined, '38 minutes after sunset is not tough');
  assert.equal(at('2026-10-18T13:30:00Z'), undefined, 'finishing just before sunset is not tough');
  assert.equal(at('2026-10-18T05:42:00Z', 12.5 * 3600).over, 'darkBoth', '30 minutes before sunrise and 80 after sunset');
  assert.equal(at('2026-10-18T05:42:00Z', 12.5 * 3600).darkS, 110 * 60);
  assert.equal(at('2026-10-18T09:00:00Z'), undefined, 'a ride well inside the day of 10h 40m says nothing');
});

import { gradientSpans, surfaceSpans } from '../../assets/map/plan-model.js';
test('the stats light their stretches: a climb in the middle of a flat leg, and the surface runs with the map reading winning', () => {
  // 3 km flat, 1 km at 6 %, 3 km flat, sampled every 30 m.
  const heights = Array.from({ length: 234 }, (_, i) => { const d = i * 30; return [d, d < 3000 ? 10 : d < 4000 ? 10 + (d - 3000) * 0.06 : 70]; });
  const leg = { routed: true, line: [], distM: 233 * 30, heights, runs: [[0, 'paved'], [2000, 'gravel'], [2500, 'paved']] };
  const climb = gradientSpans([leg], 'climb');
  assert.equal(climb.length, 1);
  assert.ok(Math.abs(climb[0][0] - 3000) < 100 && Math.abs(climb[0][1] - 4000) < 100, `climb ${climb[0]}`);
  assert.equal(gradientSpans([leg], 'descend').length, 0);
  assert.deepEqual(surfaceSpans([leg], 'gravel', () => null, 50), [[2000, 2500]]);
  // The map's own line read gravel at the sample of 5000-5050 m: that reading wins over the engine's paved.
  assert.deepEqual(surfaceSpans([leg], 'gravel', () => new Map([[100, 'gravel']]), 50), [[2000, 2500], [5000, 5050]]);
  assert.deepEqual(surfaceSpans([{ routed: true, line: [], distM: 1000, heights: null }], 'unknown', () => null, 50), [[0, 1000]], 'a leg without runs is unknown');
});

import { windSpans, windShares as sharesOf } from '../../assets/map/plan-model.js';
test('the wind stretches add up to the wind shares: north into a north wind, south with it', () => {
  const north = { routed: true, distM: 1110, line: [[5.0, 52.00], [5.0, 52.01]] };
  const south = { routed: true, distM: 1110, line: [[5.0, 52.01], [5.0, 52.00]] };
  const into = windSpans([north, south], 0, 'into'), withW = windSpans([north, south], 0, 'with');
  assert.equal(into.length, 1); assert.equal(withW.length, 1);
  assert.ok(Math.abs(into[0][1] - 1110) < 1 && Math.abs(withW[0][0] - 1110) < 1, `into ${into} with ${withW}`);
  const s = sharesOf([north, south], 0);
  assert.ok(Math.abs(s.headM - (into[0][1] - into[0][0])) < 15);
  assert.equal(windSpans([north, south], 0, 'side').length, 0);
});

test('headwind is a wind up to 70° off straight ahead, tailwind up to 70° off straight behind (owner 2026-10-23)', () => {
  const north = { routed: true, distM: 1110, line: [[5.0, 52.00], [5.0, 52.01]] };   // ridden at 0°
  const kind = from => ['into', 'side', 'with'].find(k => windSpans([north], from, k).length);
  assert.deepEqual([65, 70, 75, 105, 110, 115].map(kind), ['into', 'into', 'side', 'side', 'with', 'with']);
  assert.ok(sharesOf([north], 295).headM > 1000, 'a wind 65° off to the left is headwind too');
});

import { windChillC, heatIndexC, rideFeel, tougherLines as linesOf, TOUGHER as BASE } from '../../assets/map/plan-model.js';
test('wind chill and heat index follow the published tables, only inside their reach', () => {
  assert.equal(Math.round(windChillC(0, 20)), -5, 'Environment Canada: 0 °C at 20 km/h feels like -5');
  assert.equal(Math.round(windChillC(-10, 30)), -20);
  assert.equal(windChillC(12, 30), 12, 'above 10 °C the air is the air');
  assert.equal(Math.round(heatIndexC(32, 60)), 37, 'NOAA: 90 °F at 60 % is about 100 °F');
  assert.equal(heatIndexC(25, 90), 25, 'below 27 °C the air is the air');
});
test('the hours ridden: coldest wind chill at riding pace into the wind, hottest heat index, first chance of ice', () => {
  const base = { windKmh: 10, gustKmh: null, fromDeg: 0, tempC: 4, rainMm: 0, humidity: 80, dewC: 1, groundC: 3, snowCm: 0, code: 3 };
  const hours = Array.from({ length: 24 }, () => ({ ...base }));
  hours[7] = { ...base, tempC: 1, groundC: -0.5, rainMm: 0.3 };   // rain on a frozen ground
  hours[9] = { ...base, tempC: 0, windKmh: 20 };
  const f = rideFeel({ hours, fromH: 7, toH: 10 }, 25);
  assert.equal(f.cold.hour, 9);
  assert.equal(Math.round(f.cold.feelC), Math.round(windChillC(0, 45)), 'the wind plus the pace');
  assert.deepEqual(f.ice, { hour: 7, why: 'frost' });
  assert.equal(f.hot, null);
  hours[8] = { ...base, code: 66 };
  assert.deepEqual(rideFeel({ hours, fromH: 8, toH: 10 }, 25).ice, { hour: 8, why: 'freezing' }, 'freezing rain by its code');
  const summer = Array.from({ length: 24 }, () => ({ ...base, tempC: 31, humidity: 65, dewC: 23, groundC: 30 }));
  const hot = rideFeel({ hours: summer, fromH: 12, toH: 14 }, 25).hot;
  assert.ok(hot.feelC >= BASE.heatFeelC, `31 °C at 65 % feels ${hot.feelC}`);
  const lines = linesOf({ distM: 0, roughM: 0, busyM: 0, feel: { cold: { feelC: -4 }, ice: { hour: 7, why: 'frost' }, hot: null }, forecast: null });
  assert.deepEqual(lines.map(x => x.kind), ['cold', 'ice']);
});

import { hourSpan } from '../../assets/map/plan-model.js';
test('an hour of the day lies on the ride where the rider is then, at the average pace', () => {
  // 09:00 start, 2 h riding, 60 km.
  assert.deepEqual(hourSpan(540, 7200, 60000, 9), [0, 30000]);
  assert.deepEqual(hourSpan(540, 7200, 60000, 10), [30000, 60000]);
  assert.equal(hourSpan(540, 7200, 60000, 8), null, 'before the start');
  assert.equal(hourSpan(540, 7200, 60000, 11), null, 'after the finish');
  assert.deepEqual(hourSpan(570, 7200, 60000, 9), [0, 15000], 'a 09:30 start rides half of the 09:00 hour');
});

import { longestClimb as climbOf2 } from '../../assets/map/plan-model.js';
test('the longest climb says where it starts', () => {
  const heights = Array.from({ length: 234 }, (_, i) => { const d = i * 30; return [d, d < 3000 ? 10 : d < 5000 ? 10 + (d - 3000) * 0.05 : 110]; });
  const c = climbOf2([{ routed: true, line: [], distM: 233 * 30, heights }]);
  assert.ok(Math.abs(c.fromM - 3000) <= 100, `from ${c.fromM}`);
  assert.ok(Math.abs(c.km - 2) < 0.2, `km ${c.km}`);
});

import { repeatRuns, repeatedM, avoidPoints, REPEAT } from '../../assets/map/plan-model.js';
test('a leg back along an earlier leg\'s road is found, past the shared checkpoint, and gets points to keep off', () => {
  // Out east 5 km, then back west 5 km along the same road and on 2 km past the start.
  const east = Array.from({ length: 51 }, (_, i) => [5.0 + i * 0.00148, 52.7]);
  const back = Array.from({ length: 71 }, (_, i) => [5.074 - i * 0.00148, 52.7]);
  const legs = [{ routed: true, line: east, distM: 5000 }, { routed: true, line: back, distM: 7000 }];
  const runs = repeatRuns(legs, 1);
  assert.equal(runs.length, 1);
  assert.ok(runs[0][0] >= REPEAT.endM && Math.abs(runs[0][1] - 5000) < 100, `run ${runs[0]}`);
  assert.ok(repeatedM(runs) > 4000);
  const pts = avoidPoints(legs[1], runs);
  assert.ok(pts.length > 10 && pts.length <= REPEAT.maxAvoid, `${pts.length} points`);
  assert.equal(repeatRuns(legs, 0).length, 0, 'the first leg repeats nothing');
  // A leg on a parallel road 300 m away repeats nothing.
  const parallel = back.map(([x, y]) => [x, y + 0.0027]);
  assert.equal(repeatRuns([legs[0], { routed: true, line: parallel, distM: 7000 }], 1).length, 0);
});

import { gpxText as gpxOf, figures as figuresOf } from '../../assets/map/plan-model.js';
test('the GPX carries the cleaned heights, so a device counts the climbing the planner counts (owner 2026-10-18)', () => {
  const legs = HEIGHTS.polder.map(l => ({ routed: true, ...l, line: l.heights.map(([d]) => [5 + d / 68000, 52.7]) }));
  const xml = gpxOf({ name: 'Polder', stops: [{ lat: 52.7, lng: 5, name: 'A' }, { lat: 52.7, lng: 5.3, name: 'B' }], legs: [legs[0]] });
  const eles = [...xml.matchAll(/<ele>(-?[\d.]+)<\/ele>/g)].map(m => Number(m[1]));
  let up = 0; for(let i = 1; i < eles.length; i++) if(eles[i] > eles[i - 1]) up += eles[i] - eles[i - 1];
  const planner = figuresOf([legs[0]]).upM;
  assert.ok(Math.abs(up - planner) < 3, `GPX climbs ${up.toFixed(1)} m, the planner ${planner.toFixed(1)} m`);
});

test('a round trip climbs what it descends: the heights are cleaned over the ride, not leg by leg (owner 2026-10-18)', async () => {
  const { figures, cleanRide } = await import('../../assets/map/plan-model.js');
  // Three legs out and back over a 2 m wobble at every join: leg by leg, each join's wobble went uncounted one way.
  const leg = (hs, distM) => ({ routed: true, distM, heights: hs.map((h, i) => [i * 30, h]) });
  const legs = [leg([0, 1, 2, 6, 10, 12], 150), leg([11, 12, 13, 9, 5, 3], 150), leg([2, 1, 0, 0, 1, 0], 150)];
  const f = figures(legs);
  assert.ok(Math.abs(f.upM - f.downM) < 0.01, `climbed ${f.upM} but descended ${f.downM} on a ride that ends where it starts`);
  const clean = cleanRide(legs);
  assert.equal(clean.length, 3);
  assert.equal(clean[0].length, 6);
  assert.equal(clean[1][0], clean[0][5], 'the joins of the cleaned line move');
  const gap = cleanRide([legs[0], { routed: true, distM: 900, heights: null }, legs[2]]);
  assert.equal(gap[1], null, 'a leg without heights gets heights');
});

test('a ride file keeps its roads: its track is cut at the checkpoints, one piece per leg (owner 2026-10-18)', async () => {
  const { rideFromGpx, trackPieces, stopsOnTrack, gpxText, PIECE_MAX_POINTS } = await import('../../assets/map/plan-model.js');
  // A track of 3000 points, a point every ~7 m, with a spur up and back down a side road at its middle.
  const pts = [];
  for(let i = 0; i < 1500; i++) pts.push([5.90 + i * 0.0001, 50.40]);
  for(let i = 0; i < 300; i++) pts.push([6.05, 50.40 + i * 0.0001]);
  for(let i = 300; i >= 0; i--) pts.push([6.05 + 0.00002, 50.40 + i * 0.0001]);
  for(let i = 1; i < 900; i++) pts.push([6.05 + i * 0.0001, 50.40]);
  const gpx = `<?xml version="1.0"?><gpx creator="StravaGPX"><trk><trkseg>${pts.map(([lng, lat]) => `<trkpt lat="${lat}" lon="${lng}"></trkpt>`).join('')}</trkseg></trk></gpx>`;
  const r = rideFromGpx(gpx);
  assert.ok(Array.isArray(r.track), 'a converted track carries no pieces');
  assert.equal(r.track.length, r.stops.length - 1, 'one piece per leg');
  r.track.forEach((piece, i) => {
    assert.deepEqual(piece[0], [r.stops[i].lng, r.stops[i].lat], `piece ${i} does not start at its checkpoint`);
    assert.deepEqual(piece[piece.length - 1], [r.stops[i + 1].lng, r.stops[i + 1].lat], `piece ${i} does not end at the next checkpoint`);
    assert.ok(piece.length <= PIECE_MAX_POINTS);
  });
  const sent = r.track.reduce((n, p) => n + p.length, 0);
  assert.ok(sent < pts.length / 2, `every device point is sent (${sent})`);
  // The planner's own file: its track cut at its waypoints, a loop that passes its start again cut at the end.
  const line = pts.map(([lng, lat]) => ({ lat, lng }));
  const stops = [line[0], line[1500], line[1800], line[line.length - 1]].map(p => ({ lat: p.lat, lng: p.lng, name: '' }));
  assert.deepEqual(stopsOnTrack(line, stops), [0, 1500, 1800, line.length - 1]);
  assert.equal(stopsOnTrack(line, [{ lat: 51, lng: 6 }, stops[1]]), null, 'a stop off the track is placed');
  assert.equal(trackPieces(line, [5, 3]), null, 'checkpoints out of order cut the track');
  const legs = stops.slice(1).map((s, i) => ({ routed: true, distM: 1000, line: line.slice([0, 1500, 1800][i], [1500, 1800, line.length - 1][i] + 1).map(p => [p.lng, p.lat]), heights: null }));
  const back = rideFromGpx(gpxText({ name: 'Spur', bike: 'Road', stops, legs }));
  assert.equal(back.track && back.track.length, 3, 'the planner\'s own file loses its roads');
});

test('a slow climb keeps its points: a device writing one every 3 m is not standing still (owner 2026-10-18)', async () => {
  const { rideFromGpx } = await import('../../assets/map/plan-model.js');
  // 2 km at a point every ~3.3 m (12 km/h, one a second), then 2 km at ~11 m, then 30 points on one spot.
  const pts = [];
  for(let i = 0; i < 600; i++) pts.push([5.90, 50.40 + i * 0.00003]);
  for(let i = 1; i <= 180; i++) pts.push([5.90 + i * 0.00016, 50.40 + 599 * 0.00003]);
  for(let i = 0; i < 30; i++) pts.push(pts[pts.length - 1]);
  const gpx = `<?xml version="1.0"?><gpx creator="Garmin"><trk><trkseg>${pts.map(([lng, lat]) => `<trkpt lat="${lat}" lon="${lng}"></trkpt>`).join('')}</trkseg></trk></gpx>`;
  const r = rideFromGpx(gpx);
  const climb = r.track.flat().filter(([lng]) => lng === 5.90);
  assert.ok(climb.length > 50, `the climb kept ${climb.length} points`);
  const step = (a, b) => Math.hypot((b[0] - a[0]) * 67800, (b[1] - a[1]) * 111200);
  const biggest = Math.max(...r.track.flatMap(p => p.slice(1).map((q, k) => step(p[k], q))));
  assert.ok(biggest < 60, `a piece jumps ${Math.round(biggest)} m`);
});

/* What OpenStreetMap records along the ride, summed over the legs (route-planner.md section 4.4a, owner 2026-10-19):
   the smoothness in the five words the contribute form offers, the tracks by grade, and the speed limits, slowest
   first and "not recorded" last. A leg from before the table, without the fields, adds nothing. */
test('roadDetail sums smoothness, track grades and speed limits over the legs', () => {
  const legs = [
    { routed: true, distM: 3000, quality: { smoothness: { good: 1000, horrible: 200 }, tracktype: { grade3: 400 } }, speeds: { 50: 1000, unset: 500 } },
    { routed: true, distM: 2000, quality: { smoothness: { good: 500, very_bad: 100, impassable: 50 }, tracktype: { grade1: 100 } }, speeds: { 30: 700, 50: 300, unset: 1000 } },
    { routed: true, distM: 900 },
    null,
  ];
  const d = roadDetail(legs);
  assert.deepEqual(d.smoothness, [['good', 1500], ['very_bad', 350]]);
  assert.deepEqual(d.tracktype, [['grade1', 100], ['grade3', 400]]);
  assert.deepEqual(d.speeds, [['30', 700], ['50', 1300], ['unset', 1500]]);
  assert.deepEqual(roadDetail([{ routed: true, distM: 100 }]), { smoothness: [], tracktype: [], speeds: [] });
});

/* Each line of the road detail lights its stretches on the map (owner 2026-10-19: "But what part of the road is that
   about? I can't hover it"): the legs' runs, placed one leg after the other, OpenStreetMap's eight smoothness values
   folded into the five words the line says. */
test('detailSpans places a value\'s stretches along the whole ride', () => {
  const legs = [
    { routed: true, distM: 3000, detailRuns: { smoothness: [[0, 1000, 'good'], [1000, 1200, 'horrible']], tracktype: [[2000, 2400, 'grade3']], speeds: [[0, 1500, '50'], [1500, 3000, 'unset']] } },
    { routed: true, distM: 2000 },
    { routed: true, distM: 1000, detailRuns: { smoothness: [[0, 300, 'very_bad'], [300, 1000, 'good']], tracktype: [], speeds: [[0, 1000, '50']] } },
  ];
  assert.deepEqual(detailSpans(legs, 'sm', 'good'), [[0, 1000], [5300, 6000]]);
  assert.deepEqual(detailSpans(legs, 'sm', 'very_bad'), [[1000, 1200], [5000, 5300]], 'horrible and very bad are one word');
  assert.deepEqual(detailSpans(legs, 'tt', 'grade3'), [[2000, 2400]]);
  assert.deepEqual(detailSpans(legs, 'sp', '50'), [[0, 1500], [5000, 6000]]);
  assert.deepEqual(detailSpans(legs, 'sp', '30'), []);
});

/* Owner 2026-10-19: "also total climbing makes it tougher. Everything above 2000 m of climbing then it is shown as
   tough". */
test('a day of 2000 m climbed or more is a tough day, whatever its longest climb', () => {
  const at = (patch) => kinds(Object.assign(ordinary(), patch));
  assert.equal(TOUGHER.climbedM, 2000);
  assert.deepEqual(at({ upM: 1999 }), []);
  assert.deepEqual(at({ upM: 2000 }), ['climbed']);
  assert.deepEqual(tougherLines(Object.assign(ordinary(), { upM: 4185 })).find(x => x.kind === 'climbed'), { kind: 'climbed', upM: 4185 });
});

/* Owner 2026-10-19: "Show the avg speed". The riding distance over the riding time, stops not counted. */
test('the average speed is the distance over the riding time', () => {
  assert.equal(avgKmh(151700, 9 * 3600 + 40 * 60).toFixed(1), '15.7');
  assert.equal(avgKmh(0, 3600), null);
  assert.equal(avgKmh(1000, 0), null);
});
