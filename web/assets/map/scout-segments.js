// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Surface stretches from a Scout ride (scout plan task 6): the pure half.

   A surface tag pair (a start tap and an END tap - "2 records") describes a
   stretch of ridden road. These helpers turn that pair plus the ride's track
   into exactly what the intake accepts and the map draws:

   - the ridden LINE between the two taps, coordinates only. Deliberately no
     timestamps: the excerpt the rider approves is "this road is gravel", not
     "I was here at 14:02" - the segment is road data, the timings stay
     movement data and never leave the browser.
   - the CC surface CLASS for the stretch's colour, so the review draws the
     same palette the map legend uses (render.js SURFACE_STYLE).

   Pure functions on purpose: node-testable without a map or a DOM
   (tests/js/scout-segments.test.mjs). */

/* Device surface type (FIT poi_detail 1-8) → the map's tile class. The chain
   it shortcuts is OSM_SURFACE (scout-fit.js) ∘ SurfaceVocabulary::FROM_OSM ∘
   SurfaceVocabulary::TO_TILE_CLASS (PHP) - keep the three in step. */
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

/* Device surface type → the declarable label the A form stores, mirroring
   SurfaceVocabulary::FROM_OSM so the dropdown preselects what the rider
   already chose on the device. The server re-derives this from `osmSurface`
   when the rider leaves the dropdown alone, so a drifted entry here corrects
   itself at intake rather than storing a wrong value. */
export const DEVICE_DECLARABLE = {
  1: 'Asphalt', 2: 'Concrete', 3: 'Paving stones',
  4: 'Sett — pavé', 5: 'Sett — pavé',
  6: 'Gravel', 7: 'Dirt', 8: 'Dirt',
};

/* Mirror of CatalogContributionService::MAX_SEGMENT_POINTS - the intake
   refuses longer lines, so the cut downsamples to fit rather than failing. */
export const MAX_SEGMENT_POINTS = 3000;

/* The ridden line between a stretch's two taps.

   `track` is readFit()'s [{lat, lng, at: Date}] in ride order; `seg` is one of
   buildSurfaceSegments()'s {startTime, endTime, startLat, startLon, endLat,
   endLon}. Returns {a, b, line} in the intake's shape ([lng, lat] pairs,
   line[0] == a, line[last] == b) or null when the ride holds no usable line
   for the window (a tunnel with no fix, a recording gap).

   The exact tap points bound the line: the taps are where the rider SAID the
   surface changes, the track points are merely where the device happened to
   sample - so the taps win the endpoints and the samples fill the middle. An
   unterminated stretch (no END tap) has no end tap to trust, so the last
   sample inside the window becomes `b`. */
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

/* Even index-space thinning that always keeps both endpoints - the endpoints
   are the rider's taps and the intake checks the line starts and ends on
   them (MAX_SNAP_DRIFT_M). */
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
