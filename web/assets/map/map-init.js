// SPDX-License-Identifier: AGPL-3.0-only
/* MapLibre instance, controls, satellite base, style-ready flag, fly-to-pin,
   right-click coordinates (docs/specs/map-and-search.md §2).
   `map` is adopted from catalog-load.js (built before the catalog fetch). */
import { pinOffset, locateOffset, locateErrorMessage, offsetAsPadding } from './util.js';
import { hopIsNear } from './camera-hop.js';

export const map = (function(){
  if(window.__ccMapInstance) return window.__ccMapInstance;
  if(window.__ccMapOpts) return new maplibregl.Map(window.__ccMapOpts);
  throw new Error('map-init: no MapLibre instance and no options — map.js must be booted by catalog-load.js');
})();
/* Our layers skip MapLibre's per-layer style check (docs/specs/map-and-search.md §2; owner 2026-10-17: opening
   the planner took seconds in Firefox). Style.addLayer checks a new layer against a serialized copy of the WHOLE
   style, so n layers copy the style n times, and switching the route and surface lines on adds about 300 layers
   (a set per country). The layers are ours, built by tested code; `validate: false` is the option MapLibre itself
   offers for this, which Map.addLayer never passes. Map.addLayer in MapLibre 6.8.0 is style.addLayer(layer,
   before, options) followed by _update(true); before the style exists it keeps its own path. Map.addSource is
   the same shape and makes the same copy for every source. */
const addLayerChecked = map.addLayer.bind(map), addSourceChecked = map.addSource.bind(map);
map.addLayer = function(layer, before){
  if(!this.style || typeof this._update !== 'function') return addLayerChecked(layer, before);
  this.style.addLayer(layer, before, {validate:false});
  return this._update(true);
};
map.addSource = function(id, source){
  if(!this.style || typeof this._update !== 'function') return addSourceChecked(id, source);
  this.style.addSource(id, source, {validate:false});
  return this._update(true);
};
// Non-prod test handle (docs/specs/map-and-search.md §2). Gated on CC_DEBUG.
if(window.CC_DEBUG) window.__ccMap = map;

/* Basemap place names follow the site language (docs/specs/map-and-search.md §4.2b).
   Liberty hardcodes name_en; the tiles already carry name:de / name:fr / name:nl.
   Detection is "text-field mentions name_en" so road-shield `ref` layers stay. */
const BASEMAP_LOCALE = (document.documentElement.lang || 'en').slice(0, 2);
export function localiseBasemapLabels(){
  const style = map.getStyle();
  if(!style || !style.layers) return 0;
  const pref = ['coalesce', ['get','name:'+BASEMAP_LOCALE], ['get','name_'+BASEMAP_LOCALE]];
  let n = 0;
  for(const layer of style.layers){
    if(layer.type !== 'symbol') continue;
    const tf = layer.layout && layer.layout['text-field'];
    if(!tf) continue;
    const json = JSON.stringify(tf);
    if(!json.includes('name_en')) continue;
    const sep = json.includes('"\\n"') ? '\n' : ' ';
    map.setLayoutProperty(layer.id, 'text-field', ['case',
      ['has','name:nonlatin'],
      ['concat', [...pref, ['get','name:latin']], sep, ['get','name:nonlatin']],
      [...pref, ['get','name'], ['get','name:latin']],
    ]);
    n++;
  }
  return n;
}

/* Roads a bike may not ride, faded (map-and-search.md section 4.1, owner 2026-10-17: "you will never need them, only
   good to know they are there"): motorways in their own liberty layers fade whole; in every other road layer a trunk
   road fades, and so does a road the tiles mark `bicycle=no` or `bicycle=use_sidepath` (ride the cycle path beside it).
   A layer with an opacity of its own that is not a plain number keeps it. It runs again after every style change, so a
   reloaded style or a switched theme cannot bring the roads back; a layer already faded is left as it is. */
export const HIGHWAY_FADE = 0.1;
export const NO_BIKE_FADE = ['case', ['any', ['==', ['get', 'class'], 'trunk'], ['match', ['get', 'bicycle'], ['no', 'use_sidepath'], true, false]], HIGHWAY_FADE, 1];
/* The rider's switch in the Layers panel (owner 2026-10-18), on unless turned off, kept in this browser. Off, every
   road layer gets the opacity it had before the fade. */
const FADE_KEY = 'cc-fade-roads';
let fadeOn = (() => { try { return localStorage.getItem(FADE_KEY) !== 'off'; } catch(e){ return true; } })();
const fadeOriginal = new Map();   // layer id -> its own opacity before the fade (undefined: none)
export const highwayFadeOn = () => fadeOn;
export function setHighwayFade(next){
  fadeOn = !!next;
  try { localStorage.setItem(FADE_KEY, fadeOn ? 'on' : 'off'); } catch(e){ /* private mode: this visit only */ }
  if(fadeOn) fadeHighways();
  else for(const [id, own] of fadeOriginal){ if(map.getLayer(id)) map.setPaintProperty(id, 'line-opacity', own); }
  return fadeOn;
}
export function fadeHighways(){
  if(!fadeOn) return 0;
  // The layer order and each layer by id: cheap, where getStyle() would serialise the whole style on every run.
  const ids = typeof map.getLayersOrder === 'function' ? map.getLayersOrder() : ((map.getStyle() || {}).layers || []).map(l => l.id);
  let n = 0;
  for(const id of ids){
    const layer = map.getLayer(id);
    if(!layer || layer.type !== 'line' || layer.source !== 'openmaptiles' || (layer.sourceLayer || layer['source-layer']) !== 'transportation') continue;
    const own = fadeOriginal.has(id) ? fadeOriginal.get(id) : map.getPaintProperty(id, 'line-opacity');
    if(!fadeOriginal.has(id)) fadeOriginal.set(id, own);
    const want = /motorway/.test(id) ? HIGHWAY_FADE : own == null || own === 1 ? NO_BIKE_FADE : typeof own === 'number' ? ['case', NO_BIKE_FADE[1], HIGHWAY_FADE * own, own] : null;
    if(want == null) continue;
    if(JSON.stringify(map.getPaintProperty(id, 'line-opacity')) === JSON.stringify(want)) continue;   // faded already: setting it again would only fire another style change
    map.setPaintProperty(id, 'line-opacity', want);
    n++;
  }
  return n;
}
/* Cycle paths drawn clearly (map-and-search.md section 4.1, owner 2026-10-18: with the road beside it faded, the
   separate cycle path was lost; liberty draws a path as faint white dashes). One line layer of ours on the basemap's
   own tiles, under its labels: a path the tiles call a cycleway, or mark bicycle=designated, in green from zoom 12. */
export const CYCLEWAY_LAYER = 'cc-cycleways';
export const CYCLEWAY_FILTER = ['all', ['==', ['get', 'class'], 'path'], ['any', ['==', ['get', 'subclass'], 'cycleway'], ['==', ['get', 'bicycle'], 'designated']]];
export function addCycleways(){
  if(map.getLayer(CYCLEWAY_LAYER) || !map.getSource('openmaptiles')) return false;
  const ids = typeof map.getLayersOrder === 'function' ? map.getLayersOrder() : ((map.getStyle() || {}).layers || []).map(l => l.id);
  const before = ids.find(id => { const l = map.getLayer(id); return l && l.type === 'symbol' && l.source === 'openmaptiles'; });
  map.addLayer({ id: CYCLEWAY_LAYER, type: 'line', source: 'openmaptiles', 'source-layer': 'transportation', minzoom: 12, filter: CYCLEWAY_FILTER,
    layout: { 'line-cap': 'round', 'line-join': 'round' },
    paint: { 'line-color': '#2E7D5B', 'line-opacity': 0.9, 'line-width': ['interpolate', ['linear'], ['zoom'], 12, 1, 15, 2.2, 18, 4] } }, before);
  return true;
}
/* Small roads seen from zoom 12, white with a grey edge (map-and-search.md section 4.1, owner 2026-10-18: "what I like
   about OSM's cycle map is that you can see all roads at lower zoom levels, because all roads are white"; tracks and
   service roads stay liberty's, the surfaces show those). The tiles carry the small roads (`minor`) from zoom 12, and
   liberty draws their white only from 13.5: its two layers are set wider from 12. Each property is set only when it
   differs, so a run after a style change sets nothing again. */
export const SMALL_ROAD_EDGE = '#bcb7ad';
const exp = stops => ['interpolate', ['exponential', 1.2], ['zoom'], ...stops];
export const SMALL_ROADS = {
  // The edge's opacity a zoom expression, never a plain number: fadeHighways() rewrites a plain number with its own,
  // and the two would undo each other on every style change (owner 2026-10-18: "drawing Wallonia takes ages").
  road_minor_casing: { 'line-width': exp([12, 2.4, 13, 3.4, 14, 5, 20, 20]), 'line-color': SMALL_ROAD_EDGE, 'line-opacity': ['interpolate', ['linear'], ['zoom'], 11.5, 0, 12, 1] },
  road_minor: { 'line-width': exp([12, 1.2, 13, 2, 14, 3, 20, 18]) },
};
/* The basemap's greens warmer and deeper, a moss green (map-and-search.md section 4.1, owner 2026-10-19: "the green in
   our map needs to be a bit warmer", then "Green must be deeper a bit more mossy", then "add a bit more warmth to
   it"). Liberty's grass, wood and park sit near hue 90 to 98; ours near 72 to 75, darker and less saturated, with
   their own opacity kept. */
export const WARM_GREENS = {
  landcover_grass: { 'fill-color': 'rgb(156,174,102)' },
  landcover_wood: { 'fill-color': 'hsla(73,38%,52%,0.7)' },
  park: { 'fill-color': '#ccd6a4' },
};
export function showSmallRoads(){ return setPaints(SMALL_ROADS); }
export function warmGreens(){ return setPaints(WARM_GREENS); }
function setPaints(table){
  let n = 0;
  for(const [id, paint] of Object.entries(table)){
    if(!map.getLayer(id)) continue;
    for(const [k, v] of Object.entries(paint)){
      if(JSON.stringify(map.getPaintProperty(id, k)) === JSON.stringify(v)) continue;
      map.setPaintProperty(id, k, v); n++;
    }
  }
  return n;
}
/* After a style change, once per frame however many changes come in that frame. */
let fadeQueued = false;
export function fadeHighwaysSoon(){
  if(fadeQueued) return;
  fadeQueued = true;
  requestAnimationFrame(() => { fadeQueued = false; showSmallRoads(); warmGreens(); fadeHighways(); addCycleways(); });
}

/** Attribution, navigation, Locate me and the live zoom readout.
    `toast(msg)` says why a Locate me lookup did not work. */
export function initMapControls({toast}={}){
  /* The Copernicus programme requires its notice on products derived from the
     DEM, and the climb gradients are exactly that (credits.html.twig carries
     the full wording). It was on /credits and missing here, which is the one
     place the derived product is actually looked at. Short form plus a link,
     because an attribution bar is not where anyone reads three sentences. */
  const creditsUrl = document.getElementById('map')?.dataset.credits || '/credits';
  map.addControl(new maplibregl.AttributionControl({customAttribution:
    '© OpenStreetMap contributors · ODbL · Elevation: Copernicus WorldDEM-30 '
    + '© DLR e.V. 2010-2014 · © Airbus Defence and Space GmbH 2014-2018 '
    + `(<a href="${creditsUrl}">credits</a>)`
  }),'bottom-right');
  /* The compass with the zoom buttons (owner 2026-10-19: "can we add a compass when you click it the map always
     rotates North up"): its needle shows where north is; a click turns the map north up and keeps the tilt. */
  map.addControl(new maplibregl.NavigationControl({showCompass:true, visualizePitch:false}),'bottom-left');
  map.addControl(locateControl(toast),'bottom-left');
  map.addControl({
    onAdd(m){
      const d=document.createElement('div');
      d.className='maplibregl-ctrl zoom-badge';
      d.setAttribute('aria-hidden','true');   // decorative; the value is not actionable AT/via keyboard
      const upd=()=>{ d.textContent='z'+m.getZoom().toFixed(1); };
      m.on('zoom', upd); upd();
      this._d=d; this._upd=upd; this._m=m;
      return d;
    },
    onRemove(){ this._m.off('zoom', this._upd); this._d.remove(); },
  },'bottom-left');
}

/* Locate me (docs/specs/map-and-search.md §4.0). MapLibre's own control: one
   tap asks the browser once, flies to a dot at the rider's position and stops;
   no tracking. The position goes from navigator.geolocation to the camera and
   the dot and nowhere else: no request carries it. Its label and the "not
   available" title come from the map's `locale` option (catalog-load.js).
   `padding` is a getter because MapLibre copies fitBoundsOptions at the moment
   it moves the camera, and whether a drawer covers part of the map is only
   known then; it is padding, not an offset, for the reason offsetAsPadding
   gives. */
function locateControl(toast){
  const fitBoundsOptions = {maxZoom:15, duration:1700};
  Object.defineProperty(fitBoundsOptions, 'padding', {enumerable:true, get(){
    const d = document.getElementById('drawer');
    const open = !!d && d.classList.contains('open') && !d.classList.contains('folded');
    const box = map.getContainer().getBoundingClientRect();
    return offsetAsPadding(locateOffset(open, window.innerWidth, window.innerHeight, box.top, box.height));
  }});
  const ctrl = new maplibregl.GeolocateControl({
    positionOptions:{enableHighAccuracy:true, timeout:10000},
    trackUserLocation:false, showUserLocation:true, showAccuracyCircle:true,
    fitBoundsOptions,
  });
  // A refused or failed lookup is said in words, never a silently greyed button.
  ctrl.on('error', e => { if(toast) toast(locateErrorMessage(e && e.code, window.CC_I18N)); });
  return ctrl;
}

/* Satellite base — Esri World Imagery (docs/specs/map-and-search.md §2).
   No key means no source (the layer is optional; a 401ing source is worse). */
export const ESRI_KEY = window.CC_ESRI_KEY || '';
export const esriTileUrl = key =>
  'https://ibasemaps-api.arcgis.com/arcgis/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}?token='
  + encodeURIComponent(key);

/* Ask this, never `map.getLayer('satellite')`: the layer is added on 'load',
   the chrome is built before that, so the layer question always answered no. */
export const satelliteConfigured = () => !!ESRI_KEY;

export function addSatellite(){
  if(map.getSource('satellite') || !ESRI_KEY) return;
  map.addSource('satellite',{type:'raster',tileSize:256,
    tiles:[esriTileUrl(ESRI_KEY)],
    maxzoom:19, attribution:'Imagery © Esri, Maxar, Earthstar Geographics'});
  map.addLayer({id:'satellite',type:'raster',source:'satellite',layout:{visibility:'none'}});
}

/* Every camera move the app makes goes through moveCamera()
   (docs/specs/map-and-search.md §8), which animates when there is map under
   the move and cuts through a short fade when there is not. camera-hop.js
   holds the decision; this holds the mechanism. */
const CUT_FADE_MS = 180;      // long enough to read as deliberate, short enough not to wait on
const CUT_DARK_MAX_MS = 900;  // the map comes back even if it never paints
let _cutBack = null;

/* The fade hides the TRANSITION, never the loading: it comes back on the first
   frame drawn at the destination, not when the tiles have settled. Waiting for
   `idle` measured 2.2 s of dark on a first visit, which is a longer blank than
   the grey slide it replaced; tiles arriving into a view that is already in
   front of the rider is what any map does, and it reads as loading rather than
   as breakage. */
function cutCamera(cam){
  const el = map.getCanvasContainer();
  if(_cutBack) _cutBack();                       // a second cut mid-fade: finish the first
  el.classList.add('cc-cut');
  setTimeout(() => {
    map.jumpTo(cam);
    const back = () => {
      if(_cutBack !== back) return;
      _cutBack = null;
      clearTimeout(timer);
      map.off('render', back);
      el.classList.remove('cc-cut');
    };
    const timer = setTimeout(back, CUT_DARK_MAX_MS);
    _cutBack = back;
    map.on('render', back);                      // back on the first frame drawn where we landed
  }, CUT_FADE_MS);
}

/**
 * Put the camera on `cam` ({center, zoom, ...}). Animated when the ground it
 * crosses is ground the rider is already looking at, cut when it is not:
 * a map animates over the tiles it has, and it has none for ground it has
 * never shown (docs/specs/map-and-search.md §8). `anim` is passed to flyTo.
 * Answers 'fly' or 'cut', for the tests and for a caller that wants to know.
 */
export function moveCamera(cam, anim){
  const box = map.getContainer().getBoundingClientRect();
  const c = cam.center;
  const to = {center: Array.isArray(c) ? [+c[0], +c[1]] : [c.lng, c.lat],
              zoom: cam.zoom == null ? map.getZoom() : cam.zoom};
  const from = {center: [map.getCenter().lng, map.getCenter().lat], zoom: map.getZoom(),
                width: box.width, height: box.height};
  if(hopIsNear(from, to)){
    map.flyTo({...cam, ...(anim || {}), essential: true});
    return 'fly';
  }
  cutCamera(cam);
  return 'cut';
}

/** fitBounds through the same door: the camera is computed without moving
 *  (`cameraForBounds` bakes the padding into centre and zoom), then judged. */
export function fitMapTo(bounds, opts){
  const o = opts || {};
  const cam = map.cameraForBounds(bounds, o);
  if(!cam){ map.fitBounds(bounds, o); return 'fly'; }   // degenerate bounds: leave it to MapLibre
  return moveCamera({center: cam.center, zoom: cam.zoom},
    {duration: o.duration == null ? 900 : o.duration});
}

export function flyToPin(lngLat){   // centre + slow zoom-in on click, in the part of the map the drawer leaves free
  const box = map.getContainer().getBoundingClientRect();
  const offset = pinOffset(window.innerWidth, window.innerHeight, box.top, box.height);
  return moveCamera({center:lngLat, zoom:Math.max(map.getZoom(),14), offset}, {duration:1700});
}
/* The same framing at one exact zoom: a town card lands on the town itself
   (map-and-search.md §6.5), not on whatever zoom the rider searched from. */
export function flyToPlace(lngLat, zoom){
  const box = map.getContainer().getBoundingClientRect();
  const offset = pinOffset(window.innerWidth, window.innerHeight, box.top, box.height);
  return moveCamera({center:lngLat, zoom, offset}, {duration:900});
}

/* Style-load race: sources cannot be added, and render() must not paint, until MapLibre has the style. */
let _styleReady=false;
export const styleReady = () => _styleReady;
export function markStyleReady(){ _styleReady=true; }

/** Right-click → show + copy coordinates. */
export function initCoordPopup(){
  let _coordPopup=null;
  map.on('contextmenu', e=>{
    // After every other right-click handler: one that took the click (a place into the ride) marks it `ccTaken`.
    // Not `defaultPrevented`: MapLibre cancels every right-click's default itself.
    setTimeout(()=>{ if(!(e.originalEvent && e.originalEvent.ccTaken)) showCoords(e); }, 0);
  });
  function showCoords(e){
    const c = `${e.lngLat.lat.toFixed(6)}, ${e.lngLat.lng.toFixed(6)}`;
    if(!_coordPopup) _coordPopup=new maplibregl.Popup({closeButton:true,className:'pop'});
    const label=ok=>_coordPopup.setHTML(`<div class="pop"><div class="pop-co">Coordinates${ok?' · copied':' — select to copy'}</div>${c}</div>`);
    label(false); _coordPopup.setLngLat(e.lngLat).addTo(map);
    if(navigator.clipboard) navigator.clipboard.writeText(c).then(()=>label(true)).catch(()=>{});
  }
}
