// SPDX-License-Identifier: AGPL-3.0-only
/* Mobile snap sheet (peek / half / full) and hover tip.
   @see docs/specs/map-and-search.md §6.6 */
import { map } from './map-init.js';
import { closeDrawer } from './drawer.js';

// Callers hold `sheet` before initSheet(); no-op until then, and on pages with no drawer.
let _sheet = {reset(){}, clear(){}};
export const sheet = {
  reset(){ _sheet.reset(); },
  clear(){ _sheet.clear(); },
};

export function initSheet(){
  _sheet = initSnapSheet();
}

function initSnapSheet(){
  const d=document.getElementById('drawer'), grab=document.getElementById('drawerGrab');
  if(!d||!grab) return {reset(){}};
  const mobile=()=>window.innerWidth<=820;
  const rem=()=>parseFloat(getComputedStyle(document.documentElement).fontSize)||16;
  let snap='half', justDragged=false;
  const setSnap=s=>{ snap=s; d.classList.toggle('s-full', s==='full'); d.classList.toggle('s-peek', s==='peek'); d.style.transform=''; if(s==='full') {} else d.scrollTop=0; };
  function offsets(){
    const h=d.getBoundingClientRect().height;
    return {full:0, half:Math.max(0, h-window.innerHeight*0.5), peek:Math.max(0, h-7.5*rem())};
  }
  let active=false, dragging=false, viaGrab=false, startY=0, startT=0, dy=0, baseOff=0, peekClamp=0;
  d.addEventListener('touchstart', e=>{
    if(!mobile() || e.touches.length!==1 || !d.classList.contains('open')) return;
    viaGrab=grab.contains(e.target);
    if(!viaGrab && snap==='full' && d.scrollTop>0) return;   // mid-scroll at full → content's gesture
    active=true; dragging=false; startY=e.touches[0].clientY; startT=e.timeStamp; dy=0;
    // Start from the rendered transform: 50svh vs innerHeight diverge when the URL bar retracts.
    const tr=getComputedStyle(d).transform;
    baseOff = (tr && tr!=='none') ? new DOMMatrixReadOnly(tr).m42 : 0;
    peekClamp = offsets().peek + 40;
  }, {passive:true});
  d.addEventListener('touchmove', e=>{
    if(!active) return;
    dy=e.touches[0].clientY-startY;
    if(!dragging){
      // At full, content owns upward drags; the sheet owns downward from scrollTop 0.
      if(!viaGrab && snap==='full' && dy<-4){ active=false; return; }
      if(Math.abs(dy)<=4) return;
      dragging=true;
    }
    e.preventDefault();                              // own the gesture (passive:false)
    d.classList.add('dragging');
    d.style.transform=`translateY(${Math.min(Math.max(0, baseOff+dy), peekClamp)}px)`;
  }, {passive:false});
  d.addEventListener('touchend', ()=>{
    if(!dragging){ active=false; return; }
    active=false; dragging=false; justDragged=true; setTimeout(()=>{ justDragged=false; }, 450);
    d.classList.remove('dragging');
    const off=offsets(), pos=baseOff+dy, vel=dy/Math.max(1, performance.now()-startT);
    if(vel>0.5){ if(snap==='peek' || pos>off.peek+20){ dismiss(); return; } setSnap(snap==='full'?'half':'peek'); return; }
    if(vel<-0.5){ setSnap(snap==='peek'?'half':'full'); return; }
    if(pos>off.peek+60){ dismiss(); return; }
    let best='full', bestD=Infinity;
    ['full','half','peek'].forEach(s=>{ const dd=Math.abs(pos-off[s]); if(dd<bestD){ bestD=dd; best=s; } });
    setSnap(best);
  });
  d.addEventListener('touchcancel', ()=>{
    if(!active && !dragging) return;
    active=false; dragging=false;
    d.classList.remove('dragging');
    setSnap(snap);
  });
  function dismiss(){
    d.style.transform='translateY(102%)';
    let closed=false;
    const fin=ev=>{ if(closed||(ev&&ev.propertyName!=='transform')) return; closed=true;
      d.removeEventListener('transitionend',fin); closeDrawer(); };
    d.addEventListener('transitionend', fin);
    setTimeout(fin, 320);                            // fallback if transitionend doesn't fire
  }
  grab.addEventListener('click', ()=>{ if(!mobile()||justDragged) return; setSnap(snap==='full'?'half':'full'); });
  return {
    reset(){ setSnap('half'); },
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
