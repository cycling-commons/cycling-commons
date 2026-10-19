// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 BikeCoders
//
// A felt-safety answer (docs/specs/traffic-measurements.md §10): safe or
// unsafe, for one or more parts of the day, day types and quarters of the last
// year, for one road piece or several picked together with Ctrl+click. Pure, so node can test it; the map mode is
// assets/map/road-safety.js.
//
// The part of the day, the day type and the quarter are the traffic
// summary's (§3.4, timeKey()), so a mark and a measured line of the same road
// fall in the same groups.

import { timeKey } from './traffic-summary.js';
import { PIECE_ZOOM } from './road-pieces.js';

export const FELT = Object.freeze(['safe', 'unsafe']);
const LABELS = ['p', 'l', 'r'];
const DAY_TYPES = ['workday', 'weekend'];
const BANDS = 5;
/** This quarter and the three before it: the last year. */
export const QUARTERS = 4;
/** The most roads one answer covers (SafetyMark::MAX_ROADS). */
export const MAX_ROADS = 50;
/** The most marks one answer makes, roads times the times ticked (SafetyMark::MAX_MARKS). */
export const MAX_MARKS = 200;

const quarterIndex = q => {
  const m = /^(\d{4})-Q([1-4])$/.exec(String(q));
  return m ? Number(m[1]) * 4 + Number(m[2]) - 1 : null;
};
const quarterOf = i => `${Math.floor(i / 4)}-Q${(i % 4) + 1}`;

/** The quarters a rider may choose, newest first, from `quarter` back. */
export function quarterOptions(quarter, n = QUARTERS) {
  const i = quarterIndex(quarter);
  return i === null ? [] : Array.from({ length: n }, (_, k) => quarterOf(i - k));
}

/**
 * The time a mark starts on: now, on the rider's clock.
 *
 * @param {number} unixS UTC seconds
 * @param {number} offsetS the rider's offset from UTC, seconds
 * @param {Set<string>|null} holidays local dates (YYYY-MM-DD) of the road's country that count as weekend
 */
export function markDefaults(unixS, offsetS, holidays) {
  const { band, dayType, quarter } = timeKey(unixS, offsetS, 0, holidays);
  return { band, dayType, quarter };
}

/**
 * The road piece a map feature is: its way (the feature id), its label, the
 * region the tile names and the country of its tile's source layer.
 *
 * @returns {{way: number, label: string, region: number|null, cc: string|null}|null}
 */
export function pieceOf(feature, sourceLayer) {
  const way = feature && feature.id;
  const p = (feature && feature.properties) || {};
  if (!Number.isInteger(way) || way < 1 || !LABELS.includes(p.l)) return null;
  const m = /^roadpieces_([a-z]{2})$/.exec(String(sourceLayer || ''));
  return { way, label: p.l, region: Number.isInteger(p.g) && p.g > 0 ? p.g : null, cc: m ? m[1] : null };
}

/**
 * The roads picked after a click on `piece`: a plain click picks that road
 * alone, a Ctrl+click (`add`) adds it, or takes it away when it is picked
 * already. Never more than MAX_ROADS.
 */
export function pickPiece(picked, piece, add) {
  if (!piece) return picked;
  if (!add) return [piece];
  if (picked.some(p => p.way === piece.way)) return picked.filter(p => p.way !== piece.way);
  return picked.length >= MAX_ROADS ? picked : [...picked, piece];
}

/** How many of the picked roads carry each label. */
export function labelCounts(picked) {
  const out = { p: 0, l: 0, r: 0 };
  for (const p of picked) if (p.label in out) out[p.label] += 1;
  return out;
}

/** Whether a list holds one or more values, each once and each `ok`. */
const ticked = (list, ok) => Array.isArray(list) && list.length > 0 && new Set(list).size === list.length && list.every(ok);

/** How many marks an answer makes: every picked road at every part of the day, day type and quarter ticked. */
export const markCount = (pieces, { bands, dayTypes, quarters }) =>
  (pieces || []).length * (bands || []).length * (dayTypes || []).length * (quarters || []).length;

/** The request body for POST /map/road-safety, or null while the answer is not complete or makes more than MAX_MARKS. */
export function markPayload(pieces, { bands, dayTypes, quarters, felt }) {
  if (!Array.isArray(pieces) || !pieces.length || pieces.length > MAX_ROADS || !FELT.includes(felt)
    || !ticked(bands, b => Number.isInteger(b) && b >= 0 && b < BANDS)
    || !ticked(dayTypes, d => DAY_TYPES.includes(d))
    || !ticked(quarters, q => quarterIndex(q) !== null)
    || markCount(pieces, { bands, dayTypes, quarters }) > MAX_MARKS) return null;
  return { v: 3, roads: pieces.map(p => ({ way: p.way, label: p.label, region: p.region })), bands, dayTypes, quarters, felt };
}

/** The most tiles read to fill the roads between two clicks: 7 by 7 at zoom 14, some 15 km across. */
const FILL_TILES = 49;

/**
 * The centre of every zoom-14 tile in the box of two clicks, with one tile to
 * spare around it, as [lon, lat]; null when the box takes more than FILL_TILES
 * (too far apart to fill). loadPieces() reads the tiles under these points.
 */
export function tilePoints(a, b, zoom = PIECE_ZOOM) {
  const n = 2 ** zoom;
  const tx = lon => Math.floor((lon + 180) / 360 * n);
  const ty = lat => { const r = lat * Math.PI / 180; return Math.floor((1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2 * n); };
  const x0 = Math.min(tx(a[0]), tx(b[0])) - 1, x1 = Math.max(tx(a[0]), tx(b[0])) + 1;
  const y0 = Math.min(ty(a[1]), ty(b[1])) - 1, y1 = Math.max(ty(a[1]), ty(b[1])) + 1;
  if ((x1 - x0 + 1) * (y1 - y0 + 1) > FILL_TILES) return null;
  const out = [];
  for (let x = x0; x <= x1; x++) {
    for (let y = y0; y <= y1; y++) {
      out.push([(x + 0.5) / n * 360 - 180, Math.atan(Math.sinh(Math.PI * (1 - 2 * (y + 0.5) / n))) * 180 / Math.PI]);
    }
  }
  return out;
}

/**
 * The roads of one label between two picked roads (owner 2026-10-19: "on the
 * second ctrl+click it also takes all the roads in between those 2 clicks of
 * the same type"): the shortest run of touching ways, by length, from way
 * `from` to way `to`, both ends included, as way ids. Two ways touch where
 * they share a vertex; the parts of one way clipped by tile edges are one
 * road. Null when either road is not of that label in `pieces`, nothing joins
 * them, or the run takes more than `maxWays`.
 *
 * @param {Array<{id: number, label: string, coords: Array<[number, number]>}>} pieces decodePieces() output
 */
export function chainBetween(pieces, from, to, label, maxWays = MAX_ROADS) {
  const len = new Map();
  const at = new Map();
  for (const p of pieces) {
    if (p.label !== label || !Array.isArray(p.coords)) continue;
    let m = len.get(p.id) || 0;
    p.coords.forEach((c, i) => {
      const k = c[0].toFixed(6) + ',' + c[1].toFixed(6);
      if (!at.has(k)) at.set(k, new Set());
      at.get(k).add(p.id);
      if (i) {
        const q = p.coords[i - 1];
        const dx = (c[0] - q[0]) * 111320 * Math.cos(c[1] * Math.PI / 180), dy = (c[1] - q[1]) * 110540;
        m += Math.hypot(dx, dy);
      }
    });
    len.set(p.id, m);
  }
  if (!len.has(from) || !len.has(to)) return null;
  const next = new Map();
  for (const ways of at.values()) {
    if (ways.size < 2) continue;
    for (const w of ways) {
      if (!next.has(w)) next.set(w, new Set());
      for (const v of ways) if (v !== w) next.get(w).add(v);
    }
  }
  const dist = new Map([[from, 0]]);
  const prev = new Map();
  const open = new Set([from]);
  while (open.size) {
    let w = null;
    for (const v of open) if (w === null || dist.get(v) < dist.get(w)) w = v;
    open.delete(w);
    if (w === to) break;
    for (const v of next.get(w) || []) {
      const d = dist.get(w) + len.get(v);
      if (!dist.has(v) || d < dist.get(v)) { dist.set(v, d); prev.set(v, w); open.add(v); }
    }
  }
  if (!dist.has(to)) return null;
  const run = [to];
  while (run[run.length - 1] !== from) run.push(prev.get(run[run.length - 1]));
  return run.length > maxWays ? null : run.reverse();
}
