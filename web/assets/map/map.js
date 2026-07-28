// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Map entry module. Being split into focused modules under web/assets/map/ —
   see docs/specs/2026-07-26-map-js-module-split-design.md for the target layout
   and the rules (§4.1 cycles, §4.2 side effects belong to the entry).

   Loaded as an ES module: catalog-load.js injects it with type="module" once
   the catalog fetch has populated the CC_* globals this file reads. */
import { I18N, LAYER_L10N, D, tpl, VALUE_TR, trVal, sourceLabel } from './i18n.js';
import { wc } from './util.js';
import { map, initMapControls, addSatellite, markStyleReady, initCoordPopup,
         localiseBasemapLabels } from './map-init.js';
import { initRideCheck } from './ride-check.js';
import { CATALOG, active, layerByKey, cityLink, mode } from './catalog.js';
import { addMapillary, initMapillaryDock, initStreetToggle } from './mapillary.js';
import { curScope, scopeLabel, renderScopeChips, applyScope, initScope, initScopeRail,
         initAreaNudge, initClickToScope } from './scope-ui.js';
import { OSM_BULK, addWaterOsm, addOsmDots, setupConfClusters, updateConfMarkers } from './osm-pools.js';
import { trimEnds } from './item-index.js';
import { sheet, initSheet } from './sheet.js';
import { initLightbox } from './lightbox.js';
import { initPlanner } from './planner.js';
import { render } from './render.js';
import { COVERAGE_ON, addCoverage, widenForDeepLink, openCoverageFeatureByName,
         fetchCoverageCounts, covShownCount } from './coverage.js';
import { schemaRows, initDrawerChrome } from './drawer.js';
import { initPicking } from './picking.js';
import { resolveLocalFeature, openFeatureByName, openRouteById, openPendingById } from './places.js';
import { initCommunity, initCuratorKeys } from './community.js';
import { initSearchUi } from './search-ui.js';
import { initLayerList, initMapCtrl, initRailChrome, initBestOf,
         initChips, initViewMode } from './panels.js';

  // Scope model + rail + header (scope-ui.js).
  initScope();

  // Which view mode the map OPENS in (2026-07-27-map-view-mode-default-design.md
  // §5). Must sit exactly here: it reads the ACTIVE scope, which initScope()
  // above has just resolved, and every later mode() reader — the map.on('load')
  // applyScope/render and initChips()'s initial refreshBestOf() — must already
  // see the answer.
  initViewMode();

  initMapControls();



  map.on('load',()=>{ markStyleReady(); localiseBasemapLabels(); addSatellite(); addMapillary(); addWaterOsm(); addCoverage();   // heatmap is lazy (W43)
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

  initCuratorKeys();   // A/R arm a moderation decision (community.js)


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

  // Community loop + moderation submit (community.js): the delegated listeners.
  initCommunity();

  initPicking();   // located-correction stretch picking (picking.js)
  initClickToScope();   // empty-map click re-scopes the map (scope-ui.js)

  initRideCheck();   // riders-only "what's along my GPX?" rail control (ride-check.js)

  initDrawerChrome();   // drawer close affordances (drawer.js)
  initSheet();       // mobile snap sheet (sheet.js)
  initLightbox();    // lightbox chrome + Escape/arrows (lightbox.js)


  initLayerList();      // layer toggles, select-all, base segmented control (panels.js)
  initStreetToggle();
  initMapCtrl();        // collapsible on-map base/overlay control (panels.js)

  initRailChrome();     // filters sheet, legend, burger nav, breakpoint resize (panels.js)
  initSearchUi();   // sidebar town + feature search (search-ui.js)


  initBestOf();         // Curated best-of facets + the mode toggle (panels.js)

  initScopeRail();   // rail buttons + the cc:scopechange -> applyScope path (scope-ui.js)

  initAreaNudge();   // pan-away widen prompt for a My-area scope (scope-ui.js)

  initChips();          // every chip group; MUST follow initScopeRail/initAreaNudge (panels.js)

  // street-level imagery (Mapillary) dock controls — the on/off toggle lives in the data-layers list

  initMapillaryDock();

  initPlanner();   // illustrative Spa planner chips (planner.js)

  // initial render runs from map.on('load') above (sources need the style loaded)
