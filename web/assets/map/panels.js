// SPDX-License-Identifier: AGPL-3.0-only
/* Map panels and chrome (docs/specs/map-and-search.md §4, §4.1, §4.2, §4.3):
   layer list, base picker, legend, chips, Curated facets.
   initChips() must run after initScopeRail()/initAreaNudge() (it fires the
   initial refreshBestOf()). Pref chip and area prompt must bind after the
   generic '.grp .chips .chip' toggler, or that assignment clobbers them. */
import { I18N, D, tpl, CC_SEASON_LABEL, CC_BIKE_LABEL } from './i18n.js';
import { txtOn, currentSeason } from './util.js';
import { map, satelliteConfigured } from './map-init.js';
import { CATALOG, catalogRows, catalogUtility, catalogVotable, catalogModeration,
         active, layerByKey, mode, setMode,
         resolveInitialMode, MODE_LS_KEY } from './catalog.js';
import { PREFS, addHeatmap, updateHeatFilter, layerCounts, updateCounts, render,
         applyStaysAccessFilter, syncFacetChips, prefFilterEnabled, setPrefFilter, PREF_FILTER_KEY } from './render.js';
import { mapToast, clearRevealPin } from './drawer.js';
import { layerGlyph } from './icons.js';
import { refilterClusters, updateConfMarkers } from './osm-pools.js';
import { curScope, inScope } from './scope-ui.js';
import { modeToShow } from './filters.js';
import { surfaceTilesConfigured, setSurfaceTiles, surfaceTilesVisible,
         toggleSurfaceClass, setStudyMode, studyModeOn,
         setGapsGrid, gapsGridOn, CLASSIFIED_MIN_ZOOM, GAPS_MAX_ZOOM } from './surface-tiles.js';
import { routesTilesConfigured, setRoutesTiles, routesTilesVisible } from './routes-tiles.js';
import { setFilterDot } from './shell.js';
import { newestStamp } from './tile-sources.js';

// `app` is assigned by initRailChrome(), so it cannot stay a const inside it.
// Best-of facets are multi-select chip rows read from the DOM. Empty = wide.
let app;
let _bestOfReq=0;
const facet = id => {
  const el=document.getElementById(id);
  if(!el) return [];
  return [...el.querySelectorAll('.chip.on')].map(c=>c.dataset.v);
};
const boSeasons = () => facet('boSeason');
const boBikes = () => facet('boBike');

export function initLayerList(){
  // Categories in reading order (catalog.js): utilities, then votable.
  // No letter in the row — the swatch already carries icon and colour.
  const lc=document.getElementById('layers');
  const row=layer=>{
    const el=document.createElement('div');
    el.className='layer'; el.style.setProperty('--c',layer.color); el.style.setProperty('--ic',txtOn(layer.color));
    el.style.setProperty('--ig', txtOn(layer.color)==='#fff' ? 'brightness(0) invert(1)' : 'brightness(0)'); el.dataset.key=layer.key;
    if(!active.has(layer.key)) el.classList.add('off');
    const _lc=layerCounts(layer);
    const ct=`${_lc.shown}/${_lc.total}`;
    const glyph = layerGlyph(layer, 13);
    el.innerHTML=`<span class="sw"><i class="sw-g">${glyph}</i></span><span class="nm">${layer.label}</span><span class="ct">${ct}</span>`;
    el.onclick=()=>{ if(active.has(layer.key)){active.delete(layer.key);el.classList.add('off')} else {active.add(layer.key);el.classList.remove('off')} syncLayersAll(); render(); };
    lc.appendChild(el);
  };
  const group=(title,layers)=>{
    if(!layers.length) return;   // moderation section: curators only
    const h=document.createElement('div');
    h.className='lgrp'; h.textContent=title;
    lc.appendChild(h);
    layers.forEach(row);
  };
  group(I18N.groupUtility||'Utilities · full coverage', catalogUtility());
  group(I18N.groupVotable||'Rider picks · voted', catalogVotable());
  group(I18N.groupModeration||'Moderation', catalogModeration());
  // (de)select-all toggle for the data layers
  const layersAll=document.getElementById('layersAll');
  function syncLayersAll(){ layersAll.textContent = catalogRows().every(l=>active.has(l.key)) ? (I18N.deselectAll||'deselect all') : (I18N.selectAll||'select all'); }
  layersAll.onclick=()=>{
    const allOn=catalogRows().every(l=>active.has(l.key));
    catalogRows().forEach(l=>{ if(allOn) active.delete(l.key); else active.add(l.key); });
    document.querySelectorAll('#layers .layer').forEach(el=>el.classList.toggle('off', !active.has(el.dataset.key)));
    syncLayersAll(); render();
  };
  syncLayersAll();

  // Base picker — hide entirely when there is no Esri key (gated on the key,
  // not map.getLayer('satellite'); this runs before the layer is added).
  if(!satelliteConfigured()){
    const seg=document.getElementById('baseSeg');
    const picker=seg && seg.closest('.trw');
    if(picker) picker.hidden=true; else if(seg) seg.hidden=true;
  }
  // Road-surface skin: off by default; only offered when an artifact exists.
  const surfBtn=document.getElementById('ovSurface');
  const studyBtn=document.getElementById('skeyStudy');

  /* Overlay rows share the layer rows' state column (On/Off). */
  const paintOverlay=(btn, on)=>{
    if(!btn) return;
    btn.classList.toggle('on', on);
    btn.setAttribute('aria-pressed', on?'true':'false');
    const ct=btn.querySelector('.ct');
    if(!ct) return;
    /* Surface on but zoomed out past CLASSIFIED_MIN_ZOOM → "Zoom in". */
    const waiting = on && btn.id==='ovSurface' && map.getZoom() < CLASSIFIED_MIN_ZOOM;
    ct.classList.toggle('ct-wait', waiting);
    ct.textContent = waiting ? (I18N.overlayZoomIn||'Zoom in')
      : on ? (I18N.overlayOn||'On') : (I18N.overlayOff||'Off');
  };

  /* Study mode follows the surface layer: disabled (and off) while the skin is off. */
  const gapsBtn=document.getElementById('skeyGaps');
  const syncStudyGate=()=>{
    if(studyBtn){
      const on=surfaceTilesVisible();
      studyBtn.disabled=!on;
      if(!on && studyModeOn()){ setStudyMode(false); studyBtn.setAttribute('aria-pressed','false'); }
    }
    // Gaps toggle exists only while the skin is on, and only up to the zoom
    // the grid draws at: above it the button would switch on nothing.
    if(gapsBtn) gapsBtn.hidden=!surfaceTilesVisible() || map.getZoom() > GAPS_MAX_ZOOM + 1;
  };
  map.on('zoomend', syncStudyGate);
  if(gapsBtn){
    gapsBtn.onclick=()=>{
      const on=setGapsGrid(!gapsGridOn());
      gapsBtn.setAttribute('aria-pressed', on?'true':'false');
      gapsBtn.classList.toggle('on', on);
    };
  }

  if(surfBtn && surfaceTilesConfigured()){
    surfBtn.hidden=false;
    surfBtn.onclick=()=>{
      const on=setSurfaceTiles(!surfaceTilesVisible());
      /* One switch for the whole thing. The OSM skin and the items riders have
         corrected are one answer to one question, and they used to be two
         controls a word apart in two different groups (catalog.js). Our items
         follow the same switch, so the pair can never be half on: the skin
         hides itself wherever we hold an item, and a skin hidden with nothing
         drawn over it is a blank road. */
      if(on) active.add('surface'); else active.delete('surface');
      render();
      paintOverlay(surfBtn, on);
      if(on && map.getZoom() < CLASSIFIED_MIN_ZOOM){
        mapToast(I18N.zoomForSurfaces||'Zoom in to see road surfaces');
      }
      syncStudyGate(); syncLegend();
    };
    /* The switch starts off, so the items do too. */
    if(!surfaceTilesVisible()) active.delete('surface');
    map.on('zoomend', ()=>paintOverlay(surfBtn, surfaceTilesVisible()));
  }

  // Cycle-route network: same rules as the surface skin.
  const routesBtn=document.getElementById('ovRoutes');
  if(routesBtn && routesTilesConfigured()){
    routesBtn.hidden=false;
    routesBtn.onclick=()=>{
      paintOverlay(routesBtn, setRoutesTiles(!routesTilesVisible()));
      syncLegend();
    };
  }

  /* Legend shows only what is on the map. Surface key: tile skin OR curated
     A layer with shown > 0 (ticked-but-empty must not explain six colours). */
  const surfaceKey=document.getElementById('surfaceKey'), routesKey=document.getElementById('routesKey');
  const surfaceLayer=layerByKey['surface'];   // a lookup object, not a function
  const surfaceOnMap=()=>surfaceTilesVisible()
    || (!!surfaceLayer && active.has('surface') && layerCounts(surfaceLayer).shown > 0);
  function syncLegend(){
    if(surfaceKey) surfaceKey.hidden=!surfaceOnMap();
    if(routesKey) routesKey.hidden=!routesTilesVisible();
    const legendEl=document.querySelector('.legend');
    if(legendEl) legendEl.hidden=!!(surfaceKey?.hidden && routesKey?.hidden);
  }
  /* Filters show only what there is to filter. Gated on total, never shown
     (shown already has the chips applied — filtering to nothing must not hide the chips). */
  function syncFilterGroups(){
    document.querySelectorAll('#filters .fsub[data-layer]').forEach(g=>{
      const lyr=layerByKey[g.dataset.layer];
      g.hidden = !(lyr && active.has(lyr.key) && layerCounts(lyr).total > 0);
    });
  }
  document.addEventListener('cc:counts', ()=>{ syncLegend(); syncFilterGroups(); });
  syncLegend(); syncFilterGroups();

  // Legend-as-filter + study mode.
  document.querySelectorAll('.skey-row[data-surf-cls]').forEach(b=>{
    b.onclick=()=>{ b.setAttribute('aria-pressed', toggleSurfaceClass(b.dataset.surfCls) ? 'true' : 'false'); };
  });
  // syncStudyGate after the toggle: Study hides the basemap, so its key leaves with it.
  if(studyBtn) studyBtn.onclick=()=>{ studyBtn.setAttribute('aria-pressed', setStudyMode(!studyModeOn()) ? 'true' : 'false'); syncStudyGate(); };
  syncStudyGate();

  document.querySelectorAll('#baseSeg button').forEach(b=>b.onclick=()=>{
    document.querySelectorAll('#baseSeg button').forEach(x=>x.classList.remove('on'));
    b.classList.add('on');
    const sat=b.dataset.b==='satellite';
    if(map.getLayer('satellite')) map.setLayoutProperty('satellite','visibility', sat?'visible':'none');
    document.querySelector('.map-wrap').classList.toggle('sat', sat);
    syncStudyGate();   // satellite replaces liberty's palette, so its key goes too
  });
}

/* Base-map picker flyout. Same #baseSeg buttons initLayerList() already binds. */
export function initMapCtrl(){
  const btn=document.getElementById('tr-base'), fly=document.getElementById('fly-base');
  if(!btn || !fly) return;
  const setOpen=o=>{ fly.classList.toggle('open', o); btn.setAttribute('aria-expanded', o?'true':'false'); };
  btn.onclick=e=>{ e.stopPropagation(); setOpen(!fly.classList.contains('open')); };
  fly.querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>setOpen(false)));
  document.addEventListener('click', e=>{ if(!e.target.closest('.trw')) setOpen(false); });
  document.addEventListener('keydown', e=>{ if(e.key==='Escape') setOpen(false); });
}

/* Filter pill on the map (docs/specs/map-and-search.md §4.3): tally from
   render.js hiddenByFilters. Coverage-tile-only narrowing names no figure. */
export function initFilterPill(){
  const pill=document.getElementById('fpill');
  const msg=document.getElementById('fpillMsg');
  const reset=document.getElementById('fpillReset');
  if(!pill || !msg || !reset) return;

  document.addEventListener('cc:filters', e=>{
    const {filters, hidden}=e.detail || {filters:0, hidden:0};
    setFilterDot(filters>0);
    pill.hidden = filters<1;
    if(filters<1) return;
    msg.textContent = hidden===0 ? (I18N.filtersNarrowing || 'Filters are narrowing this map')
      : hidden===1 ? (I18N.filtersHideOne || 'Filters are hiding 1 place')
      : tpl(I18N.filtersHide || 'Filters are hiding {x} places', {x:hidden});
  });

  /* Show all: every narrowing group back to its widest (f-match vs f-optin). */
  reset.onclick=()=>{
    document.querySelectorAll('#filters .f-match .chip').forEach(c=>c.classList.add('on'));
    document.querySelectorAll('#filters .f-optin .chip').forEach(c=>{
      c.classList.remove('on'); c.setAttribute('aria-pressed','false');
    });
    // Preference state lives in render.js, not the class.
    if(prefFilterEnabled()){
      setPrefFilter(false);
      try{ localStorage.setItem(PREF_FILTER_KEY, 'off'); }catch(e){ /* private mode */ }
    }
    syncFacetChips();
    applyStaysAccessFilter();
    // Best-of facets are f-optin too — the only reset the server must hear.
    updateSubtitle();
    refreshBestOf();
    render();
    updateCounts();
  };
}

export function initRailChrome(){
  // Data-version readout: catalog ?v= plus artifact build stamps.
  const dv=document.getElementById('dataVersions');
  if(dv){
    const stamp=(u,re)=>{ const m=String(u||'').match(re); return m?m[1]:'—'; };
    const NO_STAMP='—';
    const state=window.CC_CATALOG_STATE||'…';
    const cat=state==='ok' ? stamp(window.CC_CATALOG_URL,/v=([0-9a-f]+)/) : state;
    // Re-rendered on scope/mode changes — client state is as load-bearing as versions.
    const renderVersions=()=>{
      dv.innerHTML='';
      const sc=curScope();
      [['catalog',cat,state!=='ok'],
       ['surface',newestStamp('surface')||NO_STAMP,false],
       ['routes',newestStamp('routes')||NO_STAMP,false],
       ['coverage',newestStamp('coverage')||NO_STAMP,false],
       ['scope',(sc&&sc.kind)?(sc.slug||sc.countryCode||sc.kind):'everywhere',false],
       ['mode',mode()||'—',false],
      ].forEach(([k,v,bad],i)=>{
        if(i) dv.appendChild(document.createTextNode(' · '));
        dv.appendChild(document.createTextNode(k+' '));
        const s=document.createElement('span'); if(bad) s.className='dv-bad';
        s.textContent=v; dv.appendChild(s);
      });
    };
    renderVersions();
    setTimeout(renderVersions,0);   // after initViewMode resolves the opening mode
    document.addEventListener('cc:scopechange',renderVersions);
    document.querySelectorAll('#mode button').forEach(b=>b.addEventListener('click',()=>setTimeout(renderVersions,0)));
  }

  app=document.querySelector('.app');

  /* Legend collapses on every screen. Phone opens collapsed; laptop expanded.
     Read once at init — resize does not re-decide. */
  const lgToggle=document.getElementById('lgToggle'), legend=document.querySelector('.legend');
  if(lgToggle && legend){
    const sync=()=>{
      const open=!legend.classList.contains('collapsed');
      lgToggle.setAttribute('aria-expanded', open?'true':'false');
    };
    legend.classList.toggle('collapsed', window.matchMedia('(max-width:760px)').matches);
    sync();
    lgToggle.onclick=()=>{ legend.classList.toggle('collapsed'); sync(); };
  }

  // Site nav + language + account: the ≡ button opens the rail's bottom flyout.
  const railHead=document.getElementById('railHead'), railBurger=document.getElementById('railBurger');
  if(railHead && railBurger){
    railBurger.onclick=e=>{ e.stopPropagation(); const o=railHead.classList.toggle('nav-open'); railBurger.setAttribute('aria-expanded',o?'true':'false'); };
    document.addEventListener('click',e=>{ if(railHead.classList.contains('nav-open') && !railHead.contains(e.target)){ railHead.classList.remove('nav-open'); railBurger.setAttribute('aria-expanded','false'); } });
    document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ railHead.classList.remove('nav-open'); railBurger.setAttribute('aria-expanded','false'); } });
  }

  // the #map box only really changes size at the 820px layout flip → resize then
  const _mq=window.matchMedia('(max-width:820px)');
  const _onBP=()=>requestAnimationFrame(()=>map.resize());
  _mq.addEventListener ? _mq.addEventListener('change',_onBP) : _mq.addListener(_onBP);
}

function updateSubtitle(){
  const sub=document.querySelector('.map-top .sub'); if(!sub) return;
  if(mode()==='all'){ sub.textContent=I18N.subEverything||'Everything · full catalog'; return; }
  // Confirmed is its own rung, not Best of with the season and bike facets.
  if(mode()==='confirmed'){ sub.textContent=I18N.subConfirmed||'Confirmed · places somebody checked'; return; }
  // Empty facet = whole vocabulary.
  const seasons=boSeasons(), bikes=boBikes();
  const sTxt = seasons.length ? seasons.map(v=>CC_SEASON_LABEL[v]||v).join(', ')
    : (I18N.allSeasons||'All seasons');
  const bTxt = bikes.length ? bikes.map(v=>CC_BIKE_LABEL[v]||v).join(', ')
    : (I18N.allBikes||'All bikes');
  sub.textContent=`${I18N.curated||'Best of'} · ${sTxt} · ${bTxt}`;
}

function applyBestOf(ids){
  // Membership only — don't assume server ORDER BY is honoured client-side.
  const set=new Set((ids||[]).map(Number));
  const feats=(layerByKey['experience']||{}).features||[];
  feats.forEach(f=>{ f.cur = set.has(Number(f.id)); });
  render();
}

export function refreshBestOf(){
  const req=++_bestOfReq;
  if(mode()!=='curated'){ render(); return; }
  // Named region: &region=<id>. My area: derived rid CSV (docs/specs/map-and-search.md §4.5).
  // Country/Everywhere: no region. Empty facet CSV = narrow by nothing.
  const regionIds = window.CCScope && window.CCScope.bestOfRegionIds();
  const regionQ = (regionIds && regionIds.length) ? `&region=${encodeURIComponent(regionIds.join(','))}` : '';
  fetch(`/map/best-of?season=${encodeURIComponent(boSeasons().join(','))}&bike=${encodeURIComponent(boBikes().join(','))}${regionQ}`,
    {credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
    .then(d=>{ if(req===_bestOfReq) applyBestOf(d.ids); })
    .catch(()=>{ if(req===_bestOfReq) applyBestOf([]); });   // on failure, Curated shows no picks rather than a stale set
}

// Persist Curated/Everything: profile for a rider (window.CC_MAP_MODE),
// localStorage for anonymous. Fire-and-forget.
function persistMode(m){
  const cfg = window.CC_MAP_MODE;
  if(cfg && cfg.url){
    const body = new URLSearchParams({mode: m, _token: cfg.token || ''});
    fetch(cfg.url, {method:'POST', credentials:'same-origin', body}).catch(()=>{});
    return;
  }
  try { localStorage.setItem(MODE_LS_KEY, m); } catch(e){ /* private mode */ }
}

// Opening view mode (docs/specs/map-and-search.md §4.2). Must run after
// initScope() — the region's curated_default is the third rung of precedence.
export function initViewMode(){
  const m = resolveInitialMode(
    PREFS,
    window.CCScope ? window.CCScope.get() : null,
    window.CC_REGIONS || [],
  );
  setMode(m);
  document.querySelectorAll('#mode button').forEach(b=>b.classList.toggle('on', b.dataset.m===m));
  /* The hint describes the mode that is ON, not all three at once. Strings ride
     on the element as data-curated / data-confirmed / data-all, so this needs
     no client i18n plumbing of its own. */
  const hint=document.getElementById('modeHint');
  if(hint && hint.dataset[m]) hint.textContent = hint.dataset[m];
  const bf=document.getElementById('bestFacets'); if(bf) bf.hidden = (m!=='curated');
}

export function initBestOf(){
  // Curated = best-of for (season, bike); named-region sends &region=
  // (docs/specs/map-and-search.md §4.2, §4.5).
  // Race-guard: a slower earlier response must not overwrite a newer facet.
  document.querySelectorAll('#mode button').forEach(b=>b.onclick=()=>applyMode(b.dataset.m, {persist:true}));
}

/* Switch the view mode: buttons, subtitle, facets, clusters, best-of fetch.
   A click persists (profile or localStorage); a deep-link lift does not, so
   the rider's own choice is what the next visit opens with. */
export function applyMode(m, {persist}={persist:true}){
  document.querySelectorAll('#mode button').forEach(x=>x.classList.toggle('on', x.dataset.m===m));
  setMode(m);
  if(persist) persistMode(m);
  /* Curated pool pins are a clustered source, filtered at setData — rebuild on mode change. */
  refilterClusters(); updateConfMarkers();
  clearRevealPin();
  const bf=document.getElementById('bestFacets'); if(bf) bf.hidden = (mode()!=='curated');
  updateSubtitle();
  refreshBestOf();
}

/* docs/specs/map-and-search.md §8: a deep link lands in the rider's own mode,
   and Best of hides a Verified climb: the drawer opened over a halo with no pin
   and no line under it, and the fresh approval looked "gone" (owner-reported
   2026-08-25). Lift to the lowest rung that draws the target, this visit only,
   and say so. `also` adds a second reason to the same toast (a ride row whose
   place the filter chips hide too, §9). Returns true when the mode changed. */
export function liftModeFor(layer, f, {also}={}){
  if(!layer || !f || layer.pendingLayer) return false;
  const to = modeToShow(mode(), layer, f);
  if(!to) return false;
  const label = m => { const b=document.querySelector(`#mode button[data-m="${m}"]`); return b ? b.textContent.trim() : m; };
  const from = label(mode());
  applyMode(to, {persist:false});
  mapToast(tpl(D.toastModeLift||'Shown in {to} · {from} hides this place', {to: label(to), from}) + (also ? ' · '+also : ''), {center:true});
  return true;
}

export function initChips(){
  /* Opening best-of facets: season = today; bike = every bike on the profile
     (empty = server ranks all bikes). */
  (function openFacets(){
    const season=document.getElementById('boSeason');
    if(season){
      const now=currentSeason();
      season.querySelectorAll('.chip').forEach(c=>c.classList.toggle('on', c.dataset.v===now));
    }
    const bike=document.getElementById('boBike');
    if(bike && PREFS.bikes.length){
      bike.querySelectorAll('.chip').forEach(c=>c.classList.toggle('on', PREFS.bikes.includes(c.dataset.v)));
    }
  })();

  // Initial best-of for the default facet so Curated isn't empty on load.
  updateSubtitle();
  { const bf=document.getElementById('bestFacets'); if(bf) bf.hidden = (mode()!=='curated'); }
  refreshBestOf();
  // Generic chip toggle; climb/pref/area handlers below reassign onclick.
  document.querySelectorAll('.grp .chips .chip').forEach(c=>c.onclick=()=>c.classList.toggle('on'));
  // Preference prefilter: later onclick overrides the generic binder above.
  (function initPrefChip(){
    const chip=document.getElementById('prefFilter'), grp=document.getElementById('prefGrp');
    if(!chip||!grp||!PREFS.bikes.length) return;      // anonymous / no prefs → group stays hidden
    grp.hidden=false;
    const sync=()=>{ const on=prefFilterEnabled(); chip.classList.toggle('on', on); chip.setAttribute('aria-pressed', on?'true':'false'); };
    const flip=()=>{ const on=!prefFilterEnabled(); setPrefFilter(on); try{ localStorage.setItem(PREF_FILTER_KEY, on?'on':'off'); }catch(e){} sync(); render(); updateCounts(); };
    chip.onclick=flip;
    chip.onkeydown=e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); flip(); } };
    sync();
  })();
  // "Set my area" cold-start (docs/specs/map-and-search.md §4.5). Round to 2dp
  // before the point leaves the page. Must bind after the generic chip toggler.
  (function initAreaPrompt(){
    const row=document.getElementById('areaPromptRow');
    const setChip=document.getElementById('areaPromptSet');
    const xBtn=document.getElementById('areaPromptX');
    if(!row||!setChip||!xBtn||!window.CCScope) return;
    const DISMISS_KEY='cc-area-prompt-dismissed';
    let dismissed=false; try{ dismissed=!!localStorage.getItem(DISMISS_KEY); }catch(e){}
    if(window.CCScope.myAreaAvailable()||dismissed) return;
    row.hidden=false;
    const hide=()=>{ row.hidden=true; };
    const revealMyAreaBtn=()=>{ const mb=document.getElementById('myAreaBtn'); if(mb) mb.hidden=false; };
    function setMyAreaFromCentre(){
      const c=map.getCenter();
      const lat=Math.round(c.lat*100)/100, lng=Math.round(c.lng*100)/100;   // round client-side
      if(window.CC_MY_AREA && CC_MY_AREA.url){
        // Logged-in: persist the coarse point; server rounds again.
        fetch(CC_MY_AREA.url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CC_MY_AREA.token},body:JSON.stringify({lat,lng})})
          .then(r=>r.ok?r.json():null)
          .then(a=>{
            if(!a) return;                                        // silent degradation — keep the chip
            window.CC_MY_AREA=Object.assign({},window.CC_MY_AREA,a);
            revealMyAreaBtn(); window.CCScope.setMyArea(); hide();
            mapToast(I18N.myAreaSet||'My area saved');
          })
          .catch(()=>{ /* network/CSRF failure — leave the chip so the rider can retry */ });
      } else {
        // Anonymous: localStorage-only circle; nothing server-side, no device-location prompt.
        window.CCScope.setAnonCircle(c.lat,c.lng,40);
        revealMyAreaBtn(); hide();
        mapToast(I18N.myAreaSetAnon||'My area set on this device');
      }
    }
    setChip.onclick=setMyAreaFromCentre;
    setChip.onkeydown=e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); setMyAreaFromCentre(); } };
    xBtn.onclick=()=>{ try{ localStorage.setItem(DISMISS_KEY,'1'); }catch(e){} hide(); };
  })();
  // Climb/stay chips + best-of facets: assigned after the generic toggler, so they win.
  document.querySelectorAll('#boSeason .chip, #boBike .chip').forEach(c=>c.onclick=()=>{
    c.classList.toggle('on');
    updateSubtitle();
    refreshBestOf();
  });

  document.querySelectorAll('#sqf .chip, #trf .chip, #effortf .chip, #accessf .chip').forEach(c=>c.onclick=()=>{
    c.classList.toggle('on');
    syncFacetChips();   // the four chip facets are render.js's state (setter, not assignment)
    applyStaysAccessFilter();
    render();
  });

  // Ride-heatmap: points are fetched on first On — await, then re-read the button.
  document.querySelectorAll('#heattoggle button').forEach(b=>b.onclick=async()=>{
    document.querySelectorAll('#heattoggle button').forEach(x=>x.classList.remove('on')); b.classList.add('on');
    if(b.dataset.h==='on' && !map.getLayer('rideheat')) await addHeatmap();
    const want=document.querySelector('#heattoggle button.on');
    if(map.getLayer('rideheat')) map.setLayoutProperty('rideheat','visibility', (want&&want.dataset.h==='on')?'visible':'none');
  });
  document.querySelectorAll('#season .chip').forEach(c=>c.onclick=()=>{
    document.querySelectorAll('#season .chip').forEach(x=>x.classList.remove('on')); c.classList.add('on');
    updateHeatFilter();
  });

  // Empty-scope invite: a link, never a form.
  (function initEmptyScopeInvite(){
    const row = document.getElementById('emptyScopeInvite');
    if (!row) return;
    const s = curScope() || {};
    // myArea countryCode is null; use the single derived country when there is exactly one.
    const myAreaCcs = (s.myArea && s.myArea.countryCodes)
      || (window.CC_MY_AREA && window.CC_MY_AREA.countryCodes) || [];
    const cc = s.countryCode || (myAreaCcs.length === 1 ? myAreaCcs[0] : '');
    // Curated count independent of view mode; utility/OSM layers never count.
    const curatedCount = CATALOG.reduce((n, l) => {
      if (l.key !== 'experience' && !l.exp) return n;   // utility layers never count as curated
      return n + l.features.filter(f => f.cur && inScope(f.rid)).length;
    }, 0);
    if (curatedCount > 0 || !cc) { row.hidden = true; return; }
    const a = row.querySelector('a');
    if (a) a.href = '/join/' + encodeURIComponent(cc);
    row.hidden = false;
  })();
}

/* "Add a climb here" (docs/specs/map-and-search.md §8.1): live camera only,
   rewritten on move. Do not seed the foot pin from the centre. */
export function initAddClimbHere(){
  const a=document.getElementById('addClimbHere');
  if(!a) return;   // anonymous rider: the block is not rendered at all
  // The target is /improve?type=climbs&mode=add since 2026-08-25: keep its own query, add the camera.
  const u=new URL(a.getAttribute('href'), location.origin);
  const sync=()=>{
    const c=map.getCenter();
    u.searchParams.set('lat', c.lat.toFixed(5)); u.searchParams.set('lng', c.lng.toFixed(5)); u.searchParams.set('z', map.getZoom().toFixed(1));
    a.href=u.pathname+u.search;
  };
  map.on('move', sync);
  sync();
}
