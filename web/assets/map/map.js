// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Map entry module. Being split into focused modules under web/assets/map/ —
   see docs/specs/2026-07-26-map-js-module-split-design.md for the target layout
   and the rules (§4.1 cycles, §4.2 side effects belong to the entry).

   Loaded as an ES module: catalog-load.js injects it with type="module" once
   the catalog fetch has populated the CC_* globals this file reads. */
import { I18N, LAYER_L10N, D, tpl, VALUE_TR, trVal, sourceLabel, DIFF_LABELS, CC_SEASON_LABEL, CC_BIKE_LABEL } from './i18n.js';
import { escPend, safeHref, stars, slug, txtOn, gradColor, DIFF_PURPLE, haversine, featurePoint, currentSeason, ccUrl, wc } from './util.js';
import { map, initMapControls, addSatellite, flyToPin, styleReady, markStyleReady, initCoordPopup } from './map-init.js';
import { initRideCheck } from './ride-check.js';
import { setSpotlight, setCountrySpotlight, setCircleSpotlight } from './spotlight.js';
import { CATALOG, CATALOG_AZ, active, layerByKey, CITIES, cityLink, LETTER_KEY, KEY_LETTER, mode, setMode } from './catalog.js';
import { addMapillary, initMapillaryDock, initStreetToggle } from './mapillary.js';
import { mintWaterDrops, SERVICE_GLYPH, miniIcon, clusterEl, pinEl, coverageIconId } from './icons.js';
import { curScope, inScope, scopeLabel, renderScopeChips, applyScope,
         initScope, initScopeRail, initAreaNudge } from './scope-ui.js';
import { osmLayers, OSM_BULK, addWaterOsm, addOsmDots, setupConfClusters,
         updateConfMarkers, initOsmPools } from './osm-pools.js';
import { itemIndex, idxIds, rebuildItemIndex, dropPendingFromIndex, nearbyItems, trimEnds,
         initItemIndex } from './item-index.js';
import { PREFS, addHeatmap, updateHeatFilter, boundLayerIds, surfaceClsLayerIds,
         highlightRoute, clearRouteHighlight, nearestOnPath, fracToLatLng, sliceByFrac,
         layerCounts, updateCounts, render, applyStaysAccessFilter, attrMatch,
         chipSet, syncFacetChips, prefFilterEnabled, setPrefFilter, staysAccessible,
         initRender } from './render.js';
import { COVERAGE_KEYS, COVERAGE_CCS, COVERAGE_ON, covIconFilter, updateCoverageScopeFilter,
         covScopeIsZero, covScopeQuery, syncCoverageLayers, addCoverage, openCoverageByRef,
         widenForDeepLink, openCoverageFeatureByName, fetchCoverageCounts, covShownCount,
         coverageTotal, clearSelectedCoverageIcon, invalidateCoverageDrawer,
         initCoverage } from './coverage.js';

  // Scope model + rail + header (scope-ui.js). The repaint callbacks are
  // injected because their owning modules are still inside this entry at
  // this point in the split; each becomes a plain import as its module lands.
  initScope({refreshBestOf});
  // Bulk-OSM pools + confirmed-pin clusters (osm-pools.js): a dependency
  // handover, no side effect — the pools themselves are built on map 'load'.
  initOsmPools({openDrawer, osmDrawer, waterDrawer, showTip, hideTip});
  // Coverage tiles (coverage.js): likewise a handover — addCoverage() still runs
  // from the map 'load' handler below.
  initCoverage({openDrawer, renderDrawerBody, osmDrawer, waterDrawer, revealPinAt,
                showTip, hideTip, isPicking: () => !!_pick});
  // Searchable-item index (item-index.js): the two `go` handlers each entry
  // carries. Built later, from the sidebar-search block.
  initItemIndex({openLocalFeature, openStayPivot});
  // Render loop (render.js): drawer/tip/place hooks it still needs from here.
  initRender({openDrawer, showTip, hideTip, openLocalFeature});
  // C1-T3: race-guard token for the drawer's async "Recent changes" fetch —
  // bumped on every openDrawer() call so a slow response from a since-replaced
  // drawer never paints stale history over whatever is open now.
  let _historyReq = 0;

  initMapControls();



  // Registry-driven attribute rows (spec: 2026-07-13-registry-driven-drawer-fields).
  // CC_FIELD_SCHEMA[letter] is the server's per-type display-field list
  // [{key,label,kind}] with labels already localised. For each field: a value
  // row when set, otherwise a muted "add" prompt to the /improve edit-bridge.
  // opts.skip = field keys a builder renders structurally (e.g. routes' difficulty
  // badge) so they are not double-rendered here.
  function schemaRows(letter, src, id, opts){
    const schema = (window.CC_FIELD_SCHEMA || {})[letter] || [];
    const skip = (opts && opts.skip) || [];
    const fixed = (opts && opts.fixed) || {};   // key -> assumed default when UNSET: shown as a value row instead of an "add" prompt; a stored value wins
    const rows = [];
    schema.forEach(f=>{
      if(skip.indexOf(f.key) >= 0) return;
      const v = src[f.key];
      const has = Array.isArray(v) ? v.length > 0 : (v != null && v !== '');
      // Stored values are canonical English; f.choices (schema-provided, per
      // field) maps them to the rider's locale for display only.
      const cv = f.choices || {};
      const tv = x => cv[x] != null ? cv[x] : x;
      if(has){
        if(f.kind === 'multiselect'){
          const list = Array.isArray(v) ? v : [v];
          rows.push({label:f.label, html:true, value:list.map(t=>`<span class="cc-chip">${escPend(tv(t))}</span>`).join('')});
        } else if(f.kind === 'rating'){
          rows.push({label:f.label, value: /^[1-5]$/.test(String(v)) ? stars(Number(v)) : v});
        } else if(f.kind === 'url'){
          rows.push({label:f.label, value:String(v).replace(/^https?:\/\//,'').replace(/\/$/,''), links:[{label:D.visitSite||'Visit site', href:v}]});
        } else {
          rows.push({label:f.label, value:tv(v)});
        }
      } else if(fixed[f.key] != null){
        rows.push({label:f.label, value:tv(fixed[f.key])});
      } else if(id != null){
        const href = `/improve?item=${encodeURIComponent(id)}&type=${encodeURIComponent(letter)}&field=${encodeURIComponent(f.key)}`;
        rows.push({label:f.label, html:true, empty:true, value:`<a class="cc-d-add" href="${href}">＋ ${D.add||'add'}</a>`});
      }
    });
    return rows;
  }
  // drawer card for a generic bulk-OSM point — shared by the dot click handler and the confirmed pin
  function osmDrawer(layer, p, ll, src){
    const lbl=(layer||{}).label||D.place||'Place';
    const pivot=p.src==='pivot';   // official Tourisme Wallonie accommodation (CC-BY), not OSM
    // C1-T4 (W6): p.srcType is the item's real ItemSource value from CatalogProvider.
    // A rider-added/edited item (user/manual) must read as rider-contributed even
    // when served through a bulk-OSM layer — the per-fact "Type"/"Listed" method
    // tags below are unchanged (Phase C2 scope), only the headline + source line
    // are corrected here.
    const community = p.srcType==='user' || p.srcType==='manual';
    const originLbl = pivot?'Tourisme Wallonie':(community?sourceLabel(p.srcType):'OSM');
    // D · services carries a serviceKind (shop/station/pump) — when present, the localized
    // kind label takes precedence over the raw OSM p.t value for the type row + headline
    // (e.g. EN 'Repair station' → 'Self-service station'; FR/NL/DE get real translations
    // instead of the raw English t). Every other layer (and services items with no/unknown
    // serviceKind) falls back to today's p.t||lbl behaviour, unchanged.
    const kindLbl = p.serviceKind && ({shop:D.kindShop, station:D.kindStation, pump:D.kindPump}[p.serviceKind] || lbl);
    const typeLbl = kindLbl || p.t || lbl;
    let rec=[{label:D.type||'Type', value:typeLbl, method: pivot?'Tourisme Wallonie':'OSM'}];
    if(p.town && layer.letter!=='E') rec.push({label:D.town||'Town', value:p.town});  // stays' 'town' comes from the schema (labelled "Town / commune")
    // Province renders only when the item actually carries one (curated PIVOT/
    // harvest rows). Coverage POIs are Belgium-wide with region_id NULL by design
    // (coverage-provider.md §2) — the old 'Wallonia' fallback mislabelled every
    // Flanders POI, so no prov means no row until region attribution exists.
    if(p.prov) rec.push({label:D.province||'Province', value:p.prov});
    if(pivot) rec.push({label:D.listed||'Listed', value:D.officialRegistry||'Official Tourisme Wallonie registry', method:'official'});
    // The simulated demo Status/Rating rows are gone — simulated flags die
    // (map-and-search.md §12); their payload keys stay for byte-stability but
    // nothing reads them.
    if(p.web) rec.push({label:D.website||'Website', value:p.web.replace(/^https?:\/\//,'').replace(/\/$/,''), links:[{label:D.visitSite||'Visit site',href:p.web}]});
    // Registry-driven attribute rows (single source of truth = CatalogFormRegistry,
    // served as CC_FIELD_SCHEMA). Filled rows replace any structural row of the
    // same label (e.g. a curated 'Type' overriding the raw OSM one); unset fields
    // become "add" prompts. 'web' dedupes by the shared "Website" label below.
    // Stations/pumps are unmanned and inherently 24/7 (spec §5) — the /improve
    // form has no openingHours field for them, so instead of an "add" prompt
    // (which would deep-link to a field that doesn't exist) the drawer states
    // the implied fact: Opening hours · 24/7.
    const unmanned = p.serviceKind==='station' || p.serviceKind==='pump';
    const attrRows = schemaRows((layer||{}).letter, p, p.id, unmanned ? {fixed:{openingHours:'24/7'}} : undefined);
    const attrLabels = new Set(attrRows.filter(r=>!r.empty).map(r=>r.label));
    rec = rec.filter(r=>!attrLabels.has(r.label)).concat(attrRows);
    // source is shown once, in the bottom cc-d-src line (linkified there) — like every other drawer
    const d={name:p.n||p.t||lbl, headline:typeLbl+' · '+originLbl, cur:!!p.v, geom:{ll:[ll.lat,ll.lng]}, record:rec,
      source: pivot?'Tourisme Wallonie (TW) — CC-BY 4.0 · PIVOT / Géoportail de la Wallonie'
        :(community?sourceLabel(p.srcType):(src||sourceLabel(p.srcType)||'OpenStreetMap'))};
    if(p.id!=null) d.id=p.id;          // real DB item id — the edit-bridge's `?item=` target
    if(p.desc) d.desc=p.desc;
    if(p.descTr) d.descTr=1;
    let photo=p.photo; if(typeof photo==='string'){ try{ photo=JSON.parse(photo); }catch(e){ photo=null; } }
    if(photo) d.photo=photo;
    return d;
  }
  // drawer card for a water point — shared by the droplet click handler and the confirmed pin
  function waterDrawer(p, ll){
    // C2-T7 (spec §W2): 'potable'/'type' are WaterFood registry fields
    // (CatalogFormRegistry::for(WaterFood)) — when a rider has set them, they
    // take priority over the generic OSM-derived guess below (same
    // dedup-by-label rule as the other POI drawers/climbs).
    // The simulated middle branch (demo potable flag) is gone — simulated
    // flags die (map-and-search.md §12). v:1 is existence/verification, not a
    // potability statement: without a rider-set potable field the honest row
    // is the OSM-derived fallback — and that fallback must not claim
    // "drinkable" for a fountain OSM tags as NOT potable (p.osmPotable===false,
    // derived in covProps from the tile `potable` prop / tags.drinking_water).
    const potable = p.potable
      ? {label:D.potable||'Potable', value:trVal(p.potable)}
      : (p.osmPotable===false
          ? {label:D.potable||'Potable', value:D.potableOsmNo||'Tagged not drinkable in OSM — not utility-verified; avoid unless confirmed on the spot', method:'unverified'}
          : {label:D.potable||'Potable', value:D.potableOsm||'Tagged drinkable in OSM — not utility-verified; confirm on the spot', method:'unverified'});
    // C1-T4 (W6): see osmDrawer — a rider-added/edited water point isn't OSM.
    const community = p.srcType==='user' || p.srcType==='manual';
    const rec=[{label:D.type||'Type', value:(p.type||p.t) ? trVal(p.type||p.t) : (D.drinkingWater||'Drinking water'), method: p.type?undefined:'OSM'}, potable,
      {label:D.verify||'Verify', value:D.verifyWater||'Cross-check tap-water quality with the regional utility / fountain directory', links:[{label:'SWDE · Wallonia',href:'https://www.swde.be'},{label:'eaupotable.info',href:'https://eaupotable.info/nl/be-belgie'}]}];
    // Registry-driven rows for the remaining WaterFood fields (seasonal/note/
    // bottleFill/cost). 'type' and 'potable' are rendered structurally above.
    rec.push(...schemaRows('C', p, p.id, {skip:['type','potable']}));
    const d={name:p.n||p.t||D.drinkingWater||'Drinking water', headline:(D.headlineDrinking||'drinking water')+' · '+(community?sourceLabel(p.srcType):'OSM'), cur:!!p.v, geom:{ll:[ll.lat,ll.lng]},
      record:rec,
      source: community?sourceLabel(p.srcType):'OpenStreetMap (amenity=drinking_water / drinking_water=yes)'};
    if(p.id!=null) d.id=p.id;          // real DB item id — the edit-bridge's `?item=` target
    return d;
  }
  // Race guard: EVERY drawer-context render (openDrawer, openPlace/
  // renderPlaceCard, the ride-check results drawer, closeDrawer) bumps BOTH
  // the coverage generation (coverage.js's invalidateCoverageDrawer()) and
  // _placeReq together — one shared drawer-generation convention, two names so
  // each call site reads as "this fetch kind is now stale". A stale
  // GET /map/coverage/poi/{ref} detail response or a stale
  // GET /map/coverage/nearby town-card response (_placeReq) can therefore
  // never repaint a drawer that has since moved on — regardless of which
  // click path (coverage dot, curated pin, -osm dot, route, place card,
  // another town) opened the newer drawer. Before this pairing, _placeReq
  // was bumped only in openPlace, so switching from a town card to a plain
  // feature drawer (or to a different town) mid-fetch let the stale nearby
  // response resurrect the old town card over the new drawer content. The
  // enrich repaint itself is a content-only patch (renderDrawerBody), not a
  // re-open: no halo restart, no focus steal, no mobile-sheet snap to half.
  let _placeReq=0;
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

  function photoList(f){ return f.photos || (f.photo ? [f.photo] : []); }
  // p.photo is parsed straight from the importable photo attribute (review W1):
  // credit/license/source text goes through escPend, and creditUrl/source
  // through safeHref — same hardening r.links[].href already has.
  function photoCap(p){
    const credit = p.creditUrl ? `<a href="${safeHref(p.creditUrl)}" target="_blank" rel="noopener">${escPend(p.credit)}</a>` : escPend(p.credit);
    return `© ${credit} · <a href="${ccUrl(p.license)}" target="_blank" rel="noopener">${escPend(p.license)}</a> · <a href="${safeHref(p.source)}" target="_blank" rel="noopener">Wikimedia Commons ↗</a>`;
  }
  function buildRecord(layer, f){
    const cur = f.cur ? `<div class="cc-d-cur">▲ ${I18N.curated||'Curated best-of'}</div>` : '';
    const pl = photoList(f);
    // Same edit-bridge rule as the "Edit this item" link below (spec §6/§8):
    // the add-photo CTA only ever binds to the item's real DB id — no id, no
    // link (a name-slug guess is never a faithful target).
    const addPhoto = (f.id!=null && layer.key!=='experience') ? `<a class="cc-d-addphoto" href="/improve?item=${f.id}&name=${encodeURIComponent(f.name)}&type=${layer.letter}&add=photo" aria-label="Add a photo of ${escPend(f.name)}">
      <svg class="cc-ap-cam" viewBox="0 0 48 36" width="42" height="31" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="1.5" y="7.5" width="45" height="27" rx="4"/><path d="M16 7.5l3-4h10l3 4" stroke-linejoin="round"/><circle cx="24" cy="21.5" r="8"/><path d="M40.5 13h.01" stroke-width="3" stroke-linecap="round"/>
      </svg>
      <span class="cc-ap-t">${D.noPhoto||'No photo yet'}</span>
      <span class="cc-ap-b">＋ ${D.addPhoto||'Add the first photo'}</span>
    </a>` : '';
    const photo = pl.length ? `<figure class="cc-d-photo">
      <img src="${safeHref(pl[0].sm)}" alt="${escPend(f.name)}" data-i="0" />
      <figcaption id="cc-d-cap">${photoCap(pl[0])}</figcaption>
      ${pl.length>1 ? `<div class="cc-d-thumbs">${pl.map((p,i)=>`<img class="cc-d-thumb${i===0?' on':''}" src="${safeHref(p.sm)}" data-i="${i}" alt="${escPend(f.name)} — photo ${i+1}" />`).join('')}</div>` : ''}
    </figure>` : addPhoto;
    let recs = f.record || [];
    if(layer.key==='climbs'){
      // Registry-driven (CC_FIELD_SCHEMA[B]): every climb attribute — including
      // the additional options (water on climb / hairpins / shade) the old
      // hand-list dropped — renders here, filled or as an "add" prompt.
      // Attributes flow via CatalogProvider::climbs() (CC_CLIMBS spreads
      // item.attributes onto the feature) -> layerByKey['climbs'].features -> f.
      // A filled schema row REPLACES any stale pre-baked f.record row of the same
      // label (wallonia harvest + hand-authored CATALOG fixtures) so an approved
      // edit is never shadowed by a duplicate.
      const attrRows = schemaRows('B', f, f.id);
      const attrLabels = new Set(attrRows.filter(r=>!r.empty).map(r=>r.label));
      recs = recs.filter(r=>!attrLabels.has(r.label)).concat(attrRows);
    }
    const rows = recs.map(r => {
      const links = r.links ? ' ' + r.links.map(l=>`<a class="cc-d-link" href="${safeHref(l.href)}" target="_blank" rel="noopener">${escPend(l.label)} ↗</a>`).join('') : '';
      // r.html is the explicit trusted-markup channel (like r.links): honored
      // only for rows whose markup the builder constructs itself with EVERY
      // interpolation escPend-escaped (RIDE_CITIES city links, bike-type
      // chips). Raw payload values never take this path — they stay
      // escPend-escaped below (spec §13).
      return `<li class="${r.empty?'empty':''}"><span class="k">${escPend(r.label)}</span><span class="v${r.warn?' warn':''}">${r.html?r.value:escPend(r.value)}${r.method?`<span class="m">${r.method}</span>`:''}${links}</span></li>`;
    }).join('');
    const freshState = f.freshness ? ({fresh:D.freshFresh, ageing:D.freshAgeing, stale:D.freshStale}[f.freshness.state] || f.freshness.state) : '';
    const fresh = f.freshness
      ? `<div class="cc-d-fresh ${f.freshness.state}">${freshState} · ${D.lastConfirmed||'last confirmed'} ${f.freshness.lastConfirmed==='this season'?(D.thisSeason||'this season'):f.freshness.lastConfirmed}</div>` : '';
    // difficulty is always {score,label} now (P2-D1); typeof fallback is defensive only.
    const diffLabel = f.difficulty?.label ?? (typeof f.difficulty === 'string' ? f.difficulty : undefined);
    const diffScore = f.difficulty?.score ?? null;
    const diff = diffLabel
      ? `<div class="cc-diff" title="${D.difficulty||'Difficulty'} 1–5: ${DIFF_LABELS.slice(1).join(' · ')}">${D.difficulty||'Difficulty'}
          <div class="cc-diff-scale">${[1,2,3,4,5].map(n=>`<span class="cc-diff-dot${n===diffScore?' on':''}" style="--p:${DIFF_PURPLE[n]}" title="${n} · ${DIFF_LABELS[n]}">${n}</span>`).join('')}</div>
          <b class="cc-diff-lbl">${trVal(diffLabel)}</b></div>` : '';
    const elev = f.elev ? `<div class="cc-elev-cap">${D.elevation||'Elevation'} · ${Math.min(...f.elev)}–${Math.max(...f.elev)} m`
      + (f.gain?` · ${tpl(D.mClimbing||'{n} m climbing',{n:f.gain})}`:'') + ` <em>${D.fromGpx||'(from GPX)'}</em></div>` + elevSvg(f.elev) : '';
    const grad = f.grad ? gradStrip(f.grad) : '';
    const up = f.uploader
      ? (f.uploader.public
          ? `<div class="cc-up">${D.sharedBy||'Shared by'} <b>${escPend(f.uploader.name)}</b> · <a href="/profile?u=${slug(f.uploader.name)}">${D.viewProfile||'view profile'}</a></div>`
          : `<div class="cc-up">${D.sharedAnon||'Shared anonymously'}</div>`)
      : '';
    // The edit-bridge opens /improve bound to the item's real DB id, which
    // loads that exact item and prefills the form with its current values
    // (spec §6/§8) — no id, no edit link (a name-slug guess is never a
    // faithful target).
    const ell = f.geom && f.geom.ll;                    // [lat,lng] for point features
    let edit = '';
    if(f.id!=null){
      if(layer.key==='experience'){
        // Route domain v1 phase 3 (spec §7): the community panel. GPX download
        // stays; rode-it/vote/suggest render as a container filled async by
        // openDrawer's GET /routes/{id}/community (P3-D3) — riders never edit.
        edit = routeCommunityPanel(f.id, f.state);
      } else {
        const editQ = `item=${f.id}&name=${encodeURIComponent(f.name)}`
          + `&type=${layer.letter}`
          + (ell ? `&lat=${ell[0]}&lng=${ell[1]}` : '');
        edit = `<a class="cc-d-act edit" href="/improve?${editQ}">✎ ${D.editItem||'Edit this item'}</a>`;
        // Direct "this pin is wrong" path — only when we know where it is.
        // editQ already carries lat/lng; fix=location opens the editor expanded.
        if(ell){
          edit += `<a class="cc-d-act fixloc" href="/improve?${editQ}&fix=location">◎ ${D.fixLocation||'Fix location'}</a>`;
        }
      }
    }
    let moderate = '';
    if(layer.pendingLayer && f.pending){
      // §13: real submissions now flow here (not trusted fixtures) — HTML-escape
      // every interpolated submission field before it hits innerHTML (stored-XSS-
      // in-curator-session risk). s.title/s.who/s.when render via the shared
      // f.name/f.record path above (untouched — see MapController/Task 4).
      const s=f.pending;
      // Pending items carry their own catalog letter (A–K) + coords → a faithful edit link.
      // Every interpolation is encoded (review W33): the server serves lat/lng
      // numeric and letter as an enum, but this attribute context shouldn't
      // depend on that guarantee holding forever.
      // Edit-bridge binds to the TARGET catalog item (s.itemId), NOT the
      // submission id (s.id) — using s.id sent /improve a non-existent item id
      // and it fell through to the empty "pick a place" explainer. A brand-new
      // submission (no itemId yet — the place isn't in the catalog until it's
      // approved) has nothing to edit, so no link.
      edit = (s.itemId != null)
        ? `<a class="cc-d-act edit" href="/improve?type=${encodeURIComponent(s.letter)}&item=${encodeURIComponent(s.itemId)}&name=${encodeURIComponent(s.title)}&lat=${encodeURIComponent(s.lat)}&lng=${encodeURIComponent(s.lng)}">✎ ${D.editItem||'Edit this item'}</a>`
        : '';
      const body = s.body ? `<p class="cc-mod-body">${escPend(s.body)}</p>` : '';
      // "Proposed change" — what THIS submission wants to change, not the
      // item's history. Kept visually distinct from the history section below.
      const diff = (s.was && s.now)
        ? `<div class="cc-mod-diff"><div class="cc-mod-diff-h">${D.proposedChange||'Proposed change'}</div><div class="cc-mod-was">${escPend(s.was)}</div><div class="cc-mod-now">${escPend(s.now)}</div></div>`
        : '';
      // Moderation-UX (user request): "Everybody should always be able to see
      // the history of an item." A brand-new submission (type 'new') has no
      // prior item to have a history — say so plainly, no fetch needed. An
      // edit of an existing item (itemId set) fetches the same applied
      // change_history the normal item drawer shows (C1-T3), via openDrawer
      // below — reusing loadItemHistory/renderHistoryList so both views stay
      // in sync. escPend covers every interpolated value (see historyRow).
      const modHist = 'new' === s.type
        ? `<div class="cc-d-hist cc-d-hist-initial"><h4 class="cc-d-hist-h">${D.history||'History'}</h4><p class="cc-mod-initial">${D.initialEntry||'Initial entry — new item'}</p></div>`
        : (s.itemId != null ? `<div class="cc-d-hist" id="cc-d-hist-slot" data-item="${s.itemId}"></div>` : '');
      moderate = `<div class="cc-mod" data-id="${escPend(s.id)}">
        <div class="cc-mod-badge">⚑ ${I18N.pendingReview||'Pending review'}</div>${body}${diff}
        <textarea class="cc-mod-note" placeholder="${D.modNotePh||'Optional note — a reason, or context…'}"></textarea>
        <div class="cc-mod-acts">
          <button class="cc-mod-btn approve" data-decision="approve">✓ ${D.approve||'Approve'}</button>
          <button class="cc-mod-btn info" data-decision="needs_info">? ${D.needsInfo||'Needs info'}</button>
          <button class="cc-mod-btn reject" data-decision="reject">✕ ${D.reject||'Reject'}</button>
        </div>
        <div class="cc-mod-preview">${D.modKeys||'A · approve · R · reject — recorded, not yet persisted.'}</div>
        ${modHist}
      </div>`;
    }
    // Only votable point types get the vote CTA. Utilities are confirmed, not
    // voted — the erroneous vote link used to show on water/services/etc.
    const vote = (f.cur && CC_VOTABLE.has(layer.key)) ? `<a class="cc-d-act" href="/vote">▲ ${D.voteRound||'Vote in this round'}</a>` : '';
    // Non-votable utilities carry a community confirmation panel (water:
    // potable/not-potable, others: "still here?"), hydrated async on open.
    const confirmPanel = (CC_CONFIRMABLE.has(layer.key) && f.id!=null)
      ? `<div class="cc-cf" data-item="${f.id}"><div class="cc-cf-body" data-cf-body></div><div class="cc-cf-login" hidden>${D.loginConfirm||'Log in to confirm'} · <a href="/login">${I18N.login||'Log in'}</a></div></div>`
      : '';
    const act = edit + vote;
    const desc = f.desc ? `<p class="cc-d-desc">${escPend(f.desc)}${f.descTr?` <span class="cc-d-tr">· auto-translated</span>`:''}</p>` : '';
    // C1-T3 (spec W5): an empty placeholder for the async "Recent changes"
    // section — openDrawer() fetches GET /map/item/{id}/history after this
    // HTML lands and fills #cc-d-hist-slot (buildRecord itself stays sync/pure,
    // no network calls here). Same edit-bridge id contract as `edit`/`addPhoto`
    // above: only real DB items (f.id!=null) get one. The curator's pending-
    // submission records (below) never carry f.id, so they never render this —
    // see the note on the pending branch for why that view doesn't get one either.
    const histSlot = f.id!=null ? `<div class="cc-d-hist" id="cc-d-hist-slot" data-item="${f.id}"></div>` : '';
    // Point the "OpenStreetMap" source link at the object's location on OSM (its
    // feature-query view) rather than the OSM homepage. The served OSM features
    // carry no element (node/way) id, so a coordinate query is the closest we can
    // deep-link to the exact object the rider is looking at.
    const osmHref = (f.geom && f.geom.ll)
      ? `https://www.openstreetmap.org/query?lat=${f.geom.ll[0]}&lon=${f.geom.ll[1]}#map=18/${f.geom.ll[0]}/${f.geom.ll[1]}`
      : 'https://www.openstreetmap.org';
    return `<span class="cc-d-type" style="--c:${layer.color};color:${txtOn(layer.color)}">${layer.letter} · ${layer.label}</span>
      <div class="cc-d-name">${escPend(f.name)}</div>${cur}${photo}${desc}${diff}${elev}${grad}
      <ul class="cc-d-rec">${rows}</ul>${fresh}${up}
      <div class="cc-d-src">${D.source||'Source'} · ${escPend(f.source).replace(/^(OpenStreetMap|OSM)/, `<a href="${osmHref}" target="_blank" rel="noopener" style="color:var(--glacier);text-decoration:underline;text-underline-offset:2px">$1</a>`).replace(/(Géoportail de la Wallonie)/, '<a href="https://geoportail.wallonie.be/catalogue/91721175-5f01-410c-8c78-37c1d1893ba2.html" target="_blank" rel="noopener" style="color:var(--glacier);text-decoration:underline;text-underline-offset:2px">$1</a>')}</div>${confirmPanel}${act}${moderate}${histSlot}`;
  }
  // C1-T3: renders one change_history row. Every interpolated value is
  // user-contributed (old/new attribute values, and `who`/`when`/`changedAt`
  // are server-derived but still passed through escPend for defense in depth)
  // — same stored-XSS concern the §13 pending-submission fix addressed, so
  // ALL FIVE fields go through escPend before hitting innerHTML.
  function historyRow(h){
    const isEmpty = v => v===null || v===undefined || v==='';
    const ov = isEmpty(h.oldValue) ? '—' : escPend(h.oldValue);
    const nv = isEmpty(h.newValue) ? '—' : escPend(h.newValue);
    return `<li class="cc-h-row">
      <span class="cc-h-field">${escPend(h.field)}</span>
      <span class="cc-h-diff">${ov} → ${nv}</span>
      <span class="cc-h-meta">${escPend(h.who)} · <time datetime="${escPend(h.changedAt)}">${escPend(h.when)}</time></span>
    </li>`;
  }
  // Empty history (never-edited item) renders nothing — spec W5/C1-T3
  // acceptance: "no changes yet" is silence, not a section.
  function renderHistoryList(history){
    if(!Array.isArray(history) || !history.length) return '';
    return `<h4 class="cc-d-hist-h">${D.recentChanges||'Recent changes'}</h4><ul class="cc-d-hist-list">${history.map(historyRow).join('')}</ul>`;
  }
  // Fetches an item's change log (C1-T2's GET /map/item/{id}/history) and
  // fills the drawer's history slot. Lazy/async on purpose — never blocks
  // the drawer opening. Race-guarded: `myReq` is snapshotted from the shared
  // `_historyReq` counter, which openDrawer() bumps on every call; if a newer
  // drawer opened (or this one closed and another opened) before the response
  // lands, `myReq` no longer matches and the stale response is dropped. Fetch
  // failure is silent — history is an enhancement, not core drawer content.
  function loadItemHistory(itemId){
    const myReq = ++_historyReq;
    fetch('/map/item/' + itemId + '/history')
      .then(r => r.ok ? r.json() : null)
      .catch(()=>null)   // network/HTTP failure — enhancement only, stays silent (no exception to report)
      .then(data => {
        if(myReq !== _historyReq || !data) return;   // stale response — a newer drawer has since opened
        const slot = document.getElementById('cc-d-hist-slot');
        if(!slot) return;                              // drawer content changed/closed under us
        // A render bug here must not be indistinguishable from "no history yet" —
        // only the fetch/network stage above is allowed to fail silently.
        try {
          slot.innerHTML = renderHistoryList(data.history);
        } catch(e){
          console.error('Recent-changes history failed to render', e);
        }
      });
  }
  // Route community loop (spec §7). One authenticated fetch on drawer-open
  // carries counts + my-state + a stateless CSRF token; the three POSTs reuse it.
  const CC_BIKES=['Road','Gravel','MTB','E-bike','Handbike','Recumbent','Trike','Tandem'];
  const CC_SEASONS=['spring','summer','autumn','winter'];
  const CC_REASONS=[['broken-track',D.reasonBroken||'Wrong / broken track'],['trim-privacy',D.reasonPrivacy||'Trim a private start/end'],['duplicate',D.reasonDuplicate||'Duplicate of another route'],['not-rideable',D.reasonNotRideable||'Not actually rideable'],['other',D.reasonOther||'Something else']];
  const _rcTokens={};   // route id → CSRF token from the last snapshot

  // Votable point types (climbs/stays/scenic/history) carry the vote CTA;
  // routes (experience) have their own vote block. Non-votable UTILITIES are
  // confirmed, not voted: water carries a potable/not-potable judgement, the
  // rest a plain "still here?" confirmation. Keys match the CATALOG layer keys.
  const CC_VOTABLE=new Set(['climbs','stays','scenic','history']);
  const CC_CONFIRMABLE=new Set(['water','services','hazards','transit','shelter']);
  const _cfTokens={};   // item id → CSRF token from the last confirmations snapshot

  function routeCommunityPanel(id, state){
    const bikeL=I18N.bikes||{};
    const bikeOpts=CC_BIKES.map(b=>`<option value="${b}">${bikeL[b]||b}</option>`).join('');
    // Bike type has no safe default (it changes what a ride/vote means), so the
    // picker opens on a disabled placeholder — the rider must choose actively.
    const bikePickOpts=`<option value="" selected disabled>${D.bikeTypePh||'Bike type…'}</option>`+bikeOpts;
    const seasonOpts=CC_SEASONS.map(s=>`<option value="${s}">${CC_SEASON_LABEL[s]||s[0].toUpperCase()+s.slice(1)}</option>`).join('');
    const reasonOpts=CC_REASONS.map(([v,l])=>`<option value="${v}">${l}</option>`).join('');
    // Vote block only for verified routes (spec D7); rode-it for both.
    const voteBlock = state==='verified' ? `
      <div class="cc-rc-vote">
        <label class="cc-rc-l">${D.recommend||'Recommend it'} <span class="cc-rc-count" data-rc="votes"></span></label>
        <div class="cc-rc-row"><select class="cc-rc-season">${seasonOpts}</select><select class="cc-rc-vbike">${bikePickOpts}</select>
          <button class="cc-rc-btn" data-rc-act="vote">▲ ${D.vote||'Vote'}</button></div>
      </div>` : '';
    const rideProgress = state==='unverified' ? `<span class="cc-rc-count" data-rc="rides">…</span>` : '';
    return `<div class="cc-rc" data-route="${id}" data-state="${state||''}">
      <div class="cc-rc-ride">
        <label class="cc-rc-l">${D.rodeThis||'I rode this'} ${rideProgress}</label>
        <div class="cc-rc-row"><select class="cc-rc-rbike">${bikePickOpts}</select>
          <button class="cc-rc-btn" data-rc-act="rode-it">✓ ${D.rodeThis||'I rode this'}</button></div>
      </div>
      ${voteBlock}
      <details class="cc-rc-suggest"><summary>${D.suggestCorrection||'Suggest a correction'}</summary>
        <select class="cc-rc-reason">${reasonOpts}</select>
        <textarea class="cc-rc-note" placeholder="${D.optionalDetail||'Optional detail…'}"></textarea>
        <button type="button" class="cc-rc-mark" data-rc-mark="${id}">✎ ${D.markParts||'Mark the part(s) on the map'}</button>
        <span class="cc-rc-marks" data-rc-marks></span>
        <button class="cc-rc-btn" data-rc-act="suggest">${D.send||'Send'}</button>
      </details>
      <a class="cc-d-act edit" href="/routes/${id}.gpx">⤓ ${D.downloadGpx||'Download GPX'}</a>
      <div class="cc-rc-login" hidden>${D.loginRate||'Log in to rate this route'} · <a href="/login">${I18N.login||'Log in'}</a></div>
    </div>`;
  }

  // Called from openDrawer after the route drawer HTML lands.
  function hydrateRouteCommunity(id){
    const box=document.querySelector(`.cc-rc[data-route="${id}"]`); if(!box) return;
    // Restore the "N stretches marked" indicator if a picking session was already
    // committed for this route (e.g. drawer closed without Send, then reopened) —
    // otherwise the marks silently ride along on the next Send with no visible cue.
    const segs=_pickSegs[id];
    if(segs && segs.length){
      const m=box.querySelector('[data-rc-marks]');
      if(m) m.textContent = tpl((segs.length===1?D.marksOne:D.marksMany)||`· {n} stretch${segs.length===1?'':'es'} marked`, {n:segs.length});
    }
    fetch(`/routes/${id}/community`, {credentials:'same-origin', headers:{'Accept':'application/json'}})
      .then(r=>{ if(r.status===401||r.status===403){ box.querySelector('.cc-rc-login').hidden=false; box.classList.add('cc-rc-anon'); throw new Error('anon'); } if(!r.ok) throw new Error('community'); return r.json(); })
      .then(s=>{ _rcTokens[id]=s.token; paintRouteCommunity(box, s); })
      .catch(()=>{});
  }

  function paintRouteCommunity(box, s){
    const rides=box.querySelector('[data-rc="rides"]'); if(rides) rides.textContent=`· ${tpl(D.ridesProgress||'{n} of {m} to verify', {n:s.rideCount, m:s.threshold})}`;
    const votes=box.querySelector('[data-rc="votes"]'); if(votes) votes.textContent=s.voteCount?`· ${tpl((s.voteCount===1?D.voteOne:D.voteMany)||`{n} vote${s.voteCount>1?'s':''}`, {n:s.voteCount})}`:'';
    if(s.iRode){ const b=box.querySelector('[data-rc-act="rode-it"]'); if(b){ b.textContent=`✓ ${D.youRode||'You rode this'}`; b.disabled=true; } }
    if(s.iVotedThisSeason){ const b=box.querySelector('[data-rc-act="vote"]'); if(b){ b.textContent=`✓ ${D.votedSeason||'Voted this season'}`; b.disabled=true; } }
  }

  // --- Community confirmations for non-votable utilities (water potability /
  // "still here?"). Public counts, login to confirm — mirrors the route
  // community loop but simpler (one toggle-able stance per rider). ---
  const CC_CF_STANCES={
    potability:[['potable',`✓ ${D.potable||'Potable'}`],['not_potable',`✗ ${D.notPotable||'Not potable'}`]],
    existence:[['exists',`✓ ${D.confirmHere||'Confirm it’s here'}`]],
  };
  function hydrateItemConfirm(id){
    const box=document.querySelector(`.cc-cf[data-item="${id}"]`); if(!box) return;
    fetch(`/items/${id}/confirmations`, {credentials:'same-origin', headers:{'Accept':'application/json'}})
      .then(r=>{ if(!r.ok) throw new Error('confirm'); return r.json(); })
      .then(s=>{ if(s.token) _cfTokens[id]=s.token; paintItemConfirm(box, s); })
      .catch(()=>{});   // enhancement only — never blocks the drawer
  }
  function paintItemConfirm(box, s){
    const authed=!!s.token;
    const defs=CC_CF_STANCES[s.stanceKind]||CC_CF_STANCES.existence;
    const heading = s.stanceKind==='potability'
      ? (D.waterQ||'Is the water drinkable?') : (D.hereQ||'Is this still here?');
    const btns=defs.map(([v,l])=>{
      const n=(s.stances&&s.stances[v])||0;
      const mine=s.mine===v?' is-mine':'';
      return `<button class="cc-cf-btn${mine}" data-cf-act="${v}"${authed?'':' disabled'}>${l} <span class="cc-cf-n">${n}</span></button>`;
    }).join('');
    const total = s.total ? `<span class="cc-cf-total">· ${tpl((s.total===1?D.confirmedOne:D.confirmedMany)||`{n} rider${s.total===1?'':'s'} confirmed`, {n:s.total})}</span>` : '';
    box.querySelector('[data-cf-body]').innerHTML =
      `<div class="cc-cf-h">${heading} ${total}</div><div class="cc-cf-row">${btns}</div>`;
    box.querySelector('.cc-cf-login').hidden = authed;
  }
  // Delegated: clicking a stance button records/switches it, then repaints.
  document.addEventListener('click', e=>{
    const btn=e.target.closest('[data-cf-act]'); if(!btn) return;
    const box=btn.closest('.cc-cf'); if(!box) return;
    const id=box.getAttribute('data-item'), token=_cfTokens[id];
    if(!token){ mapToast(D.toastLoginConfirm||'Please log in to confirm.'); return; }
    const body=new URLSearchParams(); body.set('_token', token); body.set('stance', btn.getAttribute('data-cf-act'));
    box.querySelectorAll('.cc-cf-btn').forEach(b=>b.disabled=true);
    fetch(`/items/${id}/confirm`, {method:'POST', credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
      .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
      .then(s=>{ paintItemConfirm(box, s); mapToast(D.toastThanks||'Thanks — recorded.'); })
      .catch(()=>{ box.querySelectorAll('.cc-cf-btn').forEach(b=>b.disabled=false); mapToast(D.toastErr||'Could not record that — please try again.'); });
  });

  // Flag a picker the rider left on its placeholder: red border + focus + toast.
  function warnPick(sel, msg){ if(sel){ sel.classList.add('cc-rc-invalid'); sel.focus(); } mapToast(msg); }
  function rcPost(box, act){
    const id=box.dataset.route, token=_rcTokens[id];
    if(!token){ mapToast(D.toastLoginRate||'Please log in to rate routes.'); return; }
    const body=new URLSearchParams(); body.set('_token', token);
    // Bike type must be actively chosen (no default) — block + warn if empty.
    if(act==='rode-it'){
      const sel=box.querySelector('.cc-rc-rbike');
      if(!sel.value){ warnPick(sel, D.pickBikeRode||'Pick the bike type you rode it on first.'); return; }
      body.set('bike_type', sel.value);
    }
    if(act==='vote'){
      const sel=box.querySelector('.cc-rc-vbike');
      if(!sel.value){ warnPick(sel, D.pickBikeVote||'Pick a bike type to recommend it for first.'); return; }
      body.set('season', box.querySelector('.cc-rc-season').value); body.set('bike_type', sel.value);
    }
    if(act==='suggest'){
      body.set('reason', box.querySelector('.cc-rc-reason').value);
      body.set('note', box.querySelector('.cc-rc-note').value);
      const segs=_pickSegs[id]; if(segs && segs.length) body.set('segments', JSON.stringify(segs));
    }
    box.querySelectorAll('.cc-rc-btn').forEach(b=>b.disabled=true);
    fetch(`/routes/${id}/${act}`, {method:'POST', credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
      .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
      .then(s=>{
        box.querySelectorAll('.cc-rc-btn').forEach(b=>b.disabled=false);
        if(act==='suggest'){ delete _pickSegs[id]; const m=box.querySelector('[data-rc-marks]'); if(m) m.textContent=''; mapToast(D.toastCurator||'Thanks — a curator will review it.'); box.querySelector('.cc-rc-suggest').open=false; box.querySelector('.cc-rc-note').value=''; return; }
        paintRouteCommunity(box, s);
        if(act==='rode-it' && s.state==='verified' && box.dataset.state==='unverified'){ mapToast(D.toastVerified||'Verified — thanks for confirming this route!'); box.dataset.state='verified'; }
        else mapToast(D.toastRecorded||'Recorded — thanks!');
      })
      .catch(err=>{ box.querySelectorAll('.cc-rc-btn').forEach(b=>b.disabled=false); mapToast(err.message==='429'?(D.toastLimit||'Daily limit reached — try again tomorrow.'):(D.toastErr||'Could not record that — please try again.')); });
  }

  // --- Located-correction picking mode (spec §16 S1). Segments captured per
  // route id, kept until a successful "suggest" POST consumes and clears them. ---
  const _pickSegs={};   // route id → list<{start,end}> captured for the open suggest form
  let _pick=null;       // active picking session or null

  // Delegated: the "Mark on map" button starts picking for the drawer's route.
  document.addEventListener('click', e=>{
    const mb=e.target.closest('[data-rc-mark]'); if(!mb) return;
    startPicking(mb.getAttribute('data-rc-mark'));
  });

  function routePathById(id){
    const layer=layerByKey['experience']; if(!layer) return null;
    const f=layer.features.find(x=>String(x.id)===String(id));
    return f && f.geom && f.geom.path ? f.geom.path : null;
  }

  function startPicking(routeId){
    if(_pick) return;   // re-entrancy guard: don't orphan an in-progress session
    const path=routePathById(routeId); if(!path){ mapToast(D.toastOpenRoute||'Open the route first.'); return; }
    _pick={ routeId, path, points:[], markers:[], segLayers:[] };
    document.querySelector('.cc-drawer')?.classList.add('cc-drawer-min');   // minimise so the map is clickable
    map.getCanvas().style.cursor='crosshair';
    showPickBar();
    // click on the route line drops a snapped point
    map.on('click', pickClick);
  }
  function pickClick(e){
    if(!_pick) return;
    const snap=nearestOnPath(_pick.path, [e.lngLat.lat, e.lngLat.lng]); if(!snap) return;
    // ignore clicks far from the line (>~30 m in deg ≈ 3e-4)
    if(Math.sqrt(snap.d2) > 3e-4) return;
    _pick.points.push(snap.frac);
    const n=_pick.points.length;
    const el=document.createElement('div'); el.className='cc-pick-pin'; el.textContent=String(n);
    _pick.markers.push(new maplibregl.Marker({element:el,anchor:'center'}).setLngLat([snap.at[1],snap.at[0]]).addTo(map));
    redrawPickSegments();
    updatePickBar();
  }
  // Click-to-scope (2026-07-22-scope-selector-scale-design.md §C): a left-click
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
    _corrLayers.forEach(id=>ids.push(id));           // curator correction-segment previews
    return ids.filter(id=>map.getLayer(id));
  }
  map.on('click', async e=>{
    if(_pick) return;                                    // stretch-picking owns the click
    if(map.queryRenderedFeatures(e.point, {layers:selectableLayers()}).length) return;  // a feature layer will handle it
    if(!window.CCScope) return;
    // Precise resolution refines an ambiguous click (2+ overlapping region bboxes)
    // against the real polygon; deep inside one region it is synchronous-fast, no
    // fetch (2026-07-24-region-adjacency-and-click-refinement-design.md §3.2).
    const r=await window.CCScope.regionOfPointPrecise(e.lngLat.lng, e.lngLat.lat);
    if(!r) return;
    // Same-region click is a no-op (final review CRITICAL 1): bail before setRegion
    // so an empty-map click inside the ALREADY-active region doesn't re-fit the
    // camera + re-fetch coverage counts + re-render on every stray click.
    const s=curScope();
    if(s.kind==='region' && s.regionIds[0]===r.id) return;
    window.CCScope.setRegion(r.slug);   // cc:scopechange -> applyScope + renderScopeChips
  });
  function redrawPickSegments(){
    _pick.segLayers.forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); if(map.getSource(id)) map.removeSource(id); });
    _pick.segLayers=[];
    const pts=_pick.points;
    for(let i=0;i+1<pts.length;i+=2){
      const id=`pickseg-${i}`, coords=sliceByFrac(_pick.path, pts[i], pts[i+1]).map(p=>[p[1],p[0]]);
      map.addSource(id,{type:'geojson',data:{type:'Feature',geometry:{type:'LineString',coordinates:coords}}});
      map.addLayer({id,type:'line',source:id,layout:{'line-cap':'round','line-join':'round'},paint:{'line-color':'#FF5A1F','line-width':8,'line-opacity':.9}});
      _pick.segLayers.push(id);
    }
  }
  function pickSegments(){ // fold the ordered points into {start,end} pairs (drop a lone trailing point)
    // Normalize start ≤ end regardless of click order (mirrors sliceByFrac's swap) —
    // the server rejects start > end with 422 invalid_segments.
    const p=_pick.points, out=[]; for(let i=0;i+1<p.length;i+=2){ const a=p[i], b=p[i+1]; out.push({start:Math.min(a,b), end:Math.max(a,b)}); } return out;
  }
  function showPickBar(){
    let bar=document.getElementById('cc-pickbar');
    if(!bar){ bar=document.createElement('div'); bar.id='cc-pickbar'; bar.className='cc-pickbar'; document.body.appendChild(bar); }
    bar.innerHTML=`<span class="cc-pickbar-t"></span>
      <button data-pick="undo">↶ ${D.undo||'Undo'}</button><button data-pick="clear">${D.clear||'Clear'}</button><button data-pick="done" class="on">${D.done||'Done'}</button>`;
    bar.hidden=false; updatePickBar();
    bar.onclick=e=>{ const b=e.target.closest('[data-pick]'); if(!b) return; pickAction(b.dataset.pick); };
  }
  function updatePickBar(){
    const t=document.querySelector('#cc-pickbar .cc-pickbar-t'); if(!t) return;
    const done=Math.floor(_pick.points.length/2), pending=_pick.points.length%2;
    t.textContent = pending
      ? tpl(D.pointSet||'Point {n} set — click the end of this stretch', {n:_pick.points.length})
      : tpl((done===1?D.barOne:D.barMany)||`{n} stretch${done===1?'':'es'} marked — click to start another, or Done`, {n:done});
  }
  function pickAction(a){
    if(a==='undo'){ _pick.points.pop(); const m=_pick.markers.pop(); if(m) m.remove(); redrawPickSegments(); updatePickBar(); return; }
    if(a==='clear'){ _pick.points=[]; _pick.markers.forEach(m=>m.remove()); _pick.markers=[]; redrawPickSegments(); updatePickBar(); return; }
    if(a==='done'){ finishPicking(); }
  }
  function finishPicking(){
    if(!_pick) return;
    _pickSegs[_pick.routeId]=pickSegments();
    map.off('click', pickClick); map.getCanvas().style.cursor='';
    _pick.markers.forEach(m=>m.remove()); _pick.segLayers.forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); if(map.getSource(id)) map.removeSource(id); });
    const rid=_pick.routeId; _pick=null;
    document.getElementById('cc-pickbar').hidden=true;
    document.querySelector('.cc-drawer')?.classList.remove('cc-drawer-min');
    const marks=document.querySelector(`.cc-rc[data-route="${rid}"] [data-rc-marks]`);
    const n=(_pickSegs[rid]||[]).length; if(marks) marks.textContent = n ? tpl((n===1?D.marksOne:D.marksMany)||`· {n} stretch${n===1?'':'es'} marked`, {n}) : '';
  }
  // Tear down an in-progress picking session without committing it to
  // _pickSegs (used when the drawer itself closes mid-pick — see closeDrawer).
  function cancelPicking(){
    if(!_pick) return;
    map.off('click', pickClick); map.getCanvas().style.cursor='';
    _pick.markers.forEach(m=>m.remove()); _pick.segLayers.forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); if(map.getSource(id)) map.removeSource(id); });
    _pick=null;
    const bar=document.getElementById('cc-pickbar'); if(bar) bar.hidden=true;
    document.querySelector('.cc-drawer')?.classList.remove('cc-drawer-min');
  }

  // Delegated click handler for every community button (drawer is re-rendered often).
  document.addEventListener('click', e=>{
    const btn=e.target.closest('[data-rc-act]'); if(!btn) return;
    const box=btn.closest('.cc-rc'); if(!box) return;
    rcPost(box, btn.dataset.rcAct);
  });
  // Clear the "must pick a bike type" warning as soon as the rider chooses one.
  document.addEventListener('change', e=>{ const s=e.target.closest('.cc-rc-invalid'); if(s) s.classList.remove('cc-rc-invalid'); });
  function mapToast(msg){
    let t=document.getElementById('cc-toast');
    if(!t){ t=document.createElement('div'); t.id='cc-toast'; t.className='cc-toast'; document.body.appendChild(t); }
    t.textContent=msg; t.classList.add('show');
    clearTimeout(mapToast._t); mapToast._t=setTimeout(()=>t.classList.remove('show'),3200);
  }
  function hidePendingPin(id){
    const layer=layerByKey.pending; if(!layer) return;
    layer.features=layer.features.filter(f=>!(f.pending && String(f.pending.id)===String(id)));
    if(_searchDropPending) _searchDropPending(id);   // keep the search index in step (W36)
    render();
  }
  // set by the sidebar-search block below (it owns SEARCH_IDX); null until then
  let _searchDropPending=null;
  // Stateless same-origin CSRF: the decision form carries a _token placeholder tied
  // to the csrf-token cookie (HttpOnly → unreadable from JS). The map page renders no
  // such form, so fetch one token from /moderate and reuse it (stable for the session);
  // a failed decision clears it so the next attempt re-fetches a fresh one.
  let _modToken;
  function moderationToken(){
    // The decision CSRF token is emitted directly on the map page
    // (window.CC_MOD_TOKEN) — approval happens only here now, so there is no
    // /moderate decision form to scrape. Reject if it is somehow absent so the
    // caller's error state fires rather than a silent CSRF failure later.
    if(_modToken) return _modToken;
    _modToken = window.CC_MOD_TOKEN
      ? Promise.resolve(window.CC_MOD_TOKEN)
      : Promise.reject(new Error('moderation token not available'));
    return _modToken;
  }
  function submitModeration(btn){
    const box=btn.closest('.cc-mod'); if(!box) return;
    const id=box.dataset.id, decision=btn.dataset.decision;
    const note=(box.querySelector('.cc-mod-note')||{}).value||'';
    box.querySelectorAll('.cc-mod-btn').forEach(b=>b.disabled=true);
    moderationToken().then(token=>{
      const body=new URLSearchParams();
      body.set('moderation_decision[submission_id]', id);
      body.set('moderation_decision[decision]', decision);
      body.set('moderation_decision[note]', note);
      body.set('moderation_decision[_token]', token);
      return fetch('/moderate/decide', { method:'POST', credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'},
        body:body.toString() });
    })
      .then(r=>{ if(!r.ok) throw new Error('decide'); return r.json(); })
      .then(res=>{ hidePendingPin(id); closeDrawer(); mapToast(tpl(D.decisionRecorded||'Decision recorded ({d}) — preview, not yet persisted · {ref}', {d:decision.replace('_',' '), ref:res.reference})); })
      .catch(()=>{ _modToken=undefined; box.querySelectorAll('.cc-mod-btn').forEach(b=>b.disabled=false); mapToast(D.decisionErr||'Could not record the decision — please try again.'); });
  }
  function gradStrip(grad){
    const max=Math.max(...grad), avg=Math.round(grad.reduce((a,b)=>a+b,0)/grad.length);
    const bars=grad.map(p=>`<span class="cc-grad-bar" style="height:${Math.round(10+(p/Math.max(max,1))*30)}px;background:${gradColor(p)}" title="${p}%"></span>`).join('');
    return `<div class="cc-elev-cap">${tpl(D.gradProfile||'Gradient profile · avg ~{a}% · max {m}%', {a:avg, m:max})} <em>${D.illustrative||'(illustrative)'}</em></div>
      <div class="cc-grad">${bars}</div>`;
  }
  function elevSvg(elev){
    const w=300,h=64,pad=3,min=Math.min(...elev),max=Math.max(...elev),rng=Math.max(1,max-min);
    const xy=elev.map((e,i)=>[pad+i/(elev.length-1)*(w-2*pad), h-pad-((e-min)/rng)*(h-2*pad)]);
    const line=xy.map(p=>p[0].toFixed(1)+','+p[1].toFixed(1)).join(' ');
    return `<svg class="cc-elev" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" role="img" aria-label="${D.elevAria||'Elevation profile'}">
      <polygon points="${pad},${h-pad} ${line} ${w-pad},${h-pad}" fill="rgba(255,90,31,.16)"/>
      <polyline points="${line}" fill="none" stroke="#FF5A1F" stroke-width="1.6"/></svg>`;
  }
  // Content-only body render, shared by openDrawer and the coverage detail
  // repaint (openCoverageDrawer's enrich-later step). (Re)writes #drawerBody
  // + its content-scoped wiring/hydrations, and deliberately does NOT touch
  // the open state, selection halo, focus or the mobile sheet snap — so a
  // progressive-hydration repaint (the loadItemHistory/hydrateItemConfirm
  // convention) never yanks the bottom sheet back to half or re-steals focus.
  function renderDrawerBody(layer, f){
    document.getElementById('drawerBody').innerHTML = buildRecord(layer, f);
    if(layer.key==='experience' && f.id!=null) hydrateRouteCommunity(f.id);
    if(CC_CONFIRMABLE.has(layer.key) && f.id!=null) hydrateItemConfirm(f.id);
    // C1-T3: async "Recent changes" — see loadItemHistory for the race guard.
    // Pending (moderation) features carry no f.id; when they target a real
    // item (f.pending.itemId, i.e. an edit — never a brand-new submission,
    // which renders "Initial entry" synchronously above with no fetch) fetch
    // that item's history instead, into the same #cc-d-hist-slot rendered by
    // buildRecord's pending branch.
    if(f.pending){
      if('new' !== f.pending.type && f.pending.itemId!=null) loadItemHistory(f.pending.itemId);
    } else if(f.id!=null){
      loadItemHistory(f.id);
    }
    const pl = photoList(f);
    const mainImg = document.querySelector('#drawerBody .cc-d-photo > img');
    const cap = document.getElementById('cc-d-cap');
    let cur = 0;
    function show(i){ cur=i; if(mainImg) mainImg.src=pl[i].sm; if(cap) cap.innerHTML=photoCap(pl[i]);
      document.querySelectorAll('#drawerBody .cc-d-thumb').forEach((t,k)=>t.classList.toggle('on',k===i)); }
    if(mainImg && pl.length) mainImg.addEventListener('click', ()=>openLightbox(pl, cur, f.name));
    document.querySelectorAll('#drawerBody .cc-d-thumb').forEach(t=>t.addEventListener('click', ()=>show(+t.dataset.i)));
    document.querySelectorAll('#drawerBody .cc-city').forEach(a=>{
      a.addEventListener('click', e=>{ e.preventDefault(); openCity(a.dataset.city); });
      a.addEventListener('mouseenter', ()=>{ const c=CITIES[a.dataset.city]; if(c) highlightAt(c.ll); });
      a.addEventListener('mouseleave', clearHighlight);
    });
    document.querySelectorAll('#drawerBody .cc-mod-btn').forEach(btn=>{
      btn.addEventListener('click', ()=>submitModeration(btn));
    });
  }
  function openDrawer(layer, f){
    // Guard (spec §16 S1): while picking correction stretches, the route line
    // still carries its normal layer click handler (drawLine's map.on('click',
    // 'experience-'+i, ()=>openDrawer(...))) — a click meant to drop a picking
    // point would ALSO fire that handler and open/switch the drawer under the
    // rider's feet. Bail out here so picking clicks never re-open a drawer;
    // pickClick (bound separately) still gets the same click event and drops
    // the point normally.
    if(_pick) return;
    clearRevealPin();   // decision C: a new pick supersedes any reveal pin (call sites re-drop after)
    clearSelectedCoverageIcon();   // drop the previous coverage selection's icon overlay; openCoverageDrawer re-adds it right after this returns
    invalidateCoverageDrawer(); _placeReq++;   // invalidate any in-flight coverage POI detail + town-card nearby fetch — this render supersedes them
    // Route selection emphasis: covers both the click path and the ?feature=
    // deep-link (both funnel through here). Layer id convention: the K line
    // layers are `experience-<feature index>` (see the drawLine call site).
    if(layer.key==='experience'){
      const i=layer.features.indexOf(f);
      if(i>=0 && map.getLayer('experience-'+i)) highlightRoute('experience-'+i);
      clearHighlight();                    // routes read as the wide line halo, not a point halo
    } else {
      clearRouteHighlight();
      // Pulsing selection halo on the clicked point — curated AND OSM — so the
      // selected place stands out; persists while the drawer is open and is
      // cleared by closeDrawer()/the next open. highlightAt(null) no-ops.
      // Confirmed items render as bottom-anchored teardrop pins whose icon sits
      // ~16px above the ground point, so raise the halo to ring the icon; flat
      // WebGL dots (unverified OSM) are centred on the point → no offset.
      // PENDING (moderation) pins are bottom-anchored teardrops too → same lift.
      // Climbs place their pin at the route START (foot), not geom.ll — the
      // halo must ring the pin the rider actually sees, not the centroid.
      const hlAt = f.route ? f.route[0] : (f.geom && f.geom.ll);   // [lat,lng] arrays both
      highlightAt(hlAt, (f.cur || f.pending) ? [0,-16] : [0,0]);
    }
    renderDrawerBody(layer, f);
    const d=document.getElementById('drawer'); d.classList.add('open'); d.setAttribute('aria-hidden','false');
    d.focus({preventScroll:true});   // move focus into the panel (not the close X — avoids a focus ring on tap/click open)
    if(window.innerWidth<=820) sheet.reset();          // land at half; desktop untouched
  }
  // place info card: fly to the town/village, show its info (when known) +
  // everything in the Commons within 5 km, grouped by layer. Works for any
  // geocoded place (spec 2026-07-14 §3.3): CITIES entries keep their wiki/info
  // blurbs; Photon hits pass just {ll}.
  function openPlace(name, meta){
    // Town outside the current scope → transiently widen (persist:false, the
    // deep-link mechanism), so the map matches the scope-exempt town drawer
    // instead of zooming into an area the scope renders empty (owner decision
    // 2026-07-21, map-and-search.md §4.5; opening a town is an explicit
    // location choice — the map should follow it). Bbox containment is the
    // deliberate approximation: a town inside the scope bbox already renders
    // its surroundings, so no widen is needed there. The saved scope returns
    // on the next plain load.
    const sbb = window.CCScope && window.CCScope.bbox ? window.CCScope.bbox() : null;
    if(sbb && (meta.ll[1]<sbb[0] || meta.ll[0]<sbb[1] || meta.ll[1]>sbb[2] || meta.ll[0]>sbb[3])){
      widenForDeepLink();
    }
    // A · Road surface segments are corridor data, not places — near any mapped
    // town they'd flood the card (Spa: 58 rows). Text search still finds them.
    const near = nearbyItems(meta.ll, 5).filter(n=>n.e.letter!=='A');
    renderPlaceCard(name, meta, near);
    // Frame the whole ≤5 km neighbourhood instead of flyToPin's zoom-14 dive —
    // hovering the list must pulse items that are actually on screen. The
    // drawer covers the right edge on desktop (bottom sheet on mobile), hence
    // the asymmetric padding. Framed ONCE, from the local rows: the coverage
    // re-render below must not re-jump the camera.
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
    // Coverage tier (coverage-provider.md §5): uncurated OSM
    // within the same 5 km from /map/coverage/nearby, merged behind the local
    // rows per letter group. Photon-style silent degradation — the local card
    // is already on screen; a slow/failed response changes nothing.
    if(!COVERAGE_ON) return;
    const myReq=++_placeReq;
    fetch(`/map/coverage/nearby?lat=${meta.ll[0]}&lng=${meta.ll[1]}&km=5`, {headers:{'Accept':'application/json'}})
      .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
      .then(d=>{ if(myReq!==_placeReq) return;
        if(!document.getElementById('drawer').classList.contains('open')) return;   // card closed while in flight
        renderPlaceCard(name, meta, near, d.groups||[]); })
      .catch(()=>{});
  }
  // town-card body renderer — split from openPlace so the coverage nearby
  // response re-renders the list without re-running the framing. Row order:
  // local (curated/served) rows nearest-first, then coverage rows appended
  // inside the same letter groups. Coverage items with a curated twin already
  // in the local index are dropped (IDX_IDS — dedupe by served item id).
  // (_placeReq is declared above; its coverage half lives in coverage.js.)
  function renderPlaceCard(name, meta, near, covGroups){
    const all=near.slice();
    (covGroups||[]).forEach(g=>{
      const key=LETTER_KEY[g.letter], layer=key&&layerByKey[key]; if(!layer) return;
      (g.items||[]).forEach(it=>{
        if(!it || !Array.isArray(it.ll)) return;
        if(it.itemId!=null && idxIds().has(g.letter+':'+it.itemId)) return;
        all.push({dist:haversine(meta.ll, it.ll), e:{name:it.n||layer.label, kind:layer.label,
          badge:g.letter, color:layer.color, letter:g.letter, ll:it.ll, hlOff:[0,0], community:!it.curated,
          go:()=>openCoverageByRef(it.ref, g.letter, it.ll, it.n)}});
      });
    });
    // group rows by letter, keeping the global nearest-first order inside each group
    const byLetter={};
    all.forEach((n,i)=>{ n._i=i; (byLetter[n.e.letter]=byLetter[n.e.letter]||[]).push(n); });
    const letters=Object.keys(byLetter).sort();
    const isComm=n=>n.e.community || n.e.verified===false;
    const list = all.length
      ? letters.map(L=>{ const rows=byLetter[L], e0=rows[0].e;
          // 07-15 decision A: verified/curated rows first, then the community
          // subgroup capped at 3 behind a "show all N" expander. Same collapsed
          // presentation on mobile (decision F) — one code path.
          const ver=rows.filter(n=>!isComm(n)), com=rows.filter(isComm);
          const row=(n,hidden)=>`<li${hidden?` hidden data-more="${L}"`:''}><button class="cc-near" data-i="${n._i}"><span class="cc-near-nm">${escPend(n.e.name)}${isComm(n)?`<span class="cc-comm-tag">${escPend(D.community||'community')}</span>`:''}</span><em>${n.dist<1?Math.round(n.dist*1000)+' m':n.dist.toFixed(1)+' km'}</em></button></li>`;
          let html=`<li class="cc-near-grp"><span class="cc-near-k" style="background:${e0.color};color:${txtOn(e0.color)}">${escPend(e0.badge)}</span>${escPend(e0.kind)} · ${rows.length}</li>`;
          html+=ver.map(n=>row(n,false)).join('');
          html+=com.slice(0,3).map(n=>row(n,false)).join('');
          html+=com.slice(3).map(n=>row(n,true)).join('');
          if(com.length>3) html+=`<li><button class="cc-near-more" data-grp="${L}">${escPend(tpl(D.showAll||'show all {n}',{n:com.length}))}</button></li>`;
          return html;
        }).join('')
      : `<li class="cc-near-empty">${D.nothingHere||'Nothing mapped here yet — be the first to add something.'}</li>`;
    invalidateCoverageDrawer();   // invalidate any in-flight coverage POI detail — this render supersedes it
    document.getElementById('drawerBody').innerHTML =
      `<span class="cc-d-type" style="--c:#3E7D8C;color:#fff">◎ ${meta.t==='City'?(D.city||'City'):(D.town||'Town')}</span>
       <div class="cc-d-name">${escPend(name)}</div>
       ${meta.info?`<div class="cc-city-info">${meta.info}</div>`:''}
       <div class="cc-city-links">${meta.wiki?`<a href="${meta.wiki}" target="_blank" rel="noopener">Wikipedia ↗</a> · `:''}<span class="cc-city-ua">${D.notesNone||'community notes — none yet'}</span></div>
       <h4 class="cc-near-h">${D.nearbyH||'In the Commons nearby · ≤ 5 km'}</h4>
       <ul class="cc-near-list">${list}</ul>`;
    document.querySelectorAll('#drawerBody .cc-near').forEach(b=>{
      const n=all[+b.dataset.i];
      b.onclick=()=>n.e.go();
      b.onmouseenter=()=>highlightAt(n.e.ll, n.e.hlOff); b.onmouseleave=clearHighlight;
    });
    // expander: reveal the collapsed community rows of one group, then retire itself
    document.querySelectorAll('#drawerBody .cc-near-more').forEach(b=>{
      b.onclick=()=>{ document.querySelectorAll(`#drawerBody li[data-more="${b.dataset.grp}"]`).forEach(li=>li.hidden=false);
        b.closest('li').hidden=true; };
    });
    const d=document.getElementById('drawer'); d.classList.add('open'); d.setAttribute('aria-hidden','false');
    d.focus({preventScroll:true});   // move focus into the panel (not the close X — avoids a focus ring on tap/click open)
    if(window.innerWidth<=820) sheet.reset();          // land at half; desktop untouched
  }
  // city info card — thin CITIES-lookup wrapper kept for existing callers (drawer .cc-city links, search)
  function openCity(name){
    const c = CITIES[name]; if(!c) return;
    openPlace(name, c);
  }
  // Resolve a name to a CATALOG feature or PIVOT stay WITHOUT side effects —
  // shared by openFeatureByName and the deep-link auto-widen gate (07-20
  // review finding 9), so "does this deep link resolve?" can be asked before
  // any scope change or drawer open, and the two lookups can never drift.
  function resolveLocalFeature(name){
    let found=null;
    CATALOG.forEach(layer=>layer.features.forEach(f=>{ if(f.name===name) found={layer,f}; }));
    if(found) return found;
    // PIVOT stays live in CC_STAYS_PIVOT, not CATALOG — resolve them here so
    // an official Tourisme Wallonie deep-link opens the full stay drawer
    // (name, province, official-registry provenance) instead of falling
    // through to the coverage path, whose /poi endpoint knows only node|way
    // refs and 404s on fx:pivot:/manual: source_refs.
    const pv=(window.CC_STAYS_PIVOT && CC_STAYS_PIVOT.features || [])
      .find(f=>f.properties && f.properties.n===name);
    return pv ? {pivot:pv} : null;
  }
  // open a specific feature by name (deep-link from e.g. a profile page): activate its layer, draw, zoom in
  function openFeatureByName(name){
    const found=resolveLocalFeature(name);
    if(!found) return false;
    if(found.pivot){
      const pv=found.pivot;
      const c=pv.geometry && pv.geometry.coordinates;
      if(c && c.length>=2) flyToPin([+c[0],+c[1]]);
      openStayPivot(pv);
      return true;
    }
    return openLocalFeature(found.layer, found.f);
  }
  // Open an ALREADY-resolved CATALOG feature (no name lookup) — the
  // side-effecting half of openFeatureByName, shared by the index/place-card
  // `go` so a nameless feature opens its exact pin rather than a name-slug guess.
  function openLocalFeature(layer, f){
    if(!active.has(layer.key)){
      active.add(layer.key);
      const t=document.querySelector(`#layers .layer[data-key="${layer.key}"]`); if(t) t.classList.remove('off');
      render();
    }
    openDrawer(layer,f);
    const p=featurePoint(f); if(p) flyToPin([p[1],p[0]]);
    return true;
  }
  // open a specific route by id, SELECTED (deep-link from the curator Routes desk).
  // Curated mode only draws best-of routes; a non-best-of route now gets a
  // single reveal pin at its start (07-15 decision C) instead of the old
  // force-switch of the whole map into Everything.
  function openRouteById(id){
    const layer=layerByKey['experience']; if(!layer) return false;
    const f=layer.features.find(x=>String(x.id)===String(id));
    if(!f) return false;
    openDrawer(layer,f);
    const p=featurePoint(f); if(p) flyToPin([p[1],p[0]]);
    if(!(mode()==='all'||f.cur) && p) revealPinAt(layer, p);
    showRouteCorrections(id);
    return true;
  }
  // §16 S3/S5: curator-only pending-corrections overlay — one colour per
  // correction, numbered stretch endpoints, bottom-left side list. The
  // /corrections endpoint 403s for non-curators; that's treated as "no
  // corrections" (renderCorrections never runs, nothing leaks).
  const CC_CORR_COLORS=['#FF5A1F','#3E9C8A','#C8923A','#6E7B96','#B5532E','#8FB6A8','#5F5A54','#6E5849'];
  let _corrLayers=[], _corrMarkers=[];
  function clearCorrections(){
    _corrLayers.forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); if(map.getSource(id)) map.removeSource(id); });
    _corrMarkers.forEach(m=>m.remove()); _corrLayers=[]; _corrMarkers=[];
    document.getElementById('cc-corrpanel')?.remove();
  }
  function showRouteCorrections(routeId){
    const path=routePathById(routeId); if(!path) return;
    fetch(`/routes/${routeId}/corrections`, {credentials:'same-origin', headers:{'Accept':'application/json'}})
      .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
      .then(d=>renderCorrections(path, d.corrections||[]))
      .catch(()=>clearCorrections());   // 403 (not curator) / error → clear any stale overlay
  }
  function renderCorrections(path, corrections){
    clearCorrections();
    if(!corrections.length) return;
    let ptN=0;
    corrections.forEach((c,ci)=>{
      const color=CC_CORR_COLORS[ci%CC_CORR_COLORS.length];
      c._color=color; c._pins=[];
      (c.segments||[]).forEach((seg,si)=>{
        const id=`corr-${c.id}-${si}`, coords=sliceByFrac(path, seg.start, seg.end).map(p=>[p[1],p[0]]);
        map.addSource(id,{type:'geojson',data:{type:'Feature',geometry:{type:'LineString',coordinates:coords}}});
        map.addLayer({id,type:'line',source:id,layout:{'line-cap':'round','line-join':'round'},paint:{'line-color':color,'line-width':7,'line-opacity':.92}});
        _corrLayers.push(id);
        [seg.start, seg.end].forEach(fr=>{ ptN++; const at=fracToLatLng(path,fr);
          const el=document.createElement('div'); el.className='cc-corr-pin'; el.style.background=color; el.textContent=String(ptN);
          _corrMarkers.push(new maplibregl.Marker({element:el,anchor:'center'}).setLngLat([at[1],at[0]]).addTo(map));
          c._pins.push(ptN);
        });
      });
    });
    buildCorrPanel(corrections, path);
  }
  function buildCorrPanel(corrections, path){
    const p=document.createElement('div'); p.id='cc-corrpanel'; p.className='cc-corrpanel';
    p.innerHTML=`<h4>Pending corrections</h4>`+corrections.map(c=>`
      <button class="cc-corr-item" data-corr="${c.id}">
        <span class="cc-corr-sw" style="background:${c._color}"></span>
        <span class="cc-corr-body"><b>${c.reason.replace(/-/g,' ')}</b>${c.note?` — ${escPend(c.note)}`:''}
          <em>${(c.segments||[]).length} stretch${(c.segments||[]).length===1?'':'es'}${c._pins&&c._pins.length?` · ${c._pins.join('→')}`:''}</em></span>
      </button>`).join('');
    document.querySelector('.mapwrap, .app, body').appendChild(p);
    p.onclick=e=>{ const b=e.target.closest('[data-corr]'); if(!b) return;
      const c=corrections.find(x=>String(x.id)===b.dataset.corr); if(!c||!c.segments||!c.segments.length) return;
      // zoom to this correction's first stretch
      const seg=c.segments[0], a=fracToLatLng(path,seg.start), b2=fracToLatLng(path,seg.end);
      map.fitBounds([[Math.min(a[1],b2[1]),Math.min(a[0],b2[0])],[Math.max(a[1],b2[1]),Math.max(a[0],b2[0])]],{padding:120,maxZoom:15,duration:600});
    };
  }
  // Ride-check lives in ./ride-check.js. Coverage, item-index and the catalogue
  // are plain imports there now that those modules exist; what is left injected
  // is only what drawer.js/sheet.js still own in this file
  // (2026-07-26-map-js-module-split-design.md §5). Closures where the binding is
  // live: `sheet` is declared further down, and _placeReq is a counter.
  initRideCheck({
    closeDrawer, openRouteById, highlightAt, clearHighlight,
    resetSheet: () => sheet.reset(),
    invalidateAsyncDrawers: () => { invalidateCoverageDrawer(); _placeReq++; },
  });

  // open a pending submission by id (deep-link from the /moderate queue's "View on map")
  function openPendingById(id){
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
  // open a PIVOT accommodation point (Tourisme Wallonie, CC-BY) from search — these are bulk
  // stays merged into the map, not CATALOG features, so activate the E layer, draw + zoom in
  function openStayPivot(f){
    const layer=layerByKey.stays; if(!layer) return false;
    if(!active.has('stays')){
      active.add('stays');
      const t=document.querySelector('#layers .layer[data-key="stays"]'); if(t) t.classList.remove('off');
      render();
    }
    const c=f.geometry.coordinates;                       // [lng,lat]
    openDrawer(layer, osmDrawer(layer, f.properties, {lng:c[0], lat:c[1]}, (osmLayers.stays||{}).src||''));
    flyToPin(c);
    return true;
  }
  // pulsing highlight marker — show where a hovered list item / town sits on the map
  let hlMarker=null;
  function highlightAt(ll, offset){
    if(!ll){ return clearHighlight(); }
    if(!hlMarker){ const el=document.createElement('div'); el.className='cc-highlight'; hlMarker=new maplibregl.Marker({element:el,anchor:'center'}); }
    hlMarker.setOffset(offset||[0,0]).setLngLat([ll[1],ll[0]]).addTo(map);
  }
  function clearHighlight(){ if(hlMarker) hlMarker.remove(); }
  // Reveal pin (07-15 decision C): picking a NON-DRAWN feature from search in
  // Curated mode drops one temporary marker (community look + selection pulse)
  // instead of force-switching the whole map to Everything. Cleared on the
  // next pick (openDrawer clears it up front), on drawer close, and on a mode
  // change.
  let _revealMarker=null;
  function clearRevealPin(){ if(_revealMarker){ _revealMarker.remove(); _revealMarker=null; } }
  function revealPinAt(layer, ll){
    clearRevealPin();
    const el=pinEl(layer, false);
    el.classList.add('community','reveal');
    _revealMarker=new maplibregl.Marker({element:el, anchor:'bottom'}).setLngLat([ll[1],ll[0]]).addTo(map);
  }
  function closeDrawer(){
    // If the rider closes the drawer (X / scrim / Escape) mid-pick, tear the
    // picking session down too — an orphaned map click handler + toolbar with
    // no drawer to return to would be a dead-end. Uncommitted points (this
    // session hasn't hit Done) are simply dropped; any previously-Done
    // stretches already live in _pickSegs and are untouched.
    if(_pick) cancelPicking();
    // Task 10 race convention: an explicit close invalidates any in-flight
    // coverage POI detail AND any in-flight town-card nearby fetch — without
    // this, openCoverageByRef (a FIRST opener, so the its-drawer-still-open
    // guard can't apply) would reopen a drawer the rider just dismissed when
    // the /map/coverage/poi/{ref} response lands, and a stale
    // /map/coverage/nearby response would resurrect a dismissed town card
    // (_placeReq bumps everywhere the coverage generation does — shared convention).
    invalidateCoverageDrawer(); _placeReq++;
    const d=document.getElementById('drawer'); d.classList.remove('open'); d.setAttribute('aria-hidden','true');
    clearHighlight();
    clearSelectedCoverageIcon();                        // remove the selected coverage POI's persistent icon overlay
    clearRevealPin();
    clearRouteHighlight();
    clearCorrections();
    sheet.clear();                                     // drop snap classes + inline transform for the next open
  }
  // lightbox doubles as a slideshow over a feature's photo gallery
  let _lb={photos:[],i:0,name:''};
  function openLightbox(photos, i, name){
    _lb.photos = Array.isArray(photos) ? photos : [{lg:photos, credit:'', license:'', source:''}];
    _lb.i = i||0; _lb.name = name||'';
    renderLightbox();
    const lb=document.getElementById('lightbox'); lb.classList.add('open'); lb.setAttribute('aria-hidden','false');
  }
  function renderLightbox(){
    const lb=document.getElementById('lightbox'), p=_lb.photos[_lb.i], multi=_lb.photos.length>1;
    lb.querySelector('img').src=p.lg;
    lb.querySelector('.cc-lb-cap').innerHTML =
      (_lb.name?`<b>${escPend(_lb.name)}</b> · `:'') + (p.source?photoCap(p):'') + (multi?` · ${_lb.i+1} / ${_lb.photos.length}`:'');
    lb.querySelector('.cc-lb-prev').hidden=!multi; lb.querySelector('.cc-lb-next').hidden=!multi;
  }
  function lbStep(d){ const n=_lb.photos.length; if(!n) return; _lb.i=(_lb.i+d+n)%n; renderLightbox(); }
  function closeLightbox(){
    const lb=document.getElementById('lightbox'); lb.classList.remove('open');
    lb.setAttribute('aria-hidden','true'); lb.querySelector('img').src='';
  }
  document.getElementById('drawerClose').onclick=closeDrawer;
  document.getElementById('drawerScrim').onclick=closeDrawer;   // tap the dimmed area above the bottom sheet to close
  // Mobile snap sheet (spec 2026-07-14): peek / half / full resting states.
  // Content scrolls only at full; below full any vertical drag moves the
  // sheet; at full, a downward drag while scrollTop===0 grabs the sheet back
  // (Google-Maps-style hand-off). Desktop (>820px) never enters this code.
  const sheet=(function initSnapSheet(){
    const d=document.getElementById('drawer'), grab=document.getElementById('drawerGrab');
    if(!d||!grab) return {reset(){}};
    const mobile=()=>window.innerWidth<=820;
    const rem=()=>parseFloat(getComputedStyle(document.documentElement).fontSize)||16;
    let snap='half', justDragged=false;
    const setSnap=s=>{ snap=s; d.classList.toggle('s-full', s==='full'); d.classList.toggle('s-peek', s==='peek'); d.style.transform=''; if(s==='full') {} else d.scrollTop=0; };
    // translateY offsets (px from fully-open) for each resting state
    function offsets(){
      const h=d.getBoundingClientRect().height;
      return {full:0, half:Math.max(0, h-window.innerHeight*0.5), peek:Math.max(0, h-7.5*rem())};
    }
    let active=false, dragging=false, viaGrab=false, startY=0, startT=0, dy=0, baseOff=0;
    d.addEventListener('touchstart', e=>{
      if(!mobile() || e.touches.length!==1 || !d.classList.contains('open')) return;
      viaGrab=grab.contains(e.target);
      if(!viaGrab && snap==='full' && d.scrollTop>0) return;   // mid-scroll at full → content's gesture
      active=true; dragging=false; startY=e.touches[0].clientY; startT=e.timeStamp; dy=0;
      // baseOff from the sheet's ACTUAL rendered transform, not the offsets()
      // table: the CSS half rule rests at 50svh while offsets() uses
      // innerHeight — with a retracted URL bar those bases differ and a
      // table-derived baseOff would jump the sheet on the first touchmove.
      // offsets() is still fine for the release-snap targets below.
      const tr=getComputedStyle(d).transform;
      baseOff = (tr && tr!=='none') ? new DOMMatrixReadOnly(tr).m42 : 0;
    }, {passive:true});
    d.addEventListener('touchmove', e=>{
      if(!active) return;
      dy=e.touches[0].clientY-startY;
      if(!dragging){
        // At full, content owns upward drags (scroll); the sheet owns downward
        // ones from scrollTop 0. Below full the sheet owns both directions.
        if(!viaGrab && snap==='full' && dy<-4){ active=false; return; }
        if(Math.abs(dy)<=4) return;                    // wait for a clear direction
        dragging=true;
      }
      e.preventDefault();                              // own the gesture (passive:false)
      d.classList.add('dragging');
      const off=offsets();
      d.style.transform=`translateY(${Math.min(Math.max(0, baseOff+dy), off.peek+40)}px)`;   // clamp: never above full; slight give past peek
    }, {passive:false});
    d.addEventListener('touchend', ()=>{
      if(!dragging){ active=false; return; }
      active=false; dragging=false; justDragged=true; setTimeout(()=>{ justDragged=false; }, 450);
      d.classList.remove('dragging');
      const off=offsets(), pos=baseOff+dy, vel=dy/Math.max(1, performance.now()-startT);   // px/ms, + = down
      // fast flick: skip straight to the neighbouring state in the flick's direction
      if(vel>0.5){ if(snap==='peek' || pos>off.peek+20){ dismiss(); return; } setSnap(snap==='full'?'half':'peek'); return; }
      if(vel<-0.5){ setSnap(snap==='peek'?'half':'full'); return; }
      if(pos>off.peek+60){ dismiss(); return; }        // dragged well past peek → close
      // otherwise: snap to nearest resting state
      let best='full', bestD=Infinity;
      ['full','half','peek'].forEach(s=>{ const dd=Math.abs(pos-off[s]); if(dd<bestD){ bestD=dd; best=s; } });
      setSnap(best);
    });
    d.addEventListener('touchcancel', ()=>{
      if(!active && !dragging) return;
      active=false; dragging=false;
      d.classList.remove('dragging');
      setSnap(snap);                    // re-settle on the last resting state
    });
    function dismiss(){
      d.style.transform='translateY(102%)';
      let closed=false;
      const fin=ev=>{ if(closed||(ev&&ev.propertyName!=='transform')) return; closed=true;
        d.removeEventListener('transitionend',fin); closeDrawer(); };
      d.addEventListener('transitionend', fin);
      setTimeout(fin, 320);                            // fallback if transitionend doesn't fire
    }
    grab.addEventListener('click', ()=>{ if(!mobile()||justDragged) return; setSnap(snap==='full'?'half':'full'); });   // Enter/Space included (button)
    return {
      reset(){ setSnap('half'); },                     // openDrawer lands every sheet at half
      clear(){ d.classList.remove('s-full','s-peek'); d.style.transform=''; snap='half'; },
    };
  })();
  document.querySelector('.cc-lb-prev').onclick=e=>{ e.stopPropagation(); lbStep(-1); };
  document.querySelector('.cc-lb-next').onclick=e=>{ e.stopPropagation(); lbStep(1); };
  document.getElementById('lightbox').addEventListener('click',e=>{ if(e.target.id==='lightbox'||e.target.classList.contains('cc-lb-x')) closeLightbox(); });
  document.addEventListener('keydown',e=>{
    const lbOpen=document.getElementById('lightbox').classList.contains('open');
    if(e.key==='Escape'){ lbOpen ? closeLightbox() : closeDrawer(); }
    else if(lbOpen && e.key==='ArrowLeft') lbStep(-1);
    else if(lbOpen && e.key==='ArrowRight') lbStep(1);
  });

  const tipEl=document.getElementById('tip');
  function showTip(text, lngLat){
    const p=map.project(lngLat);
    tipEl.textContent=text; tipEl.style.left=p.x+'px'; tipEl.style.top=p.y+'px'; tipEl.hidden=false;
  }
  function hideTip(){ tipEl.hidden=true; }
  map.on('move', ()=>{ if(!tipEl.hidden) hideTip(); });

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

  // "plan from Spa" — pick the sample loop nearest the chosen distance (faked for now)
  let planMarker=null;
  function clearPlan(){
    ['planroute','planroute-case'].forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); });
    if(map.getSource('planroute')) map.removeSource('planroute');
    if(planMarker){ planMarker.remove(); planMarker=null; }
  }
  function planFromSpa(km){
    // Empty-catalog + optional-attribute guards (review W6): reduce() with no
    // initial value throws on [], and CatalogProvider only emits `start` when
    // the attribute exists — fall back to the loop's first vertex.
    if(!window.CC_ROUTES || !CC_ROUTES.routes.length) return;
    const r=CC_ROUTES.routes.reduce((b,x)=>Math.abs(x.km-km)<Math.abs(b.km-km)?x:b);
    const start=r.start||r.loop[0];
    clearPlan();
    map.addSource('planroute',{type:'geojson',data:{type:'Feature',geometry:{type:'LineString',coordinates:r.loop.map(p=>[p[1],p[0]])}}});
    map.addLayer({id:'planroute-case',type:'line',source:'planroute',layout:{'line-cap':'round','line-join':'round'},paint:{'line-color':'#FBF4E4','line-width':9,'line-opacity':.95}});
    map.addLayer({id:'planroute',type:'line',source:'planroute',layout:{'line-cap':'round','line-join':'round'},paint:{'line-color':'#FF5A1F','line-width':5,'line-opacity':1}});
    const el=document.createElement('div'); el.className='cc-pin cur'; el.style.setProperty('--c','#FF5A1F'); el.innerHTML='<span>◎</span>';
    planMarker=new maplibregl.Marker({element:el,anchor:'bottom'}).setLngLat([start[1],start[0]]).addTo(map);
    let mnx=180,mny=90,mxx=-180,mxy=-90;
    r.loop.forEach(p=>{mny=Math.min(mny,p[0]);mxy=Math.max(mxy,p[0]);mnx=Math.min(mnx,p[1]);mxx=Math.max(mxx,p[1]);});
    map.fitBounds([[mnx,mny],[mxx,mxy]],{padding:60,duration:600});
    openDrawer({color:'#FF5A1F',letter:'R',label:D.suggestedRoute||'Suggested route'},{
      name:r.name, cur:false, source:D.fakedSrc||'Illustrative — faked from sample rides',
      elev:r.elev, gain:r.gain, difficulty:r.difficulty, uploader:r.uploader,
      record:[
        {label:D.start||'Start', value:'Spa'},
        {label:D.distance||'Distance', value:r.km+' km'},
        {label:D.shape||'Shape', value:D.roundtrip||'Roundtrip'},
        {label:D.season||'Season', value:CC_SEASON_LABEL[r.season]||trVal(r.season)},
        {label:D.why||'Why', value:D.popularSeason||'Popular this season'},
        {label:D.note||'Note', value:D.fakedNote||'⚠ Faked — the real planner stitches from the heatmap', warn:true}
      ]
    });
  }
  document.querySelectorAll('#planner .chip').forEach(c=>c.onclick=()=>{
    const wasOn=c.classList.contains('on');
    document.querySelectorAll('#planner .chip').forEach(x=>x.classList.remove('on'));
    if(wasOn){ clearPlan(); closeDrawer(); return; }   // click the active one again to clear the route
    c.classList.add('on');
    planFromSpa(+c.dataset.km);
  });

  // initial render runs from map.on('load') above (sources need the style loaded)
