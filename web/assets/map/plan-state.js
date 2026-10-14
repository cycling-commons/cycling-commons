// SPDX-License-Identifier: AGPL-3.0-only
/* Whether a ride is being planned (docs/specs/route-planner.md section 3): the
   plan panel is open, or a ride with a stop is on the map. A leaf, so the
   modules that must stand aside while planning (click-to-scope in scope-ui.js)
   can ask without importing the planner. */
let _planning = false;
export const isPlanning = () => _planning;
export function setPlanning(on){ _planning = !!on; }
