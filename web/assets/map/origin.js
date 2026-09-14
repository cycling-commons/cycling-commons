// SPDX-License-Identifier: AGPL-3.0-only
/* Where a harvested row came from, for the drawer's headline and Source line.
   A leaf: imports nothing, so it can be tested without a map.

   A layer's citation describes where that layer's rows usually come from. A
   row with its own non-OSM source (a Wikidata scenic view in the OSM scenic
   layer) is named by that source instead. Rider sources are handled by the
   caller before these run. */

export function drawerSource(srcType, layerSrc, sourceLabel) {
  const own = srcType && srcType !== 'osm' ? sourceLabel(srcType) : null;
  return own || layerSrc || sourceLabel(srcType) || 'OpenStreetMap';
}

export function drawerOrigin(srcType, sourceLabel) {
  return (srcType && srcType !== 'osm' && sourceLabel(srcType)) || 'OSM';
}
