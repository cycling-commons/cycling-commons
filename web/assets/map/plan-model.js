// SPDX-License-Identifier: AGPL-3.0-only
/* The ride planner's model (docs/specs/route-planner.md sections 4 and 6.3):
   stops and legs, where a place goes in the ride, the figures, the profile
   bins and the GPX writer. A leaf: no map, no DOM, no fetch, so node tests
   it as it is (tests/js/plan-model.test.mjs). Points are GeoJSON [lng, lat];
   a stop is {lat, lng, name, item?, letter?}; a leg is {key, routed, line,
   distM, heights} with heights as [metres along, elevation] pairs or null. */

/** A ride holds at most this many stops (route-planner.md section 4.1). */
export const MAX_STOPS = 50;
/** The corridor of the along list, metres (route-planner.md section 5). */
export const CORRIDOR_M = 250;

/** A stop is known by its place when it has one, else by its rounded point. */
export const stopKey = s => s.item ? String(s.item) : (s.lat.toFixed(5) + ',' + s.lng.toFixed(5));

/** One key per leg: both stops and the bike, so a bike change re-routes every leg; a leg ridden the shortest way
    (its arriving stop's `way`, route-planner.md section 4.2a) has a key of its own. */
export function legKeys(stops, bike){
  const out = [];
  for(let i = 0; i < stops.length - 1; i++) out.push(stopKey(stops[i]) + '|' + stopKey(stops[i + 1]) + '|' + bike + (stops[i + 1].way === 'shortest' ? '|shortest' : ''));
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

/** A rise or drop smaller than this, metres, is noise of the elevation model: whole-metre heights, trees and roofs
    in a surface model (route-planner.md section 4.4). */
export const HEIGHT_NOISE_M = 3;

/** A leg's heights as the figures read them, one rule for every ride (route-planner.md section 4.4): a three-sample
    moving average, then a height counts only once it is HEIGHT_NOISE_M or more from the last height that counted,
    and the heights between two that count lie on the straight line from one to the other. One elevation per sample. */
export function cleanHeights(heights){
  const h = heights.map(x => x[1]);
  const sm = h.map((v, i) => (h[Math.max(0, i - 1)] + v + h[Math.min(h.length - 1, i + 1)]) / 3);
  const keep = [0];
  for(let i = 1; i < sm.length; i++){ if(Math.abs(sm[i] - sm[keep[keep.length - 1]]) >= HEIGHT_NOISE_M) keep.push(i); }
  if(keep[keep.length - 1] !== sm.length - 1) keep.push(sm.length - 1);
  const out = new Array(sm.length);
  for(let k = 1; k < keep.length; k++){
    const a = keep[k - 1], b = keep[k], d0 = heights[a][0], span = heights[b][0] - d0;
    for(let i = a; i <= b; i++) out[i] = sm[a] + (sm[b] - sm[a]) * (span > 0 ? (heights[i][0] - d0) / span : (i - a) / Math.max(1, b - a));
  }
  if(sm.length === 1) out[0] = sm[0];
  return out;
}

/* Climbed and descended over the cleaned heights: every rise and every drop. */
function climbOf(heights){
  const sm = cleanHeights(heights);
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

/** The part of the ride between two distances along it, [[lng, lat], ...], measured as pointAt() measures: metres along the
    drawn line scaled to the engine's length. Empty when the span lies past the ride. */
export function sliceAlong(legs, fromM, toM){
  const out = [];
  let off = 0;
  for(const leg of legs){
    if(!leg || !leg.routed) continue;
    const line = leg.line;
    if(!line || line.length < 2){ off += leg.distM || 0; continue; }
    const P = proj(line[0]), seg = [];
    let total = 0;
    for(let i = 1; i < line.length; i++){ const a = P(line[i - 1]), b = P(line[i]); const len = Math.hypot(b[0] - a[0], b[1] - a[1]); seg.push(len); total += len; }
    const k = total ? (leg.distM || 0) / total : 0;
    let at = off;
    for(let i = 0; i < seg.length; i++){
      const len = seg[i] * k, a = at, b = at + len;
      const pt = f => [line[i][0] + (line[i + 1][0] - line[i][0]) * f, line[i][1] + (line[i + 1][1] - line[i][1]) * f];
      if(b >= fromM && a <= toM && len > 0){
        if(!out.length) out.push(a >= fromM ? line[i] : pt((fromM - a) / len));
        out.push(b <= toM ? line[i + 1] : pt((toM - a) / len));
      }
      at = b;
    }
    off += leg.distM || 0;
  }
  return out;
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
   legs carry, one waypoint per stop, a place stop with a link to its page.
   It is also the ride file (section 6.1): our own extensions carry the bike
   and each stop's place, so opening it again rebuilds the ride. The day is
   not in it: a kept ride is ridden on many days (section 4.4). */
const CC_NS = 'https://cyclingcommons.org/xmlns/ride/1';
export function gpxText(ride, o){
  const name = ride.name || 'Ride';
  const itemUrl = (o && o.itemUrl) || null;
  const meta = ride.bike ? `<cc:bike>${xml(ride.bike)}</cc:bike>` : '';
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
    const parts = (s.item ? `<cc:item>${xml(s.item)}</cc:item>${s.letter ? `<cc:letter>${xml(s.letter)}</cc:letter>` : ''}` : '') + (s.way === 'shortest' ? '<cc:way>shortest</cc:way>' : '');
    const ext = parts ? `<extensions>${parts}</extensions>` : '';
    return `<wpt lat="${s.lat.toFixed(6)}" lon="${s.lng.toFixed(6)}"><name>${xml(s.name)}</name>${link}${ext}</wpt>`;
  });
  return `<?xml version="1.0" encoding="UTF-8"?>\n<gpx version="1.1" creator="Cycling Commons" xmlns="http://www.topografix.com/GPX/1/1" xmlns:cc="${CC_NS}">\n`
    + `<metadata><name>${xml(name)}</name>${meta ? `<extensions>${meta}</extensions>` : ''}</metadata>\n${wpts.join('\n')}\n<trk><name>${xml(name)}</name><trkseg>\n${pts.join('\n')}\n</trkseg></trk>\n</gpx>\n`;
}

const unxml = s => String(s).replace(/&(lt|gt|amp|quot|apos|#\d+|#x[0-9a-f]+);/gi, (m, e) => {
  const named = { lt: '<', gt: '>', amp: '&', quot: '"', apos: "'" }[e.toLowerCase()];
  if(named) return named;
  const code = e[1] === 'x' || e[1] === 'X' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10);
  return Number.isFinite(code) && code > 0 && code < 0x110000 ? String.fromCodePoint(code) : '';
});
const tagText = (src, tag) => { const m = src.match(new RegExp(`<${tag}(?:\\s[^>]*)?>([\\s\\S]*?)</${tag}>`)); return m ? unxml(m[1].trim()) : null; };
const attr = (tagSrc, a) => { const m = tagSrc.match(new RegExp(`\\s${a}\\s*=\\s*("([^"]*)"|'([^']*)')`)); return m ? (m[2] ?? m[3]) : null; };

/** A ride file back into a ride (route-planner.md section 6.1): the planner's own GPX, the one gpxText()
    writes, older ones without the extensions included. `{error}` says why a file is refused:
    not_gpx, no_stops, too_many, bad_point. Names are cut to 80 characters. A GPX from another app is converted
    (foreignRide()). */
export function rideFromGpx(text){
  const src = String(text || '');
  const root = src.match(/<gpx\b[^>]*>/);
  if(!root) return { error: 'not_gpx' };
  if(attr(root[0], 'creator') !== 'Cycling Commons' && !root[0].includes(CC_NS)) return foreignRide(src);
  const wpts = [...src.matchAll(/<wpt\b([^>]*)>([\s\S]*?)<\/wpt>/g)];
  if(!wpts.length) return { error: 'no_stops' };
  if(wpts.length > MAX_STOPS) return { error: 'too_many' };
  const stops = [];
  for(const [, attrs, body] of wpts){
    const lat = Number(attr(' ' + attrs, 'lat')), lng = Number(attr(' ' + attrs, 'lon'));
    if(!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return { error: 'bad_point' };
    const stop = { lat, lng, name: (tagText(body, 'name') || '').slice(0, 80) };
    const item = tagText(body, 'cc:item'), letter = tagText(body, 'cc:letter');
    if(item){ stop.item = item.slice(0, 80); if(letter) stop.letter = letter.slice(0, 4); }
    if(tagText(body, 'cc:way') === 'shortest') stop.way = 'shortest';
    stops.push(stop);
  }
  const meta = (src.match(/<metadata\b[^>]*>([\s\S]*?)<\/metadata>/) || [null, ''])[1];
  return { name: (tagText(meta, 'name') || '').slice(0, 80), bike: tagText(meta, 'cc:bike'), stops };
}

/* Points of one GPX element, written open (`<trkpt ...>...</trkpt>`) or closed (`<trkpt .../>`), the attributes in
   any order; a point off the globe is left out, as a device writes one now and then. */
function gpxPoints(src, tag){
  const out = [];
  for(const m of src.matchAll(new RegExp(`<${tag}\\b([^>]*?)(?:/>|>([\\s\\S]*?)</${tag}>)`, 'g'))){
    const lat = Number(attr(' ' + m[1], 'lat')), lng = Number(attr(' ' + m[1], 'lon'));
    if(!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) continue;
    out.push({ lat, lng, name: m[2] ? (tagText(m[2], 'name') || '').slice(0, 80) : '' });
  }
  return out;
}
/* A GPX from another app as a ride (route-planner.md section 6.1, owner 2026-10-16: "You need to convert it
   then"): its track, else its route, else its waypoints, as checkpoints the planner routes again for the rider's
   bike. A route or a list of waypoints that fits is taken as it is, its points being the ones its author chose;
   a track, or a longer list, keeps the points that hold its shape (simplifyTrack()). The bike stays the rider's;
   `converted` says what the ride came from. */
function foreignRide(src){
  const track = gpxPoints(src, 'trkpt'), route = gpxPoints(src, 'rtept'), marks = gpxPoints(src, 'wpt');
  const [from, all] = track.length ? ['track', track] : route.length ? ['route', route] : ['waypoints', marks];
  // A device standing still writes the same place again and again: one checkpoint for it.
  const line = all.filter((p, i) => i === 0 || metres([all[i - 1].lat, all[i - 1].lng], [p.lat, p.lng]) >= 5);
  if(!line.length) return { error: 'no_stops' };
  const kept = from !== 'track' && line.length <= MAX_STOPS ? line : simplifyTrack(line, MAX_STOPS).map(p => Object.assign({}, p, { name: from === 'track' ? '' : p.name }));
  const meta = (src.match(/<metadata\b[^>]*>([\s\S]*?)<\/metadata>/) || [null, ''])[1];
  const head = (src.match(from === 'route' ? /<rte\b[^>]*>([\s\S]*?)<rtept\b/ : /<trk\b[^>]*>([\s\S]*?)<trkseg\b/) || [null, ''])[1];
  return {
    name: (tagText(meta, 'name') || tagText(head, 'name') || '').slice(0, 80),
    bike: null,
    stops: kept.map(p => ({ lat: p.lat, lng: p.lng, name: p.name })),
    converted: { from, points: all.length },
  };
}

/** The most points the along request takes (PlanController::MAX_LINE_POINTS). */
export const ALONG_MAX_POINTS = 5000;
/** A line cut to `max` points at most for a request: every k-th point, the first and the last always. A
    line that fits comes back as it is. A road line's points lie metres apart, so the corridor barely moves. */
export function thinLine(line, max){
  if(line.length <= max) return line;
  const step = Math.ceil((line.length - 1) / (max - 2)), out = [];
  for(let i = 0; i < line.length; i += step) out.push(line[i]);
  if(out[out.length - 1] !== line[line.length - 1]) out.push(line[line.length - 1]);
  return out;
}

/** Metres a converted line may stray from the straight line between two kept points before the point farthest
    off becomes a checkpoint too (simplifyTrack()). */
export const SHAPE_M = 25;
/** The points of a long line that keep its shape, `max` at most, its first and last always, in line order
    (route-planner.md section 6.1). Corners first: as Douglas-Peucker splits, the point farthest from the straight
    line between two kept points, until none lies more than SHAPE_M off. The checkpoints left go to the longest
    stretches, each split at its middle along the line, so a long straight road is not left to the engine's own
    choice of road. Points are `{lat, lng}`; a line of `max` points or fewer comes back whole. */
export function simplifyTrack(points, max){
  const n = points.length;
  if(n <= max || max < 2) return points.slice(0, Math.max(max, 0) || n);
  const P = proj([points[0].lng, points[0].lat]);
  const xy = points.map(p => P([p.lng, p.lat]));
  const along = [0];
  for(let i = 1; i < n; i++) along.push(along[i - 1] + Math.hypot(xy[i][0] - xy[i - 1][0], xy[i][1] - xy[i - 1][1]));
  // The point between i and j farthest off the line from i to j; for a loop's i = j end, off the point itself.
  const farthest = (i, j) => {
    const a = xy[i], b = xy[j], dx = b[0] - a[0], dy = b[1] - a[1], l2 = dx * dx + dy * dy;
    let k = -1, off = 0;
    for(let m = i + 1; m < j; m++){
      const q = xy[m];
      let t = l2 ? ((q[0] - a[0]) * dx + (q[1] - a[1]) * dy) / l2 : 0;
      t = Math.max(0, Math.min(1, t));
      const d = Math.hypot(q[0] - (a[0] + t * dx), q[1] - (a[1] + t * dy));
      if(d > off){ off = d; k = m; }
    }
    return { i, j, k, off };
  };
  const keep = new Set([0, n - 1]);
  let spans = [farthest(0, n - 1)];
  const split = (s, k) => { keep.add(k); spans = spans.filter(x => x !== s).concat([farthest(s.i, k), farthest(k, s.j)]); };
  while(keep.size < max){
    const s = spans.reduce((b, x) => (x.off > (b ? b.off : -1) ? x : b), null);
    if(!s || s.k < 0 || s.off <= SHAPE_M) break;
    split(s, s.k);
  }
  while(keep.size < max){
    const s = spans.filter(x => x.j - x.i >= 2).reduce((b, x) => (!b || along[x.j] - along[x.i] > along[b.j] - along[b.i] ? x : b), null);
    if(!s) break;
    const half = (along[s.i] + along[s.j]) / 2;
    let k = s.i + 1;
    while(k < s.j - 1 && along[k] < half) k++;
    split(s, k);
  }
  return [...keep].sort((a, b) => a - b).map(i => points[i]);
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
    /** The newest step taken back without a redo step: a change the planner tried and withdrew (a water stop that
        did not help) is not the rider's to redo. */
    discard(){ return past.length ? past.pop() : null; },
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

/** The padding that keeps a fitted ride clear of what covers the map (route-planner.md section 3): `box` is the map's
    rectangle, `covers` the rectangles over it ({left, top, right, bottom}, page pixels), `gap` the margin on every side.
    A cover counts on the edge it touches; one in a corner counts on the edge where it costs the smaller share of the map. */
export function coverPadding(box, covers, gap){
  const pad = { top: gap, right: gap, bottom: gap, left: gap };
  const W = box.right - box.left, H = box.bottom - box.top, EDGE = 24;
  if(!(W > 0) || !(H > 0)) return pad;
  for(const c of covers || []){
    if(!c || c.right <= box.left || c.left >= box.right || c.bottom <= box.top || c.top >= box.bottom) continue;
    const opts = [];
    if(c.left <= box.left + EDGE) opts.push(['left', c.right - box.left, (c.right - box.left) / W]);
    if(c.right >= box.right - EDGE) opts.push(['right', box.right - c.left, (box.right - c.left) / W]);
    if(c.top <= box.top + EDGE) opts.push(['top', c.bottom - box.top, (c.bottom - box.top) / H]);
    if(c.bottom >= box.bottom - EDGE) opts.push(['bottom', box.bottom - c.top, (box.bottom - c.top) / H]);
    if(!opts.length) continue;
    opts.sort((a, b) => a[2] - b[2]);
    const [side, px] = opts[0];
    pad[side] = Math.max(pad[side], px + gap);
  }
  // A map too small for the padding keeps half of each axis for the ride.
  if(pad.left + pad.right > W / 2){ const k = W / 2 / (pad.left + pad.right); pad.left *= k; pad.right *= k; }
  if(pad.top + pad.bottom > H / 2){ const k = H / 2 / (pad.top + pad.bottom); pad.top *= k; pad.bottom *= k; }
  return pad;
}

/* Overlapping or touching stretches, [fromM, toM], as one; short gaps under `joinM` closed. */
function joinSpans(spans, joinM){
  const out = [];
  for(const s of spans.sort((a, b) => a[0] - b[0])){
    const last = out[out.length - 1];
    if(last && s[0] - last[1] <= joinM) last[1] = Math.max(last[1], s[1]); else out.push([s[0], s[1]]);
  }
  return out;
}
/** The stretches of the ride, [fromM, toM] in metres along it, ridden at a gradient class (route-planner.md section
    4.4): `climb` steeper than 2 %, `descend` steeper than -2 %, `flat` between, over the cleaned heights as the day's
    split reads them, so the stretches lit on the map are the minutes of the bar. */
export function gradientSpans(legs, cls){
  const spans = []; let off = 0;
  for(const leg of legs){
    if(!leg || !leg.routed) continue;
    const hs = leg.heights;
    if(!Array.isArray(hs) || hs.length < 2){ if(cls === 'flat') spans.push([off, off + (leg.distM || 0)]); off += leg.distM || 0; continue; }
    const sm = cleanHeights(hs), sum = hs[hs.length - 1][0] - hs[0][0], k = sum > 0 ? leg.distM / sum : 1;
    for(let i = 1; i < hs.length; i++){
      const len = hs[i][0] - hs[i - 1][0]; if(!(len > 0)) continue;
      const g = (sm[i] - sm[i - 1]) / len;
      const c = g >= 0.02 ? 'climb' : g <= -0.02 ? 'descend' : 'flat';
      if(c === cls) spans.push([off + (hs[i - 1][0] - hs[0][0]) * k, off + (hs[i][0] - hs[0][0]) * k]);
    }
    off += leg.distM || 0;
  }
  return joinSpans(spans, 1);
}
/** The stretches of the ride on a surface class (`paved`, `gravel`, `rough`, `unknown`), [fromM, toM] in metres along
    it: the engine's runs of each leg ([start metre, class]), where the map's own surface line was read at a sample
    (`seenOf(leg)`, sample index to class, every `sampleM`) that reading winning, as the day's split counts it. A leg
    without runs is unknown. */
export function surfaceSpans(legs, cls, seenOf, sampleM){
  const spans = []; let off = 0;
  for(const leg of legs){
    if(!leg || !leg.routed) continue;
    const runs = Array.isArray(leg.runs) && leg.runs.length ? leg.runs : [[0, 'unknown']];
    const seen = (seenOf && seenOf(leg)) || new Map();
    const runAt = d => { let c = runs[0][1]; for(const r of runs){ if(r[0] <= d) c = r[1]; else break; } return c; };
    const step = sampleM || 50, n = Math.ceil((leg.distM || 0) / step);
    for(let i = 0; i < n; i++){
      const a = i * step, b = Math.min(leg.distM, a + step);
      const c = seen.has(i) ? seen.get(i) : runAt(a + (b - a) / 2);
      if(c === cls) spans.push([off + a, off + b]);
    }
    off += leg.distM || 0;
  }
  return joinSpans(spans, 1);
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
/* The steps of a leg as [metres, gradient]: the cleaned heights (the climbed rule's), scaled to the leg's length; a leg without heights is one flat step. */
function stepsOf(leg){
  const hs = leg.heights;
  if(!Array.isArray(hs) || hs.length < 2) return [[leg.distM, 0]];
  const sm = cleanHeights(hs);
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
/** Seconds as a duration in the page's words (route-planner.md section 4.4), to `stepMin` minutes: `words.hm` for
    hours and minutes ("{h}h {m}m", "3h 50m"), `words.h` on the hour ("{h}h"), `words.min` under an hour ("{m}m").
    The minutes are counted first, so 59.6 minutes is "1h", never "0h 60m". */
export function durationText(seconds, words, stepMin = 1){
  const w = words || {};
  const total = Math.max(0, Math.round(seconds / 60 / stepMin) * stepMin), h = Math.floor(total / 60), m = total % 60;
  const fill = (s, v) => String(s).replace(/\{(\w+)\}/g, (x, k) => (k in v ? String(v[k]) : x));
  if(!h) return fill(w.min || '{m}m', { m });
  if(!m) return fill(w.h || '{h}h', { h });
  return fill(w.hm || '{h}h {m}m', { h, m });
}

/** What makes a ride tougher than an ordinary one (route-planner.md section 4.4): a factor gets its line in
    the panel only from these. The one place the baselines are set. */
export const TOUGHER = {
  /** The ride is longer than this, km: a long day. */
  longKm: 100,
  /** The steepest kilometre averages this or more, percent. */
  steepGrad: 8,
  /** The longest climb is this long or more, km ... */
  climbKm: 5,
  /** ... or climbs this much or more, metres. */
  climbUpM: 300,
  /** This much or more on rough surface (cobbles, dirt and the like), km. */
  roughKm: 5,
  /** The longest stretch without water or food is longer than this, km. */
  waterKm: 40,
  /** This much or more on busy roads without a cycle lane, km. */
  busyKm: 5,
  /** The riding time plus this does not fit in the daylight, seconds: an hour to spare. */
  daylightSpareS: 3600,
  /** Daylight under this is short whatever the ride, seconds: 10 hours. */
  daylightMinS: 10 * 3600,
  /** The day's highest wind speed is this or more, km/h: Beaufort 5 and up ... */
  windKmh: 30,
  /** ... or its gusts reach this or more, km/h, when the forecast has them. */
  gustKmh: 50,
  /** The day's highest temperature is this or more, °C. */
  heatC: 30,
  /** The day's rain is this or more, mm. */
  rainMm: 1,
  /** The wind chill on the bike, riding into the wind, is this or colder, °C. */
  coldFeelC: 0,
  /** The heat with its humidity feels this hot or hotter, °C (NOAA's "extreme caution" from 32). */
  heatFeelC: 32,
};

/** Below this the wind chill is read, °C: the formula's own reach (Environment Canada and the US weather service). */
export const CHILL_FROM_C = 10;
/** From this the heat with its humidity is read, °C: the heat index's own reach (about 80 °F). */
export const HUMID_FROM_C = 27;
/** The wind chill, °C, of air at `tC` moving past at `kmh` (the 2001 North American formula); the air itself outside
    its reach (above 10 °C or under 4.8 km/h). */
export function windChillC(tC, kmh){
  if(!(tC <= CHILL_FROM_C) || !(kmh >= 4.8)) return tC;
  const v = Math.pow(kmh, 0.16);
  return 13.12 + 0.6215 * tC - 11.37 * v + 0.3965 * tC * v;
}
/** The heat index, °C, of air at `tC` with `rh` % humidity (NOAA's Rothfusz regression with its two adjustments); the
    air itself below its reach. */
export function heatIndexC(tC, rh){
  if(!(tC >= HUMID_FROM_C) || !Number.isFinite(rh)) return tC;
  const T = tC * 9 / 5 + 32, R = rh;
  let hi = -42.379 + 2.04901523 * T + 10.14333127 * R - 0.22475541 * T * R - 0.00683783 * T * T - 0.05481717 * R * R
    + 0.00122874 * T * T * R + 0.00085282 * T * R * R - 0.00000199 * T * T * R * R;
  if(R < 13 && T >= 80 && T <= 112) hi -= (13 - R) / 4 * Math.sqrt((17 - Math.abs(T - 95)) / 17);
  else if(R > 85 && T >= 80 && T <= 87) hi += (R - 85) / 10 * (87 - T) / 5;
  return Math.max(tC, (hi - 32) * 5 / 9);
}
/* Weather codes of freezing drizzle or rain and of snow (WMO 4677, as Open-Meteo gives them). */
const FREEZING_CODES = new Set([56, 57, 66, 67]), SNOW_CODES = new Set([71, 73, 75, 77, 85, 86]);
/** How the hours ridden feel (route-planner.md section 4.4): `cold`, the coldest wind chill on the bike riding into
    the wind (the wind plus `paceKmh`), with its hour, when an hour is at 10 °C or under; `hot`, the hottest heat index
    with its humidity and hour, when an hour is at 27 °C or over and the humidity is known; `ice`, the first hour with a
    chance of ice and why: freezing rain or drizzle, snow, or frost on a damp road (the ground at 0 °C or under, or the
    air at 1 °C or under without a ground figure, with rain in that hour or the three before, or the air within 1 °C of
    its dew point). Each null when it does not apply. Needs the forecast's hours, from rideHours(). */
export function rideFeel(fc, paceKmh){
  const out = { cold: null, hot: null, ice: null };
  if(!fc || !Array.isArray(fc.hours) || !Number.isFinite(fc.fromH) || !Number.isFinite(fc.toH)) return out;
  for(let h = fc.fromH; h <= fc.toH; h++){
    const x = fc.hours[h]; if(!x) continue;
    if(x.tempC <= CHILL_FROM_C){
      const c = windChillC(x.tempC, x.windKmh + (Number.isFinite(paceKmh) ? paceKmh : 0));
      if(!out.cold || c < out.cold.feelC) out.cold = { feelC: c, tempC: x.tempC, hour: h };
    }
    if(x.tempC >= HUMID_FROM_C && Number.isFinite(x.humidity)){
      const f = heatIndexC(x.tempC, x.humidity);
      if(!out.hot || f > out.hot.feelC) out.hot = { feelC: f, tempC: x.tempC, humidity: x.humidity, hour: h };
    }
    if(!out.ice){
      const cold = Number.isFinite(x.groundC) ? x.groundC <= 0 : x.tempC <= 1;
      const wetBefore = [0, 1, 2, 3].some(k => { const y = fc.hours[h - k]; return y && y.rainMm > 0; });
      const damp = Number.isFinite(x.dewC) && x.tempC - x.dewC <= 1;
      if(FREEZING_CODES.has(x.code)) out.ice = { hour: h, why: 'freezing' };
      else if(SNOW_CODES.has(x.code) || x.snowCm > 0) out.ice = { hour: h, why: 'snow' };
      else if(cold && (wetBefore || damp)) out.ice = { hour: h, why: 'frost' };
    }
  }
  return out;
}

/** The lines of "What makes it tougher" (route-planner.md section 4.4), each factor only past its TOUGHER baseline, in
    the panel's order: distance, steepest kilometre, longest climb, rough surface, water, busy roads, daylight, wind,
    heat, rain. Every input may be missing; a missing one gives no line. `o`: distM, steep (steepestKm()), climb
    (longestClimb()), roughM, waterKm (waterGap()), busyM, sun (sunTimes()), rideS (the riding time), forecast (the
    weather answer for the ride's day). An empty list is a ride with nothing out of the ordinary. */
export function tougherLines(o){
  const t = TOUGHER, out = [];
  if(o.distM > t.longKm * 1000) out.push({ kind: 'long', distM: o.distM });
  if(o.steep && o.steep.grad >= t.steepGrad) out.push({ kind: 'steep', grad: o.steep.grad, atKm: o.steep.atKm });
  if(o.climb && (o.climb.km >= t.climbKm || o.climb.upM >= t.climbUpM)) out.push({ kind: 'climb', km: o.climb.km, upM: o.climb.upM, grad: o.climb.grad });
  if(o.roughM >= t.roughKm * 1000) out.push({ kind: 'rough', roughM: o.roughM });
  if(o.waterKm != null && o.waterKm > t.waterKm) out.push({ kind: 'water', km: o.waterKm });
  if(o.busyM >= t.busyKm * 1000) out.push({ kind: 'busy', busyM: o.busyM });
  if(o.sun){
    const ride = Number.isFinite(o.rideS) ? o.rideS : 0;
    // With a start time the ride sits in the day: starting before sunrise or arriving after sunset is riding in the dark.
    const startMs = o.startAt instanceof Date ? o.startAt.getTime() : NaN;
    let over = null, arrive = null;
    if(ride > o.sun.daylightS) over = 'over';
    else if(Number.isFinite(startMs)){
      arrive = new Date(startMs + ride * 1000);
      if(startMs < o.sun.sunrise.getTime()) over = 'darkStart';
      else if(arrive.getTime() > o.sun.sunset.getTime()) over = 'darkEnd';
      else if(arrive.getTime() + t.daylightSpareS * 1000 > o.sun.sunset.getTime()) over = 'tight';
    }
    else if(ride + t.daylightSpareS > o.sun.daylightS) over = 'tight';
    if(over || o.sun.daylightS < t.daylightMinS) out.push({ kind: 'daylight', sun: o.sun, over, arrive });
  }
  const fc = o.forecast, feel = o.feel || {};
  if(feel.cold && feel.cold.feelC <= t.coldFeelC) out.push({ kind: 'cold', feelC: feel.cold.feelC });
  if(feel.ice) out.push({ kind: 'ice', hour: feel.ice.hour, why: feel.ice.why });
  if(fc){
    const gust = Number.isFinite(fc.gustKmh) && fc.gustKmh >= t.gustKmh ? fc.gustKmh : null;
    if(fc.windKmh >= t.windKmh || gust != null) out.push({ kind: 'wind', windKmh: fc.windKmh, gustKmh: gust, fromDeg: fc.windFromDeg });
    const hot = feel.hot && feel.hot.feelC >= t.heatFeelC ? feel.hot : null;
    if(fc.tempMaxC >= t.heatC || hot) out.push({ kind: 'heat', tempC: fc.tempMaxC, feelC: hot ? hot.feelC : null, humidity: hot ? hot.humidity : null });
    if(fc.rainMm >= t.rainMm) out.push({ kind: 'rain', mm: fc.rainMm });
  }
  return out;
}

/** The forecast for the hours the rider rides (route-planner.md section 4.4): from the hour of `startMin` (minutes
    after local midnight) to the hour the riding time ends, the highest wind and gust, the wind's direction weighted by
    its speed, the highest temperature and the rain of those hours; the day's figures without hours or a start. A ride
    past midnight reads to the day's last hour. */
export function rideHours(forecast, startMin, rideS){
  if(!forecast || !Array.isArray(forecast.hours) || forecast.hours.length !== 24 || !Number.isFinite(startMin)) return forecast;
  const from = Math.max(0, Math.min(23, Math.floor(startMin / 60)));
  const endMin = startMin + (Number.isFinite(rideS) ? rideS : 0) / 60;
  const to = Math.max(from, Math.min(23, Math.ceil(endMin / 60) - 1));
  const hs = forecast.hours.slice(from, to + 1);
  const gusts = hs.map(h => h.gustKmh).filter(Number.isFinite);
  let x = 0, y = 0;
  for(const h of hs){ const r = h.fromDeg * Math.PI / 180; x += Math.sin(r) * h.windKmh; y += Math.cos(r) * h.windKmh; }
  const fromDeg = x === 0 && y === 0 ? hs[0].fromDeg : Math.round((Math.atan2(x, y) * 180 / Math.PI + 360) % 360);
  return { ...forecast, windKmh: Math.max(...hs.map(h => h.windKmh)), gustKmh: gusts.length ? Math.max(...gusts) : null, windFromDeg: fromDeg,
    tempMaxC: Math.max(...hs.map(h => h.tempC)), rainMm: Math.round(hs.reduce((t, h) => t + h.rainMm, 0) * 10) / 10, fromH: from, toH: to };
}

/** The start time offered for a day (route-planner.md section 4.4), minutes after midnight: 09:00, or on today past
    09:00 the next half hour, never past 22:00. `nowMin` is the minutes of now on today, null on another day. */
export function defaultStartMin(nowMin){
  if(!Number.isFinite(nowMin) || nowMin < 9 * 60) return 9 * 60;
  return Math.min(22 * 60, Math.ceil((nowMin + 1) / 30) * 30);
}

/** Where the forecast is asked (route-planner.md section 4.4): only rides this long or longer, km; a point this far
    or farther in a straight line from the start and from the finish, km; rounded to this, degrees, before it leaves
    the browser. The start or the finish is often a home, so neither is ever near the point asked. */
export const FORECAST = { minRideKm: 20, minFromEndsKm: 5, cellDeg: 0.1 };

/** The point the forecast is asked for, [lng, lat] rounded to FORECAST.cellDeg, or null when none is asked: a ride
    under FORECAST.minRideKm, or one with no point in its middle third FORECAST.minFromEndsKm from both its start and
    its finish (laps of a short circuit). The point halfway along the ride when that is far enough from both ends;
    otherwise the point of the middle third farthest from the nearer end, as a loop comes back past home. The ride
    as it is now: a ride still being drawn asks for its own middle, and a later ask follows the ride. */
export function forecastPoint(legs){
  const total = figures(legs).distM;
  if(!(total >= FORECAST.minRideKm * 1000)) return null;
  const start = pointAt(legs, 0), end = pointAt(legs, total);
  if(!start || !end) return null;
  const fromEnds = p => Math.min(metres([p[1], p[0]], [start[1], start[0]]), metres([p[1], p[0]], [end[1], end[0]]));
  const need = FORECAST.minFromEndsKm * 1000;
  let best = pointAt(legs, total / 2), bestM = best ? fromEnds(best) : -1;
  if(bestM < need){
    for(let i = 0; i <= 60; i++){
      const p = pointAt(legs, total / 3 + total / 3 * i / 60); if(!p) continue;
      const m = fromEnds(p); if(m > bestM){ best = p; bestM = m; }
    }
  }
  if(!best || bestM < need) return null;
  const cell = v => Math.round(v / FORECAST.cellDeg) * FORECAST.cellDeg;
  return [Number(cell(best[0]).toFixed(1)), Number(cell(best[1]).toFixed(1))];
}

/** Open-Meteo's own page for the forecast the panel shows (route-planner.md section 4.4): the rounded point our server
    asked for (`forecast.lat`, `forecast.lng`) and the ride's day, the same daily figures, by the hour too. The rider
    opens it with a click; the browser never fetches it. Null without the point or a date. */
export function forecastPageUrl(forecast){
  if(!forecast || !Number.isFinite(forecast.lat) || !Number.isFinite(forecast.lng) || !/^\d{4}-\d{2}-\d{2}$/.test(String(forecast.date || ''))) return null;
  const q = [
    'latitude=' + forecast.lat.toFixed(1), 'longitude=' + forecast.lng.toFixed(1),
    'start_date=' + forecast.date, 'end_date=' + forecast.date,
    'daily=wind_speed_10m_max,wind_gusts_10m_max,wind_direction_10m_dominant,temperature_2m_max,precipitation_sum',
    'hourly=temperature_2m,precipitation,wind_speed_10m,wind_gusts_10m,wind_direction_10m',
    'timezone=auto',
  ];
  return 'https://open-meteo.com/en/docs?' + q.join('&');
}
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
      if(len >= 1000 && (!best || len > best.km * 1000)) best = { km: len / 1000, upM: up, grad: up / len * 100, fromM: run[start].d0 };
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
  const span = waterGapSpan(rows, distM);
  return span ? Math.round(span.km * 10) / 10 : null;
}
/** The ride's own place stops as along rows (route-planner.md section 4.4): their letter at the distance the ride reaches
    them, so a water stop splits the dry stretch even when its pin lies farther than the corridor from the road its leg
    ends on. A stop after an unrouted leg has no distance yet. */
export function stopRows(stops, legs){
  const out = [];
  if(stops[0] && stops[0].letter) out.push({ letter: stops[0].letter, alongKm: 0 });
  let off = 0;
  for(let i = 1; i < stops.length; i++){
    const leg = legs[i - 1];
    if(!leg || !leg.routed) break;
    off += leg.distM;
    if(stops[i].letter) out.push({ letter: stops[i].letter, alongKm: off / 1000 });
  }
  return out;
}
/** Where the longest stretch without water or food lies: from and to as km along the ride; null while the list is capped. */
export function waterGapSpan(rows, distM){
  const b = rows.filter(r => r.letter === 'B');
  if(b.some(r => r.truncated)) return null;
  const total = distM / 1000;
  const marks = [0, ...b.map(r => r.alongKm).sort((x, y) => x - y), total];
  let best = { fromKm: 0, toKm: total, km: total };
  if(b.length){
    best = { fromKm: 0, toKm: 0, km: -1 };
    for(let i = 1; i < marks.length; i++){ const km = marks[i] - marks[i - 1]; if(km > best.km) best = { fromKm: marks[i - 1], toKm: marks[i], km }; }
  }
  return best;
}
/** The water stop that best fills a dry stretch of the ride (fromM to toM along it), from /map/coverage/nearby's groups,
    ours or OpenStreetMap's, as a stop for the ride (the drawer's item names: `B:<id>` for ours, `osm:<ref>` for a coverage
    point). Each place is measured to its nearest point of the stretch (`{lat, lng, alongM}`); the one that leaves the
    shortest dry part wins: the longer of the stretch before it and after it, plus the way there and back (owner
    2026-10-15: the nearest one sat at the end of the stretch and its detour made the gap longer). The answer carries the
    distance off the line, the `alongM` where it belongs in the ride, and `leftM`, the dry part it leaves, which the planner
    weighs against the stretch. Null when there is none. */
export function bestWaterStop(groups, points, fromM, toM){
  const b = (Array.isArray(groups) ? groups : []).find(g => g && g.letter === 'B');
  let best = null;
  for(const it of (b && b.items) || []){
    if(!Array.isArray(it.ll)) continue;
    let near = null;
    for(const pt of points || []){ const distM = metres([pt.lat, pt.lng], it.ll); if(!near || distM < near.distM) near = { distM, alongM: pt.alongM }; }
    if(!near) continue;
    const left = Math.max(near.alongM - fromM, toM - near.alongM) + 2 * near.distM;
    if(!best || left < best.leftM) best = { item: it.itemId != null ? 'B:' + it.itemId : 'osm:' + it.ref, letter: 'B', name: it.n || '', lat: it.ll[0], lng: it.ll[1], distM: near.distM, alongM: near.alongM, leftM: left, food: it.food === true, potable: it.potable ?? null };
  }
  return best;
}
/** The index of the stop after the leg that holds a distance along the ride, where a stop at that distance goes;
    null past the ride's end. */
export function stopIndexAt(legs, d){
  let off = 0;
  for(let i = 0; i < legs.length; i++){
    const leg = legs[i];
    if(!leg || !leg.routed) continue;
    if(d <= off + leg.distM) return i + 1;
    off += leg.distM;
  }
  return null;
}
/** A leg that clearly goes round (route-planner.md section 4.2a): at least 1.3 times the straight distance between its
    stops and at least 500 m more; only such a leg offers "Why this way?". */
export function legGoesRound(leg, a, b){
  if(!leg || !leg.routed || !a || !b) return false;
  const straight = metres([a.lat, a.lng], [b.lat, b.lng]);
  return leg.distM >= 1.3 * straight && leg.distM - straight >= 500;
}
/** Why the leg takes the way it does, against the shortest way the same bike may ride (`alt`): the main reason, in this
    order, gravel or dirt the bike avoids, climbing it saves, busy roads it keeps off, cycle paths it keeps to, traffic
    lights it keeps clear of; `shortest` when the two are about equal; `other` when nothing measured stands out, which
    claims no reason (owner 2026-10-16: "asphalt vs asphalt"); null with nothing to compare. */
export function whyThisWay(cur, alt){
  if(!cur || !cur.routed || !alt || !alt.routed) return null;
  if(alt.distM >= cur.distM * 0.97 || cur.distM - alt.distM < 200) return { kind: 'shortest' };
  const surf = l => l.surfaces || {};
  const unpaved = l => (surf(l).gravel || 0) + (surf(l).rough || 0);
  const up = l => Array.isArray(l.heights) && l.heights.length > 1 ? climbOf(l.heights).up : 0;
  const base = { curM: cur.distM, altM: alt.distM };
  const unpavedM = unpaved(alt) - unpaved(cur);
  if(unpavedM >= 300) return { kind: 'surface', ...base, unpavedM: Math.round(unpavedM) };
  const upM = up(alt) - up(cur);
  if(upM >= 40) return { kind: 'climb', ...base, upM: Math.round(upM) };
  const busyM = (surf(alt).busy || 0) - (surf(cur).busy || 0);
  if(busyM >= 500) return { kind: 'busy', ...base, busyM: Math.round(busyM) };
  // Cycle paths: half a kilometre more of them, and a larger share of the way, not only more road in total.
  const cyc = l => surf(l).cycle || 0;
  if(cyc(cur) - cyc(alt) >= 500 && cyc(cur) / cur.distM > cyc(alt) / alt.distM + 0.2) return { kind: 'cycle', ...base, curCycleM: Math.round(cyc(cur)), altCycleM: Math.round(cyc(alt)) };
  const sig = l => surf(l).signals || 0;
  if(sig(alt) - sig(cur) >= 4) return { kind: 'signals', ...base, curSignals: sig(cur), altSignals: sig(alt) };
  return { kind: 'other', ...base };
}
function metres(a, b){
  const r = Math.PI / 180, x = Math.sin((b[0] - a[0]) * r / 2) ** 2 + Math.cos(a[0] * r) * Math.cos(b[0] * r) * Math.sin((b[1] - a[1]) * r / 2) ** 2;
  return 2 * 6371000 * Math.asin(Math.sqrt(x));
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
/** The stretches ridden into the wind (`into`), with it (`with`) or across it (`side`), [fromM, toM] in metres along the
    ride, by windShares()'s rule; the drawn line's metres are scaled to the engine's length, as sliceAlong() reads them. */
export function windSpans(legs, windFromDeg, kind){
  const rad = x => x * Math.PI / 180, spans = [];
  let off = 0;
  for(const leg of legs){
    if(!leg || !leg.routed || !Array.isArray(leg.line)) continue;
    const steps = []; let lineM = 0;
    for(let i = 1; i < leg.line.length; i++){
      const [x1, y1] = leg.line[i - 1], [x2, y2] = leg.line[i];
      const dLng = rad(x2 - x1), p1 = rad(y1), p2 = rad(y2);
      const yv = Math.sin(dLng) * Math.cos(p2), xv = Math.cos(p1) * Math.sin(p2) - Math.sin(p1) * Math.cos(p2) * Math.cos(dLng);
      const bearing = (Math.atan2(yv, xv) * 180 / Math.PI + 360) % 360;
      const h = Math.sin((p2 - p1) / 2) ** 2 + Math.cos(p1) * Math.cos(p2) * Math.sin(dLng / 2) ** 2;
      const len = 2 * 6371000 * Math.asin(Math.sqrt(h));
      const diff = Math.abs(((bearing - windFromDeg) % 360 + 540) % 360 - 180);
      steps.push([lineM, lineM + len, diff <= 60 ? 'into' : diff >= 120 ? 'with' : 'side']);
      lineM += len;
    }
    const k = lineM > 0 ? (leg.distM || lineM) / lineM : 1;
    for(const [a, b, c] of steps) if(c === kind) spans.push([off + a * k, off + b * k]);
    off += leg.distM || lineM;
  }
  return joinSpans(spans, 1);
}
/** Where the rider is during one hour of the day (route-planner.md section 4.4): [fromM, toM] along the ride at the
    average pace, from the start at `startMin` (minutes after midnight) over `rideS` seconds of riding; null when the
    hour falls before the start or after the finish. */
export function hourSpan(startMin, rideS, totalM, hour){
  const rideMin = rideS / 60;
  if(!(rideMin > 0) || !(totalM > 0)) return null;
  const a = Math.max(0, hour * 60 - startMin), b = Math.min(rideMin, hour * 60 + 60 - startMin);
  if(b <= a) return null;
  return [a / rideMin * totalM, b / rideMin * totalM];
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
  // Road facts, not surfaces: the tiles say nothing of them, so the engine's stand.
  const out = { busy: engine.busy || 0, cycle: engine.cycle || 0, signals: engine.signals || 0 };
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

/** The height scale of a profile (route-planner.md section 4.5): round values between `lo` and `hi`, in the rider's
    own unit, about `want` of them, on a step of 1, 2 or 5 times a power of ten. Empty for no span. */
export function heightTicks(lo, hi, want = 4){
  if(!(hi > lo) || !Number.isFinite(lo) || !Number.isFinite(hi)) return [];
  const raw = (hi - lo) / Math.max(1, want), mag = Math.pow(10, Math.floor(Math.log10(raw)));
  const at = step => { const out = []; for(let v = Math.ceil(lo / step) * step; v <= hi + 1e-9; v += step) out.push(Math.round(v * 1e6) / 1e6 + 0); return out; };
  // The round step whose line count comes nearest `want`; on a tie the finer step, more lines.
  let best = null;
  for(const step of [0.5, 1, 2, 5, 10, 20].map(k => k * mag)){
    const ticks = at(step);
    if(!best || Math.abs(ticks.length - want) < Math.abs(best.length - want)) best = ticks;
  }
  return best;
}
