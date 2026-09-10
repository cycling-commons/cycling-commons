// SPDX-License-Identifier: AGPL-3.0-only
/* Photo lightbox and “this photo shows me”.
   @see docs/specs/photo-uploads.md §5, §6c */
import { escPend, safeHref } from './util.js';
import { D } from './i18n.js';
import { closeDrawer, photoCap } from './drawer.js';
import { closeClimbProfile, isClimbProfileOpen } from './climb-profile.js';

// docs/specs/photo-uploads.md §6c — uuid from the stored URL so older galleries still link.
const MEDIA_UUID = /\/photos\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\//i;
export function reportLink(p){
  const m = MEDIA_UUID.exec(String(p && (p.lg || p.sm) || ''));
  if(!m) return '';
  return ` · <a class="cc-lb-report" href="/photo/${m[1]}/report"><span class="cc-bang" aria-hidden="true">!</span>${escPend(D.reportPhoto||'Report this photo')}</a>`;
}

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
  img.src=safeHref(p.lg);
  // The rider's description, under the picture in plain type, and as the
  // image's own alt: the same words a screen reader gets (owner, 2026-09-06).
  // Name as the fallback alt, exactly as the drawer does; the visible line
  // shows only when somebody actually wrote one.
  const desc = lb.querySelector('.cc-lb-desc');
  img.alt = p.alt || _lb.name || '';
  desc.textContent = p.alt || '';
  desc.hidden = !p.alt;
  // docs/specs/photo-uploads.md §5 — never offer `orig` in srcset.
  if(p.sm && p.lg){ img.srcset = `${safeHref(p.sm)} 520w, ${safeHref(p.lg)} 1400w`; img.sizes = '100vw'; }
  else { img.removeAttribute('srcset'); img.removeAttribute('sizes'); }
  // photoCap omits missing parts; do not gate the caption on p.source (uploads have none).
  lb.querySelector('.cc-lb-cap').innerHTML =
    (_lb.name?`<b>${escPend(_lb.name)}</b> · `:'') + photoCap(p)
    + (multi?` · ${_lb.i+1} / ${_lb.photos.length}`:'') + reportLink(p);
  lb.querySelector('.cc-lb-prev').hidden=!multi; lb.querySelector('.cc-lb-next').hidden=!multi;
}
export function lbStep(d){ const n=_lb.photos.length; if(!n) return; _lb.i=(_lb.i+d+n)%n; renderLightbox(); }
export function closeLightbox(){
  const lb=document.getElementById('lightbox'); lb.classList.remove('open');
  lb.setAttribute('aria-hidden','true');
  // Clear srcset with src, or a stale candidate flashes on the next open.
  const img = lb.querySelector('img');
  img.src=''; img.removeAttribute('srcset'); img.removeAttribute('sizes');
}

export function initLightbox(){
  document.querySelector('.cc-lb-prev').onclick=e=>{ e.stopPropagation(); lbStep(-1); };
  document.querySelector('.cc-lb-next').onclick=e=>{ e.stopPropagation(); lbStep(1); };
  document.getElementById('lightbox').addEventListener('click',e=>{ if(e.target.id==='lightbox'||e.target.classList.contains('cc-lb-x')) closeLightbox(); });
  // Escape: climb profile (innermost), then lightbox, then drawer — so Esc does not also close the drawer under the profile.
  const cp=document.getElementById('climbProfile');
  if(cp) cp.addEventListener('click',e=>{ if(e.target.id==='climbProfile'||e.target.classList.contains('cc-cp-x')) closeClimbProfile(); });
  document.addEventListener('keydown',e=>{
    const lbOpen=document.getElementById('lightbox').classList.contains('open');
    if(e.key==='Escape'){ if(isClimbProfileOpen()) closeClimbProfile(); else if(lbOpen) closeLightbox(); else closeDrawer(); }
    else if(lbOpen && e.key==='ArrowLeft') lbStep(-1);
    else if(lbOpen && e.key==='ArrowRight') lbStep(1);
  });
}
