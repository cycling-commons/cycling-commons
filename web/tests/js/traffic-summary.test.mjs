// SPDX-License-Identifier: AGPL-3.0-only
//
// The traffic summary a rider sends (docs/specs/traffic-measurements.md §3.4):
// the time key, the per-piece lines, and the dedupe codes.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parseFit } from '../../assets/lib/scout-fit.js';
import { timeKey, buildLines, blockCode, localOffsetSeconds, zoneOffsetSeconds, recordsFromFit } from '../../assets/lib/traffic-summary.js';

const NONE = new Set();
// Monday 5 October 2026 17:59:30 UTC.
const MON = Date.UTC(2026, 9, 5, 17, 59, 30) / 1000;

test('the slot is the local quarter hour', () => {
  assert.equal(timeKey(MON, 0, 52, NONE).slot, 71);          // 17:45-18:00
  assert.equal(timeKey(MON + 60, 0, 52, NONE).slot, 72);     // 18:00-18:15
  assert.equal(timeKey(MON, 7200, 52, NONE).slot, 79);       // 19:59:30 local
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
  assert.equal(k.slot, 2);
  assert.equal(k.day, Math.floor((Date.UTC(2026, 9, 9, 22, 30) / 1000 + 7200) / 86400));
});

test('seasons are meteorological and flip south of the equator', () => {
  const jan = Date.UTC(2026, 0, 15, 12) / 1000;
  assert.equal(timeKey(jan, 0, 52, NONE).season, 'winter');
  assert.equal(timeKey(jan, 0, -33, NONE).season, 'summer');
  assert.equal(timeKey(Date.UTC(2026, 3, 15) / 1000, 0, 52, NONE).season, 'spring');
  assert.equal(timeKey(Date.UTC(2026, 3, 15) / 1000, 0, -33, NONE).season, 'autumn');
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

test('a ride across a quarter hour becomes two lines', async () => {
  const { records, matches } = ride();
  const lines = await buildLines({ records, matches, passes: [], offsetS: 0, holidays: {} });
  assert.deepEqual(lines.map(l => l.slot).sort(), [71, 72]);
  const total = lines.reduce((m, l) => m + l.distanceM, 0);
  assert.ok(Math.abs(total - 119 * 5) < 2, `distance ${total}`);
  for (const l of lines) {
    assert.equal(l.way, 7);
    assert.equal(l.dir, 'f');
    assert.equal(l.label, 'r');
    assert.equal(l.dayType, 'workday');
    assert.equal(l.season, 'autumn');
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

test('a fifteen-minute line carries the codes of its three five-minute blocks', async () => {
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
  assert.deepEqual(Object.keys(lines[0]).sort(), ['avgSpeedKmh', 'blocks', 'carSpeedBins', 'day', 'dayType', 'dir', 'distanceM', 'label', 'nearby', 'passes', 'quarter', 'region', 'season', 'slot', 'timeS', 'way']);
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
