// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Region scope as the map sees it (docs/specs/map-and-search.md §4.5):
   curScope/inScope/scopeToken/scopeLabel, the chip rail, the header, applyScope
   (the one visual update path), and the pan-away widen prompt.
   State lives in window.CCScope (scope.js); chip decisions in scope-chips.js. */
import { map } from './map-init.js';
import { I18N, D, tpl } from './i18n.js';
import { escPend } from './util.js';
import { setSpotlight, setCountrySpotlight, setCircleSpotlight } from './spotlight.js';
import { mode } from './catalog.js';
import { refilterClusters, updateConfMarkers } from './osm-pools.js';
import { updateCoverageScopeFilter, fetchCoverageCounts } from './coverage.js';
import { updateHeatFilter, render, boundLayerIds, surfaceClsLayerIds } from './render.js';
import { refreshBestOf } from './panels.js';
import { COVERAGE_KEYS, COVERAGE_CCS } from './coverage.js';
import { isPicking } from './picking.js';
import { corrLayerIds } from './corrections.js';


// Region scope (docs/specs/map-and-search.md §4.5): area the map + search
// filter to, owned by window.CCScope. Registry from window.CC_REGIONS.
const CC_REGIONS = window.CC_REGIONS || [];
const _regionById = new Map(CC_REGIONS.map(r => [r.id, r]));
const slugOfRegion = id => { const r = _regionById.get(id); return r ? r.slug : null; };
const _defaultScope = (() => {
  // My area wins when a base location is set (docs/specs/map-and-search.md §4.5);
  // an explicit ?scope= still beats it (CCScope.init).
  if (window.CCScope && window.CCScope.myAreaAvailable()) return {kind: 'myArea', regionIds: [], countryCode: null};
  const w = CC_REGIONS.find(r => r.slug === 'wallonia') || CC_REGIONS[0];
  return w ? {kind: 'region', regionIds: [w.id], countryCode: w.countryCode} : {kind: 'everywhere', regionIds: [], countryCode: null};
})();

export const curScope = () => window.CCScope ? window.CCScope.get() : _defaultScope;
// In scope when rid is in the active scope. Region/country hide rid-less or
// out-of-region features; Everywhere shows all. Coverage tiles use their own
// filter (inverse prop-less rule — the tile artifact lags).
export const inScope = rid => { const s = curScope(); return !s || s.kind === 'everywhere' || s.regionIds.indexOf(rid) !== -1; };

// The data-scope token a scope maps to (matches the rail buttons' data-scope).
function scopeToken(s){
  if(!s||s.kind==='everywhere') return 'everywhere';
  if(s.kind==='myArea') return 'myarea';   // bare literal — never coordinates
  if(s.kind==='country') return 'country:'+s.countryCode;
  const slug = slugOfRegion(s.regionIds[0]);
  return slug ? 'region:'+slug : 'everywhere';
}
// Header label from the region registry (CCScope.label()), never the rail DOM.
// '' (not null) for everywhere/myArea/unresolvable — callers expect a string.
export function scopeLabel(s){
  return (window.CCScope && window.CCScope.label(s)) || '';
}
// Header/kicker/search-title for the active scope (scope-header.js paint).
function writeScopeHeader(){
  if(window.CCScopeHeader) window.CCScopeHeader.paint(I18N);
}
// Focus the sidebar search box (overflow "More regions" chip).
function focusSearchBox(){
  const el=document.getElementById('search');
  if(el){ el.scrollIntoView({block:'nearest'}); el.focus(); }
}
// Contextual scope chips (docs/specs/map-and-search.md §4.5). innerHTML via
// string-concat + escPend (docs/specs/security-architecture.md §4.2).
export function renderScopeChips(){
  const host=document.getElementById('scopeChips');
  if(!host||!window.CCScope) return;
  if(!window.CCScopeChips){ console.warn('CCScopeChips missing — scope chips not rendered'); return; }
  // Decisions live in scope-chips.js (unit-tested); this is DOM binding only.
  const c=(map&&map.getCenter)?map.getCenter():null;
  const m=window.CCScopeChips.chipModel({
    scope: curScope(),
    isDefault: !!(window.CCScope.isDefault && window.CCScope.isDefault()),
    activeRegions: window.CCScope.regions(),
    registry: CC_REGIONS,
    inferredCountry: window.CCScope.inferHomeCountry(),
    scopeCenter: window.CCScope.scopeCenter(),
    mapCenter: c?[c.lng,c.lat]:null,
    myArea: window.CC_MY_AREA||null,
  }, window.CCScope);
  // Model returns semantic keys; locale lookup stays here.
  const DIRK={n:'compassN',ne:'compassNe',e:'compassE',se:'compassSe',
              s:'compassS',sw:'compassSw',w:'compassW',nw:'compassNw'};
  // Foreign chips show "· NL" in the button text (and thus the compass aria-label).
  const cueText=x=>x.foreign?`${x.label} · ${(x.cc||'').toUpperCase()}`:x.label;
  const cueLabel=x=>escPend(cueText(x));
  const regionBtn=r=>`<button data-scope="region:${escPend(r.slug)}">${cueLabel(r)}</button>`;
  const countryBtn=k=>`<button data-scope="country:${escPend(k.cc)}">${escPend(k.label)}</button>`;
  const moreBtn=()=>`<button type="button" class="cc-scope-more" id="scopeMoreBtn">${escPend(D.scopesMore||'More regions…')}</button>`;
  let html='';
  if(m.mode==='countries'){
    m.countries.forEach(k=>{ html+=countryBtn(k); });
  } else if(m.mode==='compass'){
    html+=`<div class="cc-compass" role="group" aria-label="${escPend(D.compassGroup||'Nearby regions')}">`;
    m.rows.forEach(row=>row.forEach(cell=>{
      if(cell.kind==='empty'){ html+='<div class="cc-compass-cell empty" aria-hidden="true"></div>'; return; }
      if(cell.kind==='center'){
        html+=`<div class="cc-compass-cell cc-compass-center"><button data-scope="region:${escPend(cell.slug)}">${cueLabel(cell)}</button></div>`;
        return;
      }
      // Direction in the aria-label, never by grid position alone.
      const aria=tpl(D.compassLabel||'{dir}: {region}',{dir:D[DIRK[cell.dir]]||cell.dir,region:cueText(cell)});
      html+=`<div class="cc-compass-cell"><button data-scope="region:${escPend(cell.slug)}" aria-label="${escPend(aria)}">${cueLabel(cell)}</button></div>`;
    }));
    html+='</div>';
    m.overflow.forEach(r=>{ html+=regionBtn(r); });
    // More/All-country stay below the grid — neither is a geographic neighbour.
    if(m.more) html+=`<div class="cc-compass-more">${moreBtn()}</div>`;
    html+=`<div class="cc-compass-more">${countryBtn(m.country)}</div>`;
  } else {
    m.chips.forEach(r=>{ html+=regionBtn(r); });
    if(m.more) html+=moreBtn();
    html+=countryBtn(m.country);
  }
  host.innerHTML=html;
  host.classList.toggle('cc-grid', m.mode==='compass');
  // (re)bind the freshly-rendered chips to CCScope, same contract as the static ones.
  host.querySelectorAll('button[data-scope]').forEach(b=>b.onclick=()=>{
    const tok=b.dataset.scope;
    if(tok.startsWith('country:')) window.CCScope.setCountry(tok.slice(8));
    else if(tok.startsWith('region:')) window.CCScope.setRegion(tok.slice(7));
  });
  { const more=document.getElementById('scopeMoreBtn'); if(more) more.onclick=focusSearchBox; }
  // Do not call applyScope here — the cc:scopechange handler already does.
  const tok=scopeToken(curScope());
  host.querySelectorAll('button[data-scope]').forEach(x=>x.classList.toggle('on', x.dataset.scope===tok));
}
// Apply a scope: rail button + header + spotlight + viewport + re-render.
// fit:false on the initial paint — the constructor already opened on the scope bbox.
export function applyScope(s, opts){
  document.querySelectorAll('#regionScope button').forEach(x=>x.classList.toggle('on', x.dataset.scope===scopeToken(s)));
  writeScopeHeader();
  if(s&&s.kind==='myArea'&&s.myArea) setCircleSpotlight(s.myArea.center, s.myArea.radiusKm);
  else if(s&&s.kind==='country') setCountrySpotlight(s.countryCode);
  else setSpotlight(s&&s.kind==='region'&&s.regionIds.length===1 ? slugOfRegion(s.regionIds[0]) : null);
  refilterClusters(); updateConfMarkers();
  updateHeatFilter();
  updateCoverageScopeFilter();
  if(!opts||opts.fit!==false){
    // Re-fetch coverage totals so the legend matches the scoped dots
    // (docs/specs/map-and-search.md §4.5).
    fetchCoverageCounts();
    const bb = window.CCScope && window.CCScope.viewBbox(); if(bb) map.fitBounds([[bb[0],bb[1]],[bb[2],bb[3]]],{padding:24});
    // Everything: refreshBestOf() is the render. Curated: render now, re-render K when best-of lands.
    if(mode()==='curated') render();
    refreshBestOf();
  } else {
    render();
  }
}

export function initScope(){
  if (window.CCScope) {
    window.CCScope.init(CC_REGIONS, _defaultScope);
    writeScopeHeader();
  }
  // Reveal My-area once a source exists; it ships hidden so a rider with none never sees a dead control.
  { const myBtn = document.getElementById('myAreaBtn'); if (myBtn && window.CCScope && window.CCScope.myAreaAvailable()) myBtn.hidden = false; }
}

export function initScopeRail(){
  // Static myarea button; region/country chips bind in renderScopeChips().
  // There is no Everywhere button: a country is the widest place to look at.
  document.querySelectorAll('#regionScope > button').forEach(b=>b.onclick=()=>{
    const tok = b.dataset.scope||'';
    if(!window.CCScope) return;
    if(tok==='myarea') window.CCScope.setMyArea();
    else if(tok.startsWith('country:')) window.CCScope.setCountry(tok.slice(8));
    else if(tok.startsWith('region:')) window.CCScope.setRegion(tok.slice(7));
  });
  window.addEventListener('cc:scopechange', e=>{ renderScopeChips(); applyScope(e.detail, {fit:true}); });
}

// Pan-away nudge (docs/specs/map-and-search.md §4.5). One chip, two arms;
// never auto-widens. Dismissal lasts until the scope changes.
export function initAreaNudge(){
  if(!window.CCScope) return;
  let nudge=null, dismissed=false, onGo=null;
  const build=()=>{
    nudge=document.createElement('div'); nudge.id='cc-area-nudge'; nudge.className='cc-area-nudge'; nudge.hidden=true;
    const msg=document.createElement('span'); msg.className='cc-nudge-msg';
    const go=document.createElement('button'); go.type='button'; go.className='cc-nudge-go';
    const x=document.createElement('button'); x.type='button'; x.className='cc-nudge-x'; x.textContent='✕';
    x.setAttribute('aria-label', I18N.areaDismiss||'Dismiss');
    nudge.append(msg,go,x);
    (document.querySelector('.map-wrap')||document.body).appendChild(nudge);
    go.onclick=()=>{ dismissed=true; nudge.hidden=true; if(onGo) onGo(); };
    x.onclick=()=>{ dismissed=true; nudge.hidden=true; };
  };
  const hide=()=>{ if(nudge) nudge.hidden=true; };
  const show=(msg, goLabel, action)=>{
    if(!nudge) build();
    nudge.querySelector('.cc-nudge-msg').textContent = msg;
    nudge.querySelector('.cc-nudge-go').textContent = goLabel;
    onGo = action;
    nudge.hidden=false;
  };
  const showWiden=()=>{
    const nw=window.CCScope.nextWider();
    // Nothing wider than the country: offer the country under the map instead.
    if(!nw){ showCountryUnderMap(); return; }
    show(
      I18N.outsideArea||'Outside your area',
      tpl(I18N.searchWiden||'Search in {area} instead', {area:scopeLabel(nw)}),
      ()=>window.CCScope.widen()
    );
  };
  /* The rider panned into another country: name it and offer it. Everywhere
     is not a place to look at any more (owner, 2026-09-06), so the jump is to
     the country under the map centre, or nothing when that is open sea. */
  const showCountryUnderMap=()=>{
    const s=curScope(), c=map.getCenter();
    const cc=window.CCScope.countryAt(c.lat, c.lng);
    if(!cc || (s && s.kind==='country' && s.countryCode===cc)){ hide(); return; }
    const area=scopeLabel({kind:'country', regionIds:[], countryCode:cc}) || cc;
    show(
      tpl(I18N.scopeMiss||'Only showing {area}', {area:scopeLabel(s)||''}),
      tpl(I18N.scopeMissGo||'Show {area}', {area}),
      ()=>window.CCScope.setCountry(cc)
    );
  };
  /* Named-scope miss: viewport and scope bbox do not intersect. Names the
     filter, not the place (docs/specs/map-and-search.md §4.5). Everywhere cannot
     miss; myArea has its own radius arm. */
  const scopeMiss=()=>{
    const s=curScope();
    if(!s||s.kind==='everywhere'||s.kind==='myArea') return false;
    if(!window.CCScope.bbox()) return false;
    const v=map.getBounds();
    /* CCScope owns the comparison: a scope box may cross the antimeridian and
       read west > east, and the inline test that used to live here read such a
       box as overlapping everything, so this nudge never fired for the United
       States or New Zealand. */
    return !window.CCScope.bboxOverlaps([v.getWest(), v.getSouth(), v.getEast(), v.getNorth()]);
  };
  const evaluate=()=>{
    const s=curScope();
    if(s && s.kind==='myArea' && s.myArea){
      const c=map.getCenter(), ctr=s.myArea.center;   // ctr = [lat, lng]
      const dLat=(c.lat-ctr[0])*111.32;
      const dLng=(c.lng-ctr[1])*111.32*Math.cos(ctr[0]*Math.PI/180);
      const dist=Math.sqrt(dLat*dLat+dLng*dLng);
      if(dist > 1.5*s.myArea.radiusKm){ if(!dismissed) showWiden(); } else hide();
      return;
    }
    if(scopeMiss()){
      if(dismissed) return;
      showCountryUnderMap();
      return;
    }
    hide();
  };
  map.on('moveend', evaluate);
  map.once('idle', evaluate);   // deep link can land outside the saved scope with no move
  window.addEventListener('cc:scopechange', ()=>{ dismissed=false; hide(); });
}

export function initClickToScope(){
    // Click-to-scope (docs/specs/map-and-search.md §4.5): left-click on empty
    // map scopes to the region under the point. Query only feature-layer ids
    // (not the whole style — basemap polygons would always look "non-empty").
    function selectableLayers(){
      const ids=['mly-img','mly-cov','cov-sel-icon'];
      COVERAGE_KEYS.forEach(([key])=>COVERAGE_CCS.forEach(cc=>{
        const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
        ids.push(id);
      }));
      boundLayerIds.forEach(id=>ids.push(id));
      surfaceClsLayerIds().forEach(id=>ids.push(id));
      corrLayerIds().forEach(id=>ids.push(id));
      return ids.filter(id=>map.getLayer(id));
    }
    map.on('click', async e=>{
      if(isPicking()) return;
      if(map.queryRenderedFeatures(e.point, {layers:selectableLayers()}).length) return;
      if(!window.CCScope) return;
      const r=await window.CCScope.regionOfPointPrecise(e.lngLat.lng, e.lngLat.lat);
      if(!r) return;
      // Same-region click is a no-op: don't re-fit / re-fetch / re-render.
      const s=curScope();
      if(s.kind==='region' && s.regionIds[0]===r.id) return;
      window.CCScope.setRegion(r.slug);
    });
}
