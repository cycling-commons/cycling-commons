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
import { openDrawer, osmDrawer, waterDrawer, highlightAt, clearHighlight, revealPinAt, commonsPhotoHtml } from './drawer.js';
import { openLightbox } from './lightbox.js';
import { COVERAGE_ON, widenForDeepLink, openCoverageByRef,
         invalidateCoverageDrawer } from './coverage.js';
import { showRouteCorrections } from './corrections.js';
import { layerGlyph } from './icons.js';
import { watchJson } from './commons-photo.js';

export function bumpPlaceReq(){ _placeReq++; }

// Race-guard: every drawer-context render bumps this with invalidateCoverageDrawer()
// so a stale /map/coverage/nearby response cannot resurrect a dismissed town card.
let _placeReq=0;

const NEARBY_KM = 5;

export function openPlace(name, meta){
  // docs/specs/map-and-search.md §4.5 — town outside the saved scope transiently widens (persist:false).
  /* CCScope owns the comparison: a scope box may cross the antimeridian and
     read west > east (RFC 7946 §5.2), which no inline test gets right. meta.ll
     is [lat, lng]; the helper takes them the other way round. */
  if(window.CCScope && window.CCScope.bboxHasPoint && !window.CCScope.bboxHasPoint(meta.ll[1], meta.ll[0])){
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
     ${meta.wiki?`<div class="cc-city-links"><a href="${safeHref(meta.wiki)}" target="_blank" rel="noopener">Wikipedia ↗</a></div>`:''}
     ${(!meta.info && meta.osm)?townWaiting(meta):''}
     <h4 class="cc-near-h">${(D.nearbyH||'In the Commons nearby · ≤ {d}').replace('{d}', uKm(NEARBY_KM, 0))}</h4>
     <ul class="cc-near-list">${list}</ul>`;
  if(!meta.info && meta.osm) startTownWatch(name, meta);
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
/* docs/specs/map-and-search.md §6.5: what Wikipedia and Wikidata know about a
   town, fetched the first time anyone opens it and cached, the way the
   coverage photo is. The slot in the DOM is what says whether to keep polling:
   open something else and it is gone, and the poll stops by itself. */
function townUrl(meta){
  return '/map/town/'+meta.osm+'?lang='+encodeURIComponent((document.documentElement.lang||'en').slice(0,2))
    +'&lat='+(+meta.ll[0]).toFixed(5)+'&lng='+(+meta.ll[1]).toFixed(5);
}
function townWaiting(meta){
  return `<div class="cc-town" data-town-ref="${escPend(meta.osm)}">
    <div class="cc-d-photo-wait cc-town-wait"><span class="cc-d-spin" aria-hidden="true"></span>
    <span role="status">${escPend(D.townLoading||'Looking up Wikipedia…')}</span></div></div>`;
}
function relWords(rels){
  const w={start:D.raceStart||'starts here', finish:D.raceFinish||'finishes here', via:D.raceVia||'passes through',
    'stage-start':D.raceStageStart||'a stage starts here', 'stage-finish':D.raceStageFinish||'a stage finishes here', 'stage-via':D.raceVia||'passes through'};
  const has=r=>rels.includes(r);
  const out=[];
  if(has('start') && has('finish')) out.push(D.raceStartFinish||'starts and finishes here');
  else { if(has('start')) out.push(w.start); if(has('finish')) out.push(w.finish); }
  if(has('stage-start') && has('stage-finish')) out.push(D.raceStageStartFinish||'stages start and finish here');
  else { if(has('stage-start')) out.push(w['stage-start']); if(has('stage-finish')) out.push(w['stage-finish']); }
  if(has('via') || has('stage-via')) out.push(w.via);
  return out.join(' · ');
}
function townHtml(d, name, meta){
  const t=d.text, c=Array.isArray(d.cycling)?d.cycling:[], p=d.photo||{};
  const photo = p.state==='ready' ? commonsPhotoHtml(p, name)
    : (p.state==='pending' ? `<div class="cc-d-photo-wait" data-town-photo="1"><span class="cc-d-spin" aria-hidden="true"></span><span role="status">${escPend(D.photoLoading||'Loading image…')}</span></div>` : '');
  const f=d.facts||{}, lang=document.documentElement.lang||'en';
  const yearTxt=y=>{ if(!y || !y.year) return ''; const abs=Math.abs(y.year);
    let s=(y.year<0)?(D.yearBc||'{y} BC').replace('{y}', abs):String(abs);
    if(y.precision<9) s=(D.circa||'c.')+' '+s;   // Wikidata precision 7 = century, 8 = decade
    return s; };
  const popTxt=p=>(p && p.n) ? p.n.toLocaleString(lang)+(p.year?' ('+p.year+')':'') : '';
  const factRows=[[D.founded||'Founded', yearTxt(f.founded)], [D.inhabitants||'Inhabitants', popTxt(f.population)]].filter(r=>r[1]);
  const facts = factRows.length ? `<ul class="cc-town-facts">${factRows.map(r=>`<li><span>${escPend(r[0])}</span><b>${escPend(r[1])}</b></li>`).join('')}</ul>` : '';
  // docs/specs/content-reports.md: the one report door, keyed by the element, never a page.
  const reportHref = '/report/town/'+encodeURIComponent(String(meta.osm||'').replace('/', '-'))+'?name='+encodeURIComponent(name||'')+'&from='+encodeURIComponent(location.pathname+location.search);
  const report = `<a class="cc-bang" href="${safeHref(reportHref)}" title="${escPend(D.reportText||'Report this text')}" aria-label="${escPend(D.reportText||'Report this text')}">!</a>`;
  const credit = d.edited ? escPend(D.wikiEdited||'Edited by our curators, after Wikipedia CC BY-SA 4.0') : escPend(D.wikiText||'Text CC BY-SA 4.0');
  // Facts first, then the paragraph (owner 2026-09-08: "place these 2 info points above the text").
  const text = facts + (t ? `<div class="cc-city-info">${escPend(t.extract)}</div>` : '') + (t ? `
    <div class="cc-city-links"><a href="${safeHref(t.url)}" target="_blank" rel="noopener">Wikipedia ↗</a> · <a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank" rel="noopener">${credit}</a> ${report}</div>` : '');
  const races = c.length ? `<h4 class="cc-near-h">${escPend(D.cyclingH||'Cycling here')}</h4>
    <ul class="cc-town-races">${c.map(r=>{
      const label = r.url ? `<a href="${safeHref(r.url)}" target="_blank" rel="noopener">${escPend(r.label)}</a>` : escPend(r.label);
      const when = r.n>1 ? (D.raceEditions||'{n} editions · last {y}').replace('{n}', r.n).replace('{y}', r.last||'') : (r.last ? (D.raceOnce||'last {y}').replace('{y}', r.last) : '');
      return `<li class="cc-town-race">${label}<small>${escPend(relWords(r.rels||[]))}${when?' · '+escPend(when):''}</small></li>`; }).join('')}</ul>` : '';
  return photo + text + races;
}
function startTownWatch(name, meta){
  const find = () => document.querySelector(`#drawerBody .cc-town[data-town-ref="${CSS.escape(meta.osm)}"]`);
  if(!find()) return;
  const url = townUrl(meta);
  watchJson(url, { isReady: d => d.state==='ready', isPending: d => d.state==='pending' },
    d => {
      const el=find(); if(!el) return;
      el.innerHTML = townHtml(d, name, meta);
      const img = el.querySelector('.cc-d-photo > img');
      if(img) img.addEventListener('click', () => openLightbox([d.photo], 0, name||''));
      if(d.photo && d.photo.state==='pending'){
        // The text is here; the picture is still on its way. Same bounded poll again.
        watchJson(url, { isReady: x => !(x.photo && x.photo.state==='pending'), isPending: x => x.state==='ready' },
          x => { const slot=find() && find().querySelector('[data-town-photo]'); if(!slot) return;
            if(x.photo && x.photo.state==='ready'){ slot.outerHTML = commonsPhotoHtml(x.photo, name);
              const im=find().querySelector('.cc-d-photo > img'); if(im) im.addEventListener('click', () => openLightbox([x.photo], 0, name||'')); }
            else slot.remove(); },
          () => { const slot=find() && find().querySelector('[data-town-photo]'); if(slot) slot.remove(); },
          { cancelled: () => !find() });
      }
    },
    () => { const el=find(); if(el) el.remove(); },
    { cancelled: () => !find() });
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
