// SPDX-License-Identifier: AGPL-3.0-only
/* 3D relief (docs/specs/map-and-search.md section 4.2c; owner 2026-10-17, a trial): the slopes shaded and the
   ground raised, from the public Terrain Tiles fetched by our own server (`/tiles/relief/{z}/{x}/{y}.png`, the
   nginx proxy), so no rider's browser contacts the store. Off by default; switched on, the map tilts so the
   relief shows, and switched off it lies flat again. Two sources over the same tiles, one for the terrain and
   one for the shading, as MapLibre asks; the browser fetches each tile once. */
import { map } from './map-init.js';

export const RELIEF = {
  terrain: 'cc-relief-dem', shade: 'cc-relief-shade-dem', layer: 'cc-relief-shade',
  // Twice the true height: flat land still shows its dikes and dunes.
  exaggeration: 2, pitch: 55, maxzoom: 15,
};
const ATTRIBUTION = 'Relief: <a href="/credits#credits-relief">Terrain Tiles (Mapzen; USGS, Copernicus EU-DEM and others)</a>';

let on = false;
export const reliefVisible = () => on;

function demSource(){
  return { type: 'raster-dem', tiles: [location.origin + '/tiles/relief/{z}/{x}/{y}.png'], tileSize: 256,
    maxzoom: RELIEF.maxzoom, encoding: 'terrarium', attribution: ATTRIBUTION };
}
/* The shading goes under the basemap's labels and everything of ours: before the first label layer. */
function firstLabelLayer(){
  for(const id of map.getLayersOrder()){ const l = map.getLayer(id); if(l && l.type === 'symbol') return id; }
  return undefined;
}

/** Switch the relief on or off; answers whether it is on. */
export function setRelief(next){
  next = !!next;
  if(next === on) return on;
  on = next;
  if(on){
    if(!map.getSource(RELIEF.terrain)) map.addSource(RELIEF.terrain, demSource());
    if(!map.getSource(RELIEF.shade)) map.addSource(RELIEF.shade, demSource());
    if(!map.getLayer(RELIEF.layer)) map.addLayer({ id: RELIEF.layer, type: 'hillshade', source: RELIEF.shade,
      paint: { 'hillshade-exaggeration': 0.35, 'hillshade-shadow-color': '#3d3a33', 'hillshade-highlight-color': '#fffaf0' } },
      firstLabelLayer());
    map.setTerrain({ source: RELIEF.terrain, exaggeration: RELIEF.exaggeration });
    if(map.getPitch() < 20) map.easeTo({ pitch: RELIEF.pitch, duration: 700 });
  } else {
    map.setTerrain(null);
    if(map.getLayer(RELIEF.layer)) map.removeLayer(RELIEF.layer);
    if(map.getPitch() > 0) map.easeTo({ pitch: 0, duration: 500 });
  }
  return on;
}
