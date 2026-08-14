// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Map iconography: every canvas-minted MapLibre image (the water droplets, the
   per-category mini discs) and every DOM marker element (cluster bubbles,
   pins), plus the coverage-tile icon-id lookup that mirrors the icon-image
   match expressions of the <key>-<cc>-cov layers.
   Extracted from map.js by the module split.

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
// I · scenic views: the 📷 emoji silhouettes as a blank rounded box on dark
// pins (the white-icon filter flattens the colour emoji to its outline, and a
// camera emoji's outline IS a rounded box — owner: "make scenic view icon a
// camera"). So the camera is drawn as a real vector shape: body + lens hole
// (evenodd), used by both the DOM pins (inline SVG) and the canvas discs
// (Path2D). 24×24 viewBox.
export const CAMERA_PATH='M9 4h6l1.5 2.5H20a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.5a2 2 0 0 1 2-2h3.5L9 4Zm3 4.6a4.7 4.7 0 1 0 0 9.4 4.7 4.7 0 0 0 0-9.4Zm0 2a2.7 2.7 0 1 1 0 5.4 2.7 2.7 0 0 1 0-5.4Z';
const cameraSvg=(fill,size)=>`<svg viewBox="0 0 24 24" width="${size||15}" height="${size||15}" aria-hidden="true"><path fill-rule="evenodd" fill="${fill}" d="${CAMERA_PATH}"/></svg>`;
// The rail's layer row (and anything else that renders layer.icon through the
// silhouette filter): the filter flattens whatever fill we pick, so
// currentColor is fine — what matters is the SHAPE being a camera.
export const scenicGlyph=size=>cameraSvg('currentColor', size);

// M · public toilets: 🚻 has exactly the camera's problem one letter later.
// The emoji is two human figures side by side; flattened by the white-icon
// filter its outline is a filled rectangle, so the rail row and the map pin
// both showed a featureless block (owner 2026-08-14: "toilets still has a
// general icon in filter list and on the map").
//
// Drawn as a toilet seen from the SIDE rather than as the pictogram pair: at
// 13px two human figures collapse into a smudge, and a side profile cannot be
// mistaken for the shelter or stays glyphs the way a figure can. Same 24×24
// viewBox and the same single-path treatment as CAMERA_PATH, so it mints
// through the identical canvas/DOM paths with no new machinery.
//
// Two masses and nothing else: a tall cistern and a wide bowl. Three shapes
// were tried and rendered side by side at 26px before choosing — a front view
// (cistern, seat ring, pedestal) turned to mush, and a bowl alone read as a
// cup. Detail is what dies first at rail size, so there is none here.
export const TOILET_PATH='M5 3h5.6v8H5zM4 12h16a1 1 0 0 1 1 1.1c-.3 3.4-2.3 6-4.9 7.1v1.3a.9.9 0 0 1-.9.9H8.8a.9.9 0 0 1-.9-.9v-1.3C5.3 19.1 3.3 16.5 3 13.1A1 1 0 0 1 4 12z';
const toiletSvg=(fill,size)=>`<svg viewBox="0 0 24 24" width="${size||15}" height="${size||15}" aria-hidden="true"><path fill-rule="evenodd" fill="${fill}" d="${TOILET_PATH}"/></svg>`;
export const toiletGlyph=size=>toiletSvg('currentColor', size);
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
  // Two letters draw a real vector instead of a glyph, for the same reason:
  // their emoji flatten to a featureless box under the silhouette treatment.
  // I gets the camera, M the toilet (see CAMERA_PATH / TOILET_PATH).
  const drawn = !glyph && (key==='scenic' ? CAMERA_PATH : key==='toilets' ? TOILET_PATH : null);
  if(drawn){
    const side=13*S, sc=side/24;
    x.save();
    x.translate(R-side/2, R-side/2);
    x.scale(sc, sc);
    x.fillStyle=dark?'#fff':'#14160e';
    x.fill(new Path2D(drawn), 'evenodd');
    x.restore();
  } else {
    const gc=document.createElement('canvas'); gc.width=D; gc.height=D; const gx=gc.getContext('2d');
    gx.font=`${12.5*S}px "Apple Color Emoji","Noto Color Emoji","Segoe UI Emoji","Noto Sans Symbols2",system-ui,sans-serif`;
    gx.textAlign='center'; gx.textBaseline='middle';
    gx.fillText(glyph || (layer||{}).icon||'•', R, R+1*S);
    const gd=gx.getImageData(0,0,D,D), gp=gd.data;
    for(let i=0;i<gp.length;i+=4){ if(gp[i+3]>25){ gp[i]=dark?255:20; gp[i+1]=dark?255:22; gp[i+2]=dark?255:14; gp[i+3]=255; } }
    gx.putImageData(gd,0,0); x.drawImage(gc,0,0);
  }
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
  // Scenic and toilets get a drawn vector (no filter, the SVG carries its own
  // fill); every other layer keeps the glyph + silhouette-filter treatment.
  // Both emoji flatten to a featureless box under that filter, which is the
  // whole reason these two are special-cased here, in the rail row and on the
  // canvas discs — three places, one cause.
  if(layer.key==='scenic'){ d.innerHTML=`<span>${cameraSvg(white?'#fff':'#20241c')}</span>`; return d; }
  if(layer.key==='toilets'){ d.innerHTML=`<span>${toiletSvg(white?'#fff':'#20241c')}</span>`; return d; }
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
