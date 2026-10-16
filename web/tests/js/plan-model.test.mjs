// SPDX-License-Identifier: AGPL-3.0-only
//
// plan-model.js: the ride planner's pure model (docs/specs/route-planner.md
// sections 4 and 6.3). Stops and legs, where a place goes in the ride, the
// figures, the profile bins, and the GPX writer. A leaf: no map, no DOM.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  MAX_STOPS, WORK_KEEP_MS, workFresh, rideFromGpx, simplifyTrack, SHAPE_M, thinLine, ALONG_MAX_POINTS, waterGapSpan, bestWaterStop, legGoesRound, whyThisWay, sliceAlong, stopIndexAt, stopRows, stopKey, legKeys, nearLine, insertIndex, figures, profileRuns, pointAt, gpxText, barText, createHistory, tickStepKm, surfaceMix,
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
