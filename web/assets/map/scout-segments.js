// SPDX-License-Identifier: AGPL-3.0-only
/* Surface stretches from a Scout ride
   (docs/specs/moderation-and-contribution.md (Scout intake)):
   a start/END tap pair plus the track → the line intake accepts and the map draws.
   Coordinates only — timestamps stay in the browser. Pure; node-testable. */

/* Device surface type (FIT poi_detail 1-8) → the map's tile class. Keep in step
   with OSM_SURFACE (scout-fit.js) ∘ SurfaceVocabulary::FROM_OSM ∘ TO_TILE_CLASS. */
export const DEVICE_CLASS = {
  1: 'paved',   // asphalt
  2: 'paved',   // concrete
  3: 'paved',   // paving_stones
  4: 'pave',    // sett
  5: 'pave',    // cobblestone
  6: 'gravel',  // gravel
  7: 'dirt',    // ground
  8: 'dirt',    // sand
};

/* Device surface type → the A-form declarable label (SurfaceVocabulary::FROM_OSM).
   The server re-derives from `osmSurface` if the dropdown is left alone. */
export const DEVICE_DECLARABLE = {
  1: 'Asphalt', 2: 'Concrete', 3: 'Paving stones',
  4: 'Sett — pavé', 5: 'Sett — pavé',
  6: 'Gravel', 7: 'Dirt', 8: 'Dirt',
};

/* Mirror of CatalogContributionService::MAX_SEGMENT_POINTS — downsample to fit. */
export const MAX_SEGMENT_POINTS = 3000;

/* Ridden line between a stretch's two taps.
   `track` is [{lat, lng, at: Date}] in ride order; `seg` is a
   buildSurfaceSegments() window. Returns {a, b, line} ([lng, lat], line[0]==a)
   or null. Taps win the endpoints; samples fill the middle. An unterminated
   stretch has no end tap, so the last sample in the window becomes `b`. */
export function cutTrack(track, seg) {
  const start = seg.startTime instanceof Date ? seg.startTime.getTime() : null;
  if (start == null) return null;
  const end = seg.endTime instanceof Date ? seg.endTime.getTime() : Infinity;

  const line = [];
  for (const p of track) {
    if (!(p.at instanceof Date)) continue;
    const ms = p.at.getTime();
    if (ms < start) continue;
    if (ms > end) break;                    // track is in ride order
    line.push([p.lng, p.lat]);
  }

  const a = seg.startLat != null && seg.startLon != null ? [seg.startLon, seg.startLat] : null;
  const b = seg.endLat != null && seg.endLon != null ? [seg.endLon, seg.endLat] : null;
  if (a) line.unshift(a);
  if (b) line.push(b);
  if (line.length < 2) return null;

  const sampled = downsample(line, MAX_SEGMENT_POINTS);
  return { a: sampled[0], b: sampled[sampled.length - 1], line: sampled };
}

/* Even index-space thinning that always keeps both endpoints (intake snap check). */
export function downsample(line, max) {
  if (line.length <= max) return line;
  const out = [];
  const step = (line.length - 1) / (max - 1);
  for (let i = 0; i < max; i++) {
    out.push(line[Math.round(i * step)]);
  }
  out[out.length - 1] = line[line.length - 1];
  return out;
}

/* Nearest track index to a dragged point — keeps a stretch endpoint on the ride. */
export function nearestTrackIndex(track, lngLat) {
  let best = -1;
  let bestD = Infinity;
  const cos = Math.cos((lngLat.lat * Math.PI) / 180);
  for (let i = 0; i < track.length; i++) {
    const dx = (track[i].lng - lngLat.lng) * cos;
    const dy = track[i].lat - lngLat.lat;
    const d = dx * dx + dy * dy;
    if (d < bestD) { bestD = d; best = i; }
  }
  return best;
}

/* Stretch between two track indices — dragged-endpoint counterpart of cutTrack. */
export function sliceTrack(track, i0, i1) {
  if (i0 < 0 || i1 >= track.length || i1 - i0 < 1) return null;
  const line = [];
  for (let i = i0; i <= i1; i++) line.push([track[i].lng, track[i].lat]);
  const sampled = downsample(line, MAX_SEGMENT_POINTS);
  return { a: sampled[0], b: sampled[sampled.length - 1], line: sampled };
}

/* End index for a stretch from a point the rider clicked: the nearest ride
   sample, or -1 when that sample is not after the start (a stretch runs
   forward along the ride). */
export function endIndexFor(track, startIdx, lngLat) {
  const idx = nearestTrackIndex(track, lngLat);
  return idx > startIdx && startIdx >= 0 ? idx : -1;
}

/* First ride sample at or after `when`: where a tap sits on the ride by time,
   right even where the ride crosses itself. -1 when there is none. */
export function trackIndexAt(track, when) {
  if (!(when instanceof Date)) return -1;
  const ms = when.getTime();
  for (let i = 0; i < track.length; i++) {
    if (track[i].at instanceof Date && track[i].at.getTime() >= ms) return i;
  }
  return -1;
}

/* No-type taps still inside an unsent stretch: the ones a rider can act on.
   Used as an end, or on a stretch that was sent or removed, a tap is settled. */
export function openBareTaps(bareTaps, stretches) {
  return bareTaps.filter(b => stretches.some(x => x.geom && !x.approved && !x.dismissed
    && b.idx > x.startIdx && b.idx < x.endIdx));
}
