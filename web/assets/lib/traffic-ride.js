// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 BikeCoders
//
// One ride into its traffic summary, and the summary onto the wire
// (docs/specs/traffic-measurements.md §3). Used by the single-ride step and by
// bulk mode alike. The ride is decoded and matched here, in the browser; what
// leaves is the list of summary lines, shuffled, in requests of whole rides.

import { countVehicles } from './scout-fit.js';
import { loadPieces } from './road-pieces.js';
import { matchRide, riddenParts } from './traffic-match.js';
import { buildLines, recordsFromFit, localOffsetSeconds, zoneOffsetSeconds, trimEnds } from './traffic-summary.js';

/** Lines a request aims for; a ride is never split to meet it. */
export const CHUNK_LINES = 500;
/** The server's limit per request (TrafficIntake::MAX_LINES). */
export const CHUNK_MAX = 2000;

function metres(a, b) {
  const kx = 111_320 * Math.cos(((a.lat + b.lat) / 2) * Math.PI / 180);
  return Math.hypot((b.lon - a.lon) * kx, (b.lat - a.lat) * 111_320);
}

/**
 * The parts of a ride with no road, longest first, each with its box, for the
 * review's eye to visit one at a time.
 *
 * @param {Array<Array<[number, number]>>} lines [lon, lat] runs
 * @returns {Array<{metres: number, bounds: [[number, number], [number, number]]}>}
 */
export function unmatchedStops(lines) {
  return (lines || [])
    .filter(line => line.length)
    .map(line => {
      let m = 0;
      for (let i = 1; i < line.length; i++) {
        m += metres({ lon: line[i - 1][0], lat: line[i - 1][1] }, { lon: line[i][0], lat: line[i][1] });
      }
      const lons = line.map(c => c[0]);
      const lats = line.map(c => c[1]);
      return { metres: m, bounds: [[Math.min(...lons), Math.min(...lats)], [Math.max(...lons), Math.max(...lats)]] };
    })
    .sort((a, b) => b.metres - a.metres);
}

/** Holidays from the static per-country files; a country without one has none. */
const holidayCache = new Map();
export async function fetchHolidays(cc) {
  if (!holidayCache.has(cc)) {
    holidayCache.set(cc, fetch('/data/holidays/' + encodeURIComponent(cc) + '.json', { credentials: 'same-origin' })
      .then(r => (r.ok ? r.json() : { dates: [] }))
      .then(doc => new Set(Array.isArray(doc.dates) ? doc.dates : []))
      .catch(() => new Set()));
  }
  return holidayCache.get(cc);
}

/** Metres cut from each end of a ride: where a rider lives or works. */
export const TRIM_M = 500;

/**
 * The traffic summary of one decoded FIT ride, or null when it has no radar data.
 *
 * @param {object} fit parseFit() output
 * @param {{entries: object, fetchTile?: Function, fetchHolidays?: Function, trimM?: number}} deps
 * @returns {Promise<null|{lines: Array, cars: number, matchedKm: number, unmatchedKm: number, trimmedKm: number, year: number|null, matched: Array}>}
 */
export async function summariseRide(fit, { entries, fetchTile, fetchHolidays: holidaysFor = fetchHolidays, trimM = TRIM_M }) {
  const radar = countVehicles(fit);
  if (!radar || !radar.covered) return null;

  const records = recordsFromFit(fit);
  const points = records.filter(r => r.lat != null).map(r => [r.lon, r.lat]);
  const { pieces } = await loadPieces(points, entries, fetchTile);
  // The first and last metres never become lines: a cut record counts as no
  // road, but is not "unmatched" either.
  const keep = trimEnds(records, trimM);
  const matches = matchRide(records, pieces).map((m, i) => (keep[i] ? m : { ...m, way: null, label: null, dir: null }));

  const ccs = [...new Set(matches.map(m => m.cc).filter(Boolean))];
  const holidays = {};
  for (const cc of ccs) holidays[cc] = await holidaysFor(cc);

  const first = records.find(r => r.lat != null);
  const counts = new Map();
  matches.forEach(m => { if (m.cc) counts.set(m.cc, (counts.get(m.cc) || 0) + 1); });
  const mainCc = [...counts.entries()].sort((a, b) => b[1] - a[1])[0];
  const offsetS = localOffsetSeconds(fit)
    ?? (first && mainCc ? zoneOffsetSeconds(first.t, mainCc[0], first.lon, first.lat) : null)
    ?? 0;

  const lines = await buildLines({ records, matches, passes: radar.passes, offsetS, holidays });

  // Per car, in the radar's order: passed the rider, only nearby (beside a
  // cycle path), or null on a part with no road. For the map markers only.
  const recordAt = new Map(records.map((r, i) => [r.t, i]));
  const passKinds = (radar.passes || []).map(pass => {
    if (!(pass.time instanceof Date)) return null;
    const i = recordAt.get(Math.round(pass.time.getTime() / 1000));
    const m = i === undefined ? null : matches[i];
    if (!m || m.way === null) return null;
    return 'p' === m.label ? 'nearby' : 'passed';
  });

  // Radar-on distance that found no road piece: told to the rider and drawn on
  // the map, never sent. Each unbroken stretch is one line.
  let unmatched = 0;
  let trimmed = 0;
  const unmatchedLines = [];
  let run = null;
  for (let i = 1; i < records.length; i++) {
    const a = records[i - 1];
    const b = records[i];
    if (a.lat != null && b.lat != null && !(keep[i - 1] && keep[i])) {
      trimmed += metres(a, b);
      run = null;
      continue;
    }
    const off = a.lat != null && b.lat != null && a.radar && b.radar && b.t - a.t <= 5 && matches[i].way === null;
    if (!off) { run = null; continue; }
    unmatched += metres(a, b);
    if (!run) { run = [[a.lon, a.lat]]; unmatchedLines.push(run); }
    run.push([b.lon, b.lat]);
  }

  return {
    lines,
    cars: lines.reduce((n, l) => n + l.passes, 0),
    // Cars beside a cycle path, on the road next to it: not passing the rider.
    nearby: lines.reduce((n, l) => n + l.nearby, 0),
    // Every car the radar counted, matched or not: the review's own total.
    allCars: radar.total,
    matchedKm: lines.reduce((m, l) => m + l.distanceM, 0) / 1000,
    unmatchedKm: unmatched / 1000,
    // The cut ends, told to the rider; never drawn, never sent.
    trimmedKm: trimmed / 1000,
    unmatchedLines,
    passKinds,
    year: first ? new Date(first.t * 1000).getUTCFullYear() : null,
    // Only the stretch of each way the ride was on: drawn on the map, never sent.
    matched: riddenParts(records, pieces, matches),
  };
}

function shuffled(items) {
  const out = items.slice();
  const rand = new Uint32Array(out.length);
  crypto.getRandomValues(rand);
  for (let i = out.length - 1; i > 0; i--) {
    const j = rand[i] % (i + 1);
    [out[i], out[j]] = [out[j], out[i]];
  }
  return out;
}

/** Lines that share a dedupe code, directly or through a neighbour: one ride, in practice. */
function groupsByCode(lines) {
  const parent = lines.map((_, i) => i);
  const find = i => { while (parent[i] !== i) { parent[i] = parent[parent[i]]; i = parent[i]; } return i; };
  const owner = new Map();
  lines.forEach((line, i) => {
    for (const code of line.blocks) {
      if (owner.has(code)) parent[find(i)] = find(owner.get(code));
      else owner.set(code, i);
    }
  });
  const groups = new Map();
  lines.forEach((line, i) => {
    const root = find(i);
    if (!groups.has(root)) groups.set(root, []);
    groups.get(root).push(line);
  });
  return [...groups.values()];
}

/**
 * Requests to send, each a whole number of rides. Lines that share a dedupe
 * code always travel together: the server counts a line as a duplicate once
 * another request claimed one of its codes, so a ride split over two requests
 * would lose its second half. Rides are shuffled before packing and lines
 * inside each request, so neither order says anything about when or where.
 *
 * @returns {Array<{lines: Array, sent: boolean}>}
 */
export function makeChunks(lines) {
  const chunks = [];
  let current = [];
  for (const group of shuffled(groupsByCode(lines))) {
    if (current.length && current.length + group.length > CHUNK_LINES) {
      chunks.push(current);
      current = [];
    }
    // A group past the server's limit has to be cut; a single ride never is.
    for (let i = 0; i < group.length; i += CHUNK_MAX) {
      const part = group.slice(i, i + CHUNK_MAX);
      if (current.length && current.length + part.length > CHUNK_MAX) {
        chunks.push(current);
        current = [];
      }
      current.push(...part);
    }
  }
  if (current.length) chunks.push(current);
  return chunks.map(c => ({ lines: shuffled(c), sent: false }));
}

/**
 * Sends the requests not yet acknowledged, in order, and marks each that the
 * server accepted. Calling it again after a failure resends only the rest;
 * every request is idempotent on the server, so nothing counts twice either way.
 * A line leaves without its date: the date stays in the browser for "Show what
 * is sent", and the server gets only the day group (traffic-summary.js).
 *
 * @param {Array<{lines: Array, sent: boolean}>} chunks from makeChunks()
 * @param {(body: {v: number, lines: Array}) => Promise<{ok: boolean, added?: number, duplicate?: number, dropped?: number, error?: string}>} post
 * @param {(part: number, total: number) => void} [onProgress]
 */
export async function sendChunks(chunks, post, onProgress = () => {}) {
  const total = { ok: true, added: 0, duplicate: 0, dropped: 0, error: null };
  const pending = chunks.filter(c => !c.sent);
  for (let i = 0; i < pending.length; i++) {
    onProgress(i + 1, pending.length);
    let res;
    try {
      res = await post({ v: 2, lines: pending[i].lines.map(wire) });
    } catch (e) {
      res = { ok: false, error: 'network' };
    }
    if (!res || !res.ok) {
      total.ok = false;
      total.error = (res && res.error) || 'failed';
      return total;
    }
    pending[i].sent = true;
    total.added += res.added || 0;
    total.duplicate += res.duplicate || 0;
    total.dropped += res.dropped || 0;
  }
  return total;
}

/** A line as sent: everything but the date, which never leaves the browser. */
function wire(line) {
  const { day, ...sent } = line;
  return sent;
}

/** The POST the review uses: same-origin, the stateless token in a header. */
export async function postTraffic(body) {
  const res = await fetch('/scout/traffic', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CC-Token': window.CC_TRAFFIC_TOKEN || '' },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  return res.ok ? { ok: true, ...data } : { ok: false, error: data.error || String(res.status) };
}
