// SPDX-License-Identifier: AGPL-3.0-only
/* Which scope a loaded ride asks for (docs/specs/map-and-search.md §4.5).
   A leaf: no DOM, no map, no storage — the decision alone, so it can be tested. */

/** Comparable identity of a scope, so "already looking there" is one string test. */
export function scopeKey(s){
  if(!s) return '';
  return [s.kind, (s.regionIds || []).slice().sort((a, b) => a - b).join('.'), s.countryCode || ''].join('|');
}

/**
 * The scope for a ride, from the regions its track crosses.
 *
 * A scope holds a SET of region ids, so a ride through three provinces takes
 * all three rather than picking a winner and leaving the last stretch unscoped.
 * A ride across a border keeps every region it crosses in that one set,
 * whatever their country: Everywhere is not a place to look at (owner,
 * 2026-09-06), and a region set never asks the server for more than the ride.
 * A ride outside every onboarded region answers null: there is nothing better
 * to show it, and moving the rider's scope for nothing is worse than leaving it.
 */
export function rideScopeFor(regions){
  if(!Array.isArray(regions)) return null;
  const ids = regions.map(r => r && r.id).filter(id => id != null);
  if(!ids.length) return null;
  const ccs = [...new Set(regions.map(r => r && r.countryCode).filter(Boolean))];
  return { kind:'region', regionIds:ids, countryCode: ccs.length === 1 ? ccs[0] : null };
}

/**
 * The planner's scope: every region the ride passes through, the start's first, whatever the rider's scope was
 * (route-planner.md section 5; owner 2026-10-26: a checkpoint clicked outside the start's region left its places
 * unloaded, so the scope covers the whole ride, no longer only its start). A My area circle, a country or Everywhere
 * gives way to it. `regions` is one region or a list, in ride order; a region twice counts once. Null when the scope
 * is that set already, or the ride lies in no region.
 */
export function planScopeFor(scope, regions){
  const seen = new Set();
  const list = (Array.isArray(regions) ? regions : [regions])
    .filter(x => x && x.id != null && !seen.has(x.id) && seen.add(x.id));
  const next = rideScopeFor(list);
  if(!next) return null;
  // The same regions, in any order, are no change: the country code a scope carries says nothing more.
  const ids = s => (s.regionIds || []).slice().sort((x, y) => x - y).join('.');
  return scope && scope.kind === 'region' && ids(scope) === ids(next) ? null : next;
}

/**
 * The regions a ride passes through, in ride order, each once: the region of every checkpoint, and every region its
 * routed legs cross, read every `stepM` metres along them. `S` is the scope registry (CCScope): `liveRegionAt` is
 * the region whose own outline holds a point, so a leg near a border never adds the neighbour its bounding box
 * reaches into; a checkpoint just outside a simplified outline falls back to `regionOfPoint`.
 */
export function rideRegions(stops, legs, S, stepM = 1000){
  const out = [];
  const add = reg => { if(reg && reg.id != null && !out.some(x => x.id === reg.id)) out.push(reg); };
  (stops || []).forEach((s, i) => {
    if(!s) return;
    add(S.liveRegionAt(s.lng, s.lat) || S.regionOfPoint(s.lng, s.lat));
    const leg = legs && legs[i];
    if(leg && leg.routed && Array.isArray(leg.line)) for(const p of everyMetres(leg.line, stepM)) add(S.liveRegionAt(p[0], p[1]));
  });
  return out;
}

/* Points every `stepM` metres along a [lng, lat] line, its first point included. */
function everyMetres(line, stepM){
  const out = line.length ? [line[0]] : [];
  let left = stepM;
  for(let i = 1; i < line.length; i++){
    const a = line[i - 1], b = line[i];
    const kx = 111320 * Math.cos(a[1] * Math.PI / 180);
    const len = Math.hypot((b[0] - a[0]) * kx, (b[1] - a[1]) * 111320);
    let at = 0;
    while(len - at >= left){
      at += left; left = stepM;
      const f = at / len;
      out.push([a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f]);
    }
    left -= len - at;
  }
  return out;
}
