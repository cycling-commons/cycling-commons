// SPDX-License-Identifier: AGPL-3.0-only
/* The full climb profile's vertical scale (docs/specs/climb-elevation.md §6c).
   Pure, so a node test can load it. */

/* An average this steep fills the chart from foot to summit. The vertical
   scale follows the climb's length, so a gentler average draws a lower,
   gentler silhouette and every climb is drawn at the same exaggeration
   (owner 2026-10-02: Furka at 6.5 % looked like a wall). A steeper climb
   fills the chart and keeps its full height. */
export const FULL_HEIGHT_GRADIENT = 0.12;

/* The vertical span the plot covers, in metres: the climb itself, at least
   60 m (a riser is not an alp), and at least what FULL_HEIGHT_GRADIENT
   climbs over this distance. */
export function verticalSpan(climbM, totalM){
  return Math.max(climbM, 60, totalM * FULL_HEIGHT_GRADIENT);
}
