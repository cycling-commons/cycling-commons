// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 BikeCoders
//
// The traffic summary a rider sends (docs/specs/traffic-measurements.md §3.4).
//
// Built in the browser from the decoded ride and its road-piece matches. A
// line is one road piece, direction, part of the local day and date: how far
// the rider rode on it with the radar on, how many cars passed, and their
// speeds in bands. No position, no time finer than a part of the day and no
// order leave this module: the date travels as a day number for the
// several-days rule, and the dedupe codes are hashes the server can only
// compare.

import { MESG, findDevKey, semiToDeg, fitToDate } from './scout-fit.js';

const SECONDS_PER_BLOCK = 300;
/** A gap longer than this between two fixes is not ridden distance. */
const MAX_STEP_S = 5;
const SPEED_BINS = 16;

/** Local hours at which bands 1-4 start: night, morning rush, day, evening rush, evening. */
const BAND_STARTS = [6, 9, 16, 19];

/**
 * Day groups per day type (traffic-measurements.md §3.4): enough for the
 * several-days rule, few enough that each group of a quarter holds at least
 * four dates of its day type.
 */
export const DAY_GROUPS = Object.freeze({ workday: 12, weekend: 4 });

const pad = n => String(n).padStart(2, '0');

/** The local date of a day number, YYYY-MM-DD. */
function isoOf(day) {
  const d = new Date(day * 86400000);
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
}

/** Saturday, Sunday and the holidays of the ride country count as weekend. */
function isWeekend(day, holidays) {
  const weekday = new Date(day * 86400000).getUTCDay();
  return weekday === 0 || weekday === 6 || Boolean(holidays && holidays.has(isoOf(day)));
}

/**
 * The time key of one moment: part of the local day (band 0-4), day type,
 * quarter of the year and the local day number.
 *
 * @param {number} unixS UTC seconds
 * @param {number} offsetS local offset from UTC, seconds
 * @param {number} _lat unused; kept so callers pass the place as before
 * @param {Set<string>} holidays local dates (YYYY-MM-DD) that count as weekend
 */
export function timeKey(unixS, offsetS, _lat, holidays) {
  const local = unixS + offsetS;
  const d = new Date(local * 1000);
  const day = Math.floor(local / 86400);
  return {
    band: BAND_STARTS.filter(h => d.getUTCHours() >= h).length,
    dayType: isWeekend(day, holidays) ? 'weekend' : 'workday',
    quarter: `${d.getUTCFullYear()}-Q${Math.floor(d.getUTCMonth() / 3) + 1}`,
    day,
  };
}

/**
 * A day as one of the groups of its day type: how many dates of the same day
 * type come before it in its quarter, modulo DAY_GROUPS of that type. A
 * quarter has at least 58 workdays and 26 weekend dates, so each workday group
 * holds at least 4 workdays and each weekend group at least 6 weekend dates,
 * and a group sent in place of the date names none of them. Consecutive dates
 * of one day type fall in different groups, so the several-days rule (§4.4)
 * sees a week of rides as several days.
 *
 * @param {number} day local date as days since 1970-01-01
 * @param {Set<string>|null} holidays local dates (YYYY-MM-DD) that count as weekend
 */
export function dayGroupOf(day, holidays = null) {
  const d = new Date(day * 86400000);
  const first = Date.UTC(d.getUTCFullYear(), Math.floor(d.getUTCMonth() / 3) * 3, 1) / 86400000;
  const weekend = isWeekend(day, holidays);
  let before = 0;
  for (let x = first; x < day; x++) {
    if (isWeekend(x, holidays) === weekend) before++;
  }
  return before % DAY_GROUPS[weekend ? 'weekend' : 'workday'];
}

/**
 * Which records to keep: those at least `cut` metres in a straight line from
 * the ride's first fix and from its last one, wherever in the ride they are.
 * The ends are where a rider lives or works, so nothing near them becomes a
 * line: not GPS drift at the door, not a loop round the block, not a pass by
 * the door halfway. A record without a fix takes the verdict of the last fix
 * before it; one before the first fix is cut.
 *
 * @returns {boolean[]} one per record
 */
export function trimEnds(records, cut = 500) {
  const fixed = r => r.lat != null && r.lon != null;
  const first = records.find(fixed);
  const last = records.findLast(fixed);
  let kept = false;
  return records.map(r => {
    if (fixed(r)) kept = metres(first, r) >= cut && metres(last, r) >= cut;
    return kept;
  });
}

/**
 * The device's local offset, from the FIT activity message (34): its
 * local_timestamp (field 5) minus its timestamp (field 253). Null when the
 * file has none.
 */
export function localOffsetSeconds(parsed) {
  for (const m of (parsed && parsed.messages) || []) {
    if (m.globalNum !== 34) continue;
    const ts = m.fields[253];
    const local = m.fields[5];
    // Rounded to whole quarter hours: every zone is, and a stray second would
    // put slot and five-minute block boundaries out of line.
    if (typeof ts === 'number' && typeof local === 'number') return Math.round((local - ts) / 900) * 900;
  }
  return null;
}

// The zone a ride country keeps its clocks in, for files with no local
// offset. Countries that span zones choose by longitude.
const ZONES = {
  nl: 'Europe/Amsterdam', be: 'Europe/Brussels', de: 'Europe/Berlin', lu: 'Europe/Luxembourg',
  fr: 'Europe/Paris', ch: 'Europe/Zurich', gb: 'Europe/London', ie: 'Europe/Dublin',
  it: 'Europe/Rome', es: (lon, lat) => (lon < -12 && lat < 30 ? 'Atlantic/Canary' : 'Europe/Madrid'),
  si: 'Europe/Ljubljana', rw: 'Africa/Kigali',
  za: 'Africa/Johannesburg', co: 'America/Bogota', cl: 'America/Santiago',
  nz: 'Pacific/Auckland', jp: 'Asia/Tokyo',
  us: lon => (lon < -111 ? 'America/Los_Angeles' : 'America/Denver'),
  ca: lon => (lon < -100 ? 'America/Vancouver' : 'America/Toronto'),
  au: (lon, lat) => (lon < 129 ? 'Australia/Perth'
    : lon < 138 ? (lat > -26 ? 'Australia/Darwin' : 'Australia/Adelaide')
      : lat > -29 ? 'Australia/Brisbane' : lon < 141 ? 'Australia/Adelaide' : 'Australia/Sydney'),
};

/** Offset of a country's zone at one moment and place, seconds; null for an unknown country. */
export function zoneOffsetSeconds(unixS, cc, lon, lat = 0) {
  const z = ZONES[cc];
  const zone = typeof z === 'function' ? z(lon, lat) : z;
  if (!zone) return null;
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
    timeZone: zone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
  }).formatToParts(new Date(unixS * 1000)).map(p => [p.type, p.value]));
  const asUtc = Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour, +parts.minute, +parts.second) / 1000;
  return Math.round(asUtc - unixS);
}

async function sha256Hex(text) {
  const bytes = new TextEncoder().encode(text);
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  return [...new Uint8Array(digest)].map(b => b.toString(16).padStart(2, '0')).join('');
}

/**
 * The dedupe code of one clock-aligned five-minute block: the block and the
 * first fix inside it, at full precision. The same file, however edited or
 * cut, gives the same code; two riders side by side never do.
 */
export function blockCode(blockStartUnix, firstRecord) {
  return sha256Hex(`cc-traffic-block-v1|${blockStartUnix}|${firstRecord.t}|${firstRecord.latSemi}|${firstRecord.lonSemi}`);
}

function metres(a, b) {
  const kx = 111_320 * Math.cos(((a.lat + b.lat) / 2) * Math.PI / 180);
  return Math.hypot((b.lon - a.lon) * kx, (b.lat - a.lat) * 111_320);
}

function carSpeed(pass, record) {
  if (typeof pass.ground === 'number') return pass.ground;
  if (typeof pass.speed === 'number' && pass.speed !== 255 && record && typeof record.kmh === 'number') {
    return pass.speed + record.kmh;
  }
  return null;
}

/**
 * Per-piece summary lines of one ride.
 *
 * @param {object} input
 * @param {Array<{t: number, lat: number|null, lon: number|null, latSemi?: number, lonSemi?: number, kmh: number|null, radar: boolean}>} input.records
 * @param {Array<{way: number|null, label: string|null, dir: string|null, cc?: string|null}>} input.matches one per record
 * @param {boolean[]} [input.keep] one per record, from trimEnds(); a record not kept never feeds a dedupe code
 * @param {Array<{time: Date, speed: number|null, ground: number|null}>} input.passes
 * @param {number} input.offsetS local offset from UTC
 * @param {Object<string, Set<string>>} input.holidays per country code
 */
export async function buildLines({ records, matches, keep = null, passes, offsetS, holidays }) {
  const byKey = new Map();
  const keyOf = i => {
    const m = matches[i];
    const r = records[i];
    if (!m || m.way === null || r.lat == null || r.lon == null) return null;
    const dates = (holidays && holidays[m.cc]) || null;
    const tk = timeKey(r.t, offsetS, r.lat, dates);
    const key = [m.way, m.dir, m.label, tk.band, tk.dayType, tk.quarter, tk.day].join('|');
    if (!byKey.has(key)) {
      byKey.set(key, {
        line: {
          // The region adds nothing the way id does not already say; the
          // curator page counts roads per region by it.
          way: m.way, region: m.region ?? null, dir: m.dir, label: m.label, band: tk.band, dayType: tk.dayType,
          quarter: tk.quarter, dayGroup: dayGroupOf(tk.day, dates),
          // Shown to the rider in "Show what is sent"; never sent (traffic-ride.js wire()).
          day: tk.day,
          distanceM: 0, timeS: 0, passes: 0, nearby: 0, avgSpeedKmh: null, carSpeedBins: null, blocks: [],
        },
        bins: new Array(SPEED_BINS).fill(0),
        speeds: 0,
        blocks: new Set(),
      });
    }
    return key;
  };

  // The first kept fix of every clock block: a fix in the cut zone never
  // feeds a code.
  const firstInBlock = new Map();
  records.forEach((r, i) => {
    if (keep && !keep[i]) return;
    if (r.lat == null || r.lon == null || r.latSemi == null || r.lonSemi == null) return;
    const b = Math.floor(r.t / SECONDS_PER_BLOCK) * SECONDS_PER_BLOCK;
    if (!firstInBlock.has(b)) firstInBlock.set(b, r);
  });

  const keys = records.map((_, i) => keyOf(i));
  for (let i = 0; i < records.length; i++) {
    const key = keys[i];
    if (key === null) continue;
    const entry = byKey.get(key);
    entry.blocks.add(Math.floor(records[i].t / SECONDS_PER_BLOCK) * SECONDS_PER_BLOCK);
    // An interval counts on the later record's line when both fixes are on
    // the same piece in the same direction, so a band boundary loses nothing.
    if (i === 0 || keys[i - 1] === null) continue;
    const pm = matches[i - 1];
    const cm = matches[i];
    if (pm.way !== cm.way || pm.dir !== cm.dir) continue;
    const a = records[i - 1];
    const b = records[i];
    const dt = b.t - a.t;
    if (!a.radar || !b.radar || dt <= 0 || dt > MAX_STEP_S) continue;
    entry.line.distanceM += metres(a, b);
    entry.line.timeS += dt;
  }

  const indexByTime = new Map(records.map((r, i) => [r.t, i]));
  for (const pass of passes || []) {
    if (!(pass.time instanceof Date)) continue;
    const i = indexByTime.get(Math.round(pass.time.getTime() / 1000));
    if (i === undefined || keys[i] === null) continue;
    const entry = byKey.get(keys[i]);
    // No car drives on a cycle path: one the radar saw there drove on the road
    // beside it. Nearby is noise, not safety, and never counts as passing.
    if ('p' === entry.line.label) entry.line.nearby++;
    else entry.line.passes++;
    const v = carSpeed(pass, records[i]);
    if (v !== null && v >= 0) {
      entry.bins[Math.min(SPEED_BINS - 1, Math.floor(v / 10))]++;
      entry.speeds++;
    }
  }

  const out = [];
  for (const entry of byKey.values()) {
    const line = entry.line;
    line.distanceM = Math.round(line.distanceM);
    if (line.timeS < 1 || line.distanceM < 1) continue;
    line.avgSpeedKmh = Math.round(line.distanceM / line.timeS * 3.6 * 10) / 10;
    line.carSpeedBins = entry.speeds > 0 ? entry.bins : null;
    const starts = [...entry.blocks].sort((x, y) => x - y).filter(b => firstInBlock.has(b));
    line.blocks = await Promise.all(starts.map(b => blockCode(b, firstInBlock.get(b))));
    out.push(line);
  }
  return out;
}

/**
 * One record per FIT record message: UTC seconds, position (degrees and the
 * raw semicircles the block codes hash), the rider's speed in km/h, and
 * whether the radar was reporting that second.
 */
export function recordsFromFit(parsed) {
  const radarKey = findDevKey(parsed.devFields, 'radar_count');
  const out = [];
  for (const m of parsed.messages) {
    if (m.globalNum !== MESG.RECORD) continue;
    const ts = m.fields[253];
    if (typeof ts !== 'number') continue;
    const hasFix = typeof m.fields[0] === 'number' && typeof m.fields[1] === 'number';
    const raw = m.fields[73] != null ? m.fields[73] : m.fields[6];
    out.push({
      t: Math.round(fitToDate(ts).getTime() / 1000),
      lat: hasFix ? semiToDeg(m.fields[0]) : null,
      lon: hasFix ? semiToDeg(m.fields[1]) : null,
      latSemi: hasFix ? m.fields[0] : null,
      lonSemi: hasFix ? m.fields[1] : null,
      kmh: typeof raw === 'number' ? raw / 1000 * 3.6 : null,
      radar: radarKey !== null && radarKey !== undefined && m.devFields[radarKey] != null,
    });
  }
  return out;
}
