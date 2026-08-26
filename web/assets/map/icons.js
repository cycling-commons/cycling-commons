// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Pins, cluster bubbles, minted tile icons. Idempotent hasImage guards. */
import { map } from './map-init.js';
import { layerByKey, TYPE_SVG } from './catalog.js';
import { escPend, txtOn } from './util.js';

// docs/specs/coverage-provider.md §4 — blue drinkable, grey untagged.
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

// BMP symbols, not colour-emoji: the silhouette filter otherwise renders tofu.
export const SERVICE_GLYPH={shop:'⚙', station:'⚒', pump:'⊕'};
// Emoji camera flattens to a rounded box under the white-icon filter; draw the shape.
export const CAMERA_PATH=TYPE_SVG('P');   // ItemType::svgPath(), via window.CC_TYPE_ICONS
const cameraSvg=(fill,size)=>`<svg viewBox="0 0 24 24" width="${size||15}" height="${size||15}" aria-hidden="true"><path fill-rule="evenodd" fill="${fill}" d="${CAMERA_PATH}"/></svg>`;
export const scenicGlyph=size=>cameraSvg('currentColor', size);

// Same flatten-to-box problem as the camera; side profile, not the pictogram pair.
export const TOILET_PATH=TYPE_SVG('C');   // ItemType::svgPath(), via window.CC_TYPE_ICONS
const toiletSvg=(fill,size)=>`<svg viewBox="0 0 24 24" width="${size||15}" height="${size||15}" aria-hidden="true"><path fill-rule="evenodd" fill="${fill}" d="${TOILET_PATH}"/></svg>`;
export const toiletGlyph=size=>toiletSvg('currentColor', size);
// The mountain emoji flattens to one plain triangle; draw twin peaks instead (owner 2026-08-25).
export const MOUNTAIN_PATH=TYPE_SVG('N');   // ItemType::svgPath(), via window.CC_TYPE_ICONS
const mountainSvg=(fill,size)=>`<svg viewBox="0 0 24 24" width="${size||15}" height="${size||15}" aria-hidden="true"><path fill="${fill}" d="${MOUNTAIN_PATH}"/></svg>`;
export const climbGlyph=size=>mountainSvg('currentColor', size);
// One glyph for every HTML surface (drawer head, rail, badges, nearby groups):
// the drawn shapes where we have them, the escaped text glyph otherwise.
export function layerGlyph(layer, size){
  const k=(layer||{}).key;
  if(k==='scenic') return cameraSvg('currentColor', size);
  if(k==='toilets') return toiletSvg('currentColor', size);
  if(k==='climbs') return mountainSvg('currentColor', size);
  return escPend((layer||{}).icon||'');
}
// Unverified disc; `suffix` keeps per-kind cache ids distinct.
export function miniIcon(key, glyph, suffix){
  const id='mini-'+key+(suffix?('-'+suffix):'');
  if(map.hasImage(id)) return id;
  const layer=layerByKey[key], color=(layer||{}).color||'#6b6f5e';
  const r=parseInt(color.slice(1,3),16),g=parseInt(color.slice(3,5),16),b=parseInt(color.slice(5,7),16);
  const dark=(0.299*r+0.587*g+0.114*b)<150;
  const S=2, D=24*S, R=D/2;
  const cv=document.createElement('canvas'); cv.width=D; cv.height=D; const x=cv.getContext('2d');
  x.beginPath(); x.arc(R,R,R-2.5*S,0,Math.PI*2);
  x.fillStyle=color; x.fill();
  x.lineWidth=1.6*S; x.strokeStyle='rgba(20,22,14,.85)'; x.stroke();
  const drawn = !glyph && (key==='scenic' ? CAMERA_PATH : key==='toilets' ? TOILET_PATH : key==='climbs' ? MOUNTAIN_PATH : null);
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

function pinGlyph(layer, props){
  if(layer.key==='services' && props && props.serviceKind) return SERVICE_GLYPH[props.serviceKind] || layer.icon;
  return layer.icon;
}
export function pinEl(layer,cur,props){
  const d=document.createElement('div');
  // docs/specs/moderation-and-contribution.md §10.1a — stale ring only; no freshness key → no ring.
  const stale = props && props.freshness && props.freshness.state==='stale';
  d.className='cc-pin'+(cur?' cur':'')+(layer.pendingLayer?' pending':'')+(stale?' stale':''); d.style.setProperty('--c',layer.color);
  const white = txtOn(layer.color)==='#fff';
  if(layer.key==='scenic'){ d.innerHTML=`<span>${cameraSvg(white?'#fff':'#20241c')}</span>`; return d; }
  if(layer.key==='toilets'){ d.innerHTML=`<span>${toiletSvg(white?'#fff':'#20241c')}</span>`; return d; }
  if(layer.key==='climbs'){ d.innerHTML=`<span>${mountainSvg(white?'#fff':'#20241c')}</span>`; return d; }
  d.innerHTML=`<span${white?' style="filter:brightness(0) invert(1)"':''}>${pinGlyph(layer, props)}</span>`; return d;
}

// Tile icon-image id for the selected-coverage overlay (survives cluster hide).
export function coverageIconId(key, tp){
  if(key==='water') return ['yes','true','1'].includes(String(tp.potable)) ? 'water-drop' : 'water-drop-unk';
  if(key==='services'){
    if(tp.kind==='station') return miniIcon('services', SERVICE_GLYPH.station, 'station');
    if(tp.kind==='pump') return miniIcon('services', SERVICE_GLYPH.pump, 'pump');
    return miniIcon('services');
  }
  return miniIcon(key);
}
