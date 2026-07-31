// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Region scope, as the map sees it: the registry-backed scope model
   (curScope/inScope/scopeToken/scopeLabel), the contextual chip rail, the
   dynamic header, applyScope — the ONE visual update path a scope change takes
   — and the pan-away widen prompt.
   Extracted from map.js by the module split.

   The scope STATE itself is not here: it lives in window.CCScope (scope.js, a
   classic script loaded before this module graph) and every decision the chips
   make lives in scope-chips.js, both unit-tested under web/tests/js/. What this
   module owns is the map-side reading of that state and its DOM.

   applyScope() fans out to a repaint in almost every other map subsystem. Every
   one of those callees is a plain import now that panels.js has landed, so
   initScope() takes no deps — it still exists because it has a side effect of its
   own (CCScope.init + the header paint + revealing the My-area button). The
   cycles this creates are safe by §4.1 — nothing here is called at
   module-evaluation time. */
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


// Region scope (map-and-search.md §4.5 Phase 2): the area the map +
// search filter to, owned by window.CCScope (scope.js). Registry injected by
// the shell (window.CC_REGIONS: id/slug/cc/bbox); display labels are the rail
// buttons' own text. Default scope = today's Wallonia behaviour.
const CC_REGIONS = window.CC_REGIONS || [];
const _regionById = new Map(CC_REGIONS.map(r => [r.id, r]));
const slugOfRegion = id => { const r = _regionById.get(id); return r ? r.slug : null; };
const _defaultScope = (() => {
  // My area wins whenever a base location is set (map-and-search.md §4.5 /
  // §9.1 Phase 4, owner decision) — an explicit ?scope= URL still beats it
  // (CCScope.init handles that precedence). myAreaAvailable() reads the source
  // (CC_MY_AREA payload / anon 'cc-my-area' circle) directly, not the registry,
  // so it's safe to probe before init() and resolves to the same source there.
  if (window.CCScope && window.CCScope.myAreaAvailable()) return {kind: 'myArea', regionIds: [], countryCode: null};
  const w = CC_REGIONS.find(r => r.slug === 'wallonia') || CC_REGIONS[0];
  return w ? {kind: 'region', regionIds: [w.id], countryCode: w.countryCode} : {kind: 'everywhere', regionIds: [], countryCode: null};
})();

export const curScope = () => window.CCScope ? window.CCScope.get() : _defaultScope;
// A served feature is in scope when its region id (rid) is in the active
// scope. A region/country scope hides rid-less or out-of-region features
// (leak-safe default for authoritative served data); Everywhere shows all.
// Coverage TILES scope through covScopeFilter()/covScopeQuery() instead
// (Phase 3) — with the inverse prop-less rule, since the tile artifact lags.
export const inScope = rid => { const s = curScope(); return !s || s.kind === 'everywhere' || s.regionIds.indexOf(rid) !== -1; };

// The data-scope token a scope maps to (matches the rail buttons' data-scope).
function scopeToken(s){
  if(!s||s.kind==='everywhere') return 'everywhere';
  if(s.kind==='myArea') return 'myarea';   // bare literal — matches #myAreaBtn, never coordinates
  if(s.kind==='country') return 'country:'+s.countryCode;
  const slug = slugOfRegion(s.regionIds[0]);
  return slug ? 'region:'+slug : 'everywhere';
}
// Dynamic header label — resolved from the region REGISTRY (CCScope.label(),
// scope.js), never the rendered rail button (2026-07-23 flash fix: the old
// `document.querySelector('#regionScope button[data-scope=...]')` only ever
// found a match once renderScopeChips() had run — long after first paint —
// which is why every visitor briefly saw the server-rendered "Wallonia"
// fallback; it also meant a stale header stuck around if the chips
// re-rendered after applyScope). '' (not null) for everywhere/myArea/
// unresolvable, same contract as before: callers such as tpl()'s {area}
// interpolation and the header-write guard below expect a string.
export function scopeLabel(s){
  return (window.CCScope && window.CCScope.label(s)) || '';
}
// Header/kicker/search-title rewrite for the active scope (2026-07-23 flash
// fix): extracted out of applyScope() so it can run from TWO places — once
// immediately after CCScope.init() below, and again from applyScope() on
// every later scope change. The actual paint is scope-header.js's
// window.CCScopeHeader.paint() (one implementation, not a copy here):
// map.js's own execution is gated behind the /map/catalog.json fetch
// (catalog-load.js) — a real network round trip — so even code at the very
// top of THIS script only runs once that resolves, well after the browser
// has already painted the server-rendered "Wallonia, Belgium" fallback
// (browser-verified: ~130-220ms on the dev stack). scope-header.js loads as
// a plain blocking <script> right after scope.js and BEFORE
// catalog-load.js's fetch even starts (templates/map/index.html.twig), so
// ITS OWN call to paint() (at the bottom of that file) is what runs the
// true "before first paint" fix; this call here is what keeps the header
// correct on every SUBSEQUENT scope change, once the map/rail exist.
function writeScopeHeader(){
  if(window.CCScopeHeader) window.CCScopeHeader.paint(I18N);
}
// Reveal + focus the sidebar feature-search box (owner fix 2's overflow
// chip, 2026-07-23): re-queries the DOM fresh rather than closing over the
// `sBox` const declared far below, next to the `sRes` search-results wiring
// — renderScopeChips() first runs long before that line executes, so
// capturing `sBox` here would hit the temporal-dead-zone. Reuses the same
// mobile 'sheet-open' reveal + <=820 breakpoint the filter-sheet handle
// already uses rather than inventing a second show/hide mechanism.
function focusSearchBox(){
  if(window.innerWidth<=820){ const ap=document.querySelector('.app'); if(ap) ap.classList.add('sheet-open'); }
  const el=document.getElementById('search');
  if(el){ el.scrollIntoView({block:'nearest'}); el.focus(); }
}
// Contextual scope chips (map-and-search.md §4.5): the
// home country's regions + its All-<country> rung, or the onboarded country
// rungs as the cold-start fallback. Replaces the flat all-regions wall.
// Reuses escPend (top of file) rather than a third hand-rolled escaper —
// it's the same house idiom the search results list (the `escH` helper next
// to the search-results renderer) and the drawer's pending-submission
// renderer (security-architecture.md §4.2) already use for building
// interactive lists into innerHTML: string-concat + a shared HTML-escaper +
// one delegated/rebind pass, not a third one-off.
export function renderScopeChips(){
  const host=document.getElementById('scopeChips');
  if(!host||!window.CCScope) return;
  if(!window.CCScopeChips){ console.warn('CCScopeChips missing — scope chips not rendered'); return; }
  // Every DECISION below the model call lives in scope-chips.js, where it is unit
  // tested (web/tests/js/scope-chips.test.cjs). What stays here is serialization
  // and DOM binding only — deliberately, so a chip-selection change never again
  // needs a browser to catch.
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
  // Direction word lookup stays here: the model returns semantic keys, never
  // translated strings, so it has no opinion about the active locale.
  const DIRK={n:'compassN',ne:'compassNe',e:'compassE',se:'compassSe',
              s:'compassS',sw:'compassSw',w:'compassW',nw:'compassNw'};
  // Foreign chips (a region of another country than the active scope) show a
  // "· NL" country cue; native chips stay bare. The cue is part of the button
  // TEXT, so it also reaches the compass aria-label below — the country is in the
  // accessible name, not conveyed by styling alone.
  // Raw (unescaped) cue text: "Limburg · NL" for a foreign chip, bare label otherwise.
  // ONE source of the cue format — both the visible button text (via cueLabel, which
  // escapes) and the compass aria-label (escaped once at attribute insertion) use it.
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
      // The direction is spelled out in the aria-label, never conveyed by grid
      // position alone (review requirement).
      const aria=tpl(D.compassLabel||'{dir}: {region}',{dir:D[DIRK[cell.dir]]||cell.dir,region:cueText(cell)});
      html+=`<div class="cc-compass-cell"><button data-scope="region:${escPend(cell.slug)}" aria-label="${escPend(aria)}">${cueLabel(cell)}</button></div>`;
    }));
    html+='</div>';
    m.overflow.forEach(r=>{ html+=regionBtn(r); });
    // More/All-country stay BELOW the grid in plain linear flow — neither is a
    // geographic neighbour, so neither may occupy a compass cell.
    if(m.more) html+=`<div class="cc-compass-more">${moreBtn()}</div>`;
    html+=`<div class="cc-compass-more">${countryBtn(m.country)}</div>`;
  } else {
    m.chips.forEach(r=>{ html+=regionBtn(r); });
    if(m.more) html+=moreBtn();
    html+=countryBtn(m.country);
  }
  host.innerHTML=html;
  // Grid mode gets its own box (a CSS-grid column of the 3x3 grid + the linear
  // More/All-country row below it); the linear list stays `display:contents` so its
  // buttons flex alongside My-area/Everywhere exactly as before (map.css).
  host.classList.toggle('cc-grid', m.mode==='compass');
  // (re)bind the freshly-rendered chips to CCScope, same contract as the static ones.
  host.querySelectorAll('button[data-scope]').forEach(b=>b.onclick=()=>{
    const tok=b.dataset.scope;
    if(tok.startsWith('country:')) window.CCScope.setCountry(tok.slice(8));
    else if(tok.startsWith('region:')) window.CCScope.setRegion(tok.slice(7));
  });
  { const more=document.getElementById('scopeMoreBtn'); if(more) more.onclick=focusSearchBox; }
  // Self-mark the active chip (do NOT call applyScope here — the cc:scopechange
  // handler already calls applyScope; calling it back would recurse/double-work).
  const tok=scopeToken(curScope());
  host.querySelectorAll('button[data-scope]').forEach(x=>x.classList.toggle('on', x.dataset.scope===tok));
}
// Apply a scope: active rail button + dynamic header + spotlight (single named
// region only) + viewport + re-render (scope-filtered from Task 7). fit:false
// on the initial paint — the map constructor already opened on the scope bbox.
export function applyScope(s, opts){
  document.querySelectorAll('#regionScope button').forEach(x=>x.classList.toggle('on', x.dataset.scope===scopeToken(s)));
  writeScopeHeader();   // header/kicker/search-title (`s` IS window.CCScope.get() here — see writeScopeHeader() above)
  if(s&&s.kind==='myArea'&&s.myArea) setCircleSpotlight(s.myArea.center, s.myArea.radiusKm);
  else if(s&&s.kind==='country') setCountrySpotlight(s.countryCode);
  else setSpotlight(s&&s.kind==='region'&&s.regionIds.length===1 ? slugOfRegion(s.regionIds[0]) : null);
  refilterClusters(); updateConfMarkers();   // served-POI clusters follow scope (no-ops until setupConfClusters runs)
  updateHeatFilter();                         // ride-heat follows scope too (no-op until the lazy layer exists)
  updateCoverageScopeFilter();                // coverage tile dots follow scope (Phase 3; no-op until addCoverage runs)
  if(!opts||opts.fit!==false){                // a user scope change, not the initial paint
    // Coverage rail totals become scope-aware (Phase 3, map-and-search.md §4.5
    // §7 counts decision): re-fetch with the new rids/cc so the legend's
    // 'total' side matches the now scope-filtered 'shown' dots. Init doesn't
    // need this call — the standalone fetchCoverageCounts() below runs once
    // the layers exist, already reading the initial scope.
    fetchCoverageCounts();
    const bb = window.CCScope && window.CCScope.bbox(); if(bb) map.fitBounds([[bb[0],bb[1]],[bb[2],bb[3]]],{padding:24});
    // One render per scope switch (review 07-20 info d): in Everything mode
    // refreshBestOf() IS the render (its non-curated branch renders
    // synchronously); in Curated we render now for instant A–J + K feedback
    // while the region-ranked best-of fetch is in flight — applyBestOf
    // re-renders K when it lands.
    if(mode()==='curated') render();
    refreshBestOf();                          // re-fetch best-of with the new &region= (init fetch is the standalone call below)
  } else {
    render();                                 // initial paint — climbs/routes/surface via featureVisible / renderSurfaceLayer
  }
}

// Scope boot: hand over the repaint callbacks, initialise the shared scope
// model and reveal the My-area rail button. Called by the entry at the point
// these statements used to occupy, before initMapControls() (§4.2).
export function initScope(){
  if (window.CCScope) {
    window.CCScope.init(CC_REGIONS, _defaultScope);
    // Re-paint the header here too (2026-07-23 flash fix) — mostly a no-op by
    // the time this runs, since scope-header.js already did the real (early,
    // pre-first-paint) resolve + paint before map.js's script even started
    // downloading (see writeScopeHeader()'s own doc comment above for why
    // THIS call can't be the one that kills the flash: map.js only runs
    // post-catalog-fetch). Kept as a defensive safety net for the case
    // scope-header.js didn't run.
    writeScopeHeader();
  }
  // Reveal the My-area rail button once we know a source exists (Phase 4); it
  // ships hidden so a rider with no base location (and no anon circle) never
  // sees a dead control.
  { const myBtn = document.getElementById('myAreaBtn'); if (myBtn && window.CCScope && window.CCScope.myAreaAvailable()) myBtn.hidden = false; }
}

export function initScopeRail(){
  // Region scope selector (map-and-search.md §4.5 Phase 2): buttons
  // call window.CCScope; its cc:scopechange event drives the single visual
  // update path (applyScope), which also persists to localStorage + URL.
  // Narrowed to the two STATIC buttons (myarea/everywhere) — the region/country
  // chips are JS-rendered now (map-and-search.md §4.5) and
  // bind themselves inside renderScopeChips(); a wildcard #regionScope selector
  // here would double-bind them.
  document.querySelectorAll('#regionScope > button').forEach(b=>b.onclick=()=>{
    const tok = b.dataset.scope||'';
    if(!window.CCScope) return;
    if(tok==='everywhere') window.CCScope.setEverywhere();
    else if(tok==='myarea') window.CCScope.setMyArea();   // Phase 4: resolves from the base-location source
    else if(tok.startsWith('country:')) window.CCScope.setCountry(tok.slice(8));
    else if(tok.startsWith('region:')) window.CCScope.setRegion(tok.slice(7));
  });
  // renderScopeChips()/applyScope() order (2026-07-23 flash fix): no longer
  // coupled — see the load-handler comment above. Kept in this order anyway to
  // avoid unrelated churn.
  window.addEventListener('cc:scopechange', e=>{ renderScopeChips(); applyScope(e.detail, {fit:true}); });
}

// Pan-away widen nudge (map-and-search.md §4.5 "Deep links & far panning"):
// when a My-area scope is active and the map centre drifts past 1.5× the circle
// radius, surface a one-tap widen prompt — NEVER auto-widen, the rider taps. It
// hides again once the centre comes back inside; once dismissed or acted on it
// stays gone for the rest of the page load (no per-moveend nagging).
export function initAreaNudge(){
  if(!window.CCScope) return;
  let nudge=null, dismissed=false;
  const build=()=>{
    nudge=document.createElement('div'); nudge.id='cc-area-nudge'; nudge.className='cc-area-nudge'; nudge.hidden=true;
    const msg=document.createElement('span'); msg.className='cc-nudge-msg';
    const go=document.createElement('button'); go.type='button'; go.className='cc-nudge-go';
    const x=document.createElement('button'); x.type='button'; x.className='cc-nudge-x'; x.textContent='✕';
    x.setAttribute('aria-label', I18N.areaDismiss||'Dismiss');
    nudge.append(msg,go,x);
    (document.querySelector('.map-wrap')||document.body).appendChild(nudge);
    go.onclick=()=>{ dismissed=true; nudge.hidden=true; window.CCScope.widen(); };   // the rider chose to widen
    x.onclick=()=>{ dismissed=true; nudge.hidden=true; };
  };
  const hide=()=>{ if(nudge) nudge.hidden=true; };
  const show=()=>{
    if(!nudge) build();
    nudge.querySelector('.cc-nudge-msg').textContent = I18N.outsideArea||'Outside your area';
    const nw=window.CCScope.nextWider();
    // Reuse the search-widen label so the nudge names the target rung; Everywhere
    // has no rail label to interpolate, so it reads "Search everywhere".
    nudge.querySelector('.cc-nudge-go').textContent = (nw && nw.kind==='everywhere')
      ? (I18N.searchEverywhere||'Search everywhere')
      : tpl(I18N.searchWiden||'Search in {area} instead', {area:scopeLabel(nw)});
    nudge.hidden=false;
  };
  map.on('moveend',()=>{
    const s=curScope();
    if(!s||s.kind!=='myArea'||!s.myArea){ hide(); return; }
    const c=map.getCenter(), ctr=s.myArea.center;   // ctr = [lat, lng]
    // Equirectangular ground distance (km) from the map centre to the home
    // circle centre — plenty accurate at riding scale.
    const dLat=(c.lat-ctr[0])*111.32;
    const dLng=(c.lng-ctr[1])*111.32*Math.cos(ctr[0]*Math.PI/180);
    const dist=Math.sqrt(dLat*dLat+dLng*dLng);
    if(dist > 1.5*s.myArea.radiusKm){ if(!dismissed) show(); }
    else hide();
  });
}

// Click-to-scope, moved here with corrections.js (§9): it reads the correction
// preview layer ids, which had no owner until that module landed. A side
// effect, so the entry calls it where the handler used to sit (§4.2).
export function initClickToScope(){
    // Click-to-scope (map-and-search.md §4.5): a left-click
    // on EMPTY map scopes to the region under the point. Feature clicks (coverage
    // POIs/clusters, CATALOG route/climb/line layers, the A-layer surface
    // classes, curator correction previews, Mapillary) keep their own handlers —
    // this bails if any of THEIR layers has a feature under the point, or a
    // stretch-picking session is active.
    //
    // queryRenderedFeatures(e.point) with no layer filter would also match
    // basemap polygons — a click over land would then always look "non-empty"
    // and this would never fire — so the query is narrowed to the ids those
    // handlers actually bind. That list is DERIVED off the same live
    // registries the handlers themselves use (COVERAGE_KEYS/COVERAGE_CCS,
    // boundLayerIds, surfaceClsLayerIds(), _corrLayers) rather than a second,
    // hand-kept list that could silently drift from the real bindings — if a
    // layer were missing here, clicking that feature would ALSO re-scope the
    // map underneath it. cov-sel-icon/planroute(-case) have no click handler of
    // their own but are real rendered overlays, not "empty map" — included so a
    // click on them doesn't misread as empty either. Filtered to map.getLayer()
    // existence: queryRenderedFeatures throws on an id absent from the current
    // style, and boundLayerIds in particular can carry stale ids across a
    // render() that dropped a previously-bound feature.
    function selectableLayers(){
      const ids=['mly-img','mly-cov','cov-sel-icon','planroute','planroute-case'];
      COVERAGE_KEYS.forEach(([key])=>COVERAGE_CCS.forEach(cc=>{
        const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
        ids.push(id);
      }));
      boundLayerIds.forEach(id=>ids.push(id));         // drawLine/drawClimbLine: route/climb/line CATALOG layers
      surfaceClsLayerIds().forEach(id=>ids.push(id));  // A-layer surface classes
      corrLayerIds().forEach(id=>ids.push(id));           // curator correction-segment previews
      return ids.filter(id=>map.getLayer(id));
    }
    map.on('click', async e=>{
      if(isPicking()) return;                              // stretch-picking owns the click
      if(map.queryRenderedFeatures(e.point, {layers:selectableLayers()}).length) return;  // a feature layer will handle it
      if(!window.CCScope) return;
      // Precise resolution refines an ambiguous click (2+ overlapping region bboxes)
      // against the real polygon; deep inside one region it is synchronous-fast, no
      // fetch.
      const r=await window.CCScope.regionOfPointPrecise(e.lngLat.lng, e.lngLat.lat);
      if(!r) return;
      // Same-region click is a no-op (final review CRITICAL 1): bail before setRegion
      // so an empty-map click inside the ALREADY-active region doesn't re-fit the
      // camera + re-fetch coverage counts + re-render on every stray click.
      const s=curScope();
      if(s.kind==='region' && s.regionIds[0]===r.id) return;
      window.CCScope.setRegion(r.slug);   // cc:scopechange -> applyScope + renderScopeChips
    });
}
