// SPDX-License-Identifier: AGPL-3.0-only
//
// The traffic summary a rider sends (docs/specs/traffic-measurements.md §3.4):
// the time key, the per-piece lines, and the dedupe codes.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { parseFit } from '../../assets/lib/scout-fit.js';
import { timeKey, buildLines, blockCode, localOffsetSeconds, zoneOffsetSeconds, recordsFromFit, dayGroupOf, trimEnds, DAY_GROUPS } from '../../assets/lib/traffic-summary.js';

const NONE = new Set();
// Monday 5 October 2026 17:59:30 UTC.
const MON = Date.UTC(2026, 9, 5, 17, 59, 30) / 1000;

test('the band is the part of the local day: night, morning rush, day, evening rush, evening', () => {
  const at = (h, m = 0) => Date.UTC(2026, 9, 5, h, m) / 1000;
  assert.equal(timeKey(at(5, 59), 0, 52, NONE).band, 0);
  assert.equal(timeKey(at(6), 0, 52, NONE).band, 1);
  assert.equal(timeKey(at(9), 0, 52, NONE).band, 2);
  assert.equal(timeKey(at(16), 0, 52, NONE).band, 3);
  assert.equal(timeKey(MON, 0, 52, NONE).band, 3);           // 17:59:30
  assert.equal(timeKey(at(19), 0, 52, NONE).band, 4);
  assert.equal(timeKey(MON, 7200, 52, NONE).band, 4);        // 19:59:30 local
  assert.equal(timeKey(at(23, 59), 0, 52, NONE).band, 4);
});

test('no quarter hour and no season is kept', () => {
  const k = timeKey(MON, 0, 52, NONE);
  assert.equal(k.slot, undefined);
  assert.equal(k.season, undefined);
});

test('Saturday, Sunday and a public holiday are weekend', () => {
  assert.equal(timeKey(MON, 0, 52, NONE).dayType, 'workday');
  assert.equal(timeKey(Date.UTC(2026, 9, 10, 9) / 1000, 0, 52, NONE).dayType, 'weekend');
  assert.equal(timeKey(Date.UTC(2026, 3, 27, 9) / 1000, 7200, 52, new Set(['2026-04-27'])).dayType, 'weekend');
});

test('the local date decides the day near midnight', () => {
  // 22:30 UTC on Friday is 00:30 Saturday at +2.
  const k = timeKey(Date.UTC(2026, 9, 9, 22, 30) / 1000, 7200, 52, NONE);
  assert.equal(k.dayType, 'weekend');
  assert.equal(k.band, 0);
  assert.equal(k.day, Math.floor((Date.UTC(2026, 9, 9, 22, 30) / 1000 + 7200) / 86400));
});

test('the quarter is the local quarter of the year', () => {
  assert.equal(timeKey(MON, 0, 52, NONE).quarter, '2026-Q4');
  assert.equal(timeKey(Date.UTC(2026, 2, 31, 23, 30) / 1000, 7200, 52, NONE).quarter, '2026-Q2');
});

test('the local offset comes from the FIT activity message', () => {
  const parsed = { messages: [{ globalNum: 34, fields: { 253: 1_000_000, 5: 1_007_200 }, devFields: {} }] };
  assert.equal(localOffsetSeconds(parsed), 7200);
  assert.equal(localOffsetSeconds({ messages: [] }), null);
});

test('without it, the zone of the ride country gives the offset', () => {
  assert.equal(zoneOffsetSeconds(MON, 'nl', 5), 7200);             // CEST
  assert.equal(zoneOffsetSeconds(Date.UTC(2026, 0, 5) / 1000, 'nl', 5), 3600);
  assert.equal(zoneOffsetSeconds(MON, 'us', -118), -7 * 3600);      // California, PDT
  assert.equal(zoneOffsetSeconds(MON, 'us', -105), -6 * 3600);      // Colorado, MDT
  assert.equal(zoneOffsetSeconds(MON, 'zz', 5), null);
});

// A ride north at 18 km/h, one record per second, all on way 7 forward.
function ride({ start = MON, seconds = 120, radarOff = () => false } = {}) {
  const records = [];
  const matches = [];
  for (let s = 0; s < seconds; s++) {
    const lat = 52 + s * 5 / 111_320;
    records.push({ t: start + s, lat, lon: 5, latSemi: Math.round(lat * 2 ** 31 / 180), lonSemi: Math.round(5 * 2 ** 31 / 180) + s, kmh: 18, radar: !radarOff(s) });
    matches.push({ way: 7, label: 'r', dir: 'f', cc: 'nl' });
  }
  return { records, matches };
}

test('a ride across a quarter hour stays one line inside its band', async () => {
  const { records, matches } = ride();
  const lines = await buildLines({ records, matches, passes: [], offsetS: 0, holidays: {} });
  assert.deepEqual(lines.map(l => l.band), [3]);
});

test('a ride across a band boundary becomes two lines', async () => {
  const { records, matches } = ride({ start: Date.UTC(2026, 9, 5, 18, 59, 30) / 1000 });
  const lines = await buildLines({ records, matches, passes: [], offsetS: 0, holidays: {} });
  assert.deepEqual(lines.map(l => l.band).sort(), [3, 4]);
  const total = lines.reduce((m, l) => m + l.distanceM, 0);
  assert.ok(Math.abs(total - 119 * 5) < 2, `distance ${total}`);
  for (const l of lines) {
    assert.equal(l.way, 7);
    assert.equal(l.dir, 'f');
    assert.equal(l.label, 'r');
    assert.equal(l.dayType, 'workday');
    assert.equal(l.quarter, '2026-Q4');
    assert.ok(Math.abs(l.avgSpeedKmh - 18) < 0.5);
  }
});

test('seconds without radar add neither distance nor time', async () => {
  const { records, matches } = ride({ seconds: 20, radarOff: s => s >= 5 && s < 15 });
  const lines = await buildLines({ records, matches, passes: [], offsetS: 0, holidays: {} });
  const timeS = lines.reduce((m, l) => m + l.timeS, 0);
  // Only intervals between two radar-on records count: 0-4 gives 4, 15-19 gives 4.
  assert.equal(timeS, 8);
});

test('passes count on the line of their record, with their speed band', async () => {
  const { records, matches } = ride({ start: Date.UTC(2026, 9, 5, 9, 0, 0) / 1000, seconds: 60 });
  const at = s => new Date((records[s].t) * 1000);
  const passes = [
    { time: at(10), speed: 70, ground: 88, lat: records[10].lat, lon: 5 },
    { time: at(20), speed: 40, ground: null, lat: records[20].lat, lon: 5 },   // 40 + 18 rider
    { time: at(30), speed: null, ground: null, lat: records[30].lat, lon: 5 },
  ];
  const [line] = await buildLines({ records, matches, passes, offsetS: 0, holidays: {} });
  assert.equal(line.passes, 3);
  assert.equal(line.carSpeedBins[8], 1);
  assert.equal(line.carSpeedBins[5], 1);
  assert.equal(line.carSpeedBins.reduce((a, b) => a + b, 0), 2);
});

test('a line without any measured car speed sends no bins', async () => {
  const { records, matches } = ride({ start: Date.UTC(2026, 9, 5, 9) / 1000, seconds: 30 });
  const [line] = await buildLines({ records, matches, passes: [], offsetS: 0, holidays: {} });
  assert.equal(line.carSpeedBins, null);
});

test('a line carries the code of every five-minute block it covers', async () => {
  const { records, matches } = ride({ start: Date.UTC(2026, 9, 5, 9) / 1000, seconds: 900 });
  const [line] = await buildLines({ records, matches, passes: [], offsetS: 0, holidays: {} });
  assert.equal(line.blocks.length, 3);
  assert.ok(line.blocks.every(c => /^[0-9a-f]{64}$/.test(c)));
});

test('the same records give the same codes, another rider beside them does not', async () => {
  const a = ride({ start: Date.UTC(2026, 9, 5, 9) / 1000, seconds: 60 });
  const again = ride({ start: Date.UTC(2026, 9, 5, 9) / 1000, seconds: 60 });
  const beside = ride({ start: Date.UTC(2026, 9, 5, 9) / 1000, seconds: 60 });
  beside.records.forEach(r => { r.lonSemi += 1000; });
  const la = await buildLines({ records: a.records, matches: a.matches, passes: [], offsetS: 0, holidays: {} });
  const lb = await buildLines({ records: again.records, matches: again.matches, passes: [], offsetS: 0, holidays: {} });
  const lc = await buildLines({ records: beside.records, matches: beside.matches, passes: [], offsetS: 0, holidays: {} });
  assert.deepEqual(la[0].blocks, lb[0].blocks);
  assert.notDeepEqual(la[0].blocks, lc[0].blocks);
});

test('the same file cut later in a block keeps the codes of the blocks it still covers whole', async () => {
  const full = ride({ start: Date.UTC(2026, 9, 5, 9) / 1000, seconds: 900 });
  const cut = { records: full.records.slice(300), matches: full.matches.slice(300) };
  const lf = await buildLines({ ...full, passes: [], offsetS: 0, holidays: {} });
  const lc = await buildLines({ ...cut, passes: [], offsetS: 0, holidays: {} });
  assert.deepEqual(lc[0].blocks, lf[0].blocks.slice(1));
});

test('unmatched records send nothing, and a line never carries a position or a time', async () => {
  const { records, matches } = ride({ start: Date.UTC(2026, 9, 5, 9) / 1000, seconds: 30 });
  matches.forEach((m, i) => { if (i < 10) Object.assign(m, { way: null, label: null, dir: null }); });
  const lines = await buildLines({ records, matches, passes: [], offsetS: 0, holidays: {} });
  assert.equal(lines.length, 1);
  assert.deepEqual(Object.keys(lines[0]).sort(), ['avgSpeedKmh', 'band', 'blocks', 'carSpeedBins', 'day', 'dayGroup', 'dayType', 'dir', 'distanceM', 'label', 'nearby', 'passes', 'quarter', 'region', 'timeS', 'way']);
});

test('the holiday list of the ride country is used', async () => {
  const { records, matches } = ride({ start: Date.UTC(2026, 3, 27, 9) / 1000, seconds: 30 });
  const lines = await buildLines({ records, matches, passes: [], offsetS: 7200, holidays: { nl: new Set(['2026-04-27']) } });
  assert.equal(lines[0].dayType, 'weekend');
});

test('a block code is a sha256 of the block and its first fix', async () => {
  const code = await blockCode(1_791_000_000, { t: 1_791_000_003, latSemi: 620_000_000, lonSemi: 59_652_323 });
  assert.match(code, /^[0-9a-f]{64}$/);
  assert.notEqual(code, await blockCode(1_791_000_000, { t: 1_791_000_004, latSemi: 620_000_000, lonSemi: 59_652_323 }));
});

test('the records of a FIT file carry what the summary needs and nothing more', () => {
  const bytes = readFileSync(new URL('../fixtures/scout/scout-scenario.fit', import.meta.url));
  const records = recordsFromFit(parseFit(bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength)));
  assert.equal(records.length, 60);
  for (const r of records) {
    assert.deepEqual(Object.keys(r).sort(), ['kmh', 'lat', 'latSemi', 'lon', 'lonSemi', 'radar', 't']);
    assert.equal(typeof r.t, 'number');
    assert.equal(typeof r.radar, 'boolean');
  }
  assert.ok(records.some(r => r.lat !== null && Number.isInteger(r.latSemi)));
  assert.ok(records.some(r => r.radar), 'the fixture records radar_count');
});

test('the device offset is rounded to whole quarter hours', () => {
  const parsed = { messages: [{ globalNum: 34, fields: { 253: 1_000_000, 5: 1_007_199 }, devFields: {} }] };
  assert.equal(localOffsetSeconds(parsed), 7200);
});

test('zones inside a country follow the place', () => {
  const jan = Date.UTC(2026, 0, 15, 12) / 1000;
  assert.equal(zoneOffsetSeconds(jan, 'au', 151.2, -33.9), 11 * 3600, 'Sydney keeps summer time');
  assert.equal(zoneOffsetSeconds(jan, 'au', 153.0, -27.5), 10 * 3600, 'Brisbane does not');
  assert.equal(zoneOffsetSeconds(jan, 'au', 130.8, -12.4), 9.5 * 3600, 'Darwin');
  assert.equal(zoneOffsetSeconds(MON, 'es', -15.4, 28.1), 3600, 'the Canaries run an hour behind Madrid');
  assert.equal(zoneOffsetSeconds(MON, 'es', -3.7, 40.4), 7200);
});

const DAY = (y, m, d) => Date.UTC(y, m - 1, d) / 86400000;
const pad2 = n => String(n).padStart(2, '0');
const isoOf = day => { const d = new Date(day * 86400000); return `${d.getUTCFullYear()}-${pad2(d.getUTCMonth() + 1)}-${pad2(d.getUTCDate())}`; };

test('the day group counts the dates of its day type in the quarter: 12 workday groups, 4 weekend groups', () => {
  assert.equal(dayGroupOf(DAY(2026, 7, 1)), 0, 'Wednesday 1 July, the first workday of Q3');
  assert.equal(dayGroupOf(DAY(2026, 7, 2)), 1, 'the next workday');
  assert.equal(dayGroupOf(DAY(2026, 7, 6)), 3, 'the weekend in between is not counted');
  assert.equal(dayGroupOf(DAY(2026, 7, 4)), 0, 'Saturday 4 July, the first weekend date of Q3');
  assert.equal(dayGroupOf(DAY(2026, 7, 5)), 1, 'its Sunday');
  assert.equal(dayGroupOf(DAY(2026, 7, 25)), 2, 'the seventh weekend date: 6 mod 4');
  assert.equal(dayGroupOf(DAY(2026, 10, 1)), 0, 'a new quarter starts at 0');
  assert.equal(dayGroupOf(DAY(2026, 4, 27), new Set(['2026-04-27'])), 0, 'a holiday counts among the weekend dates: eight before it in Q2, 8 mod 4');
});

test('every (quarter, day group, day type) stands for at least 4 dates, in every country and quarter', () => {
  const dir = new URL('../../public/data/holidays/', import.meta.url);
  const calendars = [['calendar only', new Set()], ...readdirSync(dir).map(f => [f, new Set(JSON.parse(readFileSync(new URL(f, dir), 'utf8')).dates)])];
  for (const [name, holidays] of calendars) {
    const least = { workday: Infinity, weekend: Infinity };
    for (let y = 2015; y <= 2027; y++) {
      for (let q = 0; q < 4; q++) {
        const counts = new Map();
        for (let d = DAY(y, q * 3 + 1, 1); d < Date.UTC(y, q * 3 + 3, 1) / 86400000; d++) {
          const type = timeKey(d * 86400 + 43200, 0, 52, holidays).dayType;
          const group = dayGroupOf(d, holidays);
          assert.ok(group >= 0 && group < DAY_GROUPS[type], `${name} ${isoOf(d)}: group ${group} out of range for ${type}`);
          counts.set(type + '|' + group, (counts.get(type + '|' + group) || 0) + 1);
        }
        for (const [k, n] of counts) least[k.split('|')[0]] = Math.min(least[k.split('|')[0]], n);
      }
    }
    assert.ok(least.workday >= 4, `${name}: a workday group holds only ${least.workday} dates`);
    assert.ok(least.weekend >= 4, `${name}: a weekend group holds only ${least.weekend} dates`);
  }
});

test('a line carries its day group, and keeps its day only for the rider\'s own view', async () => {
  // Thursday 10 September 2026: 51 workdays of Q3 come before it, 51 mod 12 = 3.
  const { records, matches } = ride({ start: Date.UTC(2026, 8, 10, 9) / 1000, seconds: 30 });
  const [line] = await buildLines({ records, matches, passes: [], offsetS: 0, holidays: {} });
  assert.equal(line.dayType, 'workday');
  assert.equal(line.dayGroup, 3);
  assert.equal(line.day, DAY(2026, 9, 10));
});

test('the day group of a holiday follows the holiday list of the ride country', async () => {
  const { records, matches } = ride({ start: Date.UTC(2026, 3, 27, 9) / 1000, seconds: 30 });
  const [line] = await buildLines({ records, matches, passes: [], offsetS: 7200, holidays: { nl: new Set(['2026-04-27']) } });
  assert.equal(line.dayType, 'weekend');
  assert.equal(line.dayGroup, dayGroupOf(DAY(2026, 4, 27), new Set(['2026-04-27'])));
  assert.ok(line.dayGroup < DAY_GROUPS.weekend);
});

test('the first and last 500 m of a ride are cut, wherever the ride starts', () => {
  // 1 km due north at 5 m per second: the first and last 100 records are within 500 m.
  const records = Array.from({ length: 201 }, (_, s) => ({ t: s, lat: 52 + s * 5 / 111_320, lon: 5 }));
  const keep = trimEnds(records, 500);
  assert.equal(keep.length, 201);
  assert.equal(keep.filter(Boolean).length, 1, 'a 1 km ride keeps only its middle');
  const long = Array.from({ length: 1001 }, (_, s) => ({ t: s, lat: 52 + s * 5 / 111_320, lon: 5 }));
  const k = trimEnds(long, 500);
  assert.equal(k[99], false);
  assert.equal(k[101], true);
  assert.equal(k[899], true);
  assert.equal(k[901], false);
});

test('records without a fix take the cut of the fixes around them', () => {
  const records = Array.from({ length: 401 }, (_, s) => ({ t: s, lat: s % 2 ? null : 52 + s * 5 / 111_320, lon: s % 2 ? null : 5 }));
  const keep = trimEnds(records, 500);
  assert.equal(keep[51], false, 'a fixless record near the start is cut too');
  assert.equal(keep[201], true);
});

// A fix `east` and `north` metres from 52 N, 5 E.
const at = (t, east, north) => ({ t, lat: 52 + north / 111_320, lon: 5 + east / (111_320 * Math.cos(52 * Math.PI / 180)) });

test('GPS drift at the door is cut however far it wanders along the track', () => {
  // Five minutes of jitter within 40 m of the start: about 2 km along the track.
  const records = [];
  for (let s = 0; s < 300; s++) records.push(at(s, s % 2 ? 40 : -40, s % 3 ? 30 : -30));
  for (let s = 0; s < 400; s++) records.push(at(300 + s, 0, s * 5));   // then 2 km due north
  const keep = trimEnds(records, 500);
  assert.ok(records.slice(0, 300).every((_, i) => !keep[i]), 'no drift record is kept');
  assert.equal(keep[300 + 101], true, 'the ride is kept once it is 500 m from the door');
});

test('a loop near home at the start is cut', () => {
  // A 200 m radius loop through the start (about 1.3 km along the track, never
  // more than 400 m from the door), then away.
  const records = [];
  for (let s = 0; s < 360; s++) {
    const a = s * Math.PI / 180;
    records.push(at(s, 200 * Math.sin(a), 200 - 200 * Math.cos(a)));
  }
  for (let s = 0; s < 400; s++) records.push(at(360 + s, 0, -s * 5));
  const keep = trimEnds(records, 500);
  assert.equal(keep.slice(0, 360).filter(Boolean).length, 0, 'the whole loop lies within 500 m of the start');
});

test('passing the start point in the middle of a ride is cut there too', () => {
  // 3 km west, back east past the door, 3 km east, then north: the door lies mid-ride.
  const records = [];
  let t = 0;
  for (let m = 0; m <= 3000; m += 5) records.push(at(t++, -m, 0));
  for (let m = -3000; m <= 3000; m += 5) records.push(at(t++, m, 0));
  for (let m = 0; m <= 3000; m += 5) records.push(at(t++, 3000, m));
  const keep = trimEnds(records, 500);
  const pass = records.map((r, i) => i).filter(i => i > 601 && i < 601 + 1201);
  const near = pass.filter(i => Math.abs(records[i].lon - 5) * 111_320 * Math.cos(52 * Math.PI / 180) < 499);
  const far = pass.filter(i => Math.abs(records[i].lon - 5) * 111_320 * Math.cos(52 * Math.PI / 180) > 501);
  assert.ok(near.length > 150, 'the ride passes the door');
  assert.ok(near.every(i => !keep[i]), 'every record within 500 m of the start is cut, mid-ride as well');
  assert.ok(far.every(i => keep[i]), 'the rest of the pass is kept');
});

test('the dedupe codes come only from records that are kept', async () => {
  // Block 09:00-09:05: the first 150 s are cut, the rest is kept.
  const { records, matches } = ride({ start: Date.UTC(2026, 9, 5, 9) / 1000, seconds: 300 });
  const keep = records.map((_, i) => i >= 150);
  matches.forEach((m, i) => { if (!keep[i]) Object.assign(m, { way: null, label: null, dir: null }); });
  const [line] = await buildLines({ records, matches, keep, passes: [], offsetS: 0, holidays: {} });
  assert.deepEqual(line.blocks, [await blockCode(Date.UTC(2026, 9, 5, 9) / 1000, records[150])],
    'the code hashes the first kept fix of the block, never a fix in the cut zone');
});
