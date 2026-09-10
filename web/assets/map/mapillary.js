// SPDX-License-Identifier: AGPL-3.0-only
/* Mapillary street-level imagery (docs/specs/map-and-search.md §10): sequence
   tiles, lazily injected viewer, bottom dock. MLY_ENABLED gates the feature
   on a real token so init can be called unconditionally. */
import { map } from './map-init.js';
import { I18N } from './i18n.js';

const MAPILLARY_TOKEN = window.MAPILLARY_TOKEN || 'MLY|PASTE_TOKEN_HERE';
export const MLY_ENABLED = /^MLY\|/.test(MAPILLARY_TOKEN) && !/PASTE_TOKEN_HERE/.test(MAPILLARY_TOKEN);
const MLY_GREEN = '#05CB63';
let mlyOn=false, mlyMarker=null, mlyViewer=null, mlyLoading=null;

// coverage line source/layer — Mapillary sequence vector tiles, hidden until toggled on
export function addMapillary(){
  if(!MLY_ENABLED || map.getSource('mly')) return;
  map.addSource('mly',{type:'vector',
    tiles:[`https://tiles.mapillary.com/maps/vtp/mly1_public/2/{z}/{x}/{y}?access_token=${MAPILLARY_TOKEN}`],
    attribution:'© <a href="https://www.mapillary.com/">Mapillary</a>',   // MapLibre only shows what a source declares
    minzoom:6, maxzoom:14});
  map.addLayer({id:'mly-cov',type:'line',source:'mly','source-layer':'sequence',
    layout:{visibility:'none','line-cap':'round','line-join':'round'},
    paint:{'line-color':MLY_GREEN,'line-opacity':0.7,
      'line-width':['interpolate',['linear'],['zoom'],10,1.5,14,3,16,4]}});
  // individual image points (the little Mapillary dots) — each carries its image id in the tile properties
  map.addLayer({id:'mly-img',type:'circle',source:'mly','source-layer':'image',
    layout:{visibility:'none'},
    paint:{'circle-color':MLY_GREEN,'circle-opacity':0.9,
      'circle-stroke-color':'#0b3d22','circle-stroke-width':1,
      'circle-radius':['interpolate',['linear'],['zoom'],13,2,16,4,19,6]}});
  // Click a dot or coverage line → nearest image id from the tiles (no Graph API).
  map.on('click','mly-img',e=>openMapillaryAtPoint(e.point));
  map.on('click','mly-cov',e=>openMapillaryAtPoint(e.point));
  ['mly-img','mly-cov'].forEach(id=>{
    map.on('mouseenter',id,()=>{ if(mlyOn) map.getCanvas().style.cursor='pointer'; });
    map.on('mouseleave',id,()=>{ map.getCanvas().style.cursor=''; });
  });
}

function openMapillaryDock(){          // slide the dock up immediately + show the loading state
  const dock=document.getElementById('mlyDock');
  dock.classList.add('open','loading'); dock.setAttribute('aria-hidden','false');
  { const ap=document.querySelector('.app'); if(ap) ap.classList.add('dock-open'); }   // the dock owns the bottom on a phone; CSS hides the drawer with it
  const ld=document.getElementById('mlyLoad'); if(ld) ld.innerHTML=`<span class="mly-spin"></span>${I18N.mlyLoading||'Loading street-level…'}`;
}
function mlyDockMessage(msg){           // swap the spinner for a short message (nothing found / error)
  document.getElementById('mlyDock').classList.add('loading');
  const ld=document.getElementById('mlyLoad'); if(ld) ld.textContent=msg;
}
// find the nearest rendered Mapillary image point to a screen pixel and read its id from the tile
function nearestImageId(point){
  if(!map.getLayer('mly-img')) return null;
  for(const r of [8,16,30]){
    const fs=map.queryRenderedFeatures([[point.x-r,point.y-r],[point.x+r,point.y+r]],{layers:['mly-img']});
    if(fs.length){
      let best=fs[0],bd=Infinity;
      for(const f of fs){ const p=map.project(f.geometry.coordinates); const dx=p.x-point.x,dy=p.y-point.y,dd=dx*dx+dy*dy; if(dd<bd){bd=dd;best=f;} }
      return best.properties&&best.properties.id;
    }
  }
  return null;
}
// open the image nearest a clicked point — straight from the vector tiles, no Graph-API call
export function openMapillaryAtPoint(point){
  openMapillaryDock();
  const id=nearestImageId(point);
  if(id!=null) openMapillaryImage(String(id));
  else mlyDockMessage(I18N.mlyZoom||'Zoom in and click a green dot to open street-level here.');
}
// (legacy) resolve the nearest image via the Graph API — kept as a fallback, no longer wired to clicks
async function openMapillaryAt(lngLat){
  openMapillaryDock();                  // instant feedback — don't make the user wait on the fetch
  const d=0.0009; // ~100 m bbox half-size
  const bbox=[lngLat.lng-d,lngLat.lat-d,lngLat.lng+d,lngLat.lat+d].join(',');
  try{
    const r=await fetch(`https://graph.mapillary.com/images?access_token=${MAPILLARY_TOKEN}&fields=id&bbox=${bbox}&limit=1`);
    const j=await r.json();
    const img=j.data&&j.data[0];
    if(!img){ mlyDockMessage(I18N.mlyNone||'No street-level imagery here.'); return; }
    openMapillaryImage(img.id);
  }catch(_){ mlyDockMessage(I18N.mlyNone||'No street-level imagery here.'); }
}
// one reusable popup
let _mlyPopupInst=null;
function mlyPopup(lngLat,msg){
  if(!_mlyPopupInst) _mlyPopupInst=new maplibregl.Popup({closeButton:false,className:'pop'});
  _mlyPopupInst.setLngLat(lngLat)
    .setHTML(`<div class="pop"><div class="pop-co">Mapillary</div>${msg}</div>`).addTo(map);
}

// inject mapillary-js (JS + CSS) once, on first open; resolves when ready
function loadMapillaryJs(){
  if(window.mapillary) return Promise.resolve();
  if(mlyLoading) return mlyLoading;
  mlyLoading=new Promise((res,rej)=>{
    const V=window.CC_VENDOR||{};   // digested URLs from Twig
    const css=document.createElement('link'); css.rel='stylesheet';
    css.href=V.mapillaryCss||'/assets/lib/mapillary-js-4.1.2.css';
    document.head.appendChild(css);
    const js=document.createElement('script');
    js.src=V.mapillaryJs||'/assets/lib/mapillary-js-4.1.2.js';
    js.onload=()=>res(); js.onerror=()=>rej(new Error('mapillary-js failed to load'));
    document.head.appendChild(js);
  });
  return mlyLoading;
}

// open (or move to) an image id in the bottom dock; drops a "you are here" marker
export async function openMapillaryImage(imageId){
  const dock=document.getElementById('mlyDock');
  dock.classList.add('open'); dock.setAttribute('aria-hidden','false');
  { const ap=document.querySelector('.app'); if(ap) ap.classList.add('dock-open'); }
  try{ await loadMapillaryJs(); }
  catch(_){ mlyClose(); mlyPopup(map.getCenter(),'Viewer failed to load.'); return; }
  if(!mlyViewer){
    mlyViewer=new mapillary.Viewer({accessToken:MAPILLARY_TOKEN,container:'mlyView',imageId});
    mlyViewer.on('image',ev=>{
      document.getElementById('mlyDock').classList.remove('loading');   // photo rendered → hide the spinner
      const ll=ev.image&&ev.image.lngLat; if(!ll) return;
      if(!mlyMarker){
        const el=document.createElement('div'); el.className='cc-pin cur cam';
        el.style.setProperty('--c',MLY_GREEN); el.innerHTML='<span>📷</span>';
        mlyMarker=new maplibregl.Marker({element:el,anchor:'bottom'}).setLngLat([ll.lng,ll.lat]).addTo(map);
      } else mlyMarker.setLngLat([ll.lng,ll.lat]);
    });
  } else { mlyViewer.moveTo(imageId).catch(()=>{}); }
}
export function mlyClose(){
  const dock=document.getElementById('mlyDock');
  dock.classList.remove('open','full','loading'); dock.setAttribute('aria-hidden','true');
  document.querySelector('.app').classList.remove('dock-open');   // restore the filters peek
  if(mlyMarker){ mlyMarker.remove(); mlyMarker=null; }
}

/** The dock's close button, resize grip and fullscreen toggle. */
export function initMapillaryDock(){
  document.getElementById('mlyClose').onclick=mlyClose;
  // User-resizable dock height: drag the top-edge grip (pointer events cover
  // mouse + touch), ↑/↓ when the grip is focused, double-click resets to the
  // CSS default. Persisted per browser. The Mapillary canvas measures itself
  // once at mount, so every height change needs an explicit viewer.resize().
  const MLY_H_KEY='cc-mly-dock-h';
  const mlyDockEl=document.getElementById('mlyDock'), mlyGrip=document.getElementById('mlyGrip');
  function mlyRemeasure(){ requestAnimationFrame(()=>{ if(mlyViewer){ try{ mlyViewer.resize(); }catch(_){} } }); }
  function setDockH(px, persist){
    const h=Math.max(160, Math.min(Math.round(window.innerHeight*0.85), Math.round(px)));
    mlyDockEl.style.height=h+'px';
    if(persist){ try{ localStorage.setItem(MLY_H_KEY, String(h)); }catch(_){} }
    mlyRemeasure();
  }
  if(mlyGrip){
    try{ const saved=+localStorage.getItem(MLY_H_KEY); if(saved) setDockH(saved, false); }catch(_){}
    let _rs=null;   // {y: drag-start clientY, h: drag-start dock height}
    mlyGrip.addEventListener('pointerdown',e=>{
      if(mlyDockEl.classList.contains('full')) return;
      e.preventDefault();
      _rs={y:e.clientY, h:mlyDockEl.getBoundingClientRect().height};
      try{ mlyGrip.setPointerCapture(e.pointerId); }catch(_){}   // capture is an optimisation, not a requirement
    });
    mlyGrip.addEventListener('pointermove',e=>{ if(_rs) setDockH(_rs.h+(_rs.y-e.clientY), false); });
    mlyGrip.addEventListener('pointerup',e=>{ if(!_rs) return; setDockH(_rs.h+(_rs.y-e.clientY), true); _rs=null; });
    mlyGrip.addEventListener('pointercancel',()=>{ _rs=null; });
    mlyGrip.addEventListener('dblclick',()=>{
      mlyDockEl.style.height='';
      try{ localStorage.removeItem(MLY_H_KEY); }catch(_){}
      mlyRemeasure();
    });
    mlyGrip.addEventListener('keydown',e=>{
      if(e.key!=='ArrowUp' && e.key!=='ArrowDown') return;
      e.preventDefault();
      setDockH(mlyDockEl.getBoundingClientRect().height + (e.key==='ArrowUp'?24:-24), true);
    });
  }
  // Fullscreen relies on the class's height:auto, which an inline height would
  // override — stash the custom height while full, restore it on the way back.
  document.getElementById('mlyFull').onclick=()=>{
    const entering=!mlyDockEl.classList.contains('full');
    if(entering){ mlyDockEl.dataset.h=mlyDockEl.style.height||''; mlyDockEl.style.height=''; }
    else if(mlyDockEl.dataset.h){ mlyDockEl.style.height=mlyDockEl.dataset.h; }
    mlyDockEl.classList.toggle('full');
    mlyRemeasure();
  };
}

/* #ovStreet toggle lives here so `mlyOn` stays module-private. */
export function initStreetToggle(){
  const ovStreet=document.getElementById('ovStreet');
  if(MLY_ENABLED){
    ovStreet.onclick=function(){
      const on=!this.classList.contains('on'); this.classList.toggle('on',on);
      mlyOn=on;
      ['mly-cov','mly-img'].forEach(id=>{ if(map.getLayer(id)) map.setLayoutProperty(id,'visibility', on?'visible':'none'); });
      if(!on) mlyClose();
    };
  } else {
    // no Mapillary token → coverage tiles can't load; show the control as unavailable instead of a dead toggle
    ovStreet.classList.add('mc-off');
    ovStreet.title='Street-level needs a Mapillary token — set MAPILLARY_TOKEN in /map';
  }
}
