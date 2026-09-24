// SPDX-License-Identifier: AGPL-3.0-only
/* Region scope as the map sees it (docs/specs/map-and-search.md §4.5):
   curScope/inScope/scopeToken/scopeLabel, the chip rail, the header, applyScope
   (the one visual update path), and the pan-away widen prompt.
   State lives in window.CCScope (scope.js); chip decisions in scope-chips.js. */
import { map, fitMapTo } from './map-init.js';
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
import { hitScopeFor } from './hit-scope.js';
import { parkTiledOverlays, unparkTiledOverlays } from './tile-park.js';
import { initNoticeState, evaluateNotice, dismissNotice, isNearOutlines } from './coverage-notice.js';


// Region scope (docs/specs/map-and-search.md §4.5): area the map + search
// filter to, owned by window.CCScope. Registry from window.CC_REGIONS.
const CC_REGIONS = window.CC_REGIONS || [];
const _regionById = new Map(CC_REGIONS.map(r => [r.id, r]));
// Onboarded country codes, upper-case (docs/specs/map-and-search.md §4.5b):
// what a search hit's Photon countrycode is checked against, directly - no
// spatial lookup needed when the hit already names its own country.
const COVERAGE_COUNTRIES = new Set((window.CC_COVERAGE_COUNTRIES || []).map(cc => String(cc).toUpperCase()));
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
    /* keepCamera: the scope moved to reach something the rider asked for, and
       that thing's own framing is the answer (docs/specs/map-and-search.md §8).
       The scope box would otherwise land last and show the whole area instead
       of the place in it. */
    if(!opts || !opts.keepCamera){
      const bb = window.CCScope && window.CCScope.viewBbox(); if(bb) fitMapTo([[bb[0],bb[1]],[bb[2],bb[3]]],{padding:24});
    }
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

/* Busy while a newly picked scope is drawn (docs/specs/map-and-search.md §4.5).
   Named region to Wallonia costs seconds, most of it MapLibre re-filtering and
   re-rasterising, so the map says so instead of looking hung. The line lives in
   the .map-top card over the subtitle row: it hides no further map and moves
   no box. `_drawReq` is the race token: a rider who picks again mid-draw owns
   the map, and the older draw neither applies nor clears the line. */
let _drawReq = 0;
/* The camera belongs to whatever asked for the next scope change
   (docs/specs/map-and-search.md §8): the opener claims it, the scope neither
   fits to its own box nor draws until that framing has landed. One change
   only: cleared the moment cc:scopechange is handled. */
let _keepCamera = false;
export function keepCameraForNextScope(){ _keepCamera = true; }
/* How long the draw waits for the opener's framing to land. Longer than the
   900 ms ease every opener uses, and short enough that an opener which moves
   no camera at all still gets its region drawn promptly. */
const CAMERA_WAIT_MS = 1400;
function setScopeBusy(on, area){
  const el=document.getElementById('scopeBusy');
  if(!el) return;
  const txt=el.querySelector('.cc-scope-busy-txt');
  if(txt) txt.textContent = on ? tpl(I18N.scopeBusy||'Drawing {area}…', {area: area||''}) : '';
  el.hidden = !on;
}
/* A scope change blocks the main thread, so the browser must be given a frame
   to paint the busy line BEFORE that work starts, or the line only ever exists
   inside the freeze. Two frames: the first callback runs before its own paint,
   the second after it. */
function drawScope(s){
  const req = ++_drawReq;
  const keepCamera = _keepCamera; _keepCamera = false;   // read at event time: the flag is about THIS change
  setScopeBusy(true, scopeLabel(s));
  const draw = () => requestAnimationFrame(()=>requestAnimationFrame(()=>{
    if(req!==_drawReq) return;
    renderScopeChips();
    applyScope(s, {fit:true, keepCamera});
    // Drawn = the map has settled on the new scope with its tiles in.
    const done=()=>{ if(req===_drawReq) setScopeBusy(false); };
    map.once('idle', done);
    // A tile source that never settles must not leave the line spinning.
    setTimeout(done, 20000);
  }));
  if(!keepCamera){ draw(); return; }
  /* The opener frames its own hit the moment this returns (liftScopeForHit),
     and that ease must not share the thread with anything. Two things would
     otherwise take it (owner-reported 2026-09-16: "it teleports", then "page
     goes blank and rebuild on the now focussed route"):

     - this draw, which holds the main thread for about a second while the
       ease's clock runs on, so the browser skips the animation entirely. It
       waits for the camera to land instead. An opener that moves no camera at
       all still draws, on the timer.
     - the tiled overlays loading the area being flown into, measured as one
       1432 ms block mid-flight with nothing rendered at all. They park for the
       flight (tile-park.js) and come back with the region.

     What carries the animation is the basemap and the catalogue lines already
     in memory, which is why the rider's second visit to the same region always
     looked right: its data was loaded by then. */
  parkTiledOverlays();
  let started = false;
  const go = () => {
    if(started) return;
    started = true;
    clearTimeout(timer);
    map.off('moveend', go);
    unparkTiledOverlays();
    draw();
  };
  const timer = setTimeout(go, CAMERA_WAIT_MS);
  map.on('moveend', go);
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
  window.addEventListener('cc:scopechange', e=>{ forgetRiderScope(); drawScope(e.detail); });
}

/* A hit's scope lift (docs/specs/map-and-search.md §4.5, §8).

   A search hit, a deep link or a drawer row may name a place the rider's scope
   does not draw. Reaching it moves the scope to the target's own REGION, never
   to its country and never to Everywhere: that is the smallest area that shows
   the place, and it keeps as much of the rider's own scope as reaching it
   allows. The lift is never persisted (`persist:false`, like the ride check's),
   and the rider's scope goes back when the drawer that asked for it closes.
   A scope the rider picks themselves, or one a loaded ride asks for, retires
   the memo: that scope is theirs and nothing may put the old one back. */
let _riderScope = null, _lifting = false;

// Every scopechange that is not one of ours means someone else now owns the scope.
function forgetRiderScope(){ if(!_lifting) _riderScope = null; }

function setTransientScope(next){
  _lifting = true;
  try { window.CCScope.set(next, {persist:false}); }
  finally { _lifting = false; }
}

/**
 * Move the scope to the region of one target so the map draws it, remembering
 * the rider's own scope for restoreHitScope(). `ll` is [lat, lng]; `rid` is the
 * target's region id when the caller knows it (an item index entry, a served
 * feature), which beats reading the region off the point. Answers whether the
 * scope moved. The caller frames the target itself, right after: the camera is
 * held for this change (keepCameraForNextScope).
 */
export function liftScopeForHit(ll, rid){
  const S = window.CCScope;
  if(!S) return false;
  const known = rid != null ? (_regionById.get(rid) || _regionById.get(+rid)) : null;
  const region = known
    || (Array.isArray(ll) && ll.length === 2 ? S.regionOfPoint(+ll[1], +ll[0]) : null);
  if(!region) return false;
  const next = hitScopeFor(S.get(), {id: region.id, countryCode: region.countryCode || null});
  if(!next) return false;
  if(_riderScope == null) _riderScope = S.get();
  keepCameraForNextScope();
  setTransientScope(next);
  return true;
}

/** Put the rider's own scope back after a lift; a no-op when nothing was lifted. */
export function restoreHitScope(){
  const prev = _riderScope;
  _riderScope = null;
  if(prev && window.CCScope) setTransientScope(prev);
}

/* "Not covered yet" banner (docs/specs/map-and-search.md §4.5b): the rider
   panned or searched somewhere Cycling Commons has nothing for. Pure decision
   + state machine in coverage-notice.js; this is the DOM wiring + the
   onboarded-ness lookups the pure module has no way to make itself.

   Shares its top-centre pill with the pan-away nudge below and the two must
   never show together: this banner is evaluated first on every moveend, and
   `_covShown` makes the nudge's own evaluate() stand down for that tick
   (initCoverageNotice() is wired before initAreaNudge() in map.js so this
   runs first within the same event). */
let _covState = initNoticeState(), _covPendingHit = null, _covShown = false;

/** Whether the explicit search hit's own country is onboarded - a direct
 *  lookup against CC_COVERAGE_COUNTRIES, since the hit already names its
 *  country and needs no spatial guess. */
function hitCountryOnboarded(cc){ return !!cc && COVERAGE_COUNTRIES.has(String(cc).toUpperCase()); }

/* Onboarded-country outlines (docs/specs/map-and-search.md §4.5b), fetched
   once and lazily - only the pan branch, when the centre resolves to no
   onboarded country, ever needs the coastal-tolerance test that consults
   them. `null` = not yet requested; an array (possibly empty on a fetch
   failure) = loaded. Until loaded, the pan branch shows nothing rather than
   guess - never a flash the fetch then contradicts. */
let _outlines = null, _outlinesReq = null;
function ensureOutlines(){
  if(_outlinesReq) return _outlinesReq;
  _outlinesReq = fetch('/regions/outlines.json', {headers:{'Accept':'application/json'}})
    .then(r=>r.ok ? r.json() : {features:[]})
    .then(d=>{ _outlines = Array.isArray(d.features) ? d.features : []; })
    .catch(()=>{ _outlines = []; });
  return _outlinesReq;
}

/** Called once, right after a search pick, so the next moveend (the fly
 *  landing) evaluates the banner against that hit's own country rather than
 *  the point under the map centre (map-and-search.md §4.5b, point 1). Only
 *  Photon town hits carry a country; a catalogue row, a coordinate paste or a
 *  scope pick are always already inside an onboarded region. */
export function noteCoverageSearchHit(countryCode, countryName){
  if(!countryCode) return;
  _covPendingHit = {countryCode, countryName: countryName || null};
}

function renderCoverageNotice(d){
  const el=document.getElementById('coverageNotice');
  if(!el) return;
  if(!d.show){ el.hidden=true; return; }
  const msg = d.countryName
    ? tpl(I18N.coverageNoticeCountry||'We have no Cycling Commons data for {country} yet. You see the base map only, without our water taps, road surfaces or routes.', {country:d.countryName})
    : (I18N.coverageNoticeArea||'We have no Cycling Commons data for this area yet. You see the base map only, without our water taps, road surfaces or routes.');
  const goLabel = d.countryName
    ? tpl(I18N.coverageNoticeGoCountry||'Ask us to cover {country}', {country:d.countryName})
    : (I18N.coverageNoticeGoArea||'Ask us to cover this area');
  const msgEl=el.querySelector('.cc-nudge-msg'); if(msgEl) msgEl.textContent=msg;
  const goEl=el.querySelector('.cc-nudge-go');
  if(goEl){
    goEl.textContent=goLabel;
    // join_country (a named country) vs the plain join page ("this area") -
    // both localized paths generated server-side; the template's own
    // placeholder ("CC") is the only part filled in here.
    const countryTpl=goEl.dataset.joinCountry, areaHref=goEl.dataset.joinArea;
    if(d.countryCode && countryTpl) goEl.href=countryTpl.replace('CC', d.countryCode);
    else if(areaHref) goEl.href=areaHref;
  }
  el.hidden=false;
}

function evaluateCoverageNotice(){
  if(!window.CCScope) return;
  const c=map.getCenter(), zoom=map.getZoom();
  const cc=window.CCScope.countryAt(c.lat, c.lng);
  const hit=_covPendingHit; _covPendingHit=null;
  let near=false;
  if(!hit && !cc){
    // The pan branch needs the coastal tolerance: kick off the lazy fetch
    // the first time it is asked for, and say nothing (not even a hidden
    // dismissal-clearing tick) until it resolves.
    if(_outlines===null){
      ensureOutlines().then(evaluateCoverageNotice);
      renderCoverageNotice({show:false});
      _covShown=false;
      return;
    }
    near = isNearOutlines(c.lng, c.lat, _outlines);
  }
  const {decision, state} = evaluateNotice(_covState, {
    zoom,
    onboardedAt: hit ? hitCountryOnboarded(hit.countryCode) : !!cc,
    nearOnboarded: near,
    searchHit: hit,
  });
  _covState = state;
  renderCoverageNotice(decision);
  _covShown = decision.show;
}

export function initCoverageNotice(){
  if(!window.CCScope) return;
  const el=document.getElementById('coverageNotice');
  if(!el) return;
  const x=el.querySelector('.cc-nudge-x');
  if(x) x.onclick=()=>{ _covState=dismissNotice(_covState); el.hidden=true; _covShown=false; };
  map.on('moveend', evaluateCoverageNotice);
  map.once('idle', evaluateCoverageNotice);   // deep link can land outside coverage with no move
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
    // The coverage-notice banner wins the shared pill (map-and-search.md
    // §4.5b): it is evaluated first on this same moveend/idle tick, and a
    // myArea widen offer would otherwise show for a hop into a non-onboarded
    // country the coverage banner is already naming.
    if(_covShown){ hide(); return; }
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
