// SPDX-License-Identifier: AGPL-3.0-only
/* Whether a ride is being planned (docs/specs/route-planner.md section 3): the
   plan panel is open, or a ride with a stop is on the map. A leaf, so the
   modules that must stand aside while planning (click-to-scope in scope-ui.js)
   can ask without importing the planner. */
let _planning = false;
export const isPlanning = () => _planning;
export function setPlanning(on){ _planning = !!on; }
/* A map feature that is a line or an area, not a point (owner 2026-10-17: a click on the road under a surface
   line added no stop). While planning, a click on one is a stop: the line's own drawer stands aside, and only a
   point of ours (a pin, a coverage point) is a click on that place. */
export const isLineOrArea = f => {
  const t = f && f.geometry && f.geometry.type;
  return !!t && t !== 'Point' && t !== 'MultiPoint';
};
/* Whether a line or area click handler stands aside for the planner: true while planning and the click is on one. */
export const plannerTakesLineClick = e => _planning && !!(e && e.features && e.features.length) && isLineOrArea(e.features[0]);
