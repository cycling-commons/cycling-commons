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
      // In metres along the drawn line, scaled to the engine's length: vertices are dense in a
      // village and sparse on an open road, so a vertex count puts the point far off (owner 2026-10-15).
      const line = leg.line; if(!line || !line.length) return null;
      if(line.length < 2) return line[0];
      const P = proj(line[0]), seg = [];
      let total = 0;
      for(let i = 1; i < line.length; i++){ const a = P(line[i - 1]), b = P(line[i]); const len = Math.hypot(b[0] - a[0], b[1] - a[1]); seg.push(len); total += len; }
      let want = leg.distM ? (d - off) / leg.distM * total : 0;
      for(let i = 0; i < seg.length; i++){
        if(want <= seg[i]){ const f = seg[i] ? want / seg[i] : 0; const [x1, y1] = line[i], [x2, y2] = line[i + 1]; return [x1 + (x2 - x1) * f, y1 + (y2 - y1) * f]; }
        want -= seg[i];
      }
      return line[line.length - 1];
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
export function barText(figs, stopCount, fmt, timeText){
  const parts = [fmt.km(figs.distM), fmt.m(figs.upM) + ' ↑'];
  if(timeText) parts.push(timeText);
  parts.push(fmt.stops(stopCount));
  return parts.join(' · ');
}

/** The stops before each change, newest last, bounded; Ctrl+Z (and the Mac's command key) hands them back one
    step at a time. What undo took can be redone, the `redoLimit` steps nearest the ride at most, until the next
    change. Every step is a full copy of the stops, never the array it was given. */
export function createHistory(limit = 30, redoLimit = 5){
  const past = [], future = [];
  const copy = stops => JSON.parse(JSON.stringify(stops));
  return {
    push(stops){ past.push(copy(stops)); if(past.length > limit) past.shift(); future.length = 0; },
    undo(current){ if(!past.length) return null; future.push(copy(current)); if(future.length > redoLimit) future.shift(); return past.pop(); },
    redo(current){ if(!future.length) return null; past.push(copy(current)); if(past.length > limit) past.shift(); return future.pop(); },
    size(){ return past.length; },
    redoSize(){ return future.length; },
    /** The steps as copies, for the browser to keep. */
    state(){ return { past: copy(past), future: copy(future) }; },
    /** Kept steps back in, the newest within the bounds; anything that is not a list restores nothing. */
    restore(s){
      past.length = 0; future.length = 0;
      if(s && Array.isArray(s.past)) past.push(...copy(s.past).slice(-limit));
      if(s && Array.isArray(s.future)) future.push(...copy(s.future).slice(-redoLimit));
    },
  };
}

/** How long the browser keeps a ride's work, the draft and its undo and redo steps, after the last change
    (owner 2026-10-15). A ride to keep longer is a file the rider saves; nothing of it stays on our server. */
export const WORK_KEEP_MS = 7 * 24 * 3600 * 1000;
/** True while work stamped `at` (ms) is younger than seven days at `now`; a stamp from the future is not trusted. */
export function workFresh(at, now){ return Number.isFinite(at) && at <= now && now - at < WORK_KEEP_MS; }

/* Toughness (route-planner.md section 4.4): the routes' length bands give the
   base (BestOfPreview::LENGTHS, Short under 50 km, Medium to 150 km, Long
   past it, the Medium band split at 100 km for a one-day ride), the metres
   climbed per km add to it, and the level reads in the routes' difficulty
   words (DifficultyVocabulary::LABELS, 1 Easy to 5 Very hard). */
/** Metres on paved, gravel and rough roads over the routed legs; a leg without an answer is unknown. */
export function surfaceMix(legs){
  const mix = { paved: 0, gravel: 0, rough: 0, unknown: 0 };
  for(const leg of legs){
    if(!leg || !leg.routed) continue;
    if(leg.surfaces){ for(const k of Object.keys(mix)) mix[k] += leg.surfaces[k] || 0; }
    else mix.unknown += leg.distM || 0;
  }
  return mix;
}

/** The distance axis's tick step in km: the largest of 1, 2, 5 and their tens at or under a tenth-and-a-half of the ride, so the axis keeps about ten ticks at any length. */
export function tickStepKm(totalKm){
  const ladder = [1, 2, 5, 10, 20, 50, 100, 200, 500, 1000];
  const ceiling = totalKm * 1.5 / 10;
  let step = 1;
  for(const s of ladder){ if(s <= ceiling) step = s; }
  return step;
}

/* ---------- the day (route-planner.md section 4.4) ---------- */
/* One reference rider per bike type: the power held all day, the drag area in
   m², rider plus bike plus a day's bag in kg, the rolling resistance on smooth
   asphalt, the paved descent cap in km/h. Round numbers for a steady day on a
   loaded bike, the same for everyone: there is no personal level. */
export const PACE = {
  'Road':      { watts: 150, cda: 0.36, kg: 90,  crr: 0.005, capKmh: 42 },
  'Gravel':    { watts: 150, cda: 0.40, kg: 92,  crr: 0.006, capKmh: 38 },
  'MTB':       { watts: 140, cda: 0.45, kg: 94,  crr: 0.009, capKmh: 35 },
  'E-bike':    { watts: 100, cda: 0.50, kg: 105, crr: 0.007, capKmh: 35, motorW: 250, assistKmh: 25 },
  'Handbike':  { watts: 90,  cda: 0.30, kg: 95,  crr: 0.006, capKmh: 35 },
  'Recumbent': { watts: 150, cda: 0.25, kg: 95,  crr: 0.005, capKmh: 42 },
  'Trike':     { watts: 130, cda: 0.35, kg: 100, crr: 0.007, capKmh: 35 },
  'Tandem':    { watts: 300, cda: 0.45, kg: 170, crr: 0.006, capKmh: 42 },
};
/* Gravel and rough road roll harder and are descended slower, whatever the bike; an unknown surface is nearly asphalt. */
const SURFACE_CRR = { gravel: 0.012, rough: 0.020, unknown: 0.007 };
const SURFACE_CAP_KMH = { gravel: 30, rough: 22 };
const RHO = 1.2, G = 9.81, WALK_MS = 4 / 3.6, CLIMB_GRADE = 0.02;
const riderOf = bike => PACE[bike] || PACE.Road;
/* The power that holds v m/s on gradient g (rise over run) against rolling resistance crr. */
function powerAt(r, v, g, crr){ const th = Math.atan(g); return r.kg * G * v * (Math.sin(th) + crr * Math.cos(th)) + 0.5 * RHO * r.cda * v * v * v; }
/* The speed held: where the power needed meets the power there is, by bisection under the cap; a motor's share stops at its assist speed; below walking pace the rider walks. */
function speedAt(r, g, crr, capMs){
  const avail = v => r.watts + (r.motorW && v * 3.6 <= r.assistKmh ? r.motorW : 0);
  if(powerAt(r, capMs, g, crr) <= avail(capMs)) return capMs;
  let lo = 0, hi = capMs;
  for(let i = 0; i < 40; i++){ const mid = (lo + hi) / 2; if(powerAt(r, mid, g, crr) <= avail(mid)) lo = mid; else hi = mid; }
  return Math.max(lo, WALK_MS);
}
/** The reference rider's speed on smooth level asphalt, m/s. */
export function flatSpeed(bike){ const r = riderOf(bike); return speedAt(r, 0, r.crr, r.capKmh / 3.6); }
/* A leg's surface shares, unknown counted as paved; no answer is all paved. */
function sharesOf(leg){
  const s = leg.surfaces || {}; const total = (s.paved || 0) + (s.gravel || 0) + (s.rough || 0) + (s.unknown || 0);
  if(!(total > 0)) return { paved: 1, gravel: 0, rough: 0, unknown: 0 };
  return { paved: (s.paved || 0) / total, gravel: (s.gravel || 0) / total, rough: (s.rough || 0) / total, unknown: (s.unknown || 0) / total };
}
/* The steps of a leg as [metres, gradient]: the smoothed heights (the climbed rule's smoothing), scaled to the leg's length; a leg without heights is one flat step. */
function stepsOf(leg){
  const hs = leg.heights;
  if(!Array.isArray(hs) || hs.length < 2) return [[leg.distM, 0]];
  const h = hs.map(x => x[1]);
  const sm = h.map((v, i) => (h[Math.max(0, i - 1)] + v + h[Math.min(h.length - 1, i + 1)]) / 3);
  const steps = []; let sum = 0;
  for(let i = 1; i < hs.length; i++){ const len = hs[i][0] - hs[i - 1][0]; if(!(len > 0)) continue; steps.push([len, Math.max(-0.3, Math.min(0.3, (sm[i] - sm[i - 1]) / len))]); sum += len; }
  if(!steps.length || !(sum > 0)) return [[leg.distM, 0]];
  const k = leg.distM / sum;
  return steps.map(([len, g]) => [len * k, g]);
}
/** The riding time of the reference rider over the routed legs: seconds, the same distance flat, the flat equivalent in km, and two splits of the same seconds: by surface (paved, gravel, rough, from each leg's surface shares) and by gradient (climb, flat, descend). Null without a routed leg. */
export function rideTime(legs, bike){
  const r = riderOf(bike);
  let seconds = 0, distM = 0;
  const parts = { paved: 0, gravel: 0, rough: 0, unknown: 0, climb: 0, flat: 0, descend: 0 };
  /* The same two splits in metres, for the bars' distance view (owner 2026-10-15). */
  const metres = { paved: 0, gravel: 0, rough: 0, unknown: 0, climb: 0, flat: 0, descend: 0 };
  for(const leg of legs){
    if(!leg || !leg.routed || !(leg.distM > 0)) continue;
    const mix = sharesOf(leg);
    const crr = mix.paved * r.crr + mix.unknown * SURFACE_CRR.unknown + mix.gravel * SURFACE_CRR.gravel + mix.rough * SURFACE_CRR.rough;
    const cap = ((mix.paved + mix.unknown) * r.capKmh + mix.gravel * Math.min(r.capKmh, SURFACE_CAP_KMH.gravel) + mix.rough * Math.min(r.capKmh, SURFACE_CAP_KMH.rough)) / 3.6;
    distM += leg.distM;
    for(const [len, g] of stepsOf(leg)){
      const t = len / speedAt(r, g, crr, cap);
      seconds += t;
      // Gravel on a climb is gravel time too (owner 2026-10-15): the surface split covers every step.
      parts.paved += t * mix.paved; parts.gravel += t * mix.gravel; parts.rough += t * mix.rough; parts.unknown += t * mix.unknown;
      metres.paved += len * mix.paved; metres.gravel += len * mix.gravel; metres.rough += len * mix.rough; metres.unknown += len * mix.unknown;
      if(g >= CLIMB_GRADE){ parts.climb += t; metres.climb += len; }
      else if(g <= -CLIMB_GRADE){ parts.descend += t; metres.descend += len; }
      else { parts.flat += t; metres.flat += len; }
    }
  }
  if(!(distM > 0)) return null;
  const flatSeconds = distM / flatSpeed(bike);
  return { seconds, flatSeconds, flatKm: seconds / flatSeconds * distM / 1000, parts, metres };
}
/* One bar of the day graphic: the parts of one split with their share, in a fixed order, empty parts left out. */
function barsOf(parts, order){
  const total = order.reduce((t, k) => t + (parts[k] || 0), 0);
  if(!(total > 0)) return [];
  return order.filter(k => (parts[k] || 0) > 0).map(k => ({ cls: k, seconds: parts[k], share: parts[k] / total }));
}
/** The time by surface: asphalt, gravel, rough road. */
export function surfaceBars(parts){ return barsOf(parts, ['paved', 'gravel', 'rough', 'unknown']); }
/** The same time by gradient: climbing, flat, descending. */
export function gradientBars(parts){ return barsOf(parts, ['climb', 'flat', 'descend']); }
/** Seconds as hours and minutes, rounded to five minutes. */
export function roundedHm(seconds){ const m = Math.round(seconds / 300) * 5; return { h: Math.floor(m / 60), m: m % 60 }; }
/** The 1 km window of the profile with the highest average gradient, from 4 %: {grad, atKm}; null below that. */
export function steepestKm(legs){
  let best = null;
  for(const run of profileRuns(legs, 100)){
    for(let i = 0; i < run.length; i++){
      let j = i; while(j < run.length - 1 && run[j].d1 - run[i].d0 < 1000) j++;
      const len = run[j].d1 - run[i].d0; if(len < 800) continue;
      const grad = (run[j].h1 - run[i].h0) / len * 100;
      if(!best || grad > best.grad) best = { grad, atKm: run[i].d0 / 1000 };
    }
  }
  return best && best.grad >= 4 ? best : null;
}
/** The longest run of profile bins at 2 % or steeper, a dip of up to 300 m not breaking it, from 1 km: {km, upM, grad}; null without one. */
export function longestClimb(legs){
  let best = null;
  for(const run of profileRuns(legs, 100)){
    let start = -1, end = -1, dip = 0;
    const close = () => {
      if(start < 0) return;
      const len = run[end].d1 - run[start].d0; let up = 0;
      for(let k = start; k <= end; k++){ const d = run[k].h1 - run[k].h0; if(d > 0) up += d; }
      if(len >= 1000 && (!best || len > best.km * 1000)) best = { km: len / 1000, upM: up, grad: up / len * 100 };
      start = -1; end = -1; dip = 0;
    };
    for(let i = 0; i < run.length; i++){
      const b = run[i];
      if(b.grad >= 2){ if(start < 0) start = i; end = i; dip = 0; }
      else if(start >= 0){ dip += b.d1 - b.d0; if(dip > 300) close(); }
    }
    close();
  }
  return best;
}
/** The longest stretch along the ride without a letter B place, in km: the gaps between the start, the B rows and the end; null while that letter's list is capped. */
export function waterGap(rows, distM){
  const b = rows.filter(r => r.letter === 'B');
  if(b.some(r => r.truncated)) return null;
  const kms = b.map(r => r.alongKm).sort((x, y) => x - y);
  const total = distM / 1000;
  let gap = kms.length ? kms[0] : total, prev = kms.length ? kms[0] : 0;
  for(const k of kms.slice(1)){ gap = Math.max(gap, k - prev); prev = k; }
  if(kms.length) gap = Math.max(gap, total - prev);
  return Math.round(gap * 10) / 10;
}
/** Sunrise and sunset at a point on a date (YYYY-MM-DD), as UTC Dates, with the daylight in seconds; NOAA's solar equations, a minute's precision; null for a polar day or night. */
export function sunTimes(lat, lng, date){
  const [y, mo, d] = String(date).split('-').map(Number);
  if(!y || !mo || !d) return null;
  const rad = x => x * Math.PI / 180, deg = x => x * 180 / Math.PI;
  const a = Math.floor((14 - mo) / 12), yy = y + 4800 - a, mm = mo + 12 * a - 3;
  const jd = d + Math.floor((153 * mm + 2) / 5) + 365 * yy + Math.floor(yy / 4) - Math.floor(yy / 100) + Math.floor(yy / 400) - 32045;
  const jc = (jd - 2451545) / 36525;
  const L0 = (280.46646 + jc * (36000.76983 + jc * 0.0003032)) % 360;
  const M = 357.52911 + jc * (35999.05029 - 0.0001537 * jc);
  const e = 0.016708634 - jc * (0.000042037 + 0.0000001267 * jc);
  const C = Math.sin(rad(M)) * (1.914602 - jc * (0.004817 + 0.000014 * jc)) + Math.sin(rad(2 * M)) * (0.019993 - 0.000101 * jc) + Math.sin(rad(3 * M)) * 0.000289;
  const trueLong = L0 + C;
  const appLong = trueLong - 0.00569 - 0.00478 * Math.sin(rad(125.04 - 1934.136 * jc));
  const obliq = 23 + (26 + (21.448 - jc * (46.815 + jc * (0.00059 - jc * 0.001813))) / 60) / 60 + 0.00256 * Math.cos(rad(125.04 - 1934.136 * jc));
  const decl = Math.asin(Math.sin(rad(obliq)) * Math.sin(rad(appLong)));
  const varY = Math.tan(rad(obliq / 2)) ** 2;
  const eqTime = 4 * deg(varY * Math.sin(2 * rad(L0)) - 2 * e * Math.sin(rad(M)) + 4 * e * varY * Math.sin(rad(M)) * Math.cos(2 * rad(L0)) - 0.5 * varY * varY * Math.sin(4 * rad(L0)) - 1.25 * e * e * Math.sin(2 * rad(M)));
  const cosHa = Math.cos(rad(90.833)) / (Math.cos(rad(lat)) * Math.cos(decl)) - Math.tan(rad(lat)) * Math.tan(decl);
  if(cosHa < -1 || cosHa > 1) return null;
  const ha = deg(Math.acos(cosHa));
  const noon = 720 - 4 * lng - eqTime;
  const at = min => new Date(Date.UTC(y, mo - 1, d, 0, 0, Math.round(min * 60)));
  return { sunrise: at(noon - 4 * ha), sunset: at(noon + 4 * ha), daylightS: Math.round(8 * ha * 60) };
}
/** Metres ridden into the wind and with it: a step whose bearing is within 60° of where the wind comes from is headwind, within 60° of where it goes is tailwind. */
export function windShares(legs, windFromDeg){
  const rad = x => x * Math.PI / 180;
  let headM = 0, tailM = 0, totalM = 0;
  for(const leg of legs){
    if(!leg || !leg.routed || !Array.isArray(leg.line)) continue;
    for(let i = 1; i < leg.line.length; i++){
      const [x1, y1] = leg.line[i - 1], [x2, y2] = leg.line[i];
      const dLng = rad(x2 - x1), p1 = rad(y1), p2 = rad(y2);
      const yv = Math.sin(dLng) * Math.cos(p2), xv = Math.cos(p1) * Math.sin(p2) - Math.sin(p1) * Math.cos(p2) * Math.cos(dLng);
      const bearing = (Math.atan2(yv, xv) * 180 / Math.PI + 360) % 360;
      const dLat = p2 - p1, h = Math.sin(dLat / 2) ** 2 + Math.cos(p1) * Math.cos(p2) * Math.sin(dLng / 2) ** 2;
      const len = 2 * 6371000 * Math.asin(Math.sqrt(h));
      totalM += len;
      const diff = Math.abs(((bearing - windFromDeg) % 360 + 540) % 360 - 180);   // the angle between the step and where the wind comes from: 0 is straight into it
      if(diff <= 60) headM += len; else if(diff >= 120) tailM += len;
    }
  }
  return { headM, tailM, totalM };
}

/* ---------- the map's surface lines (route-planner.md section 4.4a) ---------- */
/** A class of the map's surface tiles as the planner's surface; null for a recorded value the planner has no class for. */
export function tileSurfaceClass(cls){
  return ({ paved: 'paved', gravel: 'gravel', pave: 'rough', dirt: 'rough', rock: 'rough', unverified: 'unknown' })[cls] || null;
}
/**
 * A leg's surfaces with the map's own lines where they were read: the tiles'
 * metres replace the engine's for the part they cover, and the rest keeps the
 * engine's split, scaled to what is left. The engine names a surface for
 * every road, guessing from the road type where OpenStreetMap has none; the
 * tiles know which roads have none.
 */
export function mergeSurfaces(engine, tile, distM){
  if(!engine) return null;
  const keys = ['paved', 'gravel', 'rough', 'unknown'];
  const seen = keys.reduce((t, k) => t + (tile[k] || 0), 0);
  if(!(seen > 0)) return engine;
  const covered = Math.min(seen, distM), scale = covered / seen;
  const engTotal = keys.reduce((t, k) => t + (engine[k] || 0), 0);
  const rest = Math.max(0, distM - covered), engScale = engTotal > 0 ? rest / engTotal : 0;
  const out = { busy: engine.busy || 0 };
  for(const k of keys) out[k] = (tile[k] || 0) * scale + (engine[k] || 0) * engScale;
  return out;
}

/* ---------- street names (route-planner.md section 4.4a) ---------- */
/** The street at a distance along the ride, from the legs' `streets` ([start metre, name]); null on an unnamed road or without names. */
export function streetAt(legs, d){
  let off = 0;
  for(const leg of legs){
    if(!leg || !leg.routed) continue;
    if(d <= off + (leg.distM || 0)){
      const rel = d - off; let name = null;
      for(const [at, n] of (leg.streets || [])){ if(at <= rel) name = n; else break; }
      return name;
    }
    off += leg.distM || 0;
  }
  return null;
}
/** A stop's street: where its next leg starts; the last stop's, where the last leg ends. */
export function stopStreet(legs, i){
  const next = legs[i];
  if(next && next.routed && Array.isArray(next.streets) && next.streets.length) return next.streets[0][1] || null;
  const prev = legs[i - 1];
  if(prev && prev.routed && Array.isArray(prev.streets) && prev.streets.length) return prev.streets[prev.streets.length - 1][1] || null;
  return null;
}
/** The road's kind at a distance along the ride (road, track, path, cycleway), what an unnamed road says instead of a name; null without names. */
export function roadAt(legs, d){
  let off = 0;
  for(const leg of legs){
    if(!leg || !leg.routed) continue;
    if(d <= off + (leg.distM || 0)){
      const rel = d - off; let kind = null;
      for(const e of (leg.streets || [])){ if(e[0] <= rel) kind = e[2] || null; else break; }
      return kind;
    }
    off += leg.distM || 0;
  }
  return null;
}
