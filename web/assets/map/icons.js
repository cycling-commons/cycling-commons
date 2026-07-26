// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Map iconography: every canvas-minted MapLibre image (the water droplets, the
   per-category mini discs) and every DOM marker element (cluster bubbles,
   pins), plus the coverage-tile icon-id lookup that mirrors the icon-image
   match expressions of the <key>-<cc>-cov layers.
   Extracted from map.js by 2026-07-26-map-js-module-split-design.md §5.

   Pure minting only — nothing here reads scope, mode or the catalogue's
   `active` set, and nothing adds a layer. `map` is a dependency solely because
   MapLibre's image registry lives on the map instance; layerByKey supplies the
   category colour/glyph. Every mint is idempotent (hasImage guard) so callers
   may re-invoke freely. */
import { map } from './map-init.js';
import { layerByKey } from './catalog.js';
import { txtOn } from './util.js';

// water-droplet icons, minted once for the coverage C tile layer: blue =
// tagged drinkable (tile prop `potable`, coverage-provider.md
// §4), grey = potability unknown/untagged — the "confirm on the spot"
// variant.
export function mintWaterDrops(){
  if(map.hasImage('water-drop')) return;
  const S=2, W=14*S, H=18*S, cx=W/2;
  const drop=(fill,stroke)=>{
    const cv=document.createElement('canvas'); cv.width=W; cv.height=H;
    const x=cv.getContext('2d');
    x.beginPath(); x.moveTo(cx,S);
    x.bezierCurveTo(W-S, H*0.46, W*0.80, H-S, cx, H-S);
    x.bezierCurveTo(W*0.20, H-S, S, H*0.46, cx, S);
    x.closePath();
    x.fillStyle=fill; x.fill();
    x.lineWidth=1.4*S; x.strokeStyle=stroke; x.stroke();
    return new Uint8Array(x.getImageData(0,0,W,H).data.buffer);
  };
  map.addImage('water-drop', {width:W, height:H, data:drop('#3E8FB0','#0d2b3a')}, {pixelRatio:S});
  map.addImage('water-drop-unk', {width:W, height:H, data:drop('#7F8C93','#2b3338')}, {pixelRatio:S});
}

// Bike-services (D) items carry a serviceKind (shop/station/pump) — distinct glyphs
// per kind, layered onto the same category-colour disc treatment as every other
// marker. shop reuses the layer's own icon (⚙) so staffed shops read exactly as
// before; station/pump are new. Shared by both the unverified symbol-layer icons
// (miniIcon below) and the confirmed/curated DOM pins (pinGlyph below).
// station/pump deliberately use plain BMP symbols (⚒ hammer-and-pick, ⊕ circled-plus/
// "add air") rather than the full-colour emoji 🛠/💨: ⚒ shares its Miscellaneous
// Symbols block with ⚙ (shop, already drawn from there); ⊕ is a Mathematical
// Operators-block character — both are plain BMP symbols, so they render from
// any standard system/UI font, with no
// dependency on a colour-emoji font being installed (verified: this dev box has none
// installed at all — `fc-list | grep -i emoji` is empty — so 🛠/💨, and even the
// pre-existing 💧/⛺/🚆/⛑/📷/🏛/⛰ layer icons, all silhouette as blank tofu boxes here).
export const SERVICE_GLYPH={shop:'⚙', station:'⚒', pump:'⊕'};
// small recognisable marker for UNVERIFIED items: paper disc + category-colour ring + the category glyph.
// glyph/suffix let a layer mint more than one disc variant (e.g. services' per-serviceKind icons) off the
// same colour/id scheme — suffix keeps the cache id distinct so each variant is registered once.
export function miniIcon(key, glyph, suffix){
  const id='mini-'+key+(suffix?('-'+suffix):'');
  if(map.hasImage(id)) return id;
  const layer=layerByKey[key], color=(layer||{}).color||'#6b6f5e';
  const r=parseInt(color.slice(1,3),16),g=parseInt(color.slice(3,5),16),b=parseInt(color.slice(5,7),16);
  const dark=(0.299*r+0.587*g+0.114*b)<150;          // dark disc → white glyph, light disc → ink glyph
  const S=2, D=24*S, R=D/2;
  const cv=document.createElement('canvas'); cv.width=D; cv.height=D; const x=cv.getContext('2d');
  // solid category-colour disc + dark hairline so it reads on light basemaps (like the water droplet does)
  x.beginPath(); x.arc(R,R,R-2.5*S,0,Math.PI*2);
  x.fillStyle=color; x.fill();
  x.lineWidth=1.6*S; x.strokeStyle='rgba(20,22,14,.85)'; x.stroke();
  // category glyph as a flat silhouette (white on dark discs, ink on light) — matches the pins' icon treatment
  const gc=document.createElement('canvas'); gc.width=D; gc.height=D; const gx=gc.getContext('2d');
  gx.font=`${12.5*S}px "Apple Color Emoji","Noto Color Emoji","Segoe UI Emoji","Noto Sans Symbols2",system-ui,sans-serif`;
  gx.textAlign='center'; gx.textBaseline='middle';
  gx.fillText(glyph || (layer||{}).icon||'•', R, R+1*S);
  const gd=gx.getImageData(0,0,D,D), gp=gd.data;
  for(let i=0;i<gp.length;i+=4){ if(gp[i+3]>25){ gp[i]=dark?255:20; gp[i+1]=dark?255:22; gp[i+2]=dark?255:14; gp[i+3]=255; } }
  gx.putImageData(gd,0,0); x.drawImage(gc,0,0);
  map.addImage(id,{width:D,height:D,data:new Uint8Array(x.getImageData(0,0,D,D).data.buffer)},{pixelRatio:S});
  return id;
}

export function clusterEl(layer, count){
  const d=document.createElement('div');
  d.className='cc-cluster'; d.style.setProperty('--c', layer.color); d.textContent=count;
  return d;
}

// D · services confirmed/curated pins show the per-kind glyph (shop/station/pump) instead of
// the layer's generic icon; every other layer (and services items with no/unknown serviceKind)
// keeps layer.icon exactly as before. props is the feature/properties object carrying serviceKind
// (GeoJSON properties for OSM-sourced points, the plain feature object for CATALOG-authored ones).
// Private: pinEl() is its only caller now that both live here.
function pinGlyph(layer, props){
  if(layer.key==='services' && props && props.serviceKind) return SERVICE_GLYPH[props.serviceKind] || layer.icon;
  return layer.icon;
}
export function pinEl(layer,cur,props){
  const d=document.createElement('div');
  d.className='cc-pin'+(cur?' cur':'')+(layer.pendingLayer?' pending':''); d.style.setProperty('--c',layer.color);
  const white = txtOn(layer.color)==='#fff';   // dark pins (e.g. purple climbs) → white icon
  d.innerHTML=`<span${white?' style="filter:brightness(0) invert(1)"':''}>${pinGlyph(layer, props)}</span>`; return d;
}

// Selected coverage-POI icon overlay (fix 2026-07-22): the exact tile icon id
// for a clicked POI, so the cov-sel overlay redraws it and it survives the
// tile clustering that hides the individual icon on zoom-out. Mirrors the
// icon-image match expressions of the <key>-<cc>-cov layers (addCoverage).
export function coverageIconId(key, tp){
  if(key==='water') return ['yes','true','1'].includes(String(tp.potable)) ? 'water-drop' : 'water-drop-unk';
  if(key==='services'){
    if(tp.kind==='station') return miniIcon('services', SERVICE_GLYPH.station, 'station');
    if(tp.kind==='pump') return miniIcon('services', SERVICE_GLYPH.pump, 'pump');
    return miniIcon('services');
  }
  return miniIcon(key);
}
