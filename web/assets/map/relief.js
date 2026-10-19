// SPDX-License-Identifier: AGPL-3.0-only
/* 3D relief (docs/specs/map-and-search.md section 4.2c; owner 2026-10-17, a trial): the slopes shaded and the
   ground raised, from the public Terrain Tiles fetched by our own server (`/tiles/relief/{z}/{x}/{y}.png`, the
   nginx proxy), so no rider's browser contacts the store. Off by default; switched on, the map tilts so the
   relief shows, and switched off it lies flat again. Two sources over the same tiles, one for the terrain and
   one for the shading, as MapLibre asks; the browser fetches each tile once.

   Hillshade (owner 2026-10-19: "Add option for hillshading also in flat view"): the shading alone, on the flat
   map, with no raised ground and no tilt. The two switches share the one shading layer, which stays while either
   is on. Switching Hillshade on switches the 3D relief off, which lays the map flat again (owner 2026-10-19: "when
   clicking Hillshade deselect 3d terrain and undo the map tilt"). */
import { map } from './map-init.js';
import { shadeBeforeId } from './relief-order.js';

export const RELIEF = {
  terrain: 'cc-relief-dem', shade: 'cc-relief-shade-dem', layer: 'cc-relief-shade',
  // Twice the true height: flat land still shows its dikes and dunes.
  exaggeration: 2, pitch: 55, maxzoom: 15,
  // The shading's strength, half as strong again as the first 0.35 (owner 2026-10-19: "50% higher the strength of
  // the hillshade").
  shading: 0.525,
};
const ATTRIBUTION = 'Relief: <a href="/credits#credits-relief">Terrain Tiles (Mapzen; USGS, Copernicus EU-DEM and others)</a>';

let on = false;
let shadeOn = false;
export const reliefVisible = () => on;
export const hillshadeVisible = () => shadeOn;

function demSource(){
  return { type: 'raster-dem', tiles: [location.origin + '/tiles/relief/{z}/{x}/{y}.png'], tileSize: 256,
    maxzoom: RELIEF.maxzoom, encoding: 'terrarium', attribution: ATTRIBUTION };
}
/* The shading's place (relief-order.js): over our lines, the routes among them, under the planned ride, the pins
   and the labels. Moved only when it is not there, so a style change it causes finds it in place. */
function styleLayers(){
  return map.getLayersOrder().map(id => { const l = map.getLayer(id); return { id, type: l && l.type, source: l && l.source }; });
}
function placeShade(){
  if(!map.getLayer(RELIEF.layer)) return;
  const ids = map.getLayersOrder();
  const before = shadeBeforeId(styleLayers(), RELIEF.layer);
  const now = ids[ids.indexOf(RELIEF.layer) + 1];
  if(now !== before) map.moveLayer(RELIEF.layer, before);
}
/* Layers come and go (a route switched on, a planned ride drawn): the shading finds its place again, once a frame. */
let placeQueued = false;
function placeSoon(){
  if(placeQueued || !map.getLayer(RELIEF.layer)) return;
  placeQueued = true;
  requestAnimationFrame(() => { placeQueued = false; placeShade(); });
}
map.on('styledata', placeSoon);

/* The shading layer, there while the hillshade or the 3D relief is on. */
function syncShade(){
  const want = shadeOn || on;
  if(want){
    if(!map.getSource(RELIEF.shade)) map.addSource(RELIEF.shade, demSource());
    if(!map.getLayer(RELIEF.layer)) map.addLayer({ id: RELIEF.layer, type: 'hillshade', source: RELIEF.shade,
      paint: { 'hillshade-exaggeration': RELIEF.shading, 'hillshade-shadow-color': '#3d3a33', 'hillshade-highlight-color': '#fffaf0' } },
      shadeBeforeId(styleLayers(), RELIEF.layer));
  } else if(map.getLayer(RELIEF.layer)) map.removeLayer(RELIEF.layer);
}

/** Switch the flat hillshade on or off; answers whether it is on. */
export function setHillshade(next){
  next = !!next;
  if(next === shadeOn) return shadeOn;
  shadeOn = next;
  syncShade();
  if(shadeOn && on) setRelief(false);
  return shadeOn;
}

/** Switch the relief on or off; answers whether it is on. */
export function setRelief(next){
  next = !!next;
  if(next === on) return on;
  on = next;
  syncShade();
  if(on){
    if(!map.getSource(RELIEF.terrain)) map.addSource(RELIEF.terrain, demSource());
    map.setTerrain({ source: RELIEF.terrain, exaggeration: RELIEF.exaggeration });
    if(map.getPitch() < 20) map.easeTo({ pitch: RELIEF.pitch, duration: 700 });
  } else {
    map.setTerrain(null);
    if(map.getPitch() > 0) map.easeTo({ pitch: 0, duration: 500 });
  }
  return on;
}
