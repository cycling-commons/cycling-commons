// SPDX-License-Identifier: AGPL-3.0-only
/* Gravel hints (docs/specs/route-planner.md section 4.2b). The routing engine rides the fastest way, and asphalt is
   always fast, so a gravel or mountain bike rides asphalt wherever asphalt is as quick. The planner reads the gravel
   and dirt roads near a leg from the road-surface tiles, picks the longest stretches that lie on the way, and asks
   the engine for the leg through points on them; the server keeps the answer only when it rides clearly more
   unpaved road for little more distance. This module is the choosing: no DOM, no map, no network of its own (the
   tile reader is passed in), so node can test it. */
import { tilesForTrack, decodeLines, pmtilesTile } from '../lib/road-pieces.js';

export const GRAVEL_HINTS = {
  /* Raised whenever the choosing changes: a leg checked by an older version is looked at again. */
  version: 7,
  zoom: 11,             // the surface tiles keep every road at every zoom (pipeline/coverage/tiles.py), so z11 is enough
  maxLegM: 80000,       // a longer leg reads too many tiles; it keeps the engine's way
  minStretchM: 600,     // shorter gravel is not worth a detour
  joinM: 15,            // line ends this close are one junction
  onBaseM: 30,          // a stretch this close to the engine's way is already ridden
  deadEndM: 150,        // no through-point this close to a dead end: the leg would ride down and back up
  maxCandidates: 6,
  maxVias: 6,
};

/* The surface classes a bike seeks out (coverage-contract.json surface.classes). Never sand for a gravel bike: the
   Veluwe's sand tracks are heavy going on narrow tyres (the owner's ride across the Ederheide, 2026-10-25). */
const SEEK = { Gravel: ['gravel', 'dirt'], MTB: ['gravel', 'dirt', 'sand', 'rock'] };
export const seeksUnpaved = bike => Object.prototype.hasOwnProperty.call(SEEK, bike);

/* Metres on a local flat projection around `lat0`: good to a few metres over a leg. */
const K = 111320;
const flat = (p, lat0) => [p[0] * K * Math.cos(lat0 * Math.PI / 180), p[1] * K];
const dist = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1]);

/** How far the hints look from the engine's way: 12% of the leg, at least 800 m, at most 3 km. */
export const corridorM = legM => Math.min(3000, Math.max(800, legM * 0.12));

/* A line as flat points plus the running length at each point. */
function measured(coords, lat0) {
  const pts = coords.map(c => flat(c, lat0));
  const at = [0];
  for (let i = 1; i < pts.length; i++) at.push(at[i - 1] + dist(pts[i - 1], pts[i]));
  return { pts, at, length: at[at.length - 1] };
}

/* A line's points and, on a longer piece, more points every SPOT_M between them, as [lng, lat]. */
const SPOT_M = 50;
function along(coords, lat0) {
  const out = [coords[0]];
  for (let i = 1; i < coords.length; i++) {
    const a = coords[i - 1], b = coords[i];
    const n = Math.floor(dist(flat(a, lat0), flat(b, lat0)) / SPOT_M);
    for (let k = 1; k <= n; k++) out.push([a[0] + (b[0] - a[0]) * k / (n + 1), a[1] + (b[1] - a[1]) * k / (n + 1)]);
    out.push(b);
  }
  return out;
}

/* Distance from a flat point to the measured line, and the share of the line's length where it is nearest. */
function project(p, line) {
  let best = Infinity, along = 0;
  for (let i = 1; i < line.pts.length; i++) {
    const a = line.pts[i - 1], b = line.pts[i];
    const dx = b[0] - a[0], dy = b[1] - a[1], l2 = dx * dx + dy * dy;
    let t = l2 > 0 ? ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / l2 : 0;
    t = Math.max(0, Math.min(1, t));
    const d = dist(p, [a[0] + t * dx, a[1] + t * dy]);
    if (d < best) { best = d; along = line.at[i - 1] + t * Math.sqrt(l2); }
  }
  return { d: best, t: line.length > 0 ? along / line.length : 0 };
}

/**
 * The gravel networks in `lines` ({props: {cls}, coords: [[lng, lat], ...]}, as decodeLines reads them): the lines
 * of the classes the bike seeks, joined wherever a point of one comes within joinM of a point of another, each
 * network with its lines and its length. Not only where their ends meet: a track often ends on the middle of
 * another (a T-junction), and a forest of them read as hundreds of pieces too short to count.
 */
export function gravelNetworks(lines, bike, lat0) {
  const want = new Set(SEEK[bike] || []);
  const kept = (lines || []).filter(l => l && l.props && want.has(l.props.cls) && Array.isArray(l.coords) && l.coords.length >= 2);
  const parent = kept.map((_, i) => i);
  const find = i => { while (parent[i] !== i) { parent[i] = parent[parent[i]]; i = parent[i]; } return i; };
  // Every point on a grid of about joinM: two lines whose points fall in the same cell or the next one meet there.
  const cell = GRAVEL_HINTS.joinM;
  const byCell = new Map();
  kept.forEach((l, i) => {
    for (const c of l.coords) {
      const f = flat(c, lat0), cx = Math.floor(f[0] / cell), cy = Math.floor(f[1] / cell);
      for (let ox = -1; ox <= 1; ox++) for (let oy = -1; oy <= 1; oy++) {
        const other = byCell.get(`${cx + ox},${cy + oy}`);
        if (other !== undefined) parent[find(i)] = find(other);
      }
      byCell.set(`${cx},${cy}`, i);
    }
  });
  const groups = new Map();
  kept.forEach((l, i) => {
    const root = find(i);
    if (!groups.has(root)) groups.set(root, []);
    groups.get(root).push(l);
  });
  return [...groups.values()].map(ls => ({ lines: ls, length: ls.reduce((s, l) => s + measured(l.coords, lat0).length, 0) }));
}

/**
 * The dead ends among `lines` (every class, paved too: a track that ends on a road is no dead end), as flat points:
 * a line end within joinM of no other line. A through-point near one sends the leg down a track and back up the same
 * road (the owner's ride near Barneveld, 2026-10-25; every way asked between Amersfoort and Ede rode 0.9 to 4.3 km
 * twice). A line cut at a tile's edge is no dead end: the next tile's copy of the same way runs on past the cut.
 */
export function deadEnds(lines, lat0) {
  const cell = 50;
  const segs = new Map();   // cell -> [[line index, a, b], ...], every segment in the cells its box touches
  const flatLines = (lines || []).map(l => (Array.isArray(l && l.coords) ? l.coords.map(c => flat(c, lat0)) : []));
  flatLines.forEach((pts, li) => {
    for (let i = 1; i < pts.length; i++) {
      const a = pts[i - 1], b = pts[i];
      for (let x = Math.floor(Math.min(a[0], b[0]) / cell); x <= Math.floor(Math.max(a[0], b[0]) / cell); x++) {
        for (let y = Math.floor(Math.min(a[1], b[1]) / cell); y <= Math.floor(Math.max(a[1], b[1]) / cell); y++) {
          const k = `${x},${y}`;
          if (!segs.has(k)) segs.set(k, []);
          segs.get(k).push([li, a, b]);
        }
      }
    }
  });
  const toSeg = (p, a, b) => {
    const dx = b[0] - a[0], dy = b[1] - a[1], l2 = dx * dx + dy * dy;
    const u = l2 > 0 ? Math.max(0, Math.min(1, ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / l2)) : 0;
    return dist(p, [a[0] + u * dx, a[1] + u * dy]);
  };
  const out = [];
  flatLines.forEach((pts, li) => {
    if (pts.length < 2) return;
    for (const end of [pts[0], pts[pts.length - 1]]) {
      const cx = Math.floor(end[0] / cell), cy = Math.floor(end[1] / cell);
      let joined = false;
      for (let ox = -1; ox <= 1 && !joined; ox++) for (let oy = -1; oy <= 1 && !joined; oy++) {
        for (const [other, a, b] of segs.get(`${cx + ox},${cy + oy}`) || []) {
          if (other !== li && toSeg(end, a, b) <= GRAVEL_HINTS.joinM) { joined = true; break; }
        }
      }
      if (!joined) out.push(end);
    }
  });
  return out;
}

/**
 * Up to GRAVEL_HINTS.maxCandidates sets of through-points for one leg, best first: each set is [[lng, lat], ...] in
 * the leg's direction, points on the gravel the engine is asked to ride through. `base` is the engine's way,
 * [[lng, lat], ...]. Empty when no gravel near the way is worth asking for.
 */
export function gravelCandidates(base, lines, bike) {
  if (!seeksUnpaved(bike) || !Array.isArray(base) || base.length < 2) return [];
  const lat0 = base[0][1];
  const way = measured(base, lat0);
  if (way.length < 1000 || way.length > GRAVEL_HINTS.maxLegM) return [];
  const reach = corridorM(way.length);
  const ends = deadEnds(lines, lat0);
  const nearEnd = p => ends.some(e => dist(p, e) < GRAVEL_HINTS.deadEndM);
  const scored = [];
  for (const net of gravelNetworks(lines, bike, lat0)) {
    if (net.length < GRAVEL_HINTS.minStretchM) continue;
    // Points every SPOT_M along the network, where each lies along the way and how far from it. Not only its
    // corners: at the tiles' zoom a straight track keeps its two ends alone, and a long one beside the way would
    // have no point near it.
    const spots = [];
    for (const l of net.lines) for (const c of along(l.coords, lat0)) { const pr = project(flat(c, lat0), way); spots.push({ c, d: pr.d, t: pr.t }); }
    const near = spots.filter(s => s.d <= reach);
    if (near.length < 2) continue;
    // Ridden already: most of it lies on the engine's way.
    if (spots.filter(s => s.d <= GRAVEL_HINTS.onBaseM).length > spots.length / 2) continue;
    near.sort((a, b) => a.t - b.t);
    const t0 = near[0].t, t1 = near[near.length - 1].t;
    if (t1 - t0 < 0.05) continue;   // it crosses the way rather than following it
    const meanD = near.reduce((s, x) => s + x.d, 0) / near.length;
    // Three points along it, start, middle and end in the leg's direction, each off the engine's way: once kept away
    // from dead ends, and once spread over the whole stretch. Which rides better shows only once routed, and the
    // server measures it: a way that rides a road twice is never kept (GravelHints::MAX_TWICE_M).
    const offWay = near.filter(s => s.d > GRAVEL_HINTS.onBaseM);
    const open = offWay.filter(s => !nearEnd(flat(s.c, lat0)));
    const spread = from => {
      if (!from.length) return [];
      const pick = q => { const goal = t0 + (t1 - t0) * q; return from.reduce((b, s) => Math.abs(s.t - goal) < Math.abs(b.t - goal) ? s : b, from[0]); };
      return [pick(0.08), pick(0.5), pick(0.92)].filter((s, i, a) => a.indexOf(s) === i);
    };
    const vias = spread(open), wide = spread(offWay);
    if (!vias.length && !wide.length) continue;
    scored.push({ t0, t1, score: net.length * (t1 - t0) - 2 * meanD, vias: vias.length ? vias : wide, wide });
  }
  scored.sort((a, b) => b.score - a.score);
  const top = scored.slice(0, 3);
  const sets = top.map(s => s.vias);
  // The two best together, when one follows the other along the way.
  if (top.length >= 2) {
    const [x, y] = top[0].t0 <= top[1].t0 ? [top[0], top[1]] : [top[1], top[0]];
    if (x.t1 <= y.t0) sets.splice(1, 0, [...x.vias, ...y.vias]);
  }
  // Then the best two again with their points spread wide, where those differ.
  for (const s of top.slice(0, 2)) if (s.wide.length && s.wide.some((p, i) => p !== s.vias[i])) sets.push(s.wide);
  return sets.slice(0, GRAVEL_HINTS.maxCandidates)
    .map(set => set.slice(0, GRAVEL_HINTS.maxVias).map(s => [s.c[0], s.c[1]]));
}

/* The tiles to read for a leg: the ones its way crosses, and the ones a corridor's width to either side. */
export function corridorTiles(base, legM) {
  if (!Array.isArray(base) || base.length < 2) return [];
  const lat0 = base[0][1], reach = corridorM(legM);
  const kx = K * Math.cos(lat0 * Math.PI / 180);
  const pts = [];
  for (let i = 1; i < base.length; i++) {
    const a = base[i - 1], b = base[i];
    const dx = (b[0] - a[0]) * kx, dy = (b[1] - a[1]) * K, l = Math.hypot(dx, dy);
    if (l === 0) continue;
    const nx = -dy / l, ny = dx / l;
    for (const s of [-1, 0, 1]) pts.push([a[0] + s * nx * reach / kx, a[1] + s * ny * reach / K]);
  }
  const last = base[base.length - 1];
  pts.push(last);
  return tilesForTrack(pts, GRAVEL_HINTS.zoom);
}

/* The surface archives whose bounds hold a tile's middle: border tiles may be in two (CC_TILES.surface). */
function archivesAt(entries, z, x, y) {
  const n = 2 ** z;
  const lon = (x + 0.5) / n * 360 - 180;
  const lat = Math.atan(Math.sinh(Math.PI * (1 - 2 * (y + 0.5) / n))) * 180 / Math.PI;
  return Object.values(entries || {}).filter(e => e && e.tiles && e.tiles.classified && Array.isArray(e.bounds)
    && lon >= e.bounds[0] && lat >= e.bounds[1] && lon <= e.bounds[2] && lat <= e.bounds[3]).map(e => e.tiles.classified);
}

const decoded = new Map();
const DECODED_MAX = 200;
/** The surface lines near a leg's way, read from the road-surface tiles. `entries` is CC_TILES.surface. */
export async function surfaceLinesNear(base, legM, entries, fetchTile = pmtilesTile) {
  const out = [];
  for (const { z, x, y } of corridorTiles(base, legM)) {
    for (const url of archivesAt(entries, z, x, y)) {
      const key = `${url}|${z}/${x}/${y}`;
      if (!decoded.has(key)) {
        try {
          const bytes = await fetchTile(url, z, x, y);
          decoded.set(key, bytes && bytes.length ? decodeLines(bytes, z, x, y, 'surface_') : []);
          if (decoded.size > DECODED_MAX) decoded.delete(decoded.keys().next().value);
        } catch (e) { continue; }
      }
      out.push(...decoded.get(key));
    }
  }
  return out;
}
