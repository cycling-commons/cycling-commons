// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Map entry (docs/specs/map-and-search.md §4). ES module: catalog-load.js
   injects it once the catalog fetch has populated the CC_* globals. */
import { I18N, LAYER_L10N, D, tpl, VALUE_TR, trVal, sourceLabel, isRiderSource } from './i18n.js';
import { wc, pinPoint } from './util.js';
import { uKm } from './units.js';
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
import { render, updateZoomHint } from './render.js';
import { COVERAGE_ON, addCoverage, widenForDeepLink, openCoverageFeatureByName,
         fetchCoverageCounts, covShownCount } from './coverage.js';
import { addSurfaceTiles, setSurfaceTiles, surfaceTilesVisible } from './surface-tiles.js';
import { schemaRows, initDrawerChrome } from './drawer.js';
import { initPicking } from './picking.js';
import { resolveLocalFeature, resolveLocalFeatureById, openFeatureByName, openFeatureById,
         openRouteById, openPendingById } from './places.js';
import { initCommunity, initCuratorKeys } from './community.js';
import { initSearchUi } from './search-ui.js';
import { initScoutReview } from './scout-review.js';
import { initLayerList, initMapCtrl, initRailChrome, initBestOf, initFilterPill,
         initChips, initViewMode, initAddClimbHere } from './panels.js';
import { initTheme } from './theme.js';
import { initShell } from './shell.js';

  initScope();

  // initViewMode must run after initScope — it reads the active scope.
  initViewMode();

  initMapControls();
  initShell();
  initTheme();



  map.on('load',()=>{ markStyleReady(); localiseBasemapLabels(); addSatellite(); addMapillary(); addWaterOsm(); addCoverage(); addSurfaceTiles();
    OSM_BULK.forEach(([key, data, src])=>addOsmDots(key, data, src));
    renderScopeChips(); applyScope(curScope(), {fit:false}); setupConfClusters();
    // Cluster markers on settle (moveend/idle), never per render frame during a fly.
    let _confRAF=null;
    const scheduleConfMarkers=()=>{ if(_confRAF) return; _confRAF=requestAnimationFrame(()=>{ _confRAF=null; updateConfMarkers(); }); };
    map.on('moveend', scheduleConfMarkers); map.on('idle', scheduleConfMarkers);
    map.on('zoomend', updateZoomHint);   // hint follows zoom, not only render()
    // Coverage counts once at load (and on each scope change via applyScope).
    if(COVERAGE_ON) fetchCoverageCounts();
    render();
    // Deep links (docs/specs/map-and-search.md §4.5, §8): widen to Everywhere
    // only when the target actually resolves. Coverage-only ?feature widens inside
    // openCoverageFeatureByName on its own hit.
    const _dl = new URLSearchParams(location.search);
    const fp=_dl.get('feature'), pp=_dl.get('pending'), rp=_dl.get('route'), ip=_dl.get('item');
    const _dlHit =
      (ip && !!resolveLocalFeatureById(ip)) ||
      (fp && !!resolveLocalFeature(fp)) ||
      (pp && !!(layerByKey.pending && (layerByKey.pending.features||[]).some(x=>x.pending && String(x.pending.id)===String(pp)))) ||
      (rp && ((layerByKey['experience']||{}).features||[]).some(x=>String(x.id)===String(rp)));
    if(_dlHit) widenForDeepLink();
    // ?feature=<name> drawer + zoom; coverage POIs via search when local index misses.
    // ?item=<id> — moderation "what did I approve", by id not name.
    if(ip) openFeatureById(ip);
    if(fp && !openFeatureByName(fp)) openCoverageFeatureByName(fp);
    if(pp) openPendingById(pp);
    if(rp) openRouteById(rp);
  });

  initCoordPopup();

  initCuratorKeys();


  // Rider-added climbs must not keep an OSM-flavoured citation.
  if(window.CC_CLIMBS){
    const climbSrc = CC_CLIMBS.map(c => isRiderSource(c.srcType)
      ? Object.assign({}, c, {source: sourceLabel(c.srcType)}) : c);
    layerByKey['climbs'].features = layerByKey['climbs'].features.concat(climbSrc);
  }
  if(window.CC_ROUTES){
    const RIDE_CITIES={
      'Spa · Sankt Vith':['Spa','Stavelot','Vielsalm','Sankt Vith'],
      'Spa · Coo · Francorchamps':['Spa','Francorchamps','Coo','Stavelot'],
      'Spa · Côte des Hézalles':['Spa','Sart','Jalhay'],
      'Rondje Spa–Chevron':['Spa','Stoumont','Chevron','La Gleize'],
      'Rondje Super Stockeu':['Spa','Stavelot','Coo','Trois-Ponts'],
      'Afternoon Ride':['Spa','Sart','Tiège']
    };
    layerByKey['experience'].features = CC_ROUTES.routes.map((r,i)=>{
      // Demo lookup keyed by ride name. No fallback — unknown routes omit town rows.
      const cities = RIDE_CITIES[r.name];
      // Trim seeded from r.id, not index (docs/specs/route-domain.md §7): stored
      // correction fractions stay valid when the served set changes.
      const seed = Number(r.id)||0;
      const startM = 350 + (seed*137)%401, endM = 350 + (seed*211+90)%401;
      const diffLabel = r.difficulty?.label ?? (typeof r.difficulty === 'string' ? r.difficulty : undefined);
      return {
      id:r.id, rid:r.rid, name:r.name, state:r.state, headline:`${uKm(r.km)}${diffLabel ? ' · ' + trVal(diffLabel) : ''}`, cur:false, edit:'ride',
      geom:{path:trimEnds(r.loop, startM, endM)}, elev:r.elev, gain:r.gain, difficulty:r.difficulty, uploader:r.uploader,
      cities: cities || [],                                // searchable start/through towns (empty when unknown)
      bikeTypes: Array.isArray(r.bikeTypes) ? r.bikeTypes : [],   // declared suitability (may be empty = undeclared)
      photo:r.photo||wc('Liège-Bastogne-Liège 2014 Echappée du jour Côte de Wanne.JPG','Les Meloures','Les Meloures','CC BY-SA 3.0'),
      source:isRiderSource(r.srcType) ? sourceLabel(r.srcType) : (D.contributedGpx||'Contributed GPX (GPS track only)'),
      // Registry rows only when a rider (or import) actually set the attribute.
      record:(()=>{
        const rec=[
          {label:D.distance||'Distance', value:uKm(r.km)}
        ];
        if(r.state === 'unverified') rec.unshift({label:D.status||'Status', value:D.proposedVerify||'Proposed · ride it to verify', warn:true});
        if(cities){
          // html:true — builder-constructed markup (cityLink escapes); never on payload-derived values
          // (docs/specs/security-architecture.md §4.2).
          rec.push({label:D.startsAt||'Starts at', value:cityLink(cities[0]), html:true});
          rec.push({label:D.townsOnRoute||'Towns on route', value:cities.map(cityLink).join(' · '), html:true});
        }
        // Derived surface mix (SurfaceProfiler) — estimate + coverage, not ground truth.
        if(r.surfaces && Array.isArray(r.surfaces.parts) && r.surfaces.parts.length){
          rec.push({label:D.surfaces||'Surfaces', value:r.surfaces.parts.map(p=>`${trVal(p.surface)} ${p.pct}%`).join(' · '),
                    method:tpl(D.estimateMethod||'estimate · {pct}% of route mapped', {pct:Number(r.surfaces.covered)||0})});
        }
        rec.push(...schemaRows('K', r, r.id, {skip:['difficulty']}));
        return rec;
      })()
    };
    });
  }
  // A · Road surface. A function because it also runs on catalog hot-refresh.
  function populateSurfaceA(){
    if(!window.CC_SURFACE) return;
    layerByKey['surface'].features = CC_SURFACE.segments.map(s=>{
      const rec = schemaRows('A', s, s.id);
      // Length leads: great-circle over the drawn path, in the rider's unit.
      const _km = (s.path||[]).reduce((acc,p,i,a)=>{
        if(!i) return 0;
        const [la1,lo1]=a[i-1],[la2,lo2]=p, r=Math.PI/180;
        const dp=(la2-la1)*r, dl=(lo2-lo1)*r;
        const h=Math.sin(dp/2)**2+Math.cos(la1*r)*Math.cos(la2*r)*Math.sin(dl/2)**2;
        return acc+6371*2*Math.asin(Math.sqrt(h));
      },0);
      if(_km>0.01) rec.unshift({label:D.length||'Length', value:uKm(_km)});
      return {
        id:s.id, rid:s.rid, name:s.name, headline:`${trVal(s.surface)} · ${trVal(s.smoothness)}`, cur:(s.cls!=='paved'), edit:'road-surface',
        geom:{path:s.path}, surfaceClass:s.cls, width:s.width, smoothness:s.smoothness,
        photo: s.photoFile ? wc(s.photoFile, s.photoCredit, s.photoUser, s.photoLicense) : undefined,
        source:isRiderSource(s.srcType) ? sourceLabel(s.srcType) : 'OSM (surface=*)',
        record:rec
      };
    });
  }
  populateSurfaceA();
  // F · Hazards — catalog point features from window.CC_HAZARDS (no coverage tile).
  if(window.CC_HAZARDS && Array.isArray(CC_HAZARDS.features)){
    layerByKey['hazards'].features = CC_HAZARDS.features.map(ft=>{
      const p=ft.properties||{}, c=(ft.geometry&&ft.geometry.coordinates)||[];
      const bits=[p.hazardType, p.severity].filter(Boolean).map(trVal);
      const named=!!p.n;
      let photo=p.photo; if(typeof photo==='string'){ try{ photo=JSON.parse(photo); }catch(e){ photo=null; } }
      return {
        id:p.id, rid:p.rid, name:p.n||(LAYER_L10N.hazards||'Hazard'), unnamed:!named,
        headline:bits.join(' · ')||(named?(LAYER_L10N.hazards||'Hazards & conditions'):''),
        cur:!!p.v, geom:{ll:[c[1], c[0]]},
        record:schemaRows('F', p, p.id),
        photo:photo,
        source:sourceLabel(p.srcType) || (D.communityReport||'Community report'),
        v:p.v
      };
    });
  }
  // Pending submissions: CC_PENDING builds the layer (curators: whole queue;
  // riders: their own rows). CC_IS_CURATOR only switches moderation chrome.
  if(Array.isArray(window.CC_PENDING)){
    /* Review pin uses pinPoint() (renderer's rule). A `new` submission keeps
       its own point — there is no item yet. */
    const pendingPin = s => {
      if('new' === s.type || s.itemId == null) return [s.lat, s.lng];
      const lyr = CATALOG.find(l => l.letter === s.letter);
      const target = lyr && (lyr.features||[]).find(x => x.id != null && String(x.id) === String(s.itemId));
      return (target && pinPoint(target)) || [s.lat, s.lng];
    };
    const pf = window.CC_PENDING.map(s=>({
      name:s.title, headline:`${(I18N.pendingTypes||{})[s.type]||s.type} · ${s.who} · ${s.when}`,
      geom:{ll:pendingPin(s)},
      record:[
        (()=>{
          const lyr = CATALOG.find(l=>l.letter===s.letter) || {};
          const name = LAYER_L10N[lyr.key] || lyr.label || s.letter;
          return {label:D.type||'Type', value:(lyr.icon ? lyr.icon+' ' : '')+name};
        })(),
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

  // Curator ghost layer: gone items, off by default.
  if(window.CC_IS_CURATOR && Array.isArray(window.CC_GONE) && window.CC_GONE.length){
    const gf = window.CC_GONE.map(g=>({
      id:g.id, letter:g.letter, name:g.name,
      headline:`${trVal('Not there anymore')} · ${g.since}`,
      cur:false, geom:{ll:[g.lat, g.lng]},
      record:[
        (()=>{ const lyr=CATALOG.find(l=>l.letter===g.letter)||{};
          const nm=LAYER_L10N[lyr.key]||lyr.label||g.letter;
          return {label:D.type||'Type', value:(lyr.icon?lyr.icon+' ':'')+nm}; })(),
        {label:D.status||'Status', value:trVal('Not there anymore')},
        {label:D.age||'Age', value:g.since},
        {label:D.where||'Where', value:g.cc}
      ],
      source:'Removed from the map · curator view'
    }));
    const goneLayer = { key:'gone', letter:'⌀', label:LAYER_L10N.gone||'Removed places', color:'#8a8d7d', icon:'⌀', kind:'point', exp:false, pendingLayer:true, features:gf };
    CATALOG.push(goneLayer);
    layerByKey['gone'] = goneLayer;
  }

  initCommunity();

  initPicking();
  initClickToScope();

  initRideCheck();

  initDrawerChrome();
  initScoutReview();
  initSheet();
  initLightbox();


  initLayerList();
  initStreetToggle();
  initMapCtrl();

  initFilterPill();

  initRailChrome();
  initSearchUi();


  initBestOf();

  initScopeRail();

  initAreaNudge();

  initAddClimbHere();

  initChips();          // MUST follow initScopeRail/initAreaNudge

  initMapillaryDock();

  initPlanner();

  // Catalog hot-refresh hook (catalog-load.js); window global because that
  // script cannot import.
  window.__ccApplyCatalog = () => {
    populateSurfaceA();
    setSurfaceTiles(surfaceTilesVisible());
    render();
  };
