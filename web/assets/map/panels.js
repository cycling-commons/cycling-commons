// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Every panel and control around the map: the layer list and its select-all, the
   base Map/Satellite picker and its top-right flyout, the collapsible legend, the
   mobile filters sheet and burger nav, the breakpoint resize, the Curated
   best-of facets, and all the chip groups (discipline, preference prefilter,
   "set my area" prompt, climb/stay filters, heat toggle, season).
   Extracted from map.js by the module split —
   the last extraction, which leaves map.js as imports plus the boot sequence.

   Six inits rather than one, because six calls the entry already made sit
   interleaved through this chrome (initStreetToggle, initSearchUi, initScopeRail,
   initAreaNudge, initMapillaryDock, initPlanner). Splitting on those seams is
   what lets the entry's boot list stay in exact source order (§4.2) — the
   ordering here is load-bearing twice over:
     - initChips() MUST run after initScopeRail()/initAreaNudge(), because its
       tail fires the initial refreshBestOf();
     - inside initChips(), the pref chip and the area prompt MUST bind after the
       generic '.grp .chips .chip' toggle binder, or that assignment clobbers
       their onclick with a toggle-only handler.

   refreshBestOf() is the one export anything else needs: scope-ui.js's
   applyScope() calls it, because a scope change re-ranks best-of. */
import { I18N, D, CC_SEASON_LABEL, CC_BIKE_LABEL } from './i18n.js';
import { txtOn, currentSeason } from './util.js';
import { map } from './map-init.js';
import { CATALOG, catalogUtility, catalogVotable, catalogModeration,
         active, layerByKey, mode, setMode,
         resolveInitialMode, MODE_LS_KEY } from './catalog.js';
import { PREFS, addHeatmap, updateHeatFilter, layerCounts, updateCounts, render,
         applyStaysAccessFilter, syncFacetChips, prefFilterEnabled, setPrefFilter, PREF_FILTER_KEY } from './render.js';
import { mapToast, clearRevealPin } from './drawer.js';
import { scenicGlyph, toiletGlyph } from './icons.js';
import { refilterClusters, updateConfMarkers } from './osm-pools.js';
import { curScope, inScope } from './scope-ui.js';
import { surfaceTilesConfigured, setSurfaceTiles, surfaceTilesVisible,
         toggleSurfaceClass, setStudyMode, studyModeOn,
         setGapsGrid, gapsGridOn } from './surface-tiles.js';
import { routesTilesConfigured, setRoutesTiles, routesTilesVisible } from './routes-tiles.js';

// Bindings a later init reads, so they cannot stay `const` inside the init that
// looks them up: `app` is assigned by initRailChrome(), the two facet <select>s
// by initBestOf(). boSeason/boBike are pure values and initialise here.
let app, boSeasonEl, boBikeEl;
let boSeason=currentSeason(), boBike='all';
let _bestOfReq=0;

export function initLayerList(){
  // The rail lists categories in READING order, grouped (catalog.js): the
  // utilities that aim for full coverage, then the layers riders vote into a
  // best-of. Same split, same vocabulary as the contribute hub, so "what kind
  // of thing is this" has one answer across the site.
  //
  // No letter in the row. The swatch already carries the category's icon and
  // colour, so "B · Climbs" spent a prefix restating what the swatch says —
  // and the letters, being storage identifiers, read as a broken sequence the
  // moment the list is ordered for humans (A, C, M, D…). They stay in the
  // URLs, tiles and API; they are gone from every surface a rider reads.
  const lc=document.getElementById('layers');
  const row=layer=>{
    const el=document.createElement('div');
    el.className='layer'; el.style.setProperty('--c',layer.color); el.style.setProperty('--ic',txtOn(layer.color));
    el.style.setProperty('--ig', txtOn(layer.color)==='#fff' ? 'brightness(0) invert(1)' : 'brightness(0)'); el.dataset.key=layer.key;
    if(!active.has(layer.key)) el.classList.add('off');
    const _lc=layerCounts(layer);
    const ct=`${_lc.shown}/${_lc.total}`;
    // Scenic wears the drawn camera here too: the row's silhouette filter
    // flattens the 📷 emoji to a blank rounded box, exactly as it did on the
    // map pins (owner 2026-08-14).
    const glyph = layer.key==='scenic' ? scenicGlyph(13)
      : layer.key==='toilets' ? toiletGlyph(13) : layer.icon;
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
  function syncLayersAll(){ layersAll.textContent = CATALOG.every(l=>active.has(l.key)) ? (I18N.deselectAll||'deselect all') : (I18N.selectAll||'select all'); }
  layersAll.onclick=()=>{
    const allOn=CATALOG.every(l=>active.has(l.key));
    CATALOG.forEach(l=>{ if(allOn) active.delete(l.key); else active.add(l.key); });
    document.querySelectorAll('#layers .layer').forEach(el=>el.classList.toggle('off', !active.has(el.dataset.key)));
    syncLayersAll(); render();
  };
  syncLayersAll();

  // on-map base/overlay control (top-right) — Map ↔ Satellite + Street-level overlay
  //
  // No satellite layer means no Esri key (map-init.js addSatellite()), and a
  // two-button Map/Satellite control where Satellite does nothing is worse
  // than no control: it reads as broken rather than absent. The whole segment
  // goes, because "Map" alone is not a choice.
  if(!map.getLayer('satellite')){
    // The whole picker goes, not just the segment: with nothing to choose
    // between, its button would open an empty menu.
    const seg=document.getElementById('baseSeg');
    const picker=seg && seg.closest('.trw');
    if(picker) picker.hidden=true; else if(seg) seg.hidden=true;
  }
  // Road-surface reference skin (surface-tiles.js). Off by default and only
  // offered when an artifact exists; SURFACE_TILES_ON is false when
  // CC_SURFACE_URL is absent, which is every region that has not been built.
  const surfBtn=document.getElementById('ovSurface');
  const studyBtn=document.getElementById('skeyStudy');

  /* Study mode strips the basemap to leave the surface lines alone on a pale
     ground. With the surface skin off that is not a study of anything — it is a
     blank page, and a rider who lands there has no way to tell whether the
     feature is broken or the layer is missing. So the control follows the layer:
     disabled while the skin is off, and switched off with it. */
  const gapsBtn=document.getElementById('skeyGaps');
  const syncStudyGate=()=>{
    if(studyBtn){
      const on=surfaceTilesVisible();
      studyBtn.disabled=!on;
      if(!on && studyModeOn()){ setStudyMode(false); studyBtn.setAttribute('aria-pressed','false'); }
    }
    // The gaps toggle exists only while the skin is on (owner 2026-08-14):
    // it is a question about this layer, and a control for an absent layer
    // reads as broken. Hidden rather than disabled — with the skin off there
    // is nothing to explain.
    if(gapsBtn) gapsBtn.hidden=!surfaceTilesVisible();
  };
  if(gapsBtn){
    gapsBtn.onclick=()=>{
      const on=setGapsGrid(!gapsGridOn());
      gapsBtn.setAttribute('aria-pressed', on?'true':'false');
      gapsBtn.classList.toggle('on', on);
    };
  }

  if(surfBtn && surfaceTilesConfigured()){
    surfBtn.hidden=false;
    surfBtn.onclick=()=>{ surfBtn.classList.toggle('on', setSurfaceTiles(!surfaceTilesVisible())); syncStudyGate(); syncLegend(); };
  }

  // Cycle-route network (routes-tiles.js). Same rules as the surface skin: off
  // by default (readable-by-default is the brief), offered only when an
  // artifact exists — a control for tiles that were never built reads as
  // broken, not absent.
  const routesBtn=document.getElementById('ovRoutes');
  if(routesBtn && routesTilesConfigured()){
    routesBtn.hidden=false;
    routesBtn.onclick=()=>{
      routesBtn.classList.toggle('on', setRoutesTiles(!routesTilesVisible()));
      syncLegend();
    };
  }

  /* THE LEGEND SHOWS ONLY WHAT IS ON THE MAP (owner 2026-08-16).
     Each key follows the thing it explains, and the panel itself follows the
     keys - six surface colours were being explained beside a map with no
     surfaces on it, which teaches a rider to read the legend as decoration.

     The surface key answers to TWO sources, because it is one key for two
     layers: the tile skin, and the curated Road-surface layer. Either one on
     screen earns it. `shown > 0` rather than merely "the layer is ticked",
     because a region with no surfaces mapped is exactly the case in the
     owner's screenshot: the row read 0/0 and the key still explained six
     colours nobody could point at. */
  const surfaceKey=document.getElementById('surfaceKey'), routesKey=document.getElementById('routesKey');
  const surfaceLayer=layerByKey['surface'];   // a lookup object, not a function
  const surfaceOnMap=()=>surfaceTilesVisible()
    || (!!surfaceLayer && active.has('surface') && layerCounts(surfaceLayer).shown > 0);
  function syncLegend(){
    if(surfaceKey) surfaceKey.hidden=!surfaceOnMap();
    if(routesKey) routesKey.hidden=!routesTilesVisible();
    // Nothing left to explain: the panel goes rather than sitting there as an
    // empty box with a collapse control on it.
    const legendEl=document.querySelector('.legend');
    if(legendEl) legendEl.hidden=!!(surfaceKey?.hidden && routesKey?.hidden);
  }
  // Counts change with scope, mode, best-of and every layer toggle; render.js
  // announces it from the one place they are all recomputed.
  document.addEventListener('cc:counts', syncLegend);
  syncLegend();

  // Legend-as-filter + study mode. Both live on the legend because that is
  // where the classes are named; the class filter works with the tile skin off
  // too, since it also governs our own curated A items.
  document.querySelectorAll('.skey-row[data-surf-cls]').forEach(b=>{
    b.onclick=()=>{ b.setAttribute('aria-pressed', toggleSurfaceClass(b.dataset.surfCls) ? 'true' : 'false'); };
  });
  // syncStudyGate() after the toggle, not before: Study mode hides the basemap,
  // so its own key has to leave with it.
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

/* The base-map picker: one icon button in the top-right corner and the small
   flyout it opens. A flyout rather than an always-open segmented control
   because a rider changes base map rarely and looks at the map constantly.

   Closing rules are the ordinary ones a menu owes: a choice closes it, a click
   anywhere else closes it, Escape closes it. The buttons inside are the SAME
   #baseSeg buttons initLayerList() already binds - this only owns the opening
   and closing. */
export function initMapCtrl(){
  const btn=document.getElementById('tr-base'), fly=document.getElementById('fly-base');
  if(!btn || !fly) return;
  const setOpen=o=>{ fly.classList.toggle('open', o); btn.setAttribute('aria-expanded', o?'true':'false'); };
  btn.onclick=e=>{ e.stopPropagation(); setOpen(!fly.classList.contains('open')); };
  fly.querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>setOpen(false)));
  document.addEventListener('click', e=>{ if(!e.target.closest('.trw')) setOpen(false); });
  document.addEventListener('keydown', e=>{ if(e.key==='Escape') setOpen(false); });
}

export function initRailChrome(){
  // Data-version readout (owner 2026-08-13): which artifact builds THIS page
  // is actually drawing — the catalog's ?v= tag plus the build stamp parsed
  // from each loaded artifact URL. When a rider says "I don't see X", this
  // line splits stale-cache from server in one glance; 'FAILED' on the
  // catalog explains an empty map by itself.
  const dv=document.getElementById('dataVersions');
  if(dv){
    const stamp=(u,re)=>{ const m=String(u||'').match(re); return m?m[1]:'—'; };
    const state=window.CC_CATALOG_STATE||'…';
    const cat=state==='ok' ? stamp(window.CC_CATALOG_URL,/v=([0-9a-f]+)/) : state;
    // Rendered as a FUNCTION and re-rendered on scope changes: the first
    // real report this line answered (owner 2026-08-13) had two browsers on
    // IDENTICAL builds — the difference was a stale region scope in one of
    // them hiding every feature (a scoped rail legitimately reads 0/0). The
    // client state is as load-bearing as the data versions, so it rides the
    // same line.
    const renderVersions=()=>{
      dv.innerHTML='';
      const sc=curScope();
      [['catalog',cat,state!=='ok'],
       ['surface',stamp(window.CC_SURFACE_URL,/\/(\d{8}-\d{4})\//),false],
       ['routes',stamp(window.CC_ROUTES_URL,/\/(\d{8}-\d{4})\//),false],
       ['coverage',stamp(window.CC_COVERAGE_URL,/\/(\d{8}-\d{4})\.pmtiles/),false],
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
    // Once more after the boot sequence settles: initRailChrome runs before
    // the view mode is resolved (initBestOf), and the line must show the mode
    // the map actually opened in, not the pre-resolution default.
    setTimeout(renderVersions,0);
    document.addEventListener('cc:scopechange',renderVersions);
    // Mode buttons re-render it too (setMode has no event of its own).
    document.querySelectorAll('#mode button').forEach(b=>b.addEventListener('click',()=>setTimeout(renderVersions,0)));
  }

  // mobile: filters bottom-sheet — the rail-foot peek toggles it
  app=document.querySelector('.app');

  const sheetHandle=document.querySelector('.rail-foot .res');
  const railFoot=document.querySelector('.rail-foot');
  if(app && sheetHandle){
    let _sheetSwiped=false;
    sheetHandle.addEventListener('click',()=>{ if(_sheetSwiped) return; if(window.innerWidth<=820) app.classList.toggle('sheet-open'); });
    const exp=document.querySelector('.rail-foot .export');
    if(exp) exp.addEventListener('click',e=>e.stopPropagation());   // export ≠ sheet toggle
    map.on('dragstart',()=>app.classList.remove('sheet-open'));      // collapse when panning
    // mobile: swipe the filters handle down to close it (or up to open it) — the sheet has no ✕, only this bar
    let fy=0, fActive=false, fMoved=0, fOpen=false;
    railFoot.addEventListener('touchstart', e=>{
      if(window.innerWidth>820 || e.touches.length!==1 || e.target.closest('.export')) return;
      fActive=true; fy=e.touches[0].clientY; fMoved=0; fOpen=app.classList.contains('sheet-open');
    }, {passive:true});
    railFoot.addEventListener('touchmove', e=>{
      if(!fActive) return; fMoved=e.touches[0].clientY-fy;
      if((fOpen && fMoved>0) || (!fOpen && fMoved<0)) e.preventDefault();   // own a meaningful vertical swipe
    }, {passive:false});
    railFoot.addEventListener('touchend', ()=>{
      if(!fActive) return; fActive=false;
      if(fOpen && fMoved>45) app.classList.remove('sheet-open');            // drag down → close
      else if(!fOpen && fMoved<-45) app.classList.add('sheet-open');        // drag up → open
      else return;
      _sheetSwiped=true; setTimeout(()=>{ _sheetSwiped=false; }, 450);      // suppress the swipe's synthesized click
    });
  }

  /* The legend collapses on EVERY screen (owner 2026-08-16), not only on
     phones: a rider who has learnt the colours wants the corner of the map
     back. One class, `collapsed`, hides #lgBody wherever it is applied - which
     also means a key section added later cannot be forgotten out of a hide
     list, the way the routes key just was.

     The starting state differs by room, not by preference: a phone opens
     collapsed because the panel would cover the map it explains, a laptop
     opens expanded because there is space and the key is the point. Read once
     at init; resizing does not re-decide, because after the first tap the
     state belongs to the reader. */
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
  const bike=boBike==='all' ? (I18N.allBikes||'All bikes') : (CC_BIKE_LABEL[boBike]||boBike);
  sub.textContent=`${I18N.curated||'Best of'} · ${CC_SEASON_LABEL[boSeason]} · ${bike}`;
}

function applyBestOf(ids){
  // Membership only: the endpoint returns ids in rank order (vote count, then
  // recency), but the map surfaces best-of routes as unordered lines — Curated
  // shows the set, Everything shows all. The server ORDER BY is latent until a
  // ranked-list UI consumes it; don't assume order is honoured client-side.
  const set=new Set((ids||[]).map(Number));
  const feats=(layerByKey['experience']||{}).features||[];
  feats.forEach(f=>{ f.cur = set.has(Number(f.id)); });
  render();
}

export function refreshBestOf(){
  const req=++_bestOfReq;
  if(mode()!=='curated'){ render(); return; }
  // A named-region scope sends &region=<id>; My area sends its derived rid SET
  // as a CSV (&region=1,24) so best-of ranks across the whole home-base area
  // (MapController parses the CSV; map-and-search.md §4.5 Phase 4).
  // Country/Everywhere send no region — the guarded unbounded aggregate. A
  // single named region keeps the identical single-id request as before.
  const regionIds = window.CCScope && window.CCScope.bestOfRegionIds();
  const regionQ = (regionIds && regionIds.length) ? `&region=${encodeURIComponent(regionIds.join(','))}` : '';
  fetch(`/map/best-of?season=${encodeURIComponent(boSeason)}&bike=${encodeURIComponent(boBike)}${regionQ}`,
    {credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
    .then(d=>{ if(req===_bestOfReq) applyBestOf(d.ids); })
    .catch(()=>{ if(req===_bestOfReq) applyBestOf([]); });   // on failure, Curated shows no picks rather than a stale set
}

// Record a manual Curated/Everything choice.
// A logged-in rider's choice
// goes to their PROFILE — window.CC_MAP_MODE only exists in the riders-only
// script block — so it follows them across devices and a shared computer never
// hands it to the next person. Anonymous visitors have no profile to hang it
// on, so theirs stays on the device. Fire-and-forget: a failed save must never
// block the map, and the mode is already applied locally.
function persistMode(m){
  const cfg = window.CC_MAP_MODE;
  if(cfg && cfg.url){
    const body = new URLSearchParams({mode: m, _token: cfg.token || ''});
    fetch(cfg.url, {method:'POST', credentials:'same-origin', body}).catch(()=>{});
    return;
  }
  try { localStorage.setItem(MODE_LS_KEY, m); } catch(e){ /* private mode */ }
}

// Resolve which mode the map OPENS in and paint the toggle to match.
// Runs after initScope(), so
// CCScope.get() is the resolved active scope — the region's curated_default is
// the third rung of the precedence and cannot be read before then. The template
// marks Everything active, which is the global default and so the common case;
// this only repaints when the precedence says otherwise.
export function initViewMode(){
  const m = resolveInitialMode(
    PREFS,
    window.CCScope ? window.CCScope.get() : null,
    window.CC_REGIONS || [],
  );
  setMode(m);
  document.querySelectorAll('#mode button').forEach(b=>b.classList.toggle('on', b.dataset.m===m));
  const bf=document.getElementById('bestFacets'); if(bf) bf.hidden = (m!=='curated');
}

export function initBestOf(){
  // Route domain phase 4 (spec §8): Curated mode = best-of for a (season, bike)
  // facet, fetched from /map/best-of; the returned ids get cur:true and Curated
  // filters K routes to them. A named-region scope now sends &region= too
  // (map-and-search.md §4.5 Phase 2).



  // Race-guard token, same pattern as the drawer's _historyReq (review W5):
  // switching Season/Bike quickly must never let a slower earlier response
  // overwrite the newer facet's membership under a subtitle that says otherwise.

  // Facet pickers (Curated only).
  boSeasonEl=document.getElementById('boSeason'); boBikeEl=document.getElementById('boBike');
  if(boSeasonEl){ boSeasonEl.value=boSeason; boSeasonEl.onchange=()=>{ boSeason=boSeasonEl.value; updateSubtitle(); refreshBestOf(); }; }
  if(boBikeEl){ boBikeEl.onchange=()=>{ boBike=boBikeEl.value; updateSubtitle(); refreshBestOf(); }; }

  // mode toggle
  document.querySelectorAll('#mode button').forEach(b=>b.onclick=()=>{
    document.querySelectorAll('#mode button').forEach(x=>x.classList.remove('on'));
    b.classList.add('on'); setMode(b.dataset.m);
    persistMode(b.dataset.m);   // profile for a rider, localStorage for a visitor
    /* The curated POOL pins are a clustered source, filtered at setData time —
       so a mode change has to rebuild them, exactly as a scope change does.
       Without this, Confirmed kept drawing the unconfirmed pins it excludes
       (and the rail, which now counts them, would have disagreed with itself). */
    refilterClusters(); updateConfMarkers();
    clearRevealPin();   // decision C: mode change clears any reveal pin
    const bf=document.getElementById('bestFacets'); if(bf) bf.hidden = (mode()!=='curated');
    updateSubtitle();
    refreshBestOf();          // Curated → fetch + filter; Everything → plain render()
  });
}

export function initChips(){
  // Exactly one saved bike → preselect the Curated facet (single-valued
  // select; multi-bike riders keep the neutral 'all').
  if(PREFS.bikes.length===1 && boBikeEl && [...boBikeEl.options].some(o=>o.value===PREFS.bikes[0])){
    boBikeEl.value=PREFS.bikes[0]; boBike=PREFS.bikes[0];
  }

  // Initial best-of for the default facet so Curated isn't empty on load.
  updateSubtitle();
  { const bf=document.getElementById('bestFacets'); if(bf) bf.hidden = (mode()!=='curated'); }
  refreshBestOf();
  // Generic chip toggle. The climb-filter and preference chips below reassign
  // onclick to do real work; this is the baseline every chip gets.
  // (The #disc discipline chips it also used to drive were retired 2026-08-03 —
  // map-and-search.md §4.3. PREFS.styles no longer preselects anything here.)
  document.querySelectorAll('.grp .chips .chip').forEach(c=>c.onclick=()=>c.classList.toggle('on'));
  // Preference prefilter chip: later onclick assignment overrides the generic
  // toggle-only binder above (same pattern as the climb-filter chips below).
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
  // "Set my area" cold-start prompt (map-and-search.md §4.5 Phase 4):
  // the pref-chip pattern — a hidden rail chip revealed only when the rider has
  // NO My-area source yet (no base location, no anon circle) AND hasn't dismissed
  // it. Tapping derives the base from the map centre (Komoot's suggest-home
  // pattern): a logged-in POST to the base-location endpoint, or an anonymous
  // localStorage-only circle. Coordinates are rounded to 2 decimals BEFORE they
  // leave the page (request precision == stored precision — no raw point in the
  // request or in localStorage; owner privacy invariant). MUST run after the
  // generic '.grp .chips .chip' toggle binder above (same as initPrefChip), or
  // that assignment clobbers this chip's onclick with a toggle-only handler.
  (function initAreaPrompt(){
    const row=document.getElementById('areaPromptRow');
    const setChip=document.getElementById('areaPromptSet');
    const xBtn=document.getElementById('areaPromptX');
    if(!row||!setChip||!xBtn||!window.CCScope) return;
    const DISMISS_KEY='cc-area-prompt-dismissed';
    let dismissed=false; try{ dismissed=!!localStorage.getItem(DISMISS_KEY); }catch(e){}
    // A My-area already exists (base location or a saved anon circle) → the prompt
    // is moot; leave it hidden.
    if(window.CCScope.myAreaAvailable()||dismissed) return;
    row.hidden=false;
    const hide=()=>{ row.hidden=true; };
    const revealMyAreaBtn=()=>{ const mb=document.getElementById('myAreaBtn'); if(mb) mb.hidden=false; };
    function setMyAreaFromCentre(){
      const c=map.getCenter();
      const lat=Math.round(c.lat*100)/100, lng=Math.round(c.lng*100)/100;   // round client-side
      if(window.CC_MY_AREA && CC_MY_AREA.url){
        // Logged-in: persist the coarse point server-side (the server rounds again
        // as the invariant holder) and merge back the derived region/country set.
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
        // Anonymous: a localStorage-only circle (setAnonCircle rounds to 2dp on
        // write); NOTHING server-side, no device-location prompt (owner decision).
        window.CCScope.setAnonCircle(c.lat,c.lng,40);
        revealMyAreaBtn(); hide();
        mapToast(I18N.myAreaSetAnon||'My area set on this device');
      }
    }
    setChip.onclick=setMyAreaFromCentre;
    setChip.onkeydown=e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); setMyAreaFromCentre(); } };
    xBtn.onclick=()=>{ try{ localStorage.setItem(DISMISS_KEY,'1'); }catch(e){} hide(); };
  })();
  // climb surface + traffic chips actually filter the climbs layer; C2-T8 adds
  // climb effort + stay accessibility to the same wiring (this assignment runs
  // after the generic '.grp .chips .chip' toggle-only handler above, so it wins).
  document.querySelectorAll('#sqf .chip, #trf .chip, #effortf .chip, #accessf .chip').forEach(c=>c.onclick=()=>{
    c.classList.toggle('on');
    syncFacetChips();   // the four chip facets are render.js's state (setter, not assignment)
    applyStaysAccessFilter();
    render();
  });

  // ride-heatmap toggle + season filter (source built on first On — W43)
  document.querySelectorAll('#heattoggle button').forEach(b=>b.onclick=async()=>{
    document.querySelectorAll('#heattoggle button').forEach(x=>x.classList.remove('on')); b.classList.add('on');
    // addHeatmap ends with updateHeatFilter(), which honours a season chip
    // selected before the layer existed AND the active region scope.
    // Awaited since 2026-08-09: the points are fetched on first use rather
    // than shipped in catalog.json, so the layer does not exist yet when this
    // returns synchronously. The visibility flip has to wait for it, and the
    // button re-read below is deliberate — an impatient rider can have
    // toggled Off again while the fetch was in flight, and the layer must end
    // up matching the button, not the click that started the fetch.
    if(b.dataset.h==='on' && !map.getLayer('rideheat')) await addHeatmap();
    const want=document.querySelector('#heattoggle button.on');
    if(map.getLayer('rideheat')) map.setLayoutProperty('rideheat','visibility', (want&&want.dataset.h==='on')?'visible':'none');
  });
  document.querySelectorAll('#season .chip').forEach(c=>c.onclick=()=>{
    document.querySelectorAll('#season .chip').forEach(x=>x.classList.remove('on')); c.classList.add('on');
    updateHeatFilter();
  });

  // "Nothing curated here yet" — the one line an empty map owes the visitor.
  // A link,
  // never a form: the page owns the form handling, validation and four-locale
  // copy, and the map bundle stays small.
  (function initEmptyScopeInvite(){
    const row = document.getElementById('emptyScopeInvite');
    if (!row) return;
    const s = curScope() || {};
    // Region/country scopes carry countryCode directly. A myArea scope always
    // resolves countryCode to null (scope.js) and instead carries a derived
    // countryCodes[] on s.myArea; window.CC_MY_AREA.countryCodes is the same
    // data for a logged-in rider, kept as a fallback in case scope.myArea is
    // ever absent. Mirror widen()'s "exactly one" rule rather than guess among
    // several candidate countries.
    const myAreaCcs = (s.myArea && s.myArea.countryCodes)
      || (window.CC_MY_AREA && window.CC_MY_AREA.countryCodes) || [];
    const cc = s.countryCode || (myAreaCcs.length === 1 ? myAreaCcs[0] : '');
    // "Curated content exists" must be counted independently of the rider's
    // CURRENT view mode (default is Everything) and must never count
    // coverage/OSM-reference POIs — otherwise a country with only a stray
    // hazard report or an unverified route upload (real content, but not
    // curated) would silently suppress the invite, and the row would flip on
    // and off as the rider merely toggles Curated/Everything. Mirrors
    // featureVisible()'s curated-mode branch (render.js) rather than
    // inventing a new rule: non-experiential layers (C/D/F/G/H, exp:false)
    // show unconditionally even in Curated mode — "full coverage" utility
    // data, per map.curated_hint — so they can never signal curation and are
    // excluded here; only f.cur on an experiential layer (A/B/E/I/J) or a K
    // best-of route counts, same as the rail would count `shown` if mode()
    // were 'curated' (K's f.cur starts false — map.js — and flips once
    // panels.js's own refreshBestOf() resolves, same eventual consistency
    // the rest of the rail already has).
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

/* "Add a climb here": keep the rail's link pointed at what the map is showing.

   The wizard's own map used to open on a hardcoded Wallonia centre wherever
   the rider arrived from, so somebody who had just been looking at the climb
   had to find it a second time (owner 2026-08-14). The link carries the
   centre and zoom instead, and ContributeController::startView() validates
   them before the wizard's map reads them.

   Rewritten on 'move' rather than built once: the rider pans while deciding,
   and a link captured at page load would send them wherever they happened to
   land first. Coordinates are rounded to 5 decimals (about a metre), which is
   far finer than a climb foot needs and keeps the URL readable.

   Only the CAMERA travels. Seeding the foot pin from the centre was the
   tempting version and it is wrong: one point cannot say which end of the
   climb it is, and a pin the rider did not place is a claim they did not
   make. */
export function initAddClimbHere(){
  const a=document.getElementById('addClimbHere');
  if(!a) return;   // anonymous rider: the block is not rendered at all
  const base=a.getAttribute('href').split('?')[0];
  const sync=()=>{
    const c=map.getCenter();
    a.href=`${base}?lat=${c.lat.toFixed(5)}&lng=${c.lng.toFixed(5)}&z=${map.getZoom().toFixed(1)}`;
  };
  map.on('move', sync);
  sync();
}
