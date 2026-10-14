// SPDX-License-Identifier: AGPL-3.0-only
/* The ride planner's model (docs/specs/route-planner.md sections 4 and 6.3):
   stops and legs, where a place goes in the ride, the figures, the profile
   bins and the GPX writer. A leaf: no map, no DOM, no fetch, so node tests
   it as it is (tests/js/plan-model.test.mjs). Points are GeoJSON [lng, lat];
   a stop is {lat, lng, name, item?, letter?}; a leg is {key, routed, line,
   distM, heights} with heights as [metres along, elevation] pairs or null. */

/** A ride holds at most this many stops (route-planner.md section 4.1). */
export const MAX_STOPS = 25;
/** The corridor of the along list, metres (route-planner.md section 5). */
export const CORRIDOR_M = 250;

/** A stop is known by its place when it has one, else by its rounded point. */
export const stopKey = s => s.item ? String(s.item) : (s.lat.toFixed(5) + ',' + s.lng.toFixed(5));

/** One key per leg: both stops and the bike, so a bike change re-routes every leg. */
export function legKeys(stops, bike){
  const out = [];
  for(let i = 0; i < stops.length - 1; i++) out.push(stopKey(stops[i]) + '|' + stopKey(stops[i + 1]) + '|' + bike);
  return out;
}

/* A local metric projection around a reference point: good to a few metres
   over the tens of kilometres a one-day ride spans. */
function proj(ref){
  const kx = Math.cos(ref[1] * Math.PI / 180) * 111320, ky = 110540;
  return p => [(p[0] - ref[0]) * kx, (p[1] - ref[1]) * ky];
}

/** Metres off a line and metres along it to the closest point; null for a line of one point. */
export function nearLine(line, pt){
  if(!Array.isArray(line) || line.length < 2) return null;
  const P = proj(pt), q = P(pt);
  let best = { distM: Infinity, alongM: 0, seg: 0 }, acc = 0;
  for(let i = 0; i < line.length - 1; i++){
    const a = P(line[i]), b = P(line[i + 1]);
    const dx = b[0] - a[0], dy = b[1] - a[1], l2 = dx * dx + dy * dy;
    let t = l2 ? ((q[0] - a[0]) * dx + (q[1] - a[1]) * dy) / l2 : 0;
    t = Math.max(0, Math.min(1, t));
    const d = Math.hypot(q[0] - (a[0] + t * dx), q[1] - (a[1] + t * dy));
    const segLen = Math.sqrt(l2);
    if(d < best.distM) best = { distM: d, alongM: acc + t * segLen, seg: i };
    acc += segLen;
  }
  return best;
}

/** Where a place goes: after the leg that passes closest, at the end with fewer than two stops, -1 when it is in the ride already. */
export function insertIndex(stops, legs, place){
  if(place.item && stops.some(s => s.item === place.item)) return -1;
  if(stops.length < 2) return stops.length;
  let bestLeg = stops.length - 2, bestD = Infinity;
  legs.forEach((leg, i) => {
    if(!leg || !leg.routed) return;
    const n = nearLine(leg.line, [place.lng, place.lat]);
    if(n && n.distM < bestD){ bestD = n.distM; bestLeg = i; }
  });
  return bestLeg + 1;
}

/* Climbed and descended, one rule for every ride (route-planner.md section
   4.4): a three-sample moving average, then every rise and every drop. */
function climbOf(heights){
  const h = heights.map(x => x[1]);
  const sm = h.map((v, i) => (h[Math.max(0, i - 1)] + v + h[Math.min(h.length - 1, i + 1)]) / 3);
  let up = 0, down = 0;
  for(let i = 1; i < sm.length; i++){ const d = sm[i] - sm[i - 1]; if(d > 0) up += d; else down -= d; }
  return { up, down };
}

/** Distance over the routed legs, climbed and descended over the legs with heights. */
export function figures(legs){
  let distM = 0, upM = 0, downM = 0;
  for(const leg of legs){
    if(!leg || !leg.routed) continue;
    distM += leg.distM || 0;
    if(Array.isArray(leg.heights) && leg.heights.length > 1){ const c = climbOf(leg.heights); upM += c.up; downM += c.down; }
  }
  return { distM, upM, downM };
}

/** The profile as runs of bins; a leg without heights breaks the run. Each bin: d0, d1, h0, h1, grad (percent). */
export function profileRuns(legs, binM){
  const runs = [];
  let run = [], off = 0;
  const close = () => { if(run.length) runs.push(run); run = []; };
  for(const leg of legs){
    if(!leg || !leg.routed) continue;
    const hs = leg.heights;
    if(!Array.isArray(hs) || hs.length < 2){ off += leg.distM || 0; close(); continue; }
    for(let i = 0; i < hs.length - 1;){
      let j = i;
      while(j < hs.length - 1 && hs[j + 1][0] - hs[i][0] < binM) j++;
      if(j === i) j = i + 1;
      const d0 = off + hs[i][0], d1 = off + hs[j][0], h0 = hs[i][1], h1 = hs[j][1];
      run.push({ d0, d1, h0, h1, grad: (h1 - h0) / Math.max(d1 - d0, 1) * 100 });
      i = j;
    }
    off += leg.distM || 0;
  }
  close();
  return runs;
}

/** The point on the ride at a distance along it, [lng, lat]; null past its end. */
export function pointAt(legs, d){
  let off = 0;
  for(const leg of legs){
    if(!leg || !leg.routed) continue;
    if(d <= off + leg.distM){
      const t = leg.distM ? (d - off) / leg.distM : 0;
      const i = Math.min(leg.line.length - 1, Math.max(0, Math.round(t * (leg.line.length - 1))));
      return leg.line[i];
    }
    off += leg.distM;
  }
  return null;
}

const xml = s => String(s ?? '').replace(/[<>&"']/g, c => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&apos;' }[c]));

/* The GPX (route-planner.md section 6.3): one track with the elevations the
   legs carry, one waypoint per stop, a place stop with a link to its page. */
export function gpxText(ride, o){
  const name = ride.name || 'Ride';
  const itemUrl = (o && o.itemUrl) || null;
  const pts = [];
  (ride.legs || []).forEach((leg, li) => {
    if(!leg || !leg.routed) return;
    const hs = Array.isArray(leg.heights) && leg.heights.length ? leg.heights : null;
    leg.line.forEach((p, i) => {
      if(li > 0 && i === 0) return;   // the join point is the previous leg's last point
      let ele = '';
      if(hs){
        const d = i / Math.max(1, leg.line.length - 1) * leg.distM;
        const h = hs.reduce((b, x) => Math.abs(x[0] - d) < Math.abs(b[0] - d) ? x : b, hs[0]);
        ele = `<ele>${Math.round(h[1])}</ele>`;
      }
      pts.push(`<trkpt lat="${p[1].toFixed(6)}" lon="${p[0].toFixed(6)}">${ele}</trkpt>`);
    });
  });
  const wpts = (ride.stops || []).map(s => {
    const link = s.item && itemUrl ? `<link href="${xml(itemUrl(s.item))}"/>` : '';
    return `<wpt lat="${s.lat.toFixed(6)}" lon="${s.lng.toFixed(6)}"><name>${xml(s.name)}</name>${link}</wpt>`;
  });
  return `<?xml version="1.0" encoding="UTF-8"?>\n<gpx version="1.1" creator="Cycling Commons" xmlns="http://www.topografix.com/GPX/1/1">\n`
    + `<metadata><name>${xml(name)}</name></metadata>\n${wpts.join('\n')}\n<trk><name>${xml(name)}</name><trkseg>\n${pts.join('\n')}\n</trkseg></trk>\n</gpx>\n`;
}

/** The ride bar's line (route-planner.md section 3): distance, climbed, stops, in the rider's units. */
export function barText(figs, stopCount, fmt){
  return `${fmt.km(figs.distM)} · ${fmt.m(figs.upM)} ↑ · ${fmt.stops(stopCount)}`;
}

/** The stops before each change, newest last, bounded; Ctrl+Z (and the Mac's command key) hands them back one step at a time. */
export function createHistory(limit = 30){
  const past = [];
  return {
    push(stops){ past.push(JSON.parse(JSON.stringify(stops))); if(past.length > limit) past.shift(); },
    pop(){ return past.length ? past.pop() : null; },
    size(){ return past.length; },
  };
}

/* Toughness (route-planner.md section 4.4): the routes' length bands give the
   base (BestOfPreview::LENGTHS, Short under 50 km, Medium to 150 km, Long
   past it, the Medium band split at 100 km for a one-day ride), the metres
   climbed per km add to it, and the level reads in the routes' difficulty
   words (DifficultyVocabulary::LABELS, 1 Easy to 5 Very hard). */
const TERRAIN_BUMP = { flat: 0, rolling: 1, hilly: 2, mountainous: 3 };
export function toughness(distM, upM){
  const km = distM / 1000;
  if(!(km > 0)) return null;
  const perKm = upM / km;
  const base = km < 50 ? 1 : km < 100 ? 2 : km < 150 ? 3 : 4;
  const terrain = perKm < 8 ? 'flat' : perKm < 15 ? 'rolling' : perKm < 25 ? 'hilly' : 'mountainous';
  return { level: Math.min(5, base + TERRAIN_BUMP[terrain]), terrain, perKm };
}

/** The distance axis's tick step in km: the largest of 1, 2, 5 and their tens at or under a tenth-and-a-half of the ride, so the axis keeps about ten ticks at any length. */
export function tickStepKm(totalKm){
  const ladder = [1, 2, 5, 10, 20, 50, 100, 200, 500, 1000];
  const ceiling = totalKm * 1.5 / 10;
  let step = 1;
  for(const s of ladder){ if(s <= ceiling) step = s; }
  return step;
}
