// SPDX-License-Identifier: AGPL-3.0-only
/* Map entry (docs/specs/map-and-search.md §4). ES module: catalog-load.js
   injects it once the catalog fetch has populated the CC_* globals. */
import { I18N, LAYER_L10N, D, tpl, VALUE_TR, trVal, sourceLabel, isRiderSource } from './i18n.js';
import { escPend, pinPoint, featureLL, attachPhotos, deskRiderHtml } from './util.js';
import { uKm } from './units.js';
import { map, initMapControls, addSatellite, markStyleReady, initCoordPopup,
         localiseBasemapLabels } from './map-init.js';
import { initRideCheck } from './ride-check.js';
import { CATALOG, active, layerByKey, cityLink, mode } from './catalog.js';
import { addMapillary, initMapillaryDock, initStreetToggle } from './mapillary.js';
import { curScope, scopeLabel, renderScopeChips, applyScope, initScope, initScopeRail,
         initAreaNudge, initClickToScope, liftScopeForHit } from './scope-ui.js';
import { OSM_BULK, addWaterOsm, addOsmDots, setupConfClusters, updateConfMarkers, refreshPools } from './osm-pools.js';
import { trimEnds, rebuildItemIndex } from './item-index.js';
import { sheet, initSheet } from './sheet.js';
import { initLightbox } from './lightbox.js';
import { render, updateZoomHint } from './render.js';
import { COVERAGE_ON, addCoverage, openCoverageFeatureByName,
         openCoverageByOsmRef, fetchCoverageCounts, covShownCount } from './coverage.js';
import { setSurfaceTiles, surfaceTilesVisible } from './surface-tiles.js';
import { schemaRows, initDrawerChrome, mapToast } from './drawer.js';
import { refFromShare, idFromShare } from './share-links.js';
import { initPicking } from './picking.js';
import { resolveLocalFeature, resolveLocalFeatureById, openFeatureByName, openFeatureById,
         openRouteById, openPendingById } from './places.js';
import { initCommunity, initCuratorKeys } from './community.js';
import { initSearchUi } from './search-ui.js';
import { initScoutReview } from './scout-review.js';
import { initDuplicateResolve } from './duplicate-resolve.js';
import { initLayerList, initMapCtrl, initRailChrome, initBestOf, initFilterPill,
         initChips, initViewMode, initAddClimbHere, liftModeFor } from './panels.js';
import { initTheme } from './theme.js';
import { initShell } from './shell.js';
import { initSelectBoxes } from './select-box.js';
import { layerGlyph } from './icons.js';

  initScope();

  // initViewMode must run after initScope — it reads the active scope.
  initViewMode();

  initMapControls({toast: mapToast});
  initShell();
  initSelectBoxes();
  initTheme();



  const bootMap=()=>{ markStyleReady(); localiseBasemapLabels(); addSatellite(); addMapillary(); addWaterOsm(); addCoverage();
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
    // Deep links (docs/specs/map-and-search.md §4.5, §8): the scope moves only
    // when the target actually resolves. Coverage-only ?feature lifts inside
    // openCoverageFeatureByName on its own hit.
    const _dl = new URLSearchParams(location.search);
    const fp=_dl.get('feature'), pp=_dl.get('pending'), rp=_dl.get('route'), rawIp=_dl.get('item');
    // map-and-search.md §8: both id params may carry a readable `/<slug>` tail.
    // The id decides; the slug is thrown away, so a renamed place still opens
    // its own link.
    const ip=rawIp ? idFromShare(rawIp) : null;
    const xp=refFromShare(_dl.get('ref'));   // ?ref=<osm ref>: the only per-POI key a coverage point has
    const _dlHit =
      (ip && !!resolveLocalFeatureById(ip)) ||
      (fp && !!resolveLocalFeature(fp)) ||
      (pp && !!(layerByKey.pending && (layerByKey.pending.features||[]).some(x=>x.pending && String(x.pending.id)===String(pp)))) ||
      (rp && ((layerByKey['experience']||{}).features||[]).some(x=>String(x.id)===String(rp)));
    // docs/specs/map-and-search.md §8: the rider's own mode may not draw the
    // target (Best of hides a Verified climb): lift it before the drawer opens,
    // or the halo lands on an empty map. Pool hits and pivots keep their own path.
    if(_dlHit){
      const hit = (ip && resolveLocalFeatureById(ip))
        || (fp && resolveLocalFeature(fp))
        || (rp && (()=>{ const l=layerByKey['experience']; const f=l && (l.features||[]).find(x=>String(x.id)===String(rp)); return f ? {layer:l, f} : null; })());
      /* The scope follows the target to the target's own REGION, transiently,
         and holds the camera so the opener below frames the target rather than
         the scope (docs/specs/map-and-search.md §8). The rider's scope goes
         back when the drawer closes. */
      // A catalog feature carries `rid`; a pool feature carries it in properties.
      if(hit && hit.f) liftScopeForHit(featureLL(hit.f),
        hit.f.rid != null ? hit.f.rid : (hit.f.properties && hit.f.properties.rid));
      if(hit && hit.layer && hit.f) liftModeFor(hit.layer, hit.f);
    }
    // ?feature=<name> drawer + zoom; coverage POIs via search when local index misses.
    // ?item=<id> — moderation "what did I approve", by id not name.
    if(ip) openFeatureById(ip);
    if(fp && !openFeatureByName(fp)) openCoverageFeatureByName(fp);
    // ?ref= widens on its own hit, like ?feature=: it cannot be resolved locally.
    if(xp) openCoverageByOsmRef(xp);
    if(pp) openPendingById(pp);
    if(rp) openRouteById(rp);
    // ?finding=<id> — curator duplicate resolve. Last, so it owns the drawer
    // if a link ever carries both params.
    initDuplicateResolve();
  };
  // `load` fires once and is not replayed. This module is injected after the
  // catalog fetch resolves, which on a slow catalog lands after the style is
  // already loaded, so ask the map where it is rather than only listening.
  if(map.isStyleLoaded()) bootMap(); else map.on('load', bootMap);

  initCoordPopup();

  initCuratorKeys();


  /* Catalog layers from the payload's variables. Run at boot, and again by
     the tab-return refresh (__ccApplyCatalog) once a fresh payload has landed,
     so climbs, routes and hazards follow an approval like the pools do. */
  function populateCatalogLayers(){
  // Rider-added climbs must not keep an OSM-flavoured citation.
  if(window.CC_CLIMBS){
    const climbSrc = CC_CLIMBS.map(c => isRiderSource(c.srcType)
      ? Object.assign({}, c, {source: sourceLabel(c.srcType)}) : c);
    layerByKey['climbs'].features = climbSrc;
  }
  if(window.CC_ROUTES || window.CC_ROUTE_PREVIEW){
    /* docs/specs/map-and-search.md §8: a ?route= link to a route waiting for
       review brings that one route in the page (CC_ROUTE_PREVIEW, MapController),
       for a curator who may moderate it or the rider who proposed it. It joins
       the layer for this visit so the link opens it like any other route. */
    const served = (window.CC_ROUTES && CC_ROUTES.routes) || [];
    const preview = window.CC_ROUTE_PREVIEW;
    const routeRows = preview && !served.some(r=>String(r.id)===String(preview.id)) ? served.concat([preview]) : served;
    const RIDE_CITIES={
      'Spa · Sankt Vith':['Spa','Stavelot','Vielsalm','Sankt Vith'],
      'Spa · Coo · Francorchamps':['Spa','Francorchamps','Coo','Stavelot'],
      'Spa · Côte des Hézalles':['Spa','Sart','Jalhay'],
      'Rondje Spa–Chevron':['Spa','Stoumont','Chevron','La Gleize'],
      'Rondje Super Stockeu':['Spa','Stavelot','Coo','Trois-Ponts'],
      'Afternoon Ride':['Spa','Sart','Tiège']
    };
    layerByKey['experience'].features = routeRows.map(r=>{
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
      // The rest of the R registry, so the drawer's correction box can start
      // its value widget from what the route says now (route-domain.md §7.1).
      // The rows themselves still come from schemaRows() over the raw payload.
      season:r.season, dominantSurface:r.dominantSurface, note:r.note,
      gradientLimited:r.gradientLimited, bestDirection:r.bestDirection,
      // The stored photo the server let through PhotoValidator, or none.
      photo:r.photo,
      // The gallery an approved rider photo lands in (docs/specs/photo-uploads.md §5i).
      photos:r.photos,
      source:isRiderSource(r.srcType) ? sourceLabel(r.srcType) : (D.contributedGpx||'Contributed GPX (GPS track only)'),
      // Registry rows only when a rider (or import) actually set the attribute.
      record:(()=>{
        const rec=[
          {label:D.distance||'Distance', value:uKm(r.km)}
        ];
        if(r.state === 'unverified') rec.unshift({label:D.status||'Status', value:D.proposedVerify||'Proposed · ride it to verify', warn:true});
        if(r.state === 'submitted') rec.unshift({label:D.status||'Status', value:D.stateSubmitted||'waiting for review', warn:true});
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
        rec.push(...schemaRows('R', r, r.id, {skip:['difficulty']}));
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
      return attachPhotos({
        id:s.id, rid:s.rid, name:s.name, headline:`${trVal(s.surface)} · ${trVal(s.smoothness)}`, cur:(s.cls!=='paved'), edit:'road-surface',
        geom:{path:s.path}, surfaceClass:s.cls, width:s.width, smoothness:s.smoothness,
        /* Who filed it. Every other layer's drawer names the rider; this shape
           carried no contributor, so the drawer cited OSM for values a rider
           typed. by:0 is a rider who has not made their profile public, and
           reads as "Shared anonymously" rather than as nobody. */
        by:s.by, byName:s.byName, byUuid:s.byUuid, srcType:s.srcType,
        source:isRiderSource(s.srcType) ? sourceLabel(s.srcType) : 'OSM (surface=*)',
        record:rec
      }, s);   // `photo` / `photos` as served, already through PhotoValidator
    });
  }
  populateSurfaceA();
  // E · Hazards — catalog point features from window.CC_HAZARDS (no coverage tile).
  if(window.CC_HAZARDS && Array.isArray(CC_HAZARDS.features)){
    layerByKey['hazards'].features = CC_HAZARDS.features.map(ft=>{
      const p=ft.properties||{}, c=(ft.geometry&&ft.geometry.coordinates)||[];
      const bits=[p.hazardType, p.severity].filter(Boolean).map(trVal);
      const named=!!p.n;
      let photo=p.photo; if(typeof photo==='string'){ try{ photo=JSON.parse(photo); }catch(e){ photo=null; } }
      return {
        id:p.id, rid:p.rid, name:p.n||(LAYER_L10N.hazards||'Hazard'), unnamed:!named,
        headline:bits.join(' · ')||(named?(LAYER_L10N.hazards||'Hazards & conditions'):''),
        cur:false, geom:{ll:[c[1], c[0]]},
        record:schemaRows('E', p, p.id),
        photo:photo,
        source:sourceLabel(p.srcType) || (D.communityReport||'Community report'),
        v:p.v
      };
    });
  }
  }
  populateCatalogLayers();
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
          return {label:D.type||'Type', html:true, value:layerGlyph(lyr, 13)+' '+escPend(name)};
        })(),
        {label:D.submittedBy||'Submitted by', html:true, value:deskRiderHtml(s.who, s.whoUuid)},
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
          return {label:D.type||'Type', html:true, value:layerGlyph(lyr, 13)+' '+escPend(nm)}; })(),
        {label:D.status||'Status', value:trVal('Not there anymore')},
        {label:D.age||'Age', value:g.since},
        {label:D.where||'Where', value:g.cc}
      ],
      source:'Removed from the map · curator view'
    }));
    // ✖, not ⌀ (owner 2026-08-27): the empty-set sign is a hairline maths glyph
    // that vanished at swatch size. Grey, not red: red already means "pending
    // review, act on this" on the layer above and on .cc-pin.pending, and a
    // colour cannot mean both "do something" and "nothing here any more"
    // (data-provider-hierarchy.md §6.3).
    const goneLayer = { key:'gone', letter:'✖', label:LAYER_L10N.gone||'Removed places', color:'#8a8d7d', icon:'✖', kind:'point', exp:false, pendingLayer:true, features:gf };
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


  // Catalog hot-refresh hook (catalog-load.js); window global because that
  // script cannot import.
  window.__ccApplyCatalog = () => {
    populateCatalogLayers();   // includes populateSurfaceA()
    refreshPools();
    rebuildItemIndex();
    setSurfaceTiles(surfaceTilesVisible());
    render();
  };
