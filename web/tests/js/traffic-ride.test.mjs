// SPDX-License-Identifier: AGPL-3.0-only
//
// One ride into its traffic summary, and the summary onto the wire
// (docs/specs/traffic-measurements.md §3). The ride is built record by record
// along the cycle path in the fixture tile (way 200, 5.0011 E, 52.0010 to
// 52.0030 N), with the radar reporting and one car passing.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { gunzipSync } from 'node:zlib';
import { summariseRide, makeChunks, sendChunks, CHUNK_MAX, unmatchedStops } from '../../assets/lib/traffic-ride.js';

const TILE = gunzipSync(readFileSync(new URL('./fixtures/roadpieces-14-8419-5411.mvt', import.meta.url)));
const ENTRIES = { nl: { tiles: { roadpieces: 'https://t/nl.pmtiles' }, bounds: [3.3, 50.7, 7.2, 53.6] } };
const FIT_EPOCH = 631065600;
const semi = deg => Math.round(deg * 2 ** 31 / 180);
// Tuesday 6 October 2026 08:00:00 UTC.
const T0 = Date.UTC(2026, 9, 6, 8, 0, 0) / 1000;

function fitRide({ seconds = 40, radar = true, car = true } = {}) {
  const messages = [{ globalNum: 34, fields: { 253: T0 - FIT_EPOCH, 5: T0 - FIT_EPOCH + 7200 }, devFields: {} }];
  for (let s = 0; s < seconds; s++) {
    const lat = 52.0011 + s * 5 / 111_320;
    const dev = {};
    if (radar) {
      // A car closes in over seconds 10-13 and passes at 14.
      const inRange = car && s >= 10 && s <= 13;
      dev['0-1'] = inRange ? 1 : 0;
      dev['0-2'] = inRange ? [30, 15, 8, 6][s - 10] : 255;
      dev['0-3'] = inRange ? 45 : 255;
    }
    messages.push({ globalNum: 20, fields: { 0: semi(lat), 1: semi(5.0011), 253: T0 + s - FIT_EPOCH, 6: 5000 }, devFields: dev });
  }
  return {
    messages,
    devFields: radar ? { '0-1': { name: 'radar_count' }, '0-2': { name: 'radar_near' }, '0-3': { name: 'radar_speed' } } : {},
  };
}

const deps = { entries: ENTRIES, fetchTile: async () => TILE, fetchHolidays: async () => new Set() };

test('a ride along the cycle path becomes lines on way 200, its car nearby', async () => {
  // No car drives on a cycle path: the radar's car drove on the road beside it.
  const got = await summariseRide(fitRide(), deps);
  assert.ok(got.lines.length >= 1);
  assert.ok(got.lines.every(l => l.way === 200 && l.label === 'p' && l.dir === 'f'));
  assert.equal(got.cars, 0, 'nothing passed the rider');
  assert.equal(got.nearby, 1);
  assert.equal(got.lines.reduce((n, l) => n + l.passes, 0), 0);
  assert.equal(got.lines.reduce((n, l) => n + l.nearby, 0), 1);
  assert.equal(got.lines[0].slot, 40, '10:00 local from the activity offset of +2 h');
  assert.equal(got.lines[0].dayType, 'workday');
  assert.ok(got.matchedKm > 0.15 && got.matchedKm < 0.25, `matched ${got.matchedKm}`);
  assert.equal(got.unmatchedKm, 0);
  assert.equal(got.year, 2026);
});

test('the summary never carries a position or a time', async () => {
  const got = await summariseRide(fitRide(), deps);
  const wire = JSON.stringify(got.lines);
  for (const key of ['"lat"', '"lon"', '"lng"', '"t"', '"time"', '"coords"']) assert.ok(!wire.includes(key), key);
});

test('a ride without radar data has nothing to summarise', async () => {
  const got = await summariseRide(fitRide({ radar: false }), deps);
  assert.equal(got, null);
});

test('kilometres outside every road-piece archive are counted as unmatched', async () => {
  const got = await summariseRide(fitRide(), { ...deps, entries: {} });
  assert.equal(got.lines.length, 0);
  assert.ok(got.unmatchedKm > 0.15, `unmatched ${got.unmatchedKm}`);
});

test('cars off the matched roads are counted, so the rider sees why two totals differ', async () => {
  const off = await summariseRide(fitRide(), { ...deps, entries: {} });
  assert.equal(off.cars, 0);
  assert.equal(off.allCars, 1, 'the radar counted one car; it was on the part with no road');
  const on = await summariseRide(fitRide(), deps);
  assert.equal(on.allCars, on.cars + on.nearby);
});

test('the part with no road comes back as lines for the map, never for the wire', async () => {
  const off = await summariseRide(fitRide(), { ...deps, entries: {} });
  assert.equal(off.unmatchedLines.length, 1, 'one unbroken stretch');
  assert.ok(off.unmatchedLines[0].length >= 30, 'every radar-on fix of it');
  assert.deepEqual(off.unmatchedLines[0][0].map(v => Math.round(v * 1e4) / 1e4), [5.0011, 52.0011]);
  const on = await summariseRide(fitRide(), deps);
  assert.deepEqual(on.unmatchedLines, []);
  assert.ok(!JSON.stringify(on.lines).includes('unmatched'));
});

test('the matched pieces come back for the map, never for the wire', async () => {
  const got = await summariseRide(fitRide(), deps);
  assert.ok(got.matched.some(p => p.id === 200 && p.label === 'p' && p.coords.length >= 2));
});

const line = (i, blocks = ['a'.repeat(63) + (i % 16).toString(16)]) => ({ way: i, dir: 'f', label: 'r', slot: 1, dayType: 'workday', season: 'autumn', quarter: '2026-Q4', day: 1, distanceM: 100, timeS: 20, passes: 0, avgSpeedKmh: 18, carSpeedBins: null, blocks });

/** A ride of n lines where neighbours share a block, as consecutive lines of a real ride do. */
function rideLines(n, salt) {
  const code = k => (salt + '-' + k).padEnd(64, '0').slice(0, 64).replace(/[^0-9a-f]/g, 'f');
  return Array.from({ length: n }, (_, i) => line(i, [code(i), code(i + 1)]));
}

/** The server's rule: a line whose codes another request claimed is a duplicate. */
function fakeServer() {
  const claimed = new Set();
  const sent = [];
  const post = async body => {
    sent.push(body);
    const mine = new Set();
    for (const l of body.lines) for (const c of l.blocks) if (!claimed.has(c)) { claimed.add(c); mine.add(c); }
    let added = 0;
    let duplicate = 0;
    for (const l of body.lines) (l.blocks.every(c => mine.has(c)) ? added++ : duplicate++);
    return { ok: true, added, duplicate, dropped: 0 };
  };
  return { post, sent };
}

test('the lines of one ride are never split across requests', async () => {
  const lines = rideLines(900, 'aa');
  const chunks = makeChunks(lines);
  assert.equal(chunks.length, 1);
  const server = fakeServer();
  const got = await sendChunks(chunks, server.post);
  assert.equal(got.added, 900);
  assert.equal(got.duplicate, 0, 'a fresh ride loses nothing');
});

test('many rides are packed into requests, each ride whole, and none is lost', async () => {
  const lines = [...rideLines(300, 'b1'), ...rideLines(300, 'b2'), ...rideLines(300, 'b3'), ...rideLines(50, 'b4')];
  const chunks = makeChunks(lines);
  assert.ok(chunks.length >= 2);
  assert.ok(chunks.every(c => c.lines.length <= CHUNK_MAX));
  const server = fakeServer();
  const got = await sendChunks(chunks, server.post);
  assert.deepEqual([got.added, got.duplicate], [950, 0]);
});

test('every request carries only v and lines, shuffled inside the request', async () => {
  const lines = rideLines(400, 'cc');
  const server = fakeServer();
  await sendChunks(makeChunks(lines), server.post);
  assert.ok(server.sent.every(b => b.v === 1 && Object.keys(b).length === 2));
  assert.notDeepEqual(server.sent[0].lines.map(l => l.way), lines.map(l => l.way), 'the order says nothing about the ride');
});

test('a retry sends only the requests that were not acknowledged', async () => {
  const chunks = makeChunks([...rideLines(400, 'd1'), ...rideLines(400, 'd2'), ...rideLines(400, 'd3')]);
  let n = 0;
  const flaky = async body => (++n === 2 ? { ok: false, error: 'network' } : { ok: true, added: body.lines.length, duplicate: 0, dropped: 0 });
  const first = await sendChunks(chunks, flaky);
  assert.equal(first.ok, false);
  assert.equal(first.error, 'network');
  const before = n;
  const second = await sendChunks(chunks, async body => { n++; return { ok: true, added: body.lines.length, duplicate: 0, dropped: 0 }; });
  assert.equal(second.ok, true);
  assert.equal(n - before, chunks.length - 1, 'the acknowledged request is not sent again');
});

test('the eye visits the parts with no road one by one, longest first', () => {
  // Small gaps spread over a whole ride: fitting all of them at once is the
  // whole ride again, so the map barely moved.
  const short = [[5.0, 52.0], [5.0, 52.0005]];
  const long = [[5.1, 52.1], [5.1, 52.103], [5.101, 52.106]];
  const mid = [[5.2, 52.2], [5.2, 52.202]];
  const stops = unmatchedStops([short, long, mid]);
  assert.deepEqual(stops.map(x => x.bounds[0]), [[5.1, 52.1], [5.2, 52.2], [5.0, 52.0]]);
  assert.deepEqual(stops[0].bounds, [[5.1, 52.1], [5.101, 52.106]]);
  assert.ok(stops[0].metres > 600 && stops[0].metres < 700, `metres ${stops[0].metres}`);
  assert.deepEqual(unmatchedStops([]), []);
});

test('each car is marked passing or nearby, in the radar\'s own order', async () => {
  // The fixture's car is on the cycle path: it drove on the road beside it.
  const onPath = await summariseRide(fitRide(), deps);
  assert.deepEqual(onPath.passKinds, ['nearby']);
  // Off every road: neither, so the marker keeps its plain look.
  const off = await summariseRide(fitRide(), { ...deps, entries: {} });
  assert.deepEqual(off.passKinds, [null]);
});

test('each line carries the region its road piece names', async () => {
  // The fixture's cycle path (way 200) lies in region 9.
  const got = await summariseRide(fitRide(), deps);
  assert.ok(got.lines.length >= 1);
  assert.ok(got.lines.every(l => l.region === 9));
});
