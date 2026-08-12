// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Before/after for a proposed climb shape, drawn on the map.

   A redrawn climb cannot be reviewed as text. "summit 50.4860, 5.6927" tells a
   curator nothing about whether the summit moved somewhere sensible, and the
   raw route array told them even less (owner, 2026-08-03: "a human can't handle
   this data — we need to see it on the map").

   So the pending card carries a switch, and this module draws whichever side is
   selected: BEFORE is the shape the item has today, AFTER is the shape being
   proposed. Both come from the submission itself (`s.shape`, built by
   SubmissionQueue::shapeSides) rather than from the loaded catalog, because a
   brand-new climb has no current feature to compare against.

   Why a switch rather than both lines at once: the two shapes usually share
   most of their length — same foot, diverging near the summit — so drawn
   together they overlap into one thick smear exactly where the difference is.
   Flipping the same line in place makes the change read as movement, which is
   the thing being judged. (Owner's call, twice stated.)

   The overlay owns its own source/layer ids and always clears them before
   drawing, so switching sides or closing the drawer can never leave a second
   line behind. */
import { map } from './map-init.js';
import { D } from './i18n.js';

const LINE = 'cc-pending-shape';
const CASE = 'cc-pending-shape-case';
let _marker = null;
let _side = 'after';

/* Remove the overlay entirely. Safe to call when nothing is drawn.

   Every LAYER goes before the source does. Both layers share one source, so
   removing the source between them throws ("cannot be removed while layer … is
   using it") — and a throw here leaves the casing behind, the re-add fails, and
   the switch silently keeps showing the previous side. */
export function clearPendingShape() {
  [LINE, CASE].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
  if (map.getSource(LINE)) map.removeSource(LINE);
  if (_marker) { _marker.remove(); _marker = null; }
}

/** Which side is currently drawn ('before' | 'after'). */
export const pendingShapeSide = () => _side;

/**
 * Draw one side of a proposed shape.
 *
 * @param shape {{before: object|null, after: object|null}} from s.shape
 * @param side  'before' | 'after'
 * @returns true when something was drawn
 */
export function showPendingShape(shape, side) {
  clearPendingShape();
  _side = side === 'before' ? 'before' : 'after';
  const s = shape && shape[_side];
  if (!s) return false;

  /* A moved PIN: one position, not a line. Drawn as a ring rather than a
     marker so the item's own pin stays visible underneath — the question a
     curator is answering is "from where to where", and hiding one of the two
     answers it badly. Before is muted and dashed, after is the violet the
     change history uses for a new value, exactly as the line sides are. */
  if (Array.isArray(s.point) && s.point.length === 2) {
    const el = document.createElement('div');
    el.className = 'cc-pending-point cc-pending-point--' + _side;
    _marker = new maplibregl.Marker({ element: el, anchor: 'center' })
      .setLngLat([+s.point[1], +s.point[0]]).addTo(map);
    return true;
  }

  if (!Array.isArray(s.route) || s.route.length < 2) return false;

  // Stored [lat,lng]; GeoJSON wants [lng,lat].
  const coords = s.route
    .filter(p => Array.isArray(p) && p.length === 2 && isFinite(+p[0]) && isFinite(+p[1]))
    .map(p => [+p[1], +p[0]]);
  if (coords.length < 2) return false;

  map.addSource(LINE, { type: 'geojson', data: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: coords } } });
  // A casing under the line so it stays legible over the climb it is being
  // compared with, whatever the basemap is doing underneath.
  map.addLayer({
    id: CASE, type: 'line', source: LINE,
    layout: { 'line-cap': 'round', 'line-join': 'round' },
    paint: { 'line-color': '#14160E', 'line-width': 11, 'line-opacity': 0.55 },
  });
  map.addLayer({
    id: LINE, type: 'line', source: LINE,
    layout: { 'line-cap': 'round', 'line-join': 'round' },
    paint: {
      /* Before is the past: muted, dashed. After is the proposal: solid, and
         in the same violet the change history uses for a new value.

         An UNRECORDED before is a different past — the road was on the map,
         with no surface anybody had recorded — and it is drawn in exactly the
         style that state has everywhere else: the legend's red dotted "not
         recorded" line (SURFACE_STYLE.unverified). Reusing the vocabulary
         matters more than a consistent grey here: a curator has already
         learned what red dots mean. */
      'line-color': 'before' !== _side ? '#B25BE8' : (s.unrecorded ? '#D92D20' : '#8a8d7d'),
      'line-width': 6,
      'line-opacity': 0.95,
      ...(_side === 'before' ? { 'line-dasharray': s.unrecorded ? [2.5, 2.5] : [2, 1.6] } : {}),
    },
  });

  if (s.steep && Array.isArray(s.steep.at) && s.steep.at.length === 2) {
    const el = document.createElement('div');
    el.className = 'cc-steep cc-steep-pending';
    el.textContent = s.steep.pct || '';
    el.title = `${D.steepest || 'Steepest pitch'} · ${s.steep.pct || ''}`;
    _marker = new maplibregl.Marker({ element: el, anchor: 'center' })
      .setLngLat([+s.steep.at[1], +s.steep.at[0]]).addTo(map);
  }
  return true;
}

/** Fit the map to whichever side is drawn, so the difference is on screen. */
export function fitPendingShape(shape, side) {
  const s = shape && shape[side === 'before' ? 'before' : 'after'];
  // A moved pin: both positions matter, so frame the pair rather than one of
  // them — a curator flipping between two off-screen points learns nothing.
  if (s && Array.isArray(s.point)) {
    const other = shape[side === 'before' ? 'after' : 'before'];
    const b = new maplibregl.LngLatBounds([+s.point[1], +s.point[0]], [+s.point[1], +s.point[0]]);
    if (other && Array.isArray(other.point)) b.extend([+other.point[1], +other.point[0]]);
    map.fitBounds(b, { padding: 120, maxZoom: 17, duration: 0 });
    return;
  }
  if (!s || !Array.isArray(s.route) || s.route.length < 2) return;
  const lats = s.route.map(p => +p[0]).filter(isFinite);
  const lngs = s.route.map(p => +p[1]).filter(isFinite);
  if (!lats.length || !lngs.length) return;
  map.fitBounds(
    [[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]],
    { padding: { top: 80, bottom: 80, left: 80, right: 460 }, duration: 600, maxZoom: 15 },
  );
}
