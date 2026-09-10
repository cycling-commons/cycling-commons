// SPDX-License-Identifier: AGPL-3.0-only
/* Before/after for a proposed climb shape
   (docs/specs/moderation-and-contribution.md §5.2b). One side at a time:
   overlapping lines smear the difference. Overlay owns its own source/layer
   ids and clears them before drawing. */
import { map } from './map-init.js';
import { D } from './i18n.js';
import { gradColor } from './util.js';

const LINE = 'cc-pending-shape';
const CASE = 'cc-pending-shape-case';
let _marker = null;
let _side = 'after';

/* Layers first: both share one source, and removing the source while a layer
   still uses it throws — leaving the previous side on screen. */
export function clearPendingShape() {
  [LINE, CASE].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
  if (map.getSource(LINE)) map.removeSource(LINE);
  if (_marker) { _marker.remove(); _marker = null; }
}

/** Which side is currently drawn ('before' | 'after'). */
export const pendingShapeSide = () => _side;

/** Draw one side of a proposed shape (`s.shape`). Returns true when something was drawn. */
export function showPendingShape(shape, side) {
  clearPendingShape();
  _side = side === 'before' ? 'before' : 'after';
  const s = shape && shape[_side];
  if (!s) return false;

  /* Moved pin: a ring so the item's own pin stays visible underneath. */
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

  /* A climb's after-side carries its measured bars: colour the line by them, the
     way the live climb layer does (render.js drawClimbLine), so the curator sees
     the climb as it will look, not a flat violet stroke (owner 2026-08-25). */
  const bars = ('after' === _side && Array.isArray(s.grad)) ? s.grad.filter(g => isFinite(+g)) : [];
  const graded = bars.length > 0;
  map.addSource(LINE, { type: 'geojson', lineMetrics: graded, data: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: coords } } });
  /* Casing: cream for an unrecorded before (legend red dots); dark ink otherwise. */
  map.addLayer({
    id: CASE, type: 'line', source: LINE,
    layout: { 'line-cap': 'round', 'line-join': 'round' },
    paint: s.unrecorded
      ? { 'line-color': '#FBF4E4', 'line-width': 11, 'line-opacity': 0.9 }
      : { 'line-color': '#14160E', 'line-width': 11, 'line-opacity': 0.55 },
  });
  map.addLayer({
    id: LINE, type: 'line', source: LINE,
    layout: { 'line-cap': 'round', 'line-join': 'round' },
    paint: graded
      ? (() => {
          const n = bars.length;
          const expr = ['step', ['line-progress'], gradColor(+bars[0])];
          for (let i = 1; i < n; i++) { expr.push(i / n); expr.push(gradColor(+bars[i])); }
          return { 'line-width': 6, 'line-opacity': 0.95, 'line-gradient': expr };
        })()
      : {
      /* Before: muted dashed (unrecorded = legend red dots). After: solid violet. */
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
  // Moved pin: frame both positions.
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
