// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Map entry module. Being split into focused modules under web/assets/map/ —
   see docs/specs/2026-07-26-map-js-module-split-design.md for the target layout
   and the rules (§4.1 cycles, §4.2 side effects belong to the entry).

   Loaded as an ES module: catalog-load.js injects it with type="module" once
   the catalog fetch has populated the CC_* globals this file reads. */
import { I18N, LAYER_L10N, D, tpl, VALUE_TR, trVal, sourceLabel, CC_SEASON_LABEL, CC_BIKE_LABEL } from './i18n.js';
import { escPend, slug, txtOn, currentSeason, wc } from './util.js';
import { map, initMapControls, addSatellite, styleReady, markStyleReady, initCoordPopup } from './map-init.js';
import { initRideCheck } from './ride-check.js';
import { setSpotlight, setCountrySpotlight, setCircleSpotlight } from './spotlight.js';
import { CATALOG, CATALOG_AZ, active, layerByKey, CITIES, cityLink, LETTER_KEY, KEY_LETTER,
         mode, setMode } from './catalog.js';
import { addMapillary, initMapillaryDock, initStreetToggle } from './mapillary.js';
import { mintWaterDrops, SERVICE_GLYPH, miniIcon, clusterEl, coverageIconId } from './icons.js';
import { curScope, inScope, scopeLabel, renderScopeChips, applyScope,
         initScope, initScopeRail, initAreaNudge, initClickToScope } from './scope-ui.js';
import { OSM_BULK, addWaterOsm, addOsmDots, setupConfClusters,
         updateConfMarkers } from './osm-pools.js';
import { itemIndex, idxIds, rebuildItemIndex, dropPendingFromIndex,
         trimEnds } from './item-index.js';
import { sheet, showTip, hideTip, initSheet } from './sheet.js';
import { initLightbox } from './lightbox.js';
import { initPlanner } from './planner.js';
import { PREFS, addHeatmap, updateHeatFilter,
         layerCounts, updateCounts, render, applyStaysAccessFilter, attrMatch,
         chipSet, syncFacetChips, prefFilterEnabled, setPrefFilter,
         staysAccessible } from './render.js';
import { COVERAGE_ON, covIconFilter, updateCoverageScopeFilter,
         covScopeIsZero, covScopeQuery, syncCoverageLayers, addCoverage, openCoverageByRef,
         widenForDeepLink, openCoverageFeatureByName, fetchCoverageCounts, covShownCount,
         coverageTotal } from './coverage.js';
import { schemaRows, mapToast, clearRevealPin, initDrawerChrome } from './drawer.js';
import { initPicking } from './picking.js';
import { openPlace, openCity, resolveLocalFeature, openFeatureByName, openRouteById,
         openPendingById } from './places.js';
import { initCommunity } from './community.js';

  // Scope model + rail + header (scope-ui.js). The repaint callbacks are
  // injected because their owning modules are still inside this entry at
  // this point in the split; each becomes a plain import as its module lands.
  initScope({refreshBestOf});

  initMapControls();



  map.on('load',()=>{ markStyleReady(); addSatellite(); addMapillary(); addWaterOsm(); addCoverage();   // heatmap is lazy (W43)
    OSM_BULK.forEach(([key, data, src])=>addOsmDots(key, data, src));
    // renderScopeChips() must wait until here (not right after CCScope.init near
    // the top of the file): it reads `curScope`, a const declared BELOW that call
    // site in this same top-level script, so calling it any earlier hits the TDZ.
    // (`scopeToken` is a hoisted function declaration and would have been fine —
    // curScope alone forces the deferral.) The 'load' handler already runs the
    // one-time initial applyScope, so it is also the natural first chip render.
    // renderScopeChips()/applyScope() order (2026-07-23 flash fix): no longer
    // coupled. scopeLabel() used to resolve by querying the rail button for the
    // incoming scope's data-scope token, so applyScope() needed the chips already
    // in the DOM or it would skip the header/kicker/search-title rewrites and
    // leave the server-rendered fallback text on screen. scopeLabel() now calls
    // CCScope.label() (registry-based, scope.js), so either function may run
    // first — kept in this order anyway to avoid unrelated churn (the chip-anchor
    // logic, CCScope.scopeCenter(), was deliberately made order-independent
    // already; see its own doc comment in scope.js).
    renderScopeChips(); applyScope(curScope(), {fit:false}); setupConfClusters();
    // Reconcile cluster/leaf markers only when the map SETTLES, never on every render frame:
    // querySourceFeatures() + DOM marker diffing across all clustered layers, run per-frame during a
    // flyTo, is what made zooming/flying stutter. MapLibre repositions the existing markers smoothly on
    // its own mid-animation; we only need to add/remove on moveend (motion stops) and idle (tiles loaded).
    let _confRAF=null;
    const scheduleConfMarkers=()=>{ if(_confRAF) return; _confRAF=requestAnimationFrame(()=>{ _confRAF=null; updateConfMarkers(); }); };
    map.on('moveend', scheduleConfMarkers); map.on('idle', scheduleConfMarkers);
    // Coverage counts fetched once at load (and again on each scope change via
    // applyScope). No moveend/idle refresh: the coverage 'shown' is the
    // scope-aware count (covShownCount), not a viewport-render count, so it
    // never changes on pan/zoom.
    if(COVERAGE_ON) fetchCoverageCounts();
    render();
    // Deep links (?feature/?pending/?route) point at a specific object a narrow
    // scope might filter out (region-scoping-design.md §4): widen to Everywhere
    // so the target always renders. Transient — the saved scope returns on the
    // next plain load; the handlers below flyTo the target. ONLY when the target
    // actually resolves (07-20 review finding 9): a stale or mistyped id must
    // not flip the whole map to Everywhere with nothing to show. This gate
    // covers the SYNCHRONOUS resolvers (local feature / pending / route); a
    // coverage-only ?feature resolves async and now (Phase 3, coverage tiles
    // scope-filtered) widens inside openCoverageFeatureByName on its own hit —
    // the resolvers here are exactly the ones the open calls use, so gate and
    // open can never disagree.
    const _dl = new URLSearchParams(location.search);
    const fp=_dl.get('feature'), pp=_dl.get('pending'), rp=_dl.get('route');
    const _dlHit =
      (fp && !!resolveLocalFeature(fp)) ||
      (pp && !!(layerByKey.pending && (layerByKey.pending.features||[]).some(x=>x.pending && String(x.pending.id)===String(pp)))) ||
      (rp && ((layerByKey['experience']||{}).features||[]).some(x=>String(x.id)===String(rp)));
    if(_dlHit) widenForDeepLink();
    // deep-link: ?feature=<name> opens that item's drawer + zooms in (e.g. from
    // a profile page); coverage POIs stay linkable via one search-endpoint
    // lookup when the local index misses (coverage-provider.md §6)
    if(fp && !openFeatureByName(fp)) openCoverageFeatureByName(fp);
    if(pp) openPendingById(pp);
    // ?route=<id> opens a specific route selected (e.g. from the curator Routes desk)
    if(rp) openRouteById(rp);
  });

  initCoordPopup();

  // curator keyboard: A approve / R reject when a pending drawer is open — plain
  // keys only (never on Ctrl/Cmd/Alt combos, e.g. Ctrl+R reload). Pressing a key
  // ARMS the decision and focuses the note (it no longer submits instantly —
  // the drawer used to close before a note could be typed); Enter inside the
  // note sends the armed decision (Shift+Enter keeps inserting a newline).
  // Mouse clicks on the buttons submit immediately, as before.
  document.addEventListener('keydown', e=>{
    if(e.ctrlKey||e.metaKey||e.altKey) return;
    const t=e.target;
    const box=document.querySelector('#drawer.open .cc-mod'); if(!box) return;
    if(t && (t.tagName==='INPUT'||t.tagName==='TEXTAREA'||t.tagName==='SELECT'||t.isContentEditable)){
      if(e.key==='Enter' && !e.shiftKey && t.classList && t.classList.contains('cc-mod-note')){
        const armed=box.querySelector('.cc-mod-btn.armed');
        if(armed){ e.preventDefault(); armed.click(); }
      }
      return;
    }
    const arm=cls=>{
      const b=box.querySelector('.cc-mod-btn.'+cls); if(!b) return;
      box.querySelectorAll('.cc-mod-btn').forEach(x=>x.classList.toggle('armed', x===b));
      const n=box.querySelector('.cc-mod-note'); if(n) n.focus();
    };
    if(e.key==='a'||e.key==='A'){ e.preventDefault(); arm('approve'); }
    if(e.key==='r'||e.key==='R'){ e.preventDefault(); arm('reject'); }
  });


  // populate K · Recommended routes with every uploaded sample route + its cyclist-experience attributes
  // C1-T4 (W6): CC_CLIMBS' 'source' field is the free-text citation ('OSM roads ·
  // geometry handmade', etc.); srcType is the real ItemSource value. A rider-
  // added/edited climb (user/manual) must not keep an OSM-flavoured citation —
  // swap the cc-d-src line to the plain rider-contributed label for those only.
  if(window.CC_CLIMBS){
    const climbSrc = CC_CLIMBS.map(c => (c.srcType==='user'||c.srcType==='manual')
      ? Object.assign({}, c, {source: sourceLabel(c.srcType)}) : c);
    layerByKey['climbs'].features = layerByKey['climbs'].features.concat(climbSrc);
  }
  if(window.CC_ROUTES){
    // towns each ride starts at / passes — lets riders search routes by start location (demo lookup)
    const RIDE_CITIES={
      'Spa · Sankt Vith':['Spa','Stavelot','Vielsalm','Sankt Vith'],
      'Spa · Coo · Francorchamps':['Spa','Francorchamps','Coo','Stavelot'],
      'Spa · Côte des Hézalles':['Spa','Sart','Jalhay'],
      'Rondje Spa–Chevron':['Spa','Stoumont','Chevron','La Gleize'],
      'Rondje Super Stockeu':['Spa','Stavelot','Coo','Trois-Ponts'],
      'Afternoon Ride':['Spa','Sart','Tiège']
    };
    layerByKey['experience'].features = CC_ROUTES.routes.map((r,i)=>{
      // Demo-era lookup keyed by ride name. NO fallback: fabricating
      // 'Starts at: Spa' for unknown routes (e.g. rider proposals) is wrong
      // data — reverse-geocoding real towns is a recorded route-domain
      // non-goal, so unknown routes simply omit the town rows.
      const cities = RIDE_CITIES[r.name];
      // Seeded from r.id (not the array index i): located corrections store
      // fractions relative to this trimmed path, so the trim must stay
      // deterministic per route even when the served route set changes
      // (e.g. another route rejected shifts indices) — an index-seeded trim
      // would re-trim the same route differently and drift stored fractions.
      const seed = Number(r.id)||0;
      const startM = 350 + (seed*137)%401, endM = 350 + (seed*211+90)%401;   // 350–750 m, varied but stable per ride
      // difficulty is always {score,label} now (P2-D1); typeof fallback is defensive only.
      const diffLabel = r.difficulty?.label ?? (typeof r.difficulty === 'string' ? r.difficulty : undefined);
      return {
      id:r.id, rid:r.rid, name:r.name, state:r.state, headline:`${r.km} km${diffLabel ? ' · ' + trVal(diffLabel) : ''}`, cur:false, edit:'ride',
      geom:{path:trimEnds(r.loop, startM, endM)}, elev:r.elev, gain:r.gain, difficulty:r.difficulty, uploader:r.uploader,
      cities: cities || [],                                // searchable start/through towns (empty when unknown)
      bikeTypes: Array.isArray(r.bikeTypes) ? r.bikeTypes : [],   // declared suitability (may be empty = undeclared)
      photo:r.photo||wc('Liège-Bastogne-Liège 2014 Echappée du jour Côte de Wanne.JPG','Les Meloures','Les Meloures','CC BY-SA 3.0'),
      // C1-T4 (W6): 'Contributed GPX' is an accurate detail for the pipeline's
      // usual auto-derived routes; a rider-added/edited one gets the plain label.
      source:(r.srcType==='user'||r.srcType==='manual') ? sourceLabel(r.srcType) : (D.contributedGpx||'Contributed GPX (GPS track only)'),
      // C2-T7 (spec §W2): every row below is a real QualityRides registry
      // attribute (CatalogFormRegistry::for(QualityRides), forwarded by
      // CatalogProvider::routes()) — the previous Quietness/Scenic
      // rating/Cycling-friendliness/Suitable bikes/Accessibility/Best direction
      // rows were index-derived formulas or literals identical for every ride
      // (the same class of bug C2-T6 fixed for climbs' "Bike type"/"Handbike"
      // filler) — deleted; only present when a rider (or import) actually set
      // the attribute.
      record:(()=>{
        const rec=[
          {label:D.distance||'Distance', value:r.km+' km'}
        ];
        // Phase-2 badge: a proposed route (unverified) rides "ride it to verify";
        // a verified route renders normally. state is served by CatalogProvider.
        if(r.state === 'unverified') rec.unshift({label:D.status||'Status', value:D.proposedVerify||'Proposed · ride it to verify', warn:true});
        if(cities){
          // html:true — builder-constructed markup from the constant
          // RIDE_CITIES table (cityLink escapes the name); NEVER set this
          // flag on payload-derived values.
          rec.push({label:D.startsAt||'Starts at', value:cityLink(cities[0]), html:true});
          rec.push({label:D.townsOnRoute||'Towns on route', value:cities.map(cityLink).join(' · '), html:true});
        }
        // Derived, not declared: measured against the A-layer mapped-road
        // segments at import/intake (SurfaceProfiler). The method note
        // discloses estimate + coverage — never present this as ground truth.
        // Kept hand-authored (it is not a registry field; K's declared field is
        // 'dominantSurface', rendered by the schema below).
        if(r.surfaces && Array.isArray(r.surfaces.parts) && r.surfaces.parts.length){
          rec.push({label:D.surfaces||'Surfaces', value:r.surfaces.parts.map(p=>`${trVal(p.surface)} ${p.pct}%`).join(' · '),
                    method:tpl(D.estimateMethod||'estimate · {pct}% of route mapped', {pct:Number(r.surfaces.covered)||0})});
        }
        // Registry-driven (CC_FIELD_SCHEMA[K]): season / dominantSurface /
        // quietness / scenic / friendliness / bikeTypes / gradientLimited /
        // bestDirection / note — value or "add" prompt. 'difficulty' is skipped
        // (rendered as the cc-diff badge); 'rideName' is display:false.
        rec.push(...schemaRows('K', r, r.id, {skip:['difficulty']}));
        return rec;
      })()
    };
    });
  }
  // populate A · Road surface from the hand-picked OSM segments
  if(window.CC_SURFACE){
    layerByKey['surface'].features = CC_SURFACE.segments.map(s=>{
      // Registry-driven rows (CC_FIELD_SCHEMA[A]) — value or "add" prompt per field.
      // Fields: surface / smoothness / width / traffic / note / lit /
      // segregated / seasonalClosure. Per-row OSM provenance now lives only
      // on the Source line.
      const rec = schemaRows('A', s, s.id);
      return {
        id:s.id, rid:s.rid, name:s.name, headline:`${trVal(s.surface)} · ${trVal(s.smoothness)}`, cur:(s.cls!=='paved'), edit:'road-surface',
        geom:{path:s.path}, surfaceClass:s.cls, width:s.width,
        photo: s.photoFile ? wc(s.photoFile, s.photoCredit, s.photoUser, s.photoLicense) : undefined,
        // C1-T4 (W6): a rider-added/edited surface segment isn't OSM.
        source:(s.srcType==='user'||s.srcType==='manual') ? sourceLabel(s.srcType) : 'OSM (surface=*)',
        record:rec
      };
    });
  }
  // F · Hazards & conditions — served items (region-scoping-design.md §7 Task A).
  // Hazards have no coverage tile layer and no OSM bulk pool, so they render as
  // CATALOG point features (like climbs), sourced from the served payload
  // (CatalogProvider 'F' key -> window.CC_HAZARDS). Region stamping is automatic
  // (item rows; recomputeMembership), so f.rid flows through featureVisible()'s
  // scope gate with zero extra work. The drawer's registry rows / confirm panel
  // / edit-bridge all key on f.id + schemaRows('F', …), same as every letter.
  if(window.CC_HAZARDS && Array.isArray(CC_HAZARDS.features)){
    layerByKey['hazards'].features = CC_HAZARDS.features.map(ft=>{
      const p=ft.properties||{}, c=(ft.geometry&&ft.geometry.coordinates)||[];
      // headline: localized hazard type + severity when the item carries them
      // (schema choices localize the stored English via trVal/VALUE_TR).
      const bits=[p.hazardType, p.severity].filter(Boolean).map(trVal);
      const named=!!p.n;
      // photo may arrive as a JSON string (importable attribute) — parse it with
      // the same guard osmDrawer uses, else a raw string flows unparsed into
      // photoList()/the <img> sink (finding 14).
      let photo=p.photo; if(typeof photo==='string'){ try{ photo=JSON.parse(photo); }catch(e){ photo=null; } }
      return {
        // Real name when the item carries one; otherwise the layer label for
        // DISPLAY only, flagged `unnamed` so the index neither name-dedupes
        // nameless hazards (two potholes 50 m apart → one dropped) nor routes
        // them by the shared label (last-match-wins onto the wrong pin) — finding 9.
        id:p.id, rid:p.rid, name:p.n||(LAYER_L10N.hazards||'Hazard'), unnamed:!named,
        // Only append the layer label as a headline when there's no type/severity
        // AND no real name would already carry it, so a bare hazard never reads
        // "Hazards & conditions · Hazards & conditions" (finding 9, cosmetic).
        headline:bits.join(' · ')||(named?(LAYER_L10N.hazards||'Hazards & conditions'):''),
        // A rider-confirmed hazard (v) earns the same verified tier as a C/D/G/H
        // confirmed twin, not a pixel-identical unconfirmed pin (finding 13).
        cur:!!p.v, geom:{ll:[c[1], c[0]]},
        // Registry-driven record (CC_FIELD_SCHEMA[F]) — filled rows + "add" prompts.
        record:schemaRows('F', p, p.id),
        photo:photo,
        // srcType drives the source line: an osm-sourced row keeps the OSM label
        // (its ODbL linkifier + attribution), user/manual reads rider-contributed,
        // and only a genuinely source-less row falls back to "Community report"
        // (finding 12 — the old ternary made osm unreachable AND would have
        // dropped OSM attribution by labelling it a community report).
        source:sourceLabel(p.srcType) || (D.communityReport||'Community report'),
        v:p.v
      };
    });
  }
  // Curator-only pending submissions (injected by MapController for ROLE_CURATOR only).
  // Off the public map by design — riders never receive window.CC_PENDING.
  if(window.CC_IS_CURATOR && Array.isArray(window.CC_PENDING)){
    const pf = window.CC_PENDING.map(s=>({
      name:s.title, headline:`${(I18N.pendingTypes||{})[s.type]||s.type} · ${s.who} · ${s.when}`,
      geom:{ll:[s.lat, s.lng]},
      record:[
        {label:D.submittedBy||'Submitted by', value:s.who},
        {label:D.age||'Age', value:s.when},
        {label:D.where||'Where', value:`${s.region||''} · ${s.country||''}`}
      ],
      source:'Pending submission · preview',
      pending:s
    }));
    const pendingLayer = { key:'pending', letter:'⚑', label:LAYER_L10N.pending||'Pending review', color:'#D92D20', icon:'⏳', kind:'point', exp:false, pendingLayer:true, features:pf };
    CATALOG.push(pendingLayer);
    layerByKey['pending'] = pendingLayer;
    active.add('pending');
  }

  // Community loop + moderation submit (community.js): the delegated listeners,
  // plus a closure over the search index's pending-drop, which the sidebar-search
  // block assigns further down (search-ui.js owns it next).
  initCommunity({dropPendingFromSearch: id => { if(_searchDropPending) _searchDropPending(id); }});

  initPicking();   // located-correction stretch picking (picking.js)
  initClickToScope();   // empty-map click re-scopes the map (scope-ui.js)

  // set by the sidebar-search block below (it owns SEARCH_IDX); null until then
  let _searchDropPending=null;
  initRideCheck();   // riders-only "what's along my GPX?" rail control (ride-check.js)

  initDrawerChrome();   // drawer close affordances (drawer.js)
  initSheet();       // mobile snap sheet (sheet.js)
  initLightbox();    // lightbox chrome + Escape/arrows (lightbox.js)


  // catalog in canonical A–K order for the rail + legend (display only; render keeps CATALOG order)

  // build layer toggles
  const lc=document.getElementById('layers');
  CATALOG_AZ.forEach(layer=>{
    const el=document.createElement('div');
    el.className='layer'; el.style.setProperty('--c',layer.color); el.style.setProperty('--ic',txtOn(layer.color));
    el.style.setProperty('--ig', txtOn(layer.color)==='#fff' ? 'brightness(0) invert(1)' : 'brightness(0)'); el.dataset.key=layer.key;
    if(!active.has(layer.key)) el.classList.add('off');
    const _lc=layerCounts(layer);
    const ct=`${_lc.shown}/${_lc.total}`;
    el.innerHTML=`<span class="sw"><i class="sw-g">${layer.icon}</i></span><span class="nm">${layer.letter} · ${layer.label}</span><span class="ct">${ct}</span>`;
    el.onclick=()=>{ if(active.has(layer.key)){active.delete(layer.key);el.classList.add('off')} else {active.add(layer.key);el.classList.remove('off')} syncLayersAll(); render(); };
    lc.appendChild(el);
  });
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
  document.querySelectorAll('#baseSeg button').forEach(b=>b.onclick=()=>{
    document.querySelectorAll('#baseSeg button').forEach(x=>x.classList.remove('on'));
    b.classList.add('on');
    const sat=b.dataset.b==='satellite';
    if(map.getLayer('satellite')) map.setLayoutProperty('satellite','visibility', sat?'visible':'none');
    document.querySelector('.map-wrap').classList.toggle('sat', sat);
  });
  initStreetToggle();
  // mobile: the control collapses to a small layers icon — tap to expand, and
  // collapse again after a choice is made
  const mcToggle=document.getElementById('mcToggle');
  const mapCtrl=document.querySelector('.map-ctrl');
  if(mcToggle && mapCtrl){
    mcToggle.onclick=()=>{
      const open=mapCtrl.classList.toggle('open');
      mcToggle.setAttribute('aria-expanded', open?'true':'false');
    };
    mapCtrl.querySelectorAll('#baseSeg button, #ovStreet').forEach(b=>b.addEventListener('click',()=>{
      if(window.innerWidth<=760){ mapCtrl.classList.remove('open'); mcToggle.setAttribute('aria-expanded','false'); }
    }));
  }

  // mobile: filters bottom-sheet — the rail-foot peek toggles it
  const app=document.querySelector('.app');
  /* ---------- sidebar search: towns + named Commons features (local index, no geocoder) ---------- */
  const sBox=document.getElementById('search'), sRes=document.getElementById('searchRes');
  if(sBox && sRes){
    const escH = s => String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    // built once — CATALOG is fully populated (climbs + rides folded in) by this point.
    // Towns first, then the unified deduplicated item index (spec 2026-07-14 §3.1):
    // ITEM_INDEX covers CATALOG features + PIVOT stays + every bulk-OSM pool
    // exactly once, so the old per-pool push loops (which double-indexed every
    // PIVOT stay via the catalog-load concat, and missed water) are gone.
    const SEARCH_IDX=[];
    Object.keys(CITIES).forEach(name=>{ const big=CITIES[name].t==='City';   // big cities stand apart from hamlets: ochre ◉ "City" vs teal ◎ "Town"
      SEARCH_IDX.push({name, key:slug(name), kind: big?(D.city||'City'):(D.town||'Town'), badge: big?'◉':'◎', color: big?'#C8923A':'#3E7D8C', town:true, go:()=>openCity(name)}); });
    rebuildItemIndex();   // ITEM_INDEX + its derived IDX_IDS, together (item-index.js)
    itemIndex().forEach(e=>{ if(!e.unnamed) SEARCH_IDX.push(e); });   // nameless POIs list in place cards, not in text search
    // pending entries carry their submission id so hidePendingPin can drop
    // them from the index after a moderation decision (review W36) — the
    // feature disappears from the map, and a search hit that "does nothing"
    // must disappear with it. Both lists share the entry objects.
    _searchDropPending=id=>{
      for(let i=SEARCH_IDX.length-1;i>=0;i--){ if(SEARCH_IDX[i].pend===String(id)) SEARCH_IDX.splice(i,1); }
      dropPendingFromIndex(id);
    };
    let sMatches=[], sHL=-1;
    const closeS=()=>{ sRes.hidden=true; sRes.innerHTML=''; sMatches=[]; sHL=-1; if(sBox.getAttribute('aria-expanded')!=='false') sBox.setAttribute('aria-expanded','false'); };
    const hlS=()=>sRes.querySelectorAll('button').forEach((b,i)=>b.classList.toggle('hl',i===sHL));
    // One-tap ladder to the next-wider scope — shared by the widen chip's
    // click and keyboard paths (07-20 review info b). cc:scopechange re-runs
    // applyScope; the re-query surfaces the wider Photon bbox, the local rows
    // the narrower scope hid, and the next rung's chip.
    // Widen one rung, then re-run all three result sources against the wider
    // scope: Photon (wider bbox), the local index (runS re-gates on inScope),
    // and coverage — which since Phase 3 is scope-filtered at the source
    // (region-scoping-design.md §6), so the wider rung's rows only appear once
    // runCoverageSearch re-fetches with the new rids/cc. runS runs last so it
    // renders the freshly-updated Photon + (imminently) coverage hits.
    function widenSearch(){ if(!window.CCScope) return; window.CCScope.widen(); runPhoton(sBox.value); runCoverageSearch(sBox.value); runS(); }
    function pickS(i){ const m=sMatches[i]; if(!m) return;
      if(m.widen){ m.go(); return; }   // widen chip: dropdown stays open, results re-query
      // Scope row: sets the map's scope (fit + relabel via cc:scopechange,
      // Task 3/5), not a fly-to — closeS()/blur() here (not inside go, so
      // widen keeps its own no-close contract) mirror applyScopeRow in the
      // task-6 brief without duplicating a second click/keydown path.
      if(m.scope){ m.go(); closeS(); sBox.blur(); return; }
      sBox.value=m.name; closeS();
      if(window.innerWidth<=820){ const ap=document.querySelector('.app'); if(ap) ap.classList.remove('sheet-open'); }  // clear the filter sheet on mobile
      m.go(); }
    // 07-15 decision A: community (unverified) rows carry a dimmed sub-tag so
    // verified vs community reads at a glance. Towns and pending rows never do.
    const commRow=m=>!m.town && !m.pend && (m.community || m.verified===false);
    const sRow=(m,i)=>`<li role="option"><button data-i="${i}"><span class="sw" style="background:${m.color};color:${txtOn(m.color)}">${escH(m.badge)}</span><span class="snm">${escH(m.name)}${commRow(m)?`<span class="scomm">${escH(D.community||'community')}</span>`:''}</span><span class="sub">${escH(m.kind)}</span></button></li>`;
    // Scopes section (2026-07-22-scope-selector-scale-design.md §A/§B): a
    // scope row renders like any other search row (same <li role="option">/
    // <button data-i> shape as sRow) so it drops into the existing sMatches/
    // data-i/go model untouched — no new keyboard or click wiring needed,
    // only pickS gains one branch (below) to route it through CCScope
    // instead of a fly-to. Reuses --clay (already in map.css :root) via
    // inline style, same idiom sRow uses for m.color — no new CSS needed.
    // Escaped with escPend per task-6 brief (escH doesn't escape `'`, and
    // region/country labels can contain one, e.g. Val-d'Or-style names).
    const SCOPE_COLOR='#B5532E';   // = --clay (map.css :root) — retune both together, this file hardcodes hex throughout (no CSS-var reads at runtime)
    // s-scope has no CSS rule (styling comes from the shared .sw/.snm/.sub
    // classes above) — it's a DOM hook only, for Task 9's browser
    // verification to select scope rows apart from place rows.
    const scopeRow=(m,i)=>`<li role="option"><button data-i="${i}" class="s-scope"><span class="sw" style="background:${SCOPE_COLOR};color:${txtOn(SCOPE_COLOR)}">${m.kind==='country'?'◆':'◇'}</span><span class="snm">${escPend(m.name)}</span><span class="sub">${escPend(m.kind==='country'?(D.wholeCountry||'Whole country'):(D.region||'Region'))}</span></button></li>`;
    // Any-town live place search via Photon (spec 2026-07-14 §3.2) — Photon,
    // not Nominatim: Nominatim's usage policy forbids type-ahead. Wallonia
    // bbox, place types only, ≥3 chars, 350 ms debounce, one in-flight request
    // (stale ones aborted). On error/timeout search silently degrades to the
    // local index + hardcoded quick-picks — no toast. Host already in CSP.
    let _phAbort=null, _phHits=[], _phQ='';
    const PH_BASE='https://photon.komoot.io/api/?limit=6'
      +'&osm_tag=place:city&osm_tag=place:town&osm_tag=place:village&osm_tag=place:hamlet&osm_tag=place:municipality';
    function runPhoton(qRaw){
      const q=qRaw.trim();
      if(q.length<3){ _phHits=[]; _phQ=''; return; }
      if(_phAbort) _phAbort.abort();
      const ctl=new AbortController(); _phAbort=ctl;
      // Photon bbox + country gate derive from the active scope (region-scoping-
      // design.md §4/§7 Phase 2), replacing the hardcoded Wallonia bbox / BE gate:
      // a named region/country biases + filters to its country; Everywhere drops
      // both and searches worldwide. The widen ladder just changes the scope.
      const pp = window.CCScope ? window.CCScope.photonParams() : {bbox:null, countrycode:null};
      const url = PH_BASE + (pp.bbox ? '&bbox='+pp.bbox.join(',') : '') + '&q='+encodeURIComponent(q);
      fetch(url, {signal:ctl.signal})
        .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(d=>{
          if(ctl.signal.aborted) return;
          const seen=new Set(Object.keys(CITIES).map(n=>slug(n)));   // quick-picks win over their Photon twin
          _phHits=(d.features||[])
            .filter(f=>f && f.properties && f.properties.name && f.geometry && Array.isArray(f.geometry.coordinates))
            // The bbox is a coarse pre-filter that spills over borders; countrycode
            // is the precise gate (Mazy BE stays, Malzy FR goes) — but only when the
            // scope has a country. Everywhere (no countrycode) admits worldwide hits.
            .filter(f=>!pp.countrycode || String(f.properties.countrycode||'').toLowerCase()===pp.countrycode)
            .filter(f=>{ const k=slug(f.properties.name); if(!k || seen.has(k)) return false; seen.add(k); return true; })
            .map(f=>{ const c=f.geometry.coordinates, name=f.properties.name;
              return {name, key:slug(name), kind:'Town', badge:'◎', color:'#3E7D8C', town:true, ph:1,
                go:()=>openPlace(name, {ll:[+c[1],+c[0]]})}; });
          _phQ=slug(q);
          if(!sRes.hidden) runS();   // merge into the open dropdown
        })
        .catch(()=>{});   // abort / network / quota — degrade silently
    }
    // Coverage search (coverage-provider.md §5/§6): the local
    // index only spans the served pool; everything else comes from
    // /map/coverage/search. Photon's conventions apply — ≥2 chars, one
    // in-flight request (stale ones aborted), silent degradation, results
    // merged into the open dropdown behind the local rows of each letter group.
    let _covAbort=null, _covHits=[], _covSQ='';
    function runCoverageSearch(qRaw){
      const q=qRaw.trim();
      if(!COVERAGE_ON || q.length<2){ _covHits=[]; _covSQ=''; return; }
      if(_covAbort) _covAbort.abort();
      // myArea derived to zero regions (map-and-search.md §4.5): an unscoped
      // fetch would silently return GLOBAL results, so skip it and contribute
      // no coverage rows — same "in scope: nothing" rule as fetchCoverageCounts.
      if(covScopeIsZero()){
        _covHits=[]; _covSQ=slug(q);
        if(!sRes.hidden) runS();
        return;
      }
      const ctl=new AbortController(); _covAbort=ctl;
      // Scope the search to the active region (Phase 3, region-scoping-design.md
      // §6): the server returns only in-scope rows, so the coverage results
      // mirror the scope-filtered tiles. Everywhere adds no params.
      const sq=covScopeQuery();
      fetch('/map/coverage/search?q='+encodeURIComponent(q)+(sq?('&'+sq):''), {signal:ctl.signal, headers:{'Accept':'application/json'}})
        .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(d=>{
          if(ctl.signal.aborted) return;
          _covHits=(d.results||[])
            .filter(h=>h && h.n && LETTER_KEY[h.letter] && Array.isArray(h.ll))
            .filter(h=>!(h.itemId!=null && idxIds().has(h.letter+':'+h.itemId)))   // curated twin already indexed locally
            .map(h=>{ const layer=layerByKey[LETTER_KEY[h.letter]];
              return {name:h.n, key:slug(h.n), kind:layer.label, badge:h.letter, color:layer.color,
                letter:h.letter, ll:h.ll, cov:1, community:!h.curated,
                go:()=>openCoverageByRef(h.ref, h.letter, h.ll, h.n)}; });
          _covSQ=slug(q);
          if(!sRes.hidden) runS();   // merge into the open dropdown
        })
        .catch(()=>{});   // abort / network / 429 — degrade to the local index
    }
    function runS(){
      const q=slug(sBox.value.trim());
      if(!q){ closeS(); return; }
      const starts=[], has=[];                                  // prefix matches rank above substring matches
      for(const it of SEARCH_IDX){
        // Scope-first search (region-scoping-design.md §4, 07-20 review
        // finding 3): served rows filter to the active scope with exactly the
        // map's gate — a hidden pin must not resurface as a search row that
        // opens a drawer over an empty spot. Out-of-scope rows come back via
        // the widen chip. Towns are exempt (places, not scoped features —
        // the hardcoded CITIES list generalises per scope in Phase 5).
        if(!it.town && !inScope(it.rid)) continue;
        const i=it.key.indexOf(q); if(i===0) starts.push(it); else if(i>0) has.push(it);
      }
      const ranked=starts.concat(has);
      // Grouped display (spec 2026-07-14 §3.3): places first, then items by
      // letter A–K, 30 rows total (the old flat slice(0,8) hid most matches
      // around a populous town). sMatches stays flat in display order so the
      // existing keyboard navigation is untouched; group headers aren't options.
      const towns=ranked.filter(m=>m.town), items=ranked.filter(m=>!m.town);
      if(_phQ===q) towns.push(..._phHits.slice(0, Math.max(0, 6-towns.length)));   // geocoded towns behind local ones
      const byLetter={};
      items.forEach(m=>{ (byLetter[m.letter]=byLetter[m.letter]||[]).push(m); });
      // Coverage matches slot into the same letter groups, behind local rows
      // (same freshness handshake as Photon's _phQ: only merge results that
      // answer THIS query). Scope-filtered at the SOURCE (Phase 3,
      // region-scoping-design.md §6/§7): runCoverageSearch sends the active
      // scope's rids/cc, so _covHits already mirror the scope-filtered tiles —
      // the widen chip re-runs the fetch rung by rung. (The served local rows
      // above are gated client-side by inScope() since they aren't re-fetched.)
      if(_covSQ===q) _covHits.forEach(m=>{ (byLetter[m.letter]=byLetter[m.letter]||[]).push(m); });
      // decision A ordering: verified/curated rows first inside each letter
      // group, community after. Array.prototype.sort is stable (ES2019), so the
      // prefix-before-substring ranking survives within each tier.
      Object.keys(byLetter).forEach(L=>byLetter[L].sort((a,b)=>(commRow(a)?1:0)-(commRow(b)?1:0)));
      // Scopes group (task-6 brief; 2026-07-22-scope-selector-scale-design.md
      // §A): matching regions + country rungs, ranked by CCScope.searchScopes
      // (prefix before substring; a country rung outranks its own regions on
      // a tie), rendered above Places. Each hit becomes a row shaped exactly
      // like every other sMatches entry — {..., go} — so it needs no bespoke
      // click/keydown handling; only pickS's new m.scope branch (above)
      // distinguishes "apply scope" from "fly to place".
      const scopeHits = window.CCScope ? window.CCScope.searchScopes(sBox.value) : [];
      const groups=scopeHits.length ? [{label:D.scopes||'Scopes', rows:scopeHits.map(s=>({
        scope:true, kind:s.kind, name:s.label,
        go:()=> s.kind==='country' ? window.CCScope.setCountry(s.cc) : window.CCScope.setRegion(s.slug),
      }))}] : [];
      if(towns.length) groups.push({label:D.places||'Places', rows:towns});
      Object.keys(byLetter).sort().forEach(L=>groups.push({label:`${L} · ${byLetter[L][0].kind}`, rows:byLetter[L]}));
      const CAP=30;
      sMatches=[]; sHL=-1;
      let html='';
      for(const g of groups){
        if(sMatches.length>=CAP) break;
        const rows=g.rows.slice(0, CAP-sMatches.length);
        // Group headers are role="presentation" <li>s with no <button> inside
        // (unchanged from the pre-existing Places/letter headers) — hlS's
        // querySelectorAll('button') walk and sMatches both skip them
        // naturally, so a header can never receive keyboard highlight. The
        // Scopes header reuses this same convention rather than a new one.
        html+=`<li class="sgrp" role="presentation">${escH(g.label)}</li>`;
        rows.forEach(m=>{ html+=(m.scope?scopeRow:sRow)(m, sMatches.length); sMatches.push(m); });
      }
      sRes.hidden=false;
      // Only mutate aria-expanded on a real open/close transition: setting an
      // attribute on the element being IME-composed restarts composition on
      // Android Chrome, re-anchoring the caret at 0 — typed text comes out
      // reversed ("spa" → "aps").
      if(sBox.getAttribute('aria-expanded')!=='true') sBox.setAttribute('aria-expanded','true');
      // Widen chip (region-scoping-design.md §4, the Craigslist lesson): a
      // one-tap ladder to the next-wider scope, always offered while the scope
      // can widen. It sits under the results, so it auto-surfaces exactly when
      // they are sparse — no settings dig. It is a REAL option (07-20 review
      // info b): it carries data-i and joins sMatches as a pseudo-entry so
      // ArrowDown/Enter reach it like any row; realCount keeps the empty-state
      // message keyed on actual matches, not the chip.
      const realCount=sMatches.length;
      let widenHtml='';
      if(window.CCScope && window.CCScope.canWiden()){
        const nw = window.CCScope.nextWider();
        const wl = (nw && nw.kind==='everywhere') ? (I18N.searchEverywhere||'Search everywhere')
          : tpl(I18N.searchWiden||'Search in {area} instead', {area:scopeLabel(nw)});
        widenHtml = `<li class="search-widen" role="option"><button type="button" data-widen="1" data-i="${sMatches.length}">${escH(wl)}</button></li>`;
        sMatches.push({widen:true, go:widenSearch});
      }
      sRes.innerHTML = (realCount ? html : `<li class="search-empty">${D.noMatch||'No match in the Wallonia demo yet.'}</li>`) + widenHtml;
    }
    // one delegated listener + a short debounce (review W41): the per-keystroke
    // cost was a full index scan, an innerHTML rebuild AND fresh per-result
    // listeners — the pattern that degrades linearly as the catalog grows.
    sRes.addEventListener('click', e=>{
      // Widen chip: step the scope one rung wider (cc:scopechange re-runs
      // applyScope), then re-query so the wider Photon bbox + fresh chip appear.
      // stopPropagation: runS() rebuilds the dropdown, detaching this button, so
      // the document close-listener would otherwise see a detached target
      // (closest('#searchRes')===null) and close the just-reopened dropdown.
      if(e.target.closest('button[data-widen]')){ e.stopPropagation(); widenSearch(); return; }
      const b=e.target.closest('button[data-i]'); if(b) pickS(+b.dataset.i);
    });
    let _sDeb=null, _phDeb=null, _covDeb=null;
    sBox.addEventListener('input', ()=>{ clearTimeout(_sDeb); _sDeb=setTimeout(runS,150);
      clearTimeout(_phDeb); _phDeb=setTimeout(()=>runPhoton(sBox.value),350);
      clearTimeout(_covDeb); _covDeb=setTimeout(()=>runCoverageSearch(sBox.value),250); });
    sBox.addEventListener('keydown', e=>{
      if(sRes.hidden){ if(e.key==='ArrowDown') runS(); return; }
      if(e.key==='ArrowDown'){ e.preventDefault(); sHL=Math.min(sHL+1, sMatches.length-1); hlS(); }
      else if(e.key==='ArrowUp'){ e.preventDefault(); sHL=Math.max(sHL-1, 0); hlS(); }
      else if(e.key==='Enter'){ e.preventDefault(); pickS(sHL<0?0:sHL); }
      else if(e.key==='Escape'){ closeS(); }
    });
    document.addEventListener('click', e=>{ if(e.target!==sBox && !e.target.closest('#searchRes')) closeS(); });
  }

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

  // mobile: road-surface legend collapses to an icon (mirrors #mcToggle)
  const lgToggle=document.getElementById('lgToggle'), legend=document.querySelector('.legend');
  if(lgToggle && legend) lgToggle.onclick=()=>{ const o=legend.classList.toggle('open'); lgToggle.setAttribute('aria-expanded',o?'true':'false'); };

  // mobile: top-bar nav hamburger -> dropdown
  const railHead=document.querySelector('.rail-head'), railBurger=document.getElementById('railBurger');
  if(railHead && railBurger){
    railBurger.onclick=e=>{ e.stopPropagation(); const o=railHead.classList.toggle('nav-open'); railBurger.setAttribute('aria-expanded',o?'true':'false'); };
    document.addEventListener('click',e=>{ if(railHead.classList.contains('nav-open') && !railHead.contains(e.target)){ railHead.classList.remove('nav-open'); railBurger.setAttribute('aria-expanded','false'); } });
    document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ railHead.classList.remove('nav-open'); railBurger.setAttribute('aria-expanded','false'); } });
  }

  // the #map box only really changes size at the 820px layout flip → resize then
  const _mq=window.matchMedia('(max-width:820px)');
  const _onBP=()=>requestAnimationFrame(()=>map.resize());
  _mq.addEventListener ? _mq.addEventListener('change',_onBP) : _mq.addListener(_onBP);

  // Route domain phase 4 (spec §8): Curated mode = best-of for a (season, bike)
  // facet, fetched from /map/best-of; the returned ids get cur:true and Curated
  // filters K routes to them. A named-region scope now sends &region= too
  // (region-scoping-design.md §6 / §7 Phase 2).
  let boSeason=currentSeason(), boBike='all';

  function updateSubtitle(){
    const sub=document.querySelector('.map-top .sub'); if(!sub) return;
    if(mode()==='all'){ sub.textContent=I18N.subEverything||'Everything · full backlog'; return; }
    const bike=boBike==='all' ? (I18N.allBikes||'All bikes') : (CC_BIKE_LABEL[boBike]||boBike);
    sub.textContent=`${I18N.curated||'Curated best-of'} · ${CC_SEASON_LABEL[boSeason]} · ${bike}`;
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

  // Race-guard token, same pattern as the drawer's _historyReq (review W5):
  // switching Season/Bike quickly must never let a slower earlier response
  // overwrite the newer facet's membership under a subtitle that says otherwise.
  let _bestOfReq=0;
  function refreshBestOf(){
    const req=++_bestOfReq;
    if(mode()!=='curated'){ render(); return; }
    // A named-region scope sends &region=<id>; My area sends its derived rid SET
    // as a CSV (&region=1,24) so best-of ranks across the whole home-base area
    // (MapController parses the CSV; region-scoping-design.md §6 / §9.1 Phase 4).
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

  // Facet pickers (Curated only).
  const boSeasonEl=document.getElementById('boSeason'), boBikeEl=document.getElementById('boBike');
  if(boSeasonEl){ boSeasonEl.value=boSeason; boSeasonEl.onchange=()=>{ boSeason=boSeasonEl.value; updateSubtitle(); refreshBestOf(); }; }
  if(boBikeEl){ boBikeEl.onchange=()=>{ boBike=boBikeEl.value; updateSubtitle(); refreshBestOf(); }; }

  // mode toggle
  document.querySelectorAll('#mode button').forEach(b=>b.onclick=()=>{
    document.querySelectorAll('#mode button').forEach(x=>x.classList.remove('on'));
    b.classList.add('on'); setMode(b.dataset.m);
    clearRevealPin();   // decision C: mode change clears any reveal pin
    const bf=document.getElementById('bestFacets'); if(bf) bf.hidden = (mode()!=='curated');
    updateSubtitle();
    refreshBestOf();          // Curated → fetch + filter; Everything → plain render()
  });

  initScopeRail();   // rail buttons + the cc:scopechange -> applyScope path (scope-ui.js)

  initAreaNudge();   // pan-away widen prompt for a My-area scope (scope-ui.js)

  // Exactly one saved bike → preselect the Curated facet (single-valued
  // select; multi-bike riders keep the neutral 'all').
  if(PREFS.bikes.length===1 && boBikeEl && [...boBikeEl.options].some(o=>o.value===PREFS.bikes[0])){
    boBikeEl.value=PREFS.bikes[0]; boBike=PREFS.bikes[0];
  }

  // Initial best-of for the default facet so Curated isn't empty on load.
  updateSubtitle();
  { const bf=document.getElementById('bestFacets'); if(bf) bf.hidden = (mode()!=='curated'); }
  refreshBestOf();
  // discipline + freshness chips (visual)
  document.querySelectorAll('#disc .chip, .grp .chips .chip').forEach(c=>c.onclick=()=>c.classList.toggle('on'));
  // Saved riding styles preselect the (visual-only) discipline chips.
  if(PREFS.styles.length){
    document.querySelectorAll('#disc .chip').forEach(c=>c.classList.toggle('on', PREFS.styles.includes(c.dataset.style)));
  }
  // Preference prefilter chip: later onclick assignment overrides the generic
  // toggle-only binder above (same pattern as the climb-filter chips below).
  (function initPrefChip(){
    const chip=document.getElementById('prefFilter'), grp=document.getElementById('prefGrp');
    if(!chip||!grp||!PREFS.bikes.length) return;      // anonymous / no prefs → group stays hidden
    grp.hidden=false;
    const sync=()=>{ const on=prefFilterEnabled(); chip.classList.toggle('on', on); chip.setAttribute('aria-pressed', on?'true':'false'); };
    const flip=()=>{ const on=!prefFilterEnabled(); setPrefFilter(on); try{ localStorage.setItem('cc-pref-filter', on?'on':'off'); }catch(e){} sync(); render(); updateCounts(); };
    chip.onclick=flip;
    chip.onkeydown=e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); flip(); } };
    sync();
  })();
  // "Set my area" cold-start prompt (region-scoping-design.md §4 / §9.1 Phase 4):
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
  document.querySelectorAll('#heattoggle button').forEach(b=>b.onclick=()=>{
    document.querySelectorAll('#heattoggle button').forEach(x=>x.classList.remove('on')); b.classList.add('on');
    // addHeatmap ends with updateHeatFilter(), which honours a season chip
    // selected before the layer existed AND the active region scope.
    if(b.dataset.h==='on' && !map.getLayer('rideheat')) addHeatmap();
    if(map.getLayer('rideheat')) map.setLayoutProperty('rideheat','visibility', b.dataset.h==='on'?'visible':'none');
  });
  document.querySelectorAll('#season .chip').forEach(c=>c.onclick=()=>{
    document.querySelectorAll('#season .chip').forEach(x=>x.classList.remove('on')); c.classList.add('on');
    updateHeatFilter();
  });

  // street-level imagery (Mapillary) dock controls — the on/off toggle lives in the data-layers list

  initMapillaryDock();

  initPlanner();   // illustrative Spa planner chips (planner.js)

  // initial render runs from map.on('load') above (sources need the style loaded)
