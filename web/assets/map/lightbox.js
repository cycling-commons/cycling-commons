// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The photo lightbox: a slideshow over one feature's gallery, plus its arrow
   and Escape key handling.
   Extracted from map.js by the module split.

   The keydown handler is shared with the drawer — Escape closes the lightbox if
   it is open and the drawer otherwise — so it lives here (the lightbox owns the
   "is the lightbox open" question) and imports closeDrawer from drawer.js.
   photoCap renders the credit/licence caption and is shared with the drawer's
   own photo strip, so it stays where the drawer builds it. */
import { escPend, safeHref } from './util.js';
import { closeDrawer, photoCap } from './drawer.js';

// lightbox doubles as a slideshow over a feature's photo gallery
export let _lb={photos:[],i:0,name:''};
export function openLightbox(photos, i, name){
  _lb.photos = Array.isArray(photos) ? photos : [{lg:photos, credit:'', license:'', source:''}];
  _lb.i = i||0; _lb.name = name||'';
  renderLightbox();
  const lb=document.getElementById('lightbox'); lb.classList.add('open'); lb.setAttribute('aria-hidden','false');
}
export function renderLightbox(){
  const lb=document.getElementById('lightbox'), p=_lb.photos[_lb.i], multi=_lb.photos.length>1;
  const img = lb.querySelector('img');
  img.src=p.lg;
  // Full-viewport display: let the device choose, but never offer `orig`
  // (docs/specs/photo-uploads.md §5).
  if(p.sm && p.lg){ img.srcset = `${p.sm} 520w, ${p.lg} 1400w`; img.sizes = '100vw'; }
  else { img.removeAttribute('srcset'); img.removeAttribute('sizes'); }
  // The caption used to be gated on p.source, which every rider upload lacks —
  // that would have silently dropped the licence and credit for exactly the
  // photos whose licence most needs stating. photoCap() now renders each part
  // only when it exists, so it is safe to always call.
  lb.querySelector('.cc-lb-cap').innerHTML =
    (_lb.name?`<b>${escPend(_lb.name)}</b> · `:'') + photoCap(p) + (multi?` · ${_lb.i+1} / ${_lb.photos.length}`:'');
  lb.querySelector('.cc-lb-prev').hidden=!multi; lb.querySelector('.cc-lb-next').hidden=!multi;
}
export function lbStep(d){ const n=_lb.photos.length; if(!n) return; _lb.i=(_lb.i+d+n)%n; renderLightbox(); }
export function closeLightbox(){
  const lb=document.getElementById('lightbox'); lb.classList.remove('open');
  lb.setAttribute('aria-hidden','true');
  // Clear srcset alongside src, or a stale candidate flashes on the next open.
  const img = lb.querySelector('img');
  img.src=''; img.removeAttribute('srcset'); img.removeAttribute('sizes');
}

// Lightbox chrome + the shared Escape/arrow key handling (§4.2).
export function initLightbox(){
  document.querySelector('.cc-lb-prev').onclick=e=>{ e.stopPropagation(); lbStep(-1); };
  document.querySelector('.cc-lb-next').onclick=e=>{ e.stopPropagation(); lbStep(1); };
  document.getElementById('lightbox').addEventListener('click',e=>{ if(e.target.id==='lightbox'||e.target.classList.contains('cc-lb-x')) closeLightbox(); });
  document.addEventListener('keydown',e=>{
    const lbOpen=document.getElementById('lightbox').classList.contains('open');
    if(e.key==='Escape'){ lbOpen ? closeLightbox() : closeDrawer(); }
    else if(lbOpen && e.key==='ArrowLeft') lbStep(-1);
    else if(lbOpen && e.key==='ArrowRight') lbStep(1);
  });
}
