// SPDX-License-Identifier: AGPL-3.0-only
/* The town pointer: the pin from the site's own mark (assets/brand/logo-mark.svg,
   a map pin with a spoked wheel inside), dropped on a town when its card
   opens (map-and-search.md §6.5). A town is not a catalogue item and has no
   category pin, so until 2026-09-21 a town card opened with nothing on the
   map to say where the town was (owner: "it should also get our logo's spot
   pointer"). Pure markup, so a test can read it without a map. */

/* Zoom a town card lands on: the town itself, streets readable, the nearby
   list's 5 km still one drag away. Fixed, not "at least": a town is the same
   size whatever the rider was looking at before. */
export const TOWN_ZOOM = 14;

const SPOKES = [[50,20.5],[59.33,22.63],[66.81,28.59],[70.96,37.22],[70.96,46.78],[66.81,55.41],[59.33,61.37],[50,63.5],[40.67,61.37],[33.19,55.41],[29.04,46.78],[29.04,37.22],[33.19,28.59],[40.67,22.63]];
const HUB = [[50,37.3],[52.04,37.77],[53.67,39.07],[54.58,40.95],[54.58,43.05],[53.67,44.93],[52.04,46.23],[50,46.7],[47.96,46.23],[46.33,44.93],[45.42,43.05],[45.42,40.95],[46.33,39.07],[47.96,37.77]];

/** The mark's pin, 26 × 43 px at the default size; colours come from CSS. */
export function townPinSvg(){
  const spokes = SPOKES.map(([x,y],i)=>`<line x1="${HUB[i][0]}" y1="${HUB[i][1]}" x2="${x}" y2="${y}" stroke="var(--spoke,#C2551F)" stroke-width="1.5" stroke-linecap="round"/><circle cx="${x}" cy="${y}" r="1.7" fill="var(--spoke,#C2551F)"/>`).join('');
  return `<svg class="cc-town-pin-svg" viewBox="14 6 72 120" width="26" height="43" aria-hidden="true" focusable="false">`
    + `<path d="M50 8 C31 8 16 23 16 42 C16 68 50 124 50 124 C50 124 84 68 84 42 C84 23 69 8 50 8 Z M76 42 A26 26 0 1 0 24 42 A26 26 0 1 0 76 42 Z" fill="currentColor" fill-rule="evenodd"/>`
    + `<circle cx="50" cy="42" r="22.5" fill="var(--wheel,#EFE6D4)" stroke="var(--spoke,#C2551F)" stroke-width="1"/>`
    + spokes
    + `<circle cx="50" cy="42" r="3.2" fill="currentColor"/></svg>`;
}
