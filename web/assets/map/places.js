// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Town card and deep-link / search openers.
   @see docs/specs/map-and-search.md §6.5, §8 */
import { modeShows } from './filters.js';
import { D, tpl } from './i18n.js';
import { escPend, safeHref, txtOn, haversine, featurePoint } from './util.js';
import { uKm, uM } from './units.js';
import { map, flyToPin } from './map-init.js';
import { CATALOG, CITIES, active, layerByKey, LETTER_KEY, mode } from './catalog.js';
import { osmLayers } from './osm-pools.js';
import { nearbyItems, idxIds } from './item-index.js';
import { render } from './render.js';
import { sheet } from './sheet.js';
import { openDrawer, osmDrawer, waterDrawer, highlightAt, clearHighlight, revealPinAt } from './drawer.js';
import { COVERAGE_ON, widenForDeepLink, openCoverageByRef,
         invalidateCoverageDrawer } from './coverage.js';
import { showRouteCorrections } from './corrections.js';
import { layerGlyph } from './icons.js';

export function bumpPlaceReq(){ _placeReq++; }

// Race-guard: every drawer-context render bumps this with invalidateCoverageDrawer()
// so a stale /map/coverage/nearby response cannot resurrect a dismissed town card.
let _placeReq=0;

const NEARBY_KM = 5;

export function openPlace(name, meta){
  // docs/specs/map-and-search.md §4.5 — town outside the saved scope transiently widens (persist:false).
  const sbb = window.CCScope && window.CCScope.bbox ? window.CCScope.bbox() : null;
  if(sbb && (meta.ll[1]<sbb[0] || meta.ll[0]<sbb[1] || meta.ll[1]>sbb[2] || meta.ll[0]>sbb[3])){
    widenForDeepLink(meta.ll);
  }
  // A · segments are corridor data, not places — they would flood the card.
  const near = nearbyItems(meta.ll, NEARBY_KM).filter(n=>n.e.letter!=='A');
  renderPlaceCard(name, meta, near);
  if(near.length){
    let minLat=meta.ll[0],maxLat=meta.ll[0],minLng=meta.ll[1],maxLng=meta.ll[1];
    near.forEach(n=>{ if(!n.e.ll) return; const [la,ln]=n.e.ll;
      if(la<minLat)minLat=la; if(la>maxLat)maxLat=la; if(ln<minLng)minLng=ln; if(ln>maxLng)maxLng=ln; });
    const mobile=window.innerWidth<=820;
    map.fitBounds([[minLng,minLat],[maxLng,maxLat]],
      {padding:{top:70, bottom:mobile?300:70, left:70, right:mobile?70:400}, maxZoom:13.5, duration:900, essential:true});
  } else {
    map.flyTo({center:[meta.ll[1],meta.ll[0]], zoom:12.5, offset:[window.innerWidth<=820?0:-150,0], duration:900, essential:true});
  }
  if(!COVERAGE_ON) return;
  const myReq=++_placeReq;
  fetch(`/map/coverage/nearby?lat=${meta.ll[0]}&lng=${meta.ll[1]}&km=${NEARBY_KM}`, {headers:{'Accept':'application/json'}})
    .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
    .then(d=>{ if(myReq!==_placeReq) return;
      if(!document.getElementById('drawer').classList.contains('open')) return;
      renderPlaceCard(name, meta, near, d.groups||[]); })
    .catch(()=>{});
}
function renderPlaceCard(name, meta, near, covGroups){
  const all=near.slice();
  (covGroups||[]).forEach(g=>{
    const key=LETTER_KEY[g.letter], layer=key&&layerByKey[key]; if(!layer) return;
    (g.items||[]).forEach(it=>{
      if(!it || !Array.isArray(it.ll)) return;
      if(it.itemId!=null && idxIds().has(g.letter+':'+it.itemId)) return;
      all.push({dist:haversine(meta.ll, it.ll), e:{name:it.n||layer.label, kind:layer.label,
        badge:layerGlyph(layer), color:layer.color, letter:g.letter, ll:it.ll, hlOff:[0,0], osm:!it.curated,
        go:()=>openCoverageByRef(it.ref, g.letter, it.ll, it.n, it.itemId)}});
    });
  });
  const byLetter={};
  all.forEach((n,i)=>{ n._i=i; (byLetter[n.e.letter]=byLetter[n.e.letter]||[]).push(n); });
  const letters=Object.keys(byLetter).sort();
  // Three tiers (map-and-search.md §12, owner 2026-09-06): "community" is a
  // row OUR community has confirmed, whatever put it there first (OSM, a
  // register, a rider); "unconfirmed" is a row nobody here has checked yet;
  // "OSM" is the imported baseline the catalogue does not hold. Confirmed
  // rows list first; the other two share the capped second tier.
  const isUnconfirmed=n=>!n.e.osm && (n.e.community || n.e.verified===false);
  const isConfirmed=n=>!n.e.osm && !isUnconfirmed(n) && n.e.verified!==undefined;
  const isSecond=n=>!isConfirmed(n);
  const tierTag=n=>isConfirmed(n) ? `<span class="cc-comm-tag">${escPend(D.community||'community')}</span>`
    : isUnconfirmed(n) ? `<span class="cc-comm-tag">${escPend(D.unconfirmed||'unconfirmed')}</span>`
    : (n.e.osm ? `<span class="cc-comm-tag">${escPend(D.osmTag||'OSM')}</span>` : '');
  const list = all.length
    ? letters.map(L=>{ const rows=byLetter[L], e0=rows[0].e;
        // docs/specs/map-and-search.md §12 — verified first; community subgroup capped at 3.
        const ver=rows.filter(n=>!isSecond(n)), com=rows.filter(isSecond);
        const row=(n,hidden)=>`<li${hidden?` hidden data-more="${L}"`:''}><button class="cc-near" data-i="${n._i}"><span class="cc-near-nm">${escPend(n.e.name)}${tierTag(n)}</span><em>${n.dist<1?uM(Math.round(n.dist*1000)):uKm(n.dist)}</em></button></li>`;
        let html=`<li class="cc-near-grp"><span class="cc-near-k" style="background:${e0.color};color:${txtOn(e0.color)}">${e0.badge}</span>${escPend(e0.kind)} · ${rows.length}</li>`;
        html+=ver.map(n=>row(n,false)).join('');
        html+=com.slice(0,3).map(n=>row(n,false)).join('');
        html+=com.slice(3).map(n=>row(n,true)).join('');
        if(com.length>3) html+=`<li><button class="cc-near-more" data-grp="${L}">${escPend(tpl(D.showAll||'show all {n}',{n:com.length}))}</button></li>`;
        return html;
      }).join('')
    : `<li class="cc-near-empty">${D.nothingHere||'Nothing mapped here yet — be the first to add something.'}</li>`;
  invalidateCoverageDrawer();
  document.getElementById('drawerBody').innerHTML =
    `<span class="cc-d-type" style="--c:#3E7D8C;color:#fff">◎ ${meta.t==='City'?(D.city||'City'):(D.town||'Town')}</span>
     <div class="cc-d-name">${escPend(name)}</div>
     ${meta.info?`<div class="cc-city-info">${escPend(meta.info)}</div>`:''}
     <div class="cc-city-links">${meta.wiki?`<a href="${safeHref(meta.wiki)}" target="_blank" rel="noopener">Wikipedia ↗</a> · `:''}<span class="cc-city-ua">${D.notesNone||'community notes — none yet'}</span></div>
     <h4 class="cc-near-h">${(D.nearbyH||'In the Commons nearby · ≤ {d}').replace('{d}', uKm(NEARBY_KM, 0))}</h4>
     <ul class="cc-near-list">${list}</ul>`;
  document.querySelectorAll('#drawerBody .cc-near').forEach(b=>{
    const n=all[+b.dataset.i];
    b.onclick=()=>n.e.go();
    b.onmouseenter=()=>highlightAt(n.e.ll, n.e.hlOff); b.onmouseleave=clearHighlight;
  });
  document.querySelectorAll('#drawerBody .cc-near-more').forEach(b=>{
    b.onclick=()=>{ document.querySelectorAll(`#drawerBody li[data-more="${b.dataset.grp}"]`).forEach(li=>li.hidden=false);
      b.closest('li').hidden=true; };
  });
  const d=document.getElementById('drawer'); d.classList.add('open'); d.setAttribute('aria-hidden','false');
  d.focus({preventScroll:true});
  if(window.innerWidth<=820) sheet.reset();
}
export function openCity(name){
  const c = CITIES[name]; if(!c) return;
  openPlace(name, c);
}
export function resolveLocalFeature(name){
  let found=null;
  CATALOG.forEach(layer=>layer.features.forEach(f=>{ if(f.name===name) found={layer,f}; }));
  if(found) return found;
  // An authority's stays are not in CATALOG; resolving here avoids a coverage
  // 404 on their synthetic refs.
  const pv=(window.CC_STAYS_AUTHORITY && CC_STAYS_AUTHORITY.features || [])
    .find(f=>f.properties && f.properties.n===name);
  if(pv) return {authority:pv};
  // docs/specs/map-and-search.md §12 — pool features are not in CATALOG; skip this and a name deep-link opens the OSM twin.
  for(const key of Object.keys(osmLayers)){
    const info=osmLayers[key];
    const f=info && info.data && (info.data.features||[]).find(x=>x.properties && x.properties.n===name);
    if(f) return {poolKey:key, f};
  }
  return null;
}
// Side-effect-free lookup; the deep-link widen gate asks this before any scope change.
export function resolveLocalFeatureById(id){
  const want = String(id);
  let found = null;
  CATALOG.forEach(layer => (layer.features || []).forEach(f => {
    if (f.id != null && String(f.id) === want) found = { layer, f };
  }));
  if (found) return found;
  for (const key of Object.keys(osmLayers)) {
    const info = osmLayers[key];
    const f = info && info.data && (info.data.features || [])
      .find(x => x.properties && x.properties.id != null && String(x.properties.id) === want);
    if (f) return { poolKey: key, f };
  }
  return null;
}

export function openFeatureById(id){
  const found = resolveLocalFeatureById(id);
  if (!found) return false;
  if (found.poolKey) return openPoolFeature(found.poolKey, found.f);
  return openLocalFeature(found.layer, found.f);
}

export function openFeatureByName(name){
  const found=resolveLocalFeature(name);
  if(!found) return false;
  if(found.authority){
    const pv=found.authority;
    const c=pv.geometry && pv.geometry.coordinates;
    if(c && c.length>=2) flyToPin([+c[0],+c[1]]);
    openStayAuthority(pv);
    return true;
  }
  if(found.poolKey) return openPoolFeature(found.poolKey, found.f);
  return openLocalFeature(found.layer, found.f);
}
export function openPoolFeature(key, f){
  const layer=layerByKey[key]; if(!layer) return false;
  const c=f.geometry && f.geometry.coordinates; if(!c || c.length<2) return false;
  const lo={lng:+c[0], lat:+c[1]};
  if(!active.has(key)){
    active.add(key);
    const t=document.querySelector(`#layers .layer[data-key="${key}"]`); if(t) t.classList.remove('off');
    render();
  }
  const info=osmLayers[key]||{};
  openDrawer(layer, info.water ? waterDrawer(f.properties, lo) : osmDrawer(layer, f.properties, lo, info.src||''));
  flyToPin([lo.lng, lo.lat]);
  return true;
}
export function openLocalFeature(layer, f){
  if(!active.has(layer.key)){
    active.add(layer.key);
    const t=document.querySelector(`#layers .layer[data-key="${layer.key}"]`); if(t) t.classList.remove('off');
    render();
  }
  openDrawer(layer,f);
  const p=featurePoint(f); if(p) flyToPin([p[1],p[0]]);
  return true;
}
export function openRouteById(id){
  const layer=layerByKey['experience']; if(!layer) return false;
  const f=layer.features.find(x=>String(x.id)===String(id));
  if(!f) return false;
  if(!active.has('experience')){
    active.add('experience');
    const t=document.querySelector('#layers .layer[data-key="experience"]'); if(t) t.classList.remove('off');
    render();
  }
  openDrawer(layer,f);
  const p=featurePoint(f); if(p) flyToPin([p[1],p[0]]);
  if(!modeShows(mode(), layer, f) && p) revealPinAt(layer, p);  // docs/specs/map-and-search.md §12: reveal, never force-switch
  showRouteCorrections(id);
  return true;
}

export function openPendingById(id){
  const layer = layerByKey.pending; if(!layer) return false;
  const f = layer.features.find(x=>x.pending && String(x.pending.id)===String(id));
  if(!f) return false;
  if(!active.has('pending')){
    active.add('pending');
    const t=document.querySelector('#layers .layer[data-key="pending"]'); if(t) t.classList.remove('off');
    render();
  }
  openDrawer(layer,f);
  const p=featurePoint(f); if(p) flyToPin([p[1],p[0]]);
  return true;
}
export function openStayAuthority(f){
  const layer=layerByKey.stays; if(!layer) return false;
  if(!active.has('stays')){
    active.add('stays');
    const t=document.querySelector('#layers .layer[data-key="stays"]'); if(t) t.classList.remove('off');
    render();
  }
  const c=f.geometry.coordinates;
  openDrawer(layer, osmDrawer(layer, f.properties, {lng:c[0], lat:c[1]}, (osmLayers.stays||{}).src||''));
  flyToPin(c);
  return true;
}
