// SPDX-License-Identifier: AGPL-3.0-only
/* The basemap's names over our lines (docs/specs/map-and-search.md section 4.1): a road surface, route or road-type
   line drawn over a road name made the name unreadable. No imports, so the road editor's page loads it by its own
   address as the main map does (render.js on the main map, templates/contribute/improve.html.twig on the editor's). */

/** Lines that stay over the names: the planned ride (the rider's own line), the ride check's line, the ride a
    curator reviews (scout-review.js keeps it on top after every style change, so moving it would never settle), the
    region's outline (it sits over everything) and the stretches a card or the planner lights. */
const OVER_NAMES = /^(plan-|region-|ridecheck|cc-scout-)|-hl$|-sel$/;
const BASEMAP = 'openmaptiles';

/**
 * Which of our lines to move, and the layer they go before. `layers` are the style's layers in order,
 * `{id, type, source}`. The name layer is the basemap's first symbol after its last drawn layer, so a symbol among
 * its roads (the one-way arrows) is passed over. Without one (a style still loading) nothing moves.
 */
export function linesUnderNames(layers) {
  let lastDrawn = -1;
  layers.forEach((l, i) => { if (l.source === BASEMAP && l.type !== 'symbol') lastDrawn = i; });
  const at = layers.findIndex((l, i) => i > lastDrawn && l.source === BASEMAP && l.type === 'symbol');
  if (at < 0) return { before: undefined, ids: [] };
  const ids = layers.slice(at + 1)
    .filter(l => l.type === 'line' && l.source !== BASEMAP && !OVER_NAMES.test(l.id))
    .map(l => l.id);
  return { before: layers[at].id, ids };
}

/** Each of our lines on `m` above the names moved just under them, in its own order; the number moved. Only what is
    out of place moves, so the style change a move causes finds nothing more to move. */
export function moveLinesUnderNamesOn(m) {
  if (typeof m.getLayersOrder !== 'function') return 0;
  const layers = m.getLayersOrder().map(id => { const l = m.getLayer(id); return { id, type: l && l.type, source: l && l.source }; });
  const { before, ids } = linesUnderNames(layers);
  ids.forEach(id => m.moveLayer(id, before));
  return ids.length;
}
