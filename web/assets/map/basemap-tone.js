// SPDX-License-Identifier: AGPL-3.0-only
/* The basemap's tone, for every map that draws liberty (map-and-search.md section 4.1): the main map through
   map-init.js, the contribute wizard's map through its page (templates/contribute/improve.html.twig). One palette,
   two maps. No imports, so a page without the map's import map loads it by its own address. Every call takes the
   map it tones and is safe to repeat: a colour already set and a layer already there are left alone. */

/* The greens warmer and deeper, a moss green (owner 2026-10-19: "the green in our map needs to be a bit warmer",
   then "Green must be deeper a bit more mossy", then "add a bit more warmth to it"). Liberty's grass, wood and park
   sit near hue 90 to 98; ours near 72 to 75, darker and less saturated, with their own opacity kept. */
export const WARM_GREENS = {
  landcover_grass: { 'fill-color': 'rgb(156,174,102)' },
  landcover_wood: { 'fill-color': 'hsla(73,38%,52%,0.7)' },
  park: { 'fill-color': '#ccd6a4' },
};

/* The land itself in the tone of a field (owner 2026-10-26: the fields east of Hoorn read as white patches beside the
   meadows, and zoomed out more so). Liberty paints no farmland, and below zoom 12 the tiles leave most fields out
   (east of Hoorn, grass and farmland cover 60% of a zoom 12 tile and 5% of a zoom 11 one), so its near-white land
   `#f8f4f0` showed wherever no green was drawn. The land is a light, fresh yellow-green (owner 2026-10-26, a first
   greyer `#E2E3CF` read "pale and dead" beside Komoot's): a field and unmapped country read alike at every zoom, a
   meadow a little greener, a town its own grey. */
export const LAND_TONE = { background: { 'background-color': '#E5EBCF' } };

/* Towns a light warm grey, nearly solid, at every zoom (owner 2026-10-26: with the land a field's tone, Hoorn and
   the villages round it no longer stood out, and a cream town on the land lacked contrast). Liberty draws its
   residential fill see-through (49% at zoom 12) and only up to zoom 12, leaving its cream land to stand in for towns
   above that. `TOWN_LAYER` keeps liberty's own filter, residential land only. */
export const TOWN_LAYER = 'landuse_residential';
export const TOWN_TONE = { [TOWN_LAYER]: { 'fill-color': ['interpolate', ['linear'], ['zoom'],
  9, 'hsla(40,8%,84%,0.9)', 12, 'hsla(40,8%,87%,0.92)', 16, 'hsla(40,8%,89%,0.95)'] } };

/* A calmer base under the ride (owner 2026-10-26, beside Komoot's: "which one is better for cycling"). The strong
   colours are kept for what a rider reads: the ride, the cycle paths, the places, and the roads a bike may not take.
   - A road a bike may ride is white with the small roads' grey edge, the bigger ones only wider: liberty's yellow on
     every road made the orange ride line read as one more road. The edge is darker zoomed out, or a white road on
     the light land is lost at zoom 9 to 10 (owner 2026-10-26: "missing road detail at this level").
   - A road for cars only stays liberty's yellow with its orange edge (owner 2026-10-26: "cars only roads should stay
     yellow"): a trunk road, or one that says bicycle=no or use_sidepath, as map-init.js NO_BIKE_FADE reads it. The
     fade switch in the Layers panel still fades them; motorways keep liberty's own colour, faded.
   - Ditches and small canals thinner and faint until zoom 13, in liberty's own blue: east of Hoorn the polder read
     as a blue grid. Rivers, lakes and the sea keep liberty's colour (owner 2026-10-26: a greyer blue was not wanted). */
export const ROAD_EDGE = '#bcb7ad';
const ROAD_EDGE_FAR = '#8f8a80';
export const CAR_ONLY = ['any', ['==', ['get', 'class'], 'trunk'], ['match', ['get', 'bicycle'], ['no', 'use_sidepath'], true, false]];
const CAR_YELLOW = '#fea', CAR_EDGE = '#e9ac77';
const ROAD_FILLS = ['secondary_tertiary', 'trunk_primary', 'link'];
export const ROAD_TONE = Object.fromEntries(['road', 'bridge', 'tunnel'].flatMap(kind => ROAD_FILLS.flatMap(cls => [
  [`${kind}_${cls}`, { 'line-color': ['case', CAR_ONLY, CAR_YELLOW, '#ffffff'] }],
  [`${kind}_${cls}_casing`, { 'line-color': ['interpolate', ['linear'], ['zoom'],
    8, ['case', CAR_ONLY, CAR_EDGE, ROAD_EDGE_FAR], 12, ['case', CAR_ONLY, CAR_EDGE, ROAD_EDGE]] }],
])));
/* Secondary and tertiary roads wider zoomed out: the tiles carry no smaller road below zoom 11, so these are the
   network a rider reads at zoom 9 to 10, and liberty draws them half a pixel wide there (owner 2026-10-26: "still
   missing detail"). From zoom 13 liberty's own widths, unchanged. */
for (const kind of ['road', 'bridge', 'tunnel']) {
  ROAD_TONE[`${kind}_secondary_tertiary`]['line-width'] = ['interpolate', ['exponential', 1.2], ['zoom'], 8, 0.8, 10, 1.4, 12, 2.2, 20, 13];
  ROAD_TONE[`${kind}_secondary_tertiary_casing`]['line-width'] = ['interpolate', ['exponential', 1.2], ['zoom'], 8, 1.8, 10, 2.6, 12, 3.6, 20, 17];
}

/* Railways from zoom 8, a thin grey dashed line, as Komoot draws them: the tiles carry them from there, and liberty
   draws its own only from zoom 14, where this one gives way. Tunnels are left out. */
export const RAIL_LAYER = 'cc-rail';
export const RAIL_SPEC = {
  id: RAIL_LAYER, type: 'line', source: 'openmaptiles', 'source-layer': 'transportation', minzoom: 8, maxzoom: 14,
  filter: ['all', ['==', ['get', 'class'], 'rail'], ['!=', ['get', 'brunnel'], 'tunnel']],
  paint: { 'line-color': '#8f8a80', 'line-width': ['interpolate', ['linear'], ['zoom'], 8, 0.8, 12, 1.2, 14, 1.4], 'line-dasharray': [3, 2], 'line-opacity': 0.8 },
};
/** The railway layer on `m`, once, under liberty's own railway; false when it is there already or `m` has no tiles. */
export function addRailOn(m){
  if(typeof m.addLayer !== 'function' || m.getLayer(RAIL_LAYER) || !m.getSource || !m.getSource('openmaptiles') || !m.getLayer('road_major_rail')) return false;
  m.addLayer(RAIL_SPEC, 'road_major_rail');
  return true;
}
/* Lakes and the sea a little lighter, still clearly blue (owner 2026-10-26: "water can be a bit lighter but not the
   same as Komoot", whose water is a grey-blue). */
export const WATER_TONE = {
  water: { 'fill-color': '#B0CAFF' },
  waterway_other: {
    'line-width': ['interpolate', ['exponential', 1.3], ['zoom'], 13, 0.4, 16, 1.2, 20, 3],
    'line-opacity': ['interpolate', ['linear'], ['zoom'], 11, 0.25, 13, 0.55, 16, 0.9],
  },
};

/* More village names zoomed out (owner 2026-10-26, beside Komoot's: the villages are a rider's landmarks). The
   tiles carry them, 171 villages in a zoom 10 tile round Hoorn, but liberty sets them in 10 to 12 px black, and a
   label that would overlap another is dropped, so most never showed. Smaller, a dark grey, with less room round
   each, many more fit; towns a touch smaller zoomed out, so they leave room and still stand above the villages. */
export const LABEL_LAYOUT = {
  label_village: {
    'text-size': ['interpolate', ['exponential', 1.2], ['zoom'], 9, 8.5, 11, 10.5, 13, 12],
    'text-padding': 1,
  },
  label_town: {
    'text-size': ['interpolate', ['exponential', 1.2], ['zoom'], 7, 11, 9, 11.5, 11, 13.5],
  },
};
export const LABEL_PAINT = { label_village: { 'text-color': '#333' } };

/** Set each layout property of `table` on `m` where it differs; the number set. */
export function setLayoutsOn(m, table){
  let n = 0;
  if(typeof m.setLayoutProperty !== 'function') return n;
  for(const [id, layout] of Object.entries(table)){
    if(!m.getLayer(id)) continue;
    for(const [k, v] of Object.entries(layout)){
      if(JSON.stringify(m.getLayoutProperty(id, k)) === JSON.stringify(v)) continue;
      m.setLayoutProperty(id, k, v); n++;
    }
  }
  return n;
}

/** Set each paint of `table` ({layer: {property: value}}) on `m` where it differs; the number set. */
export function setPaintsOn(m, table){
  let n = 0;
  for(const [id, paint] of Object.entries(table)){
    if(!m.getLayer(id)) continue;
    for(const [k, v] of Object.entries(paint)){
      if(JSON.stringify(m.getPaintProperty(id, k)) === JSON.stringify(v)) continue;
      m.setPaintProperty(id, k, v); n++;
    }
  }
  return n;
}

/** Towns on `m` at every zoom, not only up to liberty's 12; true when the range was changed. */
export function townsAtEveryZoomOn(m){
  const l = m.getLayer(TOWN_LAYER);
  if(!l || typeof m.setLayerZoomRange !== 'function' || (l.maxzoom == null || l.maxzoom >= 24)) return false;
  m.setLayerZoomRange(TOWN_LAYER, l.minzoom || 0, 24);
  return true;
}

/** The base's calm on `m`: the greens, the towns, the roads and the water, not the land; the number of changes. */
export function calmBasemapOn(m){
  return setPaintsOn(m, WARM_GREENS) + setPaintsOn(m, TOWN_TONE) + (townsAtEveryZoomOn(m) ? 1 : 0)
    + setPaintsOn(m, ROAD_TONE) + setPaintsOn(m, WATER_TONE) + (addRailOn(m) ? 1 : 0)
    + setLayoutsOn(m, LABEL_LAYOUT) + setPaintsOn(m, LABEL_PAINT);
}

/** All of it on `m`: the calm and the land; the number of changes made. */
export function toneBasemap(m){
  return calmBasemapOn(m) + setPaintsOn(m, LAND_TONE);
}
