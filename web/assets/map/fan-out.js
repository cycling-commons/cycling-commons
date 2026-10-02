// SPDX-License-Identifier: AGPL-3.0-only
/* Pins on one spot fan out: the geometry, with no map and no DOM.
   Two or more pins whose tips land within FAN.joinPx of each other on screen
   form a group; each member is moved onto a small ring around the group's
   centre, and pin-fan.js draws a thin leader from the moved tip back to the
   pin's own point. Order is by key, so a pin keeps its seat between renders.
   @see docs/specs/map-and-search.md (Pins on one spot fan out) */

export const FAN = Object.freeze({
  joinPx: 12,      // tips this close on screen are one spot
  minRadius: 28,   // a pair: tips 56 px apart, a 22 px gap between 34 px pins
  spacing: 40,     // tip-to-tip distance between neighbours on the ring
  ringMax: 8,      // up to this many on one ring, more on a spiral
  maxGroup: 16,    // a bigger pile stays as drawn: zoom in to part it
  spiralStart: 40, // spiral's first radius
  spiralGrowth: 44 // radius added per turn: a pin's height and a gap
});

/* Natural order on keys (`N:9` before `N:10`), so seats follow item ids. */
export const compareKeys = (a, b) =>
  String(a).localeCompare(String(b), 'en', {numeric: true});

/**
 * Group screen points that sit on one spot. `points` is [{key, x, y}] in
 * pixels. Greedy and deterministic: points are taken in key order, and each
 * one not yet taken seeds a group of every free point within `joinPx` of it.
 * A seed's group never chains on through its members, so a dense town at a
 * low zoom gives many small groups rather than one long chain. Neighbours are
 * found through a grid of `joinPx` cells, never by comparing every pair.
 * Answers arrays of indexes into `points`, members in key order; a pin on
 * its own is a group of one.
 */
export function groupByPixel(points, joinPx = FAN.joinPx){
  const order = points.map((p, i) => i).sort((a, b) => compareKeys(points[a].key, points[b].key) || a - b);
  const cell = v => Math.floor(v / joinPx);
  const grid = new Map();
  order.forEach(i => {
    const k = cell(points[i].x) + ',' + cell(points[i].y);
    if(!grid.has(k)) grid.set(k, []);
    grid.get(k).push(i);
  });
  const rank = new Map(order.map((i, r) => [i, r]));
  const taken = new Set();
  const groups = [];
  const lim = joinPx * joinPx;
  order.forEach(i => {
    if(taken.has(i)) return;
    taken.add(i);
    const p = points[i], g = [i];
    const cx = cell(p.x), cy = cell(p.y);
    for(let dx = -1; dx <= 1; dx++) for(let dy = -1; dy <= 1; dy++){
      const bucket = grid.get((cx + dx) + ',' + (cy + dy));
      if(!bucket) continue;
      bucket.forEach(j => {
        if(taken.has(j)) return;
        const ex = points[j].x - p.x, ey = points[j].y - p.y;
        if(ex * ex + ey * ey <= lim){ taken.add(j); g.push(j); }
      });
    }
    g.sort((a, b) => rank.get(a) - rank.get(b));
    groups.push(g);
  });
  return groups;
}

/**
 * Seats for `n` pins around a centre, as [dx, dy] pixels (y down), first seat
 * first. One pin keeps its place. Up to FAN.ringMax sit evenly on one ring
 * whose radius keeps neighbours FAN.spacing apart (never under
 * FAN.minRadius); the ring is turned so no seat is straight below the centre,
 * where a pin's body would hide the point its leader comes back to. A pair
 * sits left and right; three are top, lower right, lower left. More than
 * FAN.ringMax follow a spiral out from the top, clockwise.
 */
export function fanSeats(n){
  if(n <= 1) return [[0, 0]];
  const out = [];
  if(n <= FAN.ringMax){
    const r = Math.max(FAN.minRadius, FAN.spacing / (2 * Math.sin(Math.PI / n)));
    const start = -Math.PI / 2 - (n % 2 === 0 ? Math.PI / n : 0);
    for(let i = 0; i < n; i++){
      const a = start + i * 2 * Math.PI / n;
      out.push([round1(r * Math.cos(a)), round1(r * Math.sin(a))]);
    }
    return out;
  }
  const a0 = -Math.PI / 2;
  let a = a0;
  for(let i = 0; i < n; i++){
    const r = FAN.spiralStart + FAN.spiralGrowth * (a - a0) / (2 * Math.PI);
    out.push([round1(r * Math.cos(a)), round1(r * Math.sin(a))]);
    a += FAN.spacing / r;
  }
  return out;
}

/**
 * The whole layout: for each of `points` ([{key, x, y}], screen pixels of each
 * pin's tip), the pixel offset that moves its tip onto its seat, or null for a
 * pin left where it is (on its own, or in a pile over FAN.maxGroup). Seats ring
 * the mean of the group's tips, so a group of pins on one exact point rings
 * that point. Offsets are whole pixels.
 */
export function fanLayout(points, opts = {}){
  const joinPx = opts.joinPx || FAN.joinPx;
  const maxGroup = opts.maxGroup || FAN.maxGroup;
  const out = points.map(() => null);
  groupByPixel(points, joinPx).forEach(g => {
    if(g.length < 2 || g.length > maxGroup) return;
    let cx = 0, cy = 0;
    g.forEach(i => { cx += points[i].x; cy += points[i].y; });
    cx /= g.length; cy /= g.length;
    const seats = fanSeats(g.length);
    g.forEach((i, s) => {
      out[i] = [Math.round(cx + seats[s][0] - points[i].x) + 0, Math.round(cy + seats[s][1] - points[i].y) + 0];
    });
  });
  return out;
}

function round1(v){ return Math.round(v * 10) / 10 + 0; }
