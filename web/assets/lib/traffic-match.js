// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 BikeCoders
//
// Matching a ride to road pieces (docs/specs/traffic-measurements.md §3.3).
//
// Runs in the browser on the decoded ride; nothing here is sent anywhere. Each
// fix is placed on the nearest road piece within reach, with three corrections
// for what GPS gets wrong: a heading that disagrees with the piece costs
// extra (so a crossing street is not taken), leaving the previous piece costs
// a little (so one jittery fix does not hop), and a road that says a separate
// cycle path runs beside it loses to that path. Runs shorter than a block are
// then handed to a neighbour or dropped.

export const REACH_M = 20;
const HEADING_PENALTY_M = 15;
const HEADING_LIMIT_DEG = 45;
const SWITCH_PENALTY_M = 5;
const SIDEPATH_PARALLEL_DEG = 30;
const MIN_RUN_M = 100;
const MIN_HEADING_KMH = 5;
/** Movement back along a way, past this, starts a new leg (an out-and-back). */
const TURN_BACK_M = 30;
const CELL_M = 50;
/** A drawn part runs this far past the first and last fix on it: fixes come a few metres apart, so this closes the gap at a junction. */
const PART_PAD_M = 10;

/** An equirectangular frame in metres around the ride's mean latitude. */
function frame(records) {
  let lat0 = 0;
  let lon0 = 0;
  let n = 0;
  for (const r of records) {
    if (r.lat == null || r.lon == null) continue;
    lat0 += r.lat; lon0 += r.lon; n++;
  }
  lat0 = n ? lat0 / n : 0;
  lon0 = n ? lon0 / n : 0;
  const kx = 111_320 * Math.cos(lat0 * Math.PI / 180);
  const ky = 111_320;
  return ([lon, lat]) => [(lon - lon0) * kx, (lat - lat0) * ky];
}

function bearing(ax, ay, bx, by) {
  return Math.atan2(bx - ax, by - ay) * 180 / Math.PI;
}

/** Smallest angle between two undirected lines, 0..90. */
function lineAngle(a, b) {
  let d = Math.abs(a - b) % 180;
  if (d > 90) d = 180 - d;
  return d;
}

function buildIndex(pieces, project) {
  const segs = [];
  pieces.forEach((p, pi) => {
    const pts = p.coords.map(project);
    let along = 0;
    for (let i = 1; i < pts.length; i++) {
      const [ax, ay] = pts[i - 1];
      const [bx, by] = pts[i];
      const len = Math.hypot(bx - ax, by - ay);
      if (len > 0) segs.push({ pi, ax, ay, bx, by, len, along, brg: bearing(ax, ay, bx, by) });
      along += len;
    }
  });
  const grid = new Map();
  const key = (cx, cy) => cx + ',' + cy;
  segs.forEach((s, si) => {
    const x0 = Math.floor((Math.min(s.ax, s.bx) - REACH_M) / CELL_M);
    const x1 = Math.floor((Math.max(s.ax, s.bx) + REACH_M) / CELL_M);
    const y0 = Math.floor((Math.min(s.ay, s.by) - REACH_M) / CELL_M);
    const y1 = Math.floor((Math.max(s.ay, s.by) + REACH_M) / CELL_M);
    for (let cx = x0; cx <= x1; cx++) {
      for (let cy = y0; cy <= y1; cy++) {
        const k = key(cx, cy);
        if (!grid.has(k)) grid.set(k, []);
        grid.get(k).push(si);
      }
    }
  });
  return { segs, near: (x, y) => grid.get(key(Math.floor(x / CELL_M), Math.floor(y / CELL_M))) || [] };
}

/** Nearest point of one segment: distance, position along the piece part. */
function onSegment(s, x, y) {
  const dx = s.bx - s.ax;
  const dy = s.by - s.ay;
  let t = ((x - s.ax) * dx + (y - s.ay) * dy) / (s.len * s.len);
  t = Math.max(0, Math.min(1, t));
  const px = s.ax + t * dx;
  const py = s.ay + t * dy;
  return { d: Math.hypot(x - px, y - py), along: s.along + t * s.len };
}

/** Best candidate per piece id within reach of (x, y). */
function candidates(index, pieces, x, y) {
  const best = new Map();
  for (const si of index.near(x, y)) {
    const s = index.segs[si];
    const hit = onSegment(s, x, y);
    if (hit.d > REACH_M) continue;
    const id = pieces[s.pi].id;
    const prev = best.get(id);
    if (!prev || hit.d < prev.d) best.set(id, { id, piece: pieces[s.pi], pi: s.pi, d: hit.d, along: hit.along, brg: s.brg });
  }
  return [...best.values()];
}

/**
 * One entry per record: the way it is placed on, that way's label, and the
 * direction the run rode it in ('f' along the way's drawing, 'b' against it).
 * Unplaced records are `{way: null, label: null, dir: null, cc: null}`. `cc` is
 * the country of the archive the piece came from, for holidays and the clock.
 *
 * @param {Array<{t: number, lat: number|null, lon: number|null, kmh: number|null}>} records
 * @param {Array<{id: number, label: string, side: boolean, coords: Array<[number, number]>}>} pieces
 */
export function matchRide(records, pieces) {
  const empty = () => ({ way: null, label: null, dir: null, cc: null });
  if (!pieces.length) return records.map(empty);
  const project = frame(records);
  const index = buildIndex(pieces, project);
  const xy = records.map(r => (r.lat == null || r.lon == null ? null : project([r.lon, r.lat])));

  const heading = i => {
    if (!xy[i] || records[i].kmh == null || records[i].kmh < MIN_HEADING_KMH) return null;
    let a = i - 1;
    while (a >= 0 && !xy[a]) a--;
    let b = i + 1;
    while (b < xy.length && !xy[b]) b++;
    const from = a >= 0 ? xy[a] : xy[i];
    const to = b < xy.length ? xy[b] : xy[i];
    if (Math.hypot(to[0] - from[0], to[1] - from[1]) < 3) return null;
    return bearing(from[0], from[1], to[0], to[1]);
  };

  // 1. Place each fix.
  const placed = new Array(records.length).fill(null);
  let prevId = null;
  for (let i = 0; i < records.length; i++) {
    if (!xy[i]) continue;
    const cands = candidates(index, pieces, xy[i][0], xy[i][1]);
    if (!cands.length) { prevId = null; continue; }
    const h = heading(i);
    let best = null;
    for (const c of cands) {
      let score = c.d;
      if (h !== null && lineAngle(h, c.brg) > HEADING_LIMIT_DEG) score += HEADING_PENALTY_M;
      if (prevId !== null && c.id !== prevId) score += SWITCH_PENALTY_M;
      if (!best || score < best.score) best = { ...c, score };
    }
    if (best.piece.label !== 'p' && best.piece.side) {
      const paths = cands.filter(c => c.piece.label === 'p' && lineAngle(c.brg, best.brg) <= SIDEPATH_PARALLEL_DEG);
      if (paths.length) best = paths.reduce((a, b) => (b.d < a.d ? b : a));
    }
    placed[i] = best;
    prevId = best.id;
  }

  // 2. Runs shorter than a block go to a neighbour within reach, or are dropped.
  const runs = [];
  for (let i = 0; i < placed.length; i++) {
    if (!placed[i]) continue;
    const last = runs[runs.length - 1];
    if (last && last.id === placed[i].id && last.end === i - 1) { last.end = i; continue; }
    if (last && last.id === placed[i].id) {
      // Same way after unplaced records only: one run.
      let gapFree = true;
      for (let k = last.end + 1; k < i; k++) if (placed[k]) gapFree = false;
      if (gapFree) { last.end = i; continue; }
    }
    runs.push({ id: placed[i].id, start: i, end: i });
  }
  const runLength = run => {
    let m = 0;
    let prev = null;
    for (let k = run.start; k <= run.end; k++) {
      if (!xy[k]) continue;
      if (prev) m += Math.hypot(xy[k][0] - prev[0], xy[k][1] - prev[1]);
      prev = xy[k];
    }
    return m;
  };
  runs.forEach((run, ri) => {
    if (runLength(run) >= MIN_RUN_M) return;
    // A short run is wobble only when the same piece lies before and after it
    // (a fix onto a parallel road, a crossing street passed over). Between two
    // different pieces it is road ridden: OSM cuts streets at every junction.
    const prevRun = runs[ri - 1];
    const nextRun = runs[ri + 1];
    if (!prevRun || !nextRun || prevRun.id !== nextRun.id) return;
    const neighbours = [prevRun];
    for (let k = run.start; k <= run.end; k++) {
      if (!placed[k]) continue;
      let moved = null;
      for (const nb of neighbours) {
        const c = candidates(index, pieces, xy[k][0], xy[k][1]).find(c => c.id === nb.id);
        if (c) { moved = c; break; }
      }
      placed[k] = moved;
    }
  });

  // 3. Direction per leg of one way: a run is cut where the rider turns back
  // along it, and each leg takes the direction its position moved in.
  const out = records.map(empty);
  const assign = (from, to, id) => {
    let drift = 0;
    let prev = null;
    for (let k = from; k <= to; k++) {
      if (!placed[k]) continue;
      if (prev && placed[k].pi === prev.pi) drift += placed[k].along - prev.along;
      prev = placed[k];
    }
    const dir = drift >= 0 ? 'f' : 'b';
    for (let k = from; k <= to; k++) {
      if (placed[k]) {
        out[k] = {
          way: id, label: placed[k].piece.label, dir, cc: placed[k].piece.cc ?? null,
          region: placed[k].piece.region ?? null, pi: placed[k].pi, at: placed[k].along,
        };
      }
    }
  };
  let i = 0;
  while (i < placed.length) {
    if (!placed[i]) { i++; continue; }
    const id = placed[i].id;
    let j = i;
    while (j + 1 < placed.length && (!placed[j + 1] || placed[j + 1].id === id)) j++;
    while (j > i && !placed[j]) j--;
    let legStart = i;
    let sign = 0;
    let extreme = placed[i].along;
    let prev = placed[i];
    for (let k = i + 1; k <= j; k++) {
      const cur = placed[k];
      if (!cur || cur.pi !== prev.pi) { if (cur) prev = cur; continue; }
      const moved = cur.along - extreme;
      if (sign === 0) {
        if (Math.abs(moved) > TURN_BACK_M / 3) { sign = Math.sign(moved); extreme = cur.along; }
      } else if (sign * moved > 0) {
        extreme = cur.along;
      } else if (sign * moved < -TURN_BACK_M) {
        assign(legStart, k - 1, id);
        legStart = k;
        sign = -sign;
        extreme = cur.along;
      }
      prev = cur;
    }
    assign(legStart, j, id);
    i = j + 1;
  }
  return out;
}

/** The point `d` metres along a line whose cumulative lengths are `cum`. */
function pointAt(coords, cum, d) {
  let i = 0;
  while (i < cum.length - 2 && cum[i + 1] < d) i++;
  const span = cum[i + 1] - cum[i];
  const t = span > 0 ? Math.max(0, Math.min(1, (d - cum[i]) / span)) : 0;
  return [coords[i][0] + t * (coords[i + 1][0] - coords[i][0]), coords[i][1] + t * (coords[i + 1][1] - coords[i][1])];
}

/**
 * The parts of each piece the ride was on, for the map: a way is drawn from
 * the first to the last fix matched to it (plus a few metres), never whole,
 * so a street the rider turned off halfway does not run on past the turn.
 *
 * @param {Array<{t: number, lat: number|null, lon: number|null}>} records the ride, as given to matchRide()
 * @param {Array<{id: number, label: string, coords: Array<[number, number]>}>} pieces
 * @param {Array<{way: number|null, pi?: number, at?: number}>} matches matchRide()'s result
 * @returns {Array<{id: number, label: string, coords: Array<[number, number]>}>}
 */
export function riddenParts(records, pieces, matches, padM = PART_PAD_M) {
  const project = frame(records);
  const ranges = new Map();
  let cur = null;
  matches.forEach(m => {
    if (!m || m.way === null || m.pi == null) { cur = null; return; }
    if (cur && cur.pi === m.pi) { cur.a = Math.min(cur.a, m.at); cur.b = Math.max(cur.b, m.at); return; }
    cur = { pi: m.pi, a: m.at, b: m.at };
    if (!ranges.has(m.pi)) ranges.set(m.pi, []);
    ranges.get(m.pi).push(cur);
  });
  const parts = [];
  ranges.forEach((list, pi) => {
    const piece = pieces[pi];
    const pts = piece.coords.map(project);
    const cum = [0];
    for (let i = 1; i < pts.length; i++) cum.push(cum[i - 1] + Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]));
    const total = cum[cum.length - 1];
    const merged = [];
    list.slice().sort((x, y) => x.a - y.a).forEach(r => {
      const a = Math.max(0, r.a - padM);
      const b = Math.min(total, r.b + padM);
      const last = merged[merged.length - 1];
      if (last && a <= last.b) last.b = Math.max(last.b, b);
      else merged.push({ a, b });
    });
    merged.forEach(({ a, b }) => {
      const coords = [pointAt(piece.coords, cum, a)];
      for (let i = 0; i < cum.length; i++) if (cum[i] > a && cum[i] < b) coords.push(piece.coords[i]);
      coords.push(pointAt(piece.coords, cum, b));
      parts.push({ id: piece.id, label: piece.label, coords });
    });
  });
  return parts;
}
