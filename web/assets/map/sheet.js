// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The mobile snap sheet (peek / half / full) and the map hover tip.
   Extracted from map.js by 2026-07-26-map-js-module-split-design.md §5.

   The sheet is built by an IIFE that binds drag handlers, so constructing it is
   a side effect and belongs to the entry (§4.2): initSheet() runs it, and the
   exported `sheet` is a stable facade over the result. That indirection is what
   lets ride-check.js and the drawer hold a reference to `sheet` before
   initSheet() has run — the pre-init facade is a no-op rather than a crash,
   which is exactly the old `{reset(){}}` fallback for a page with no drawer. */
import { map } from './map-init.js';

// Injected by initSheet() until drawer.js exists (§5 step 6).
let closeDrawer;

// Pre-init and no-drawer pages both land on this no-op, same as before.
let _sheet = {reset(){}, clear(){}};
export const sheet = {
  reset(){ _sheet.reset(); },
  clear(){ _sheet.clear(); },
};

export function initSheet(deps){
  ({closeDrawer} = deps);
  _sheet = initSnapSheet();
}

// Mobile snap sheet (spec 2026-07-14): peek / half / full resting states.
// Content scrolls only at full; below full any vertical drag moves the
// sheet; at full, a downward drag while scrollTop===0 grabs the sheet back
// (Google-Maps-style hand-off). Desktop (>820px) never enters this code.
function initSnapSheet(){
  const d=document.getElementById('drawer'), grab=document.getElementById('drawerGrab');
  if(!d||!grab) return {reset(){}};
  const mobile=()=>window.innerWidth<=820;
  const rem=()=>parseFloat(getComputedStyle(document.documentElement).fontSize)||16;
  let snap='half', justDragged=false;
  const setSnap=s=>{ snap=s; d.classList.toggle('s-full', s==='full'); d.classList.toggle('s-peek', s==='peek'); d.style.transform=''; if(s==='full') {} else d.scrollTop=0; };
  // translateY offsets (px from fully-open) for each resting state
  function offsets(){
    const h=d.getBoundingClientRect().height;
    return {full:0, half:Math.max(0, h-window.innerHeight*0.5), peek:Math.max(0, h-7.5*rem())};
  }
  let active=false, dragging=false, viaGrab=false, startY=0, startT=0, dy=0, baseOff=0;
  d.addEventListener('touchstart', e=>{
    if(!mobile() || e.touches.length!==1 || !d.classList.contains('open')) return;
    viaGrab=grab.contains(e.target);
    if(!viaGrab && snap==='full' && d.scrollTop>0) return;   // mid-scroll at full → content's gesture
    active=true; dragging=false; startY=e.touches[0].clientY; startT=e.timeStamp; dy=0;
    // baseOff from the sheet's ACTUAL rendered transform, not the offsets()
    // table: the CSS half rule rests at 50svh while offsets() uses
    // innerHeight — with a retracted URL bar those bases differ and a
    // table-derived baseOff would jump the sheet on the first touchmove.
    // offsets() is still fine for the release-snap targets below.
    const tr=getComputedStyle(d).transform;
    baseOff = (tr && tr!=='none') ? new DOMMatrixReadOnly(tr).m42 : 0;
  }, {passive:true});
  d.addEventListener('touchmove', e=>{
    if(!active) return;
    dy=e.touches[0].clientY-startY;
    if(!dragging){
      // At full, content owns upward drags (scroll); the sheet owns downward
      // ones from scrollTop 0. Below full the sheet owns both directions.
      if(!viaGrab && snap==='full' && dy<-4){ active=false; return; }
      if(Math.abs(dy)<=4) return;                    // wait for a clear direction
      dragging=true;
    }
    e.preventDefault();                              // own the gesture (passive:false)
    d.classList.add('dragging');
    const off=offsets();
    d.style.transform=`translateY(${Math.min(Math.max(0, baseOff+dy), off.peek+40)}px)`;   // clamp: never above full; slight give past peek
  }, {passive:false});
  d.addEventListener('touchend', ()=>{
    if(!dragging){ active=false; return; }
    active=false; dragging=false; justDragged=true; setTimeout(()=>{ justDragged=false; }, 450);
    d.classList.remove('dragging');
    const off=offsets(), pos=baseOff+dy, vel=dy/Math.max(1, performance.now()-startT);   // px/ms, + = down
    // fast flick: skip straight to the neighbouring state in the flick's direction
    if(vel>0.5){ if(snap==='peek' || pos>off.peek+20){ dismiss(); return; } setSnap(snap==='full'?'half':'peek'); return; }
    if(vel<-0.5){ setSnap(snap==='peek'?'half':'full'); return; }
    if(pos>off.peek+60){ dismiss(); return; }        // dragged well past peek → close
    // otherwise: snap to nearest resting state
    let best='full', bestD=Infinity;
    ['full','half','peek'].forEach(s=>{ const dd=Math.abs(pos-off[s]); if(dd<bestD){ bestD=dd; best=s; } });
    setSnap(best);
  });
  d.addEventListener('touchcancel', ()=>{
    if(!active && !dragging) return;
    active=false; dragging=false;
    d.classList.remove('dragging');
    setSnap(snap);                    // re-settle on the last resting state
  });
  function dismiss(){
    d.style.transform='translateY(102%)';
    let closed=false;
    const fin=ev=>{ if(closed||(ev&&ev.propertyName!=='transform')) return; closed=true;
      d.removeEventListener('transitionend',fin); closeDrawer(); };
    d.addEventListener('transitionend', fin);
    setTimeout(fin, 320);                            // fallback if transitionend doesn't fire
  }
  grab.addEventListener('click', ()=>{ if(!mobile()||justDragged) return; setSnap(snap==='full'?'half':'full'); });   // Enter/Space included (button)
  return {
    reset(){ setSnap('half'); },                     // openDrawer lands every sheet at half
    clear(){ d.classList.remove('s-full','s-peek'); d.style.transform=''; snap='half'; },
  };
}

export const tipEl=document.getElementById('tip');
export function showTip(text, lngLat){
  const p=map.project(lngLat);
  tipEl.textContent=text; tipEl.style.left=p.x+'px'; tipEl.style.top=p.y+'px'; tipEl.hidden=false;
}
export function hideTip(){ tipEl.hidden=true; }
map.on('move', ()=>{ if(!tipEl.hidden) hideTip(); });
