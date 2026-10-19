// SPDX-License-Identifier: AGPL-3.0-only
/* Where the relief shading sits in the layer order (docs/specs/map-and-search.md section 4.2c, owner 2026-10-19:
   "Can we draw the hillshade/3d relief over the routes when on"): right above our last line, so the routes, the
   route network and the surface lines are shaded with the ground, and under the planned ride, the pins and the
   labels, which it would dim. Pure, so node can test it; relief.js moves the layer. */

/** Lines that stay over the shading and do not set its place: the planned ride (the rider's own line), the region's
    outline and shade (they sit over everything, the pins included), and the stretches a card or the planner lights. */
const OVER_SHADE = /^(plan-|region-)|-hl$|-sel$/;

/**
 * The layer the shading goes before, or undefined for the top. `layers` are the style's layers in order,
 * `{id, type, source}`; a basemap layer has source `openmaptiles`.
 */
export function shadeBeforeId(layers, shadeId) {
  const rest = layers.filter(l => l.id !== shadeId);
  let last = -1;
  rest.forEach((l, i) => { if (l.type === 'line' && l.source !== 'openmaptiles' && !OVER_SHADE.test(l.id)) last = i; });
  if (last < 0) {
    const label = rest.find(l => l.type === 'symbol');
    return label ? label.id : undefined;
  }
  const next = rest[last + 1];
  return next ? next.id : undefined;
}
