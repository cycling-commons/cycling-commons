// SPDX-License-Identifier: AGPL-3.0-only
/* Where the climb's markers sit on the full profile (docs/specs/climb-elevation.md §6d).
   Pure, so a node test can load it. */
import { haversine } from './util.js';

/* How far along `route` ([lat, lng], foot to summit) the point nearest `pt`
   lies, in metres from the foot. Metres, not a share of the line: the chart's
   last bar is a full bin, so the chart is a little longer than the road and a
   share would land past the spot. Null without a usable route or point. Each segment is flattened around `pt`, longitude scaled by
   cos(lat), which is exact enough at the length of one segment. */
export function metresAlong(route, pt){
  if(!Array.isArray(route) || route.length < 2 || !Array.isArray(pt) || pt.length < 2) return null;
  const lat = Number(pt[0]), lng = Number(pt[1]);
  if(!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
  const k = Math.cos(lat*Math.PI/180);
  let walked = 0, best = Infinity, bestAt = 0;
  for(let i = 1; i < route.length; i++){
    const a = route[i-1], b = route[i];
    if(!Array.isArray(a) || !Array.isArray(b)) return null;
    const seg = haversine(a, b)*1000;
    const ax = (a[1]-lng)*k, ay = a[0]-lat, bx = (b[1]-lng)*k, by = b[0]-lat;
    const dx = bx-ax, dy = by-ay, len2 = dx*dx + dy*dy;
    const t = len2 > 0 ? Math.min(1, Math.max(0, -(ax*dx + ay*dy)/len2)) : 0;
    const qx = ax + t*dx, qy = ay + t*dy, d2 = qx*qx + qy*qy;
    if(d2 < best){ best = d2; bestAt = walked + t*seg; }
    walked += seg;
  }
  return walked > 0 ? bestAt : null;
}

/* The steepest window on the chart, [from, to] in metres: centred on the
   spot it was measured at, and moved inside the climb when it would hang
   over the foot or the summit. */
export function windowSpan(centreM, windowM, totalM){
  const w = Math.min(windowM, totalM);
  const from = Math.min(Math.max(0, centreM - w/2), totalM - w);
  return [from, from + w];
}
