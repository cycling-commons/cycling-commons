// SPDX-License-Identifier: AGPL-3.0-only
/* Whether the "not covered yet" banner shows, and what it says
   (docs/specs/map-and-search.md §4.5b). A leaf: no DOM, no map, no CCScope
   import - the decision alone, so it can be tested. The caller (scope-ui.js)
   resolves onboarded-ness and the coastal-distance question against the
   region registry and /regions/outlines.json, and passes the answers in.

   State survives across ticks, because panning carries no geocoder: once an
   explicit hit has named a country, further panning that never re-enters
   onboarded territory keeps naming it (and keeps a dismissal), rather than
   losing that identity on the very next moveend. */

// Panning names no country (there is no geocoder for an arbitrary point), so
// it never fires below this zoom - a rider zoomed out over a whole continent
// is not "at" any one place yet.
export const PAN_MIN_ZOOM = 7;

// Coastal tolerance (docs/specs/map-and-search.md §4.5b): a centre this close
// to an onboarded country's outline counts as covered, so water off an
// onboarded coastline never flashes the banner. Plain planar degrees, the
// same convention the pipeline's BOUNDARY_SNAP_DEG uses (pipeline/coverage/
// load.py) - fine at this scale, and consistent with a codebase that already
// treats a geodesic correction as unwarranted precision for a snap distance.
export const NEAR_TOLERANCE_DEG = 0.1;

const HIDDEN = { show: false, countryName: null, countryCode: null };

/** A blank excursion: not currently naming anywhere, not dismissed. */
export function initNoticeState() {
  return { country: null, dismissed: false };
}

/**
 * One evaluation tick.
 *
 * @param {{country:?{code:string,name:?string}, dismissed:boolean}} state -
 *   the previous state (`initNoticeState()` for a fresh page)
 * @param {object} input
 * @param {number} [input.zoom] - current map zoom (ignored when `searchHit` is set)
 * @param {boolean} input.onboardedAt - true when the place in question (the
 *   search hit's country, or the point under the map centre) is onboarded
 * @param {boolean} [input.nearOnboarded] - true when the pan centre sits
 *   within the coastal tolerance of an onboarded region (only consulted
 *   while panning; a search hit is a named country, not a coastline)
 * @param {{countryCode:?string, countryName:?string}} [input.searchHit] - set
 *   once, right after an explicit search selection has flown to its target;
 *   absent for a plain pan
 * @returns {{decision:{show:boolean,countryName:?string,countryCode:?string}, state:object}}
 */
export function evaluateNotice(state, input) {
  const cur = state || initNoticeState();
  const { zoom, onboardedAt, nearOnboarded, searchHit } = input || {};

  if (searchHit) {
    const cc = searchHit.countryCode || null;
    if (!cc) return { decision: HIDDEN, state: cur };              // no country: nothing to name, nothing changes
    if (onboardedAt) return { decision: HIDDEN, state: initNoticeState() };  // covered: the excursion (if any) is over
    // A fresh explicit selection of a non-onboarded country always shows,
    // whether or not it repeats the country the rider just dismissed - it is
    // a deliberate new action, not a pan drifting back over old ground.
    const next = { country: { code: cc.toUpperCase(), name: searchHit.countryName || null }, dismissed: false };
    return { decision: { show: true, countryName: next.country.name, countryCode: next.country.code }, state: next };
  }

  if (onboardedAt) return { decision: HIDDEN, state: initNoticeState() };  // entered onboarded territory: forget the excursion
  // Too zoomed out, or coastal water off an onboarded coast: hidden for this
  // tick only. Not dismissed, and not "covered" either - the excursion (its
  // country identity and its dismissal) is left exactly as it was, so
  // zooming back in resumes it rather than starting over.
  if (zoom == null || zoom < PAN_MIN_ZOOM || nearOnboarded) return { decision: HIDDEN, state: cur };
  if (cur.dismissed) return { decision: HIDDEN, state: cur };
  const name = cur.country ? cur.country.name : null;
  const code = cur.country ? cur.country.code : null;
  return { decision: { show: true, countryName: name, countryCode: code }, state: cur };
}

/** Close button: dismiss the current excursion (a no-op state shape change -
 *  it still carries whatever country identity, if any, is already known). */
export function dismissNotice(state) {
  return { ...(state || initNoticeState()), dismissed: true };
}

// ---- Coastal-tolerance geometry, against /regions/outlines.json ----------
// A FeatureCollection of { properties:{cc}, geometry: Polygon|MultiPolygon },
// coordinates as GeoJSON [lng,lat] pairs (PageController::regionOutlines).
// Ray-casting point-in-ring + point-to-segment distance, the same two
// primitives scope.js already uses for its own region outlines, rewritten
// here against GeoJSON's [[lng,lat],…] ring shape rather than scope.js's flat
// array, so this module keeps its own copy and no import of scope.js.

function pointInRing(x, y, ring) {
  let inside = false;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const xi = ring[i][0]; const yi = ring[i][1];
    const xj = ring[j][0]; const yj = ring[j][1];
    if (((yi > y) !== (yj > y)) && (x < ((xj - xi) * (y - yi)) / (yj - yi) + xi)) inside = !inside;
  }
  return inside;
}

function pointInRings(x, y, rings) {
  if (!Array.isArray(rings) || !rings.length || !Array.isArray(rings[0])) return false;
  if (!pointInRing(x, y, rings[0])) return false;        // outside the outer ring
  for (let k = 1; k < rings.length; k++) {
    if (Array.isArray(rings[k]) && pointInRing(x, y, rings[k])) return false;   // inside a hole
  }
  return true;
}

function distToSegment(x, y, ax, ay, bx, by) {
  const dx = bx - ax; const dy = by - ay;
  const l2 = dx * dx + dy * dy;
  let t = l2 > 0 ? ((x - ax) * dx + (y - ay) * dy) / l2 : 0;
  if (t < 0) t = 0; else if (t > 1) t = 1;
  const px = ax + t * dx; const py = ay + t * dy;
  return Math.hypot(x - px, y - py);
}

function ringMinDist(x, y, ring) {
  let best = Infinity;
  const n = ring.length;
  for (let i = 0; i < n; i++) {
    const a = ring[i]; const b = ring[(i + 1) % n];
    const d = distToSegment(x, y, a[0], a[1], b[0], b[1]);
    if (d < best) best = d;
  }
  return best;
}

function distanceToRings(x, y, rings) {
  if (pointInRings(x, y, rings)) return 0;
  let best = Infinity;
  (rings || []).forEach((ring) => {
    if (Array.isArray(ring) && ring.length >= 2) {
      const d = ringMinDist(x, y, ring);
      if (d < best) best = d;
    }
  });
  return best;
}

function distanceToGeometry(x, y, geometry) {
  if (!geometry) return Infinity;
  if (geometry.type === 'Polygon') return distanceToRings(x, y, geometry.coordinates);
  if (geometry.type === 'MultiPolygon') {
    let best = Infinity;
    (geometry.coordinates || []).forEach((rings) => {
      const d = distanceToRings(x, y, rings);
      if (d < best) best = d;
    });
    return best;
  }
  return Infinity;   // an unexpected geometry type names no distance, never a crash
}

/**
 * Minimum planar-degree distance from [lng,lat] to any onboarded country's
 * outline (0 when the point is inside one). `features` is
 * /regions/outlines.json's `features` array. Tested at the centre's own
 * longitude and at +/-360 degrees from it, so a centre near the antimeridian
 * is measured against outline coordinates on whichever side of the seam they
 * were stored on.
 */
export function distanceToOutlinesDeg(lng, lat, features) {
  let best = Infinity;
  const lngs = [lng, lng + 360, lng - 360];
  (features || []).forEach((f) => {
    const g = f && f.geometry;
    lngs.forEach((lx) => {
      const d = distanceToGeometry(lx, lat, g);
      if (d < best) best = d;
    });
  });
  return best;
}

/** Whether [lng,lat] sits within NEAR_TOLERANCE_DEG of any onboarded outline. */
export function isNearOutlines(lng, lat, features) {
  return distanceToOutlinesDeg(lng, lat, features) <= NEAR_TOLERANCE_DEG;
}
