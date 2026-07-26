// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Uncurated OSM coverage: the PMTiles vector source, one icon + one overview-
   heat layer per (catalogue letter, country), every cov* filter that composes
   scope with the curated-ref dedupe, the coverage drawers, the selected-icon
   overlay and the scope-aware rail totals.
   Extracted from map.js by 2026-07-26-map-js-module-split-design.md §5.

   The whole feature is gated on COVERAGE_ON — a real CC_COVERAGE_URL plus a
   loaded pmtiles protocol lib. Absent either, every export here is a no-op and
   the map keeps its pool-only behaviour, so the entry may call them
   unconditionally (coverage-provider.md §6).

   Two filter rules are deliberately opposite and must stay that way: served
   features hide when rid-less (leak-safe, scope-ui.js's inScope), coverage
   points RENDER when prop-less, because the tile artifact is rebuilt weekly and
   lags the region stamping. covScopeFilter()'s own comment carries the full
   rationale.

   The drawer hooks are plain imports now that drawer.js has landed; only the
   picking session is still injected, until picking.js does (§5 step 6). */
import { map, flyToPin } from './map-init.js';
import { showTip, hideTip } from './sheet.js';
import { layerByKey, active, mode, LETTER_KEY, KEY_LETTER } from './catalog.js';
import { curScope } from './scope-ui.js';
import { mintWaterDrops, miniIcon, SERVICE_GLYPH, coverageIconId } from './icons.js';
import { updateCounts, applyStaysAccessFilter } from './render.js';
import { openDrawer, renderDrawerBody, osmDrawer, waterDrawer, revealPinAt } from './drawer.js';

// Injected by initCoverage() until picking.js exists (see the header). isPicking
// is a CLOSURE over the entry's live `_pick` session, not a snapshot: a picking
// session starts and ends long after this handover.
let isPicking;

export function initCoverage(deps){
  ({isPicking} = deps);
}

// ---- Coverage tiles (coverage-provider.md §6) ----
// Uncurated OSM coverage renders from ONE PMTiles vector source ('coverage',
// one tile layer per catalogue letter, OSM-arch: osm-data-architecture.md §5)
// instead of inlined GeoJSON pools. Gated on window.CC_COVERAGE_URL (injected
// by MapController only when COVERAGE_TILES is on and the manifest resolves)
// AND on the pmtiles protocol lib actually having loaded — absent either, the
// map keeps today's pool-only behaviour (Photon-style silent degradation).
export const COVERAGE_KEYS=[['water','c'],['services','d'],['stays','e'],['transit','g'],['shelter','h'],['scenic','i'],['history','j']];
// Coverage tiles split their source-layers per country (Task 1:
// 2026-07-22-coverage-scope-rendering-design.md §A): a letter's rows live in
// '<letter>_<cc>' (lowercase cc), with unstamped rows in the 'zz' bucket. The
// published manifest (window.CC_COVERAGE_COUNTRIES, §D) lists the real
// countries; we always append 'zz' so unstamped rows still render. Each
// (letter, cc) pair becomes its own icon + cluster layer. [null] is the
// pre-split fallback: a single unsplit '<letter>' source-layer, so a tile
// artifact built before the per-country split still renders.
export const COVERAGE_CCS = (Array.isArray(window.CC_COVERAGE_COUNTRIES) && window.CC_COVERAGE_COUNTRIES.length)
  ? window.CC_COVERAGE_COUNTRIES.map(c=>c.toLowerCase()).concat(['zz'])
  : [null];   // [null] = single unsplit '<letter>' layer (tiles predate the per-country split)
export const COVERAGE_ON = typeof window.CC_COVERAGE_URL==='string' && !!window.CC_COVERAGE_URL && typeof pmtiles!=='undefined';
// Per-layer OSM source notes for the drawer's Source line — same wording as
// OSM_BULK above (water goes through waterDrawer, which owns its own string).
export const COV_SRC={
  services:'OpenStreetMap (shop=bicycle / amenity=bicycle_repair_station / compressed_air)',
  scenic:'OpenStreetMap (tourism=viewpoint / natural=peak / waterway=waterfall)',
  history:'OpenStreetMap (historic=castle/fort/ruins/monument/memorial/…)',
  stays:'OpenStreetMap (tourism=camp_site/hostel/guest_house/chalet/hotel/…)',
  shelter:'OpenStreetMap (shelter_type=picnic/weather/field/…)',
  transit:'OpenStreetMap (railway=station / railway=halt)'
};
// Curated-ref dedupe (osm-data-architecture.md §8): any object already served
// as an item draws once, as curated — its coverage twin is filtered out. A
// ref identifies one point, so this arm applies cleanly to every coverage icon.
export const covDedupeFilter=()=>['!',['in',['get','ref'],['literal', Array.from(window.CC_CURATED_REFS||[])]]];
// Region scope filter for the coverage tile layers (Phase 3,
// region-scoping-design.md §6). Scope keys are pipe-delimited membership
// TOKENS, not scalars: ridtok = "|<region_id>|" (empty when unstamped), cctok
// = "|<cc>|" — one token pair per feature, never unioned. Coverage renders as
// individual points only, no clusters (2026-07-24-coverage-no-cluster-design.md
// §2), so `'|id|' in ridtok` answers "is THIS point in scope?" exactly, per
// feature — no cluster-member aggregation to worry about (finding 2 is moot).
//
// DELIBERATELY the inverse of the leak-safe rule updateHeatFilter()/inScope()
// use for served data: a PROP-LESS feature (ridtok AND cctok both empty)
// RENDERS instead of hiding. Rationale: the coverage PMTiles is a
// separately-built, weekly-rebuilt artifact (§8 risk 2) — a transition/unsplit
// row carries no tokens, and hiding-all would blank the map, so empty tokens →
// render unfiltered (fallback ladder, never hide-all). But a cc-bearing
// rid-less row (cctok non-empty, ridtok empty) is NOT prop-less, so under a
// region scope it HIDES — matching /counts, which excludes region_id-NULL rows
// (finding 5: the tile used to leak these via the old `!has rid` arm). It
// reappears only under its country scope, admitted by the cctok arm. coalesce
// keeps the test safe against a stale pre-token tile (absent → '' → prop-less →
// render). Returns null for Everywhere (no filter).
// Delegates to the pure, unit-tested builder in scope.js (web/tests/js) so the
// token/prop-less/coalesce logic lives in one place with real tests, not buried
// in this IIFE. Falls back to a null filter if scope.js is somehow absent.
export function covScopeFilter(){
  return window.CCScope && window.CCScope.coverageTileFilter ? window.CCScope.coverageTileFilter() : null;
}
// Icon base = scope + the curated-ref dedupe (icons can be exact curated twins).
export function covBaseFilter(){
  const f=['all', covDedupeFilter()]; const sc=covScopeFilter(); if(sc) f.push(sc); return f;
}
// The coverage icon layer's filter (no clustering —
// 2026-07-24-coverage-no-cluster-design.md §2): scope + curated-ref dedupe
// plus any per-layer extra (stays' accessibility narrow). `!has point_count`
// is kept as a harmless no-op — tippecanoe no longer emits clustered
// features, so every feature already satisfies it.
export function covIconFilter(extra){
  const f=covBaseFilter(); f.push(['!',['has','point_count']]); if(extra) f.push(extra); return f;
}
// The coverage HEAT layer's filter: scope ONLY
// (2026-07-24-coverage-overview-heatmap-design.md §3.2). No curated-ref dedupe,
// no stays-accessibility narrow, no `!has point_count` arm — a density surface
// is not a clickable/exact feature; it only needs in-scope points to contribute
// density. Returns the scope expression, or null (Everywhere → unfiltered, all
// points), which setFilter accepts.
export function covHeatFilter(){
  return covScopeFilter() || null;
}
// Re-apply the composed filter to every coverage icon layer on a scope
// change, so each tracks scope like every served layer. stays' icon layer
// re-composes through applyStaysAccessFilter (it owns the acc extra).
export function updateCoverageScopeFilter(){
  if(!COVERAGE_ON) return;
  COVERAGE_KEYS.forEach(([key])=>{
    COVERAGE_CCS.forEach(cc=>{
      const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
      if(map.getLayer(id)){
        // stays owns an acc extra; applyStaysAccessFilter re-composes every
        // stays-<cc>-cov icon layer itself (it loops COVERAGE_CCS), so calling
        // it once per key is enough — guard so it fires only on the first cc.
        if(key==='stays'){ if(cc===COVERAGE_CCS[0]) applyStaysAccessFilter(); }
        else map.setFilter(id, covIconFilter());
      }
      const heatId = cc ? key+'-'+cc+'-heat' : key+'-heat';
      if(map.getLayer(heatId)) map.setFilter(heatId, covHeatFilter());
    });
  });
}
// A myArea scope whose derived region set is empty (map-and-search.md §4.5):
// coverageParams() returns rids:[] (an empty array, NOT null) as an explicit
// "in scope: nothing" sentinel, distinct from Everywhere's rids:null. Callers
// that would otherwise build an unscoped '' query — and silently fall back to
// GLOBAL /counts or /search results — check this first and skip the fetch.
export function covScopeIsZero(){
  const p=window.CCScope && window.CCScope.coverageParams();
  return !!(p && Array.isArray(p.rids) && !p.rids.length);
}
// Active-scope coverage params (rids/cc) as a query fragment
// (region-scoping-design.md §6); '' for Everywhere so the URL — and the
// shared HTTP-cache key — stays scope-free. Callers prepend '?' or '&'.
export function covScopeQuery(){
  if(!window.CCScope) return '';
  const p=window.CCScope.coverageParams(), parts=[];
  if(p.rids && p.rids.length) parts.push('rids='+p.rids.join(','));
  if(p.cc) parts.push('cc='+encodeURIComponent(p.cc));
  return parts.join('&');
}
// Community tier on the map (07-15 decision B, rebased in
// map-and-search.md §12): utility letters C/D/G/H draw
// in BOTH modes — at 0.55 opacity in Curated so verified pins keep visual
// priority — while experiential letters E/I/J stay Everything-only (Curated
// remains best-of for them).
export const COV_UTILITY=new Set(['C','D','G','H']);
export function syncCoverageLayers(){
  if(!COVERAGE_ON) return;
  COVERAGE_KEYS.forEach(([key])=>{
    // on/off + Curated dim are per-letter decisions; apply them uniformly to
    // every per-country layer of this letter.
    const utility=COV_UTILITY.has(KEY_LETTER[key]);
    const show=active.has(key) && (mode()==='all' || utility);
    const dim=(mode()==='curated' && utility)?0.55:1;
    COVERAGE_CCS.forEach(cc=>{
      const id = cc ? key+'-'+cc+'-cov' : key+'-cov'; if(!map.getLayer(id)) return;
      map.setLayoutProperty(id,'visibility', show?'visible':'none');
      map.setPaintProperty(id,'icon-opacity', dim);
      const heatId = cc ? key+'-'+cc+'-heat' : key+'-heat';
      if(map.getLayer(heatId)) map.setLayoutProperty(heatId,'visibility', show?'visible':'none');
    });
  });
}
export function addCoverage(){
  if(!COVERAGE_ON || map.getSource('coverage')) return;
  maplibregl.addProtocol('pmtiles', new pmtiles.Protocol().tile);
  mintWaterDrops();
  map.addSource('coverage',{type:'vector', url:'pmtiles://'+window.CC_COVERAGE_URL});
  COVERAGE_KEYS.forEach(([key, letter])=>{
    // One icon layer per (letter, country): the source-layer is
    // '<letter>_<cc>' (lowercase cc; 'zz' = unstamped rows), and the layer ids
    // carry the cc so scope filters / visibility toggles address each country.
    // A missing '<letter>_<cc>' source-layer (e.g. no unstamped rows) renders
    // nothing — the correct "empty bucket" outcome, no special-casing. cc===null
    // is the pre-split fallback: the plain '<letter>' source-layer + '<key>-cov'
    // ids, so a tile artifact built before the split still renders.
    COVERAGE_CCS.forEach(cc=>{
      const srcLayer = cc ? letter+'_'+cc : letter;
      const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
      // Reuse the existing canvas-minted icons: droplet variants for C (keyed
      // on the flat `potable` tile prop, tolerant of bool/num/string encoding),
      // the per-serviceKind discs for D (tile prop `kind`), miniIcon elsewhere.
      // Icons are shared across a letter's countries, so they stay keyed on `key`.
      const icon = key==='water'
        ? ['match',['to-string',['get','potable']],['yes','true','1'],'water-drop','water-drop-unk']
        : key==='services'
          ? ['match',['get','kind'],
              'shop', miniIcon('services'),
              'station', miniIcon('services', SERVICE_GLYPH.station, 'station'),
              'pump', miniIcon('services', SERVICE_GLYPH.pump, 'pump'),
              miniIcon('services')]
          : miniIcon(key);
      // Overview density heatmap (2026-07-24-coverage-overview-heatmap-design.md §3.2):
      // mirrors the icon layer on the same source-layer but renders z6-9 as a
      // heatmap (maxzoom 9), handing off to the individual icons (minzoom 9) so
      // the actual spots are visible from z9 (owner request 2026-07-24).
      // Scope-only filter → phantom-free (only in-scope points add density).
      // Per-CATEGORY hue: each letter's heat carries its OWN colour (the rail
      // colour), so the stacked layers alpha-blend into a MULTI-colour coverage
      // density — water blue, stays orange, … mixing where categories overlap
      // (owner request 2026-07-24). The ramp is the same hue from transparent to
      // translucent (never toward black or white), and the low top alpha keeps
      // it a soft, see-through blur.
      // ONE clean hue for the overview density blur (owner decision 2026-07-24).
      // A blended multi-colour heatmap is dominated by the densest letters
      // (stays/history) and averages to mud, so category distinction comes from
      // the coloured ICONS (which now appear from z9); the heat just answers
      // "where is coverage dense". Every letter's heat is the SAME teal, so the
      // stacked layers accumulate to *more teal* — never brown. Teal is distinct
      // from the ride heatmap's warm gold; no pale top stop (a pale/white max
      // punched white "holes" in a dense surface).
      const heatId = cc ? key+'-'+cc+'-heat' : key+'-heat';
      // covHeatFilter() is null under an Everywhere scope (unfiltered — every
      // point contributes density). setFilter() accepts null, but addLayer()
      // does NOT: `filter: null` fails style validation and MapLibre drops the
      // whole layer ("layers.<id>.filter: array expected, null found"), so a
      // first paint in Everywhere used to lose every coverage heat layer.
      // Omit the key entirely instead — the layer spec's own default is
      // unfiltered, which is exactly what null means here.
      const heatSpec={id:heatId, type:'heatmap', source:'coverage', 'source-layer':srcLayer,
        maxzoom: 9,
        layout:{visibility:'none'},
        paint:{
          'heatmap-weight':0.6,
          'heatmap-intensity':['interpolate',['linear'],['zoom'],6,0.9,9,1.3],
          'heatmap-radius':['interpolate',['linear'],['zoom'],6,16,9,28],
          // semi-transparent; fades to 0 at z9 for the crossfade into the icons.
          'heatmap-opacity':['interpolate',['linear'],['zoom'],6,0.6,8,0.6,9,0],
          'heatmap-color':['interpolate',['linear'],['heatmap-density'],
            0,'rgba(0,0,0,0)',
            0.25,'rgba(150,110,190,0.32)',
            0.6,'rgba(112,72,158,0.58)',
            1,'#5B2A86']}};
      { const hf=covHeatFilter(); if(hf) heatSpec.filter=hf; }
      map.addLayer(heatSpec);
      // Individual coverage icons (no clustering — 2026-07-24-coverage-no-cluster-design.md
      // §2); scope-filtered exactly. minzoom 9 so the spots are visible from the
      // region-fit zoom (z6-8 tiles are thinned, so z9-10 icons are a sample that
      // densifies to complete at z11+; the rail counts stay the exact total).
      map.addLayer({id, type:'symbol', source:'coverage', 'source-layer':srcLayer,
        minzoom: 9,
        filter:covIconFilter(),   // dedupe + scope
        layout:{visibility:'none','icon-image':icon,'icon-allow-overlap':true,
          'icon-size': key==='water'
            ? ['interpolate',['linear'],['zoom'],8,0.55,13,0.9,18,1.3]
            : ['interpolate',['linear'],['zoom'],8,0.42,13,0.7,18,0.95]}});
      map.on('click',id,e=>{ const f0=e.features[0], tp=f0.properties, c=f0.geometry.coordinates;
        openCoverageDrawer(key, tp, {lng:c[0], lat:c[1]}); flyToPin([c[0],c[1]]); });   // exact feature coords, same halo rule as addOsmDots
      map.on('mouseenter',id,()=>map.getCanvas().style.cursor='pointer');
      map.on('mousemove',id,e=>{ const p=e.features[0].properties; showTip(p.n||p.t||(layerByKey[key]||{}).label||'Item', e.lngLat); });
      map.on('mouseleave',id,()=>{ map.getCanvas().style.cursor=''; hideTip(); });
    });
  });
  // Selected-POI icon overlay (fix 2026-07-22): a coverage POI's individual
  // icon is drawn only by the tile <key>-<cc>-cov layer, which the z11 minzoom
  // hides on zoom-out — but the selection pulse (a coord-anchored DOM
  // marker) stays, leaving an "empty pulsing halo". This single-feature GeoJSON
  // overlay redraws the SELECTED POI's icon on top, independent of the tile
  // minzoom, so it stays visible at every zoom. Sits under the DOM pulse,
  // which then rings the icon as intended. Same icon-image + size ramps as the
  // tile icon layers so there's no visual jump where the two overlap at high zoom.
  if(!map.getSource('cov-sel')){
    map.addSource('cov-sel',{type:'geojson',data:{type:'FeatureCollection',features:[]}});
    map.addLayer({id:'cov-sel-icon',type:'symbol',source:'cov-sel',
      // ONE zoom interpolate (MapLibre forbids two), with per-feature stop
      // outputs (_s8/_s13/_s18) so water vs the rest keep their exact tile ramps.
      layout:{'icon-image':['get','_icon'],'icon-allow-overlap':true,
        'icon-size':['interpolate',['linear'],['zoom'],
          8,['get','_s8'],13,['get','_s13'],18,['get','_s18']]}});
  }
}
// Adapt coverage properties (tile props + optionally the detail payload) into
// the property bag osmDrawer/waterDrawer already consume. Coverage POIs are
// always OSM-sourced; a curated twin (detail.curated — normally suppressed by
// the dedupe filter, but reachable via deep links/search) binds the drawer to
// the real item id so the edit bridge, confirm panel and registry rows work.
export function covProps(key, tp, d){
  const p={ srcType:'osm' };
  if(tp.t) p.t=tp.t;
  if(tp.n) p.n=tp.n;
  if(key==='services' && tp.kind) p.serviceKind=tp.kind;
  // C · water potability (coverage-provider.md §4 `potable` tile prop): the
  // pipeline pre-computes it as OSM drinking_water='yes' OR (bare
  // amenity=drinking_water with no contradicting tag); false covers every
  // other case, including an explicit drinking_water='no'. Tolerant
  // coercion matches the icon-choice expression (mintWaterDrops' paint
  // match on ['yes','true','1']) since the tile value's wire type isn't
  // guaranteed. Threaded through immediately so the open-now paint never
  // claims "Tagged drinkable in OSM" for a fountain OSM does not attest as
  // drinkable — waterDrawer reads p.osmPotable.
  if(key==='water' && tp.potable!=null){
    const v=tp.potable;
    p.osmPotable = v===true || v==='true' || v===1 || v==='1' || v==='yes';
  }
  if(d){
    if(d.name) p.n=d.name;
    if(key==='services' && d.kind) p.serviceKind=d.kind;
    const tags=d.tags||{};
    const web=tags.website||tags['contact:website'];
    if(web) p.web=web;
    // The whitelisted raw tag (CoverageRepository::TAG_WHITELIST) is more
    // precise than the tile's precomputed boolean once hydrated — an
    // explicit 'no' is definitive.
    if(key==='water' && tags.drinking_water==='no') p.osmPotable=false;
    if(d.curated){
      if(d.curated.itemId!=null) p.id=d.curated.itemId;
      Object.assign(p, d.curated.fields||{});
    }
  }
  return p;
}

// Open a coverage POI drawer from tile props immediately, then hydrate from
// GET /map/coverage/poi/{ref} — the open-now-enrich-later pattern the drawer
// already uses for history/confirmations (coverage-provider.md §6).
let _covReq=0;
// The entry (and, through it, ride-check) bumps this wherever a drawer render
// supersedes an in-flight coverage detail fetch — the other half of the shared
// drawer-generation convention whose _placeReq half lives in the entry.
export function invalidateCoverageDrawer(){ _covReq++; }
export function openCoverageDrawer(key, tp, ll){
  // Picking guard (spec §16 S1, same as openDrawer): during a stretch-picking
  // session the route drawer stays open (minimised) with the suggest form's
  // typed state — a picking click that also lands on a coverage dot must not
  // start a detail fetch whose repaint would wipe that form. openDrawer's own
  // _pick bail-out only stops the initial paint, not the fetch, so bail here.
  if(isPicking()) return;
  const layer=layerByKey[key];
  const feat=p=>key==='water' ? waterDrawer(p, ll) : osmDrawer(layer, p, ll, COV_SRC[key]);
  openDrawer(layer, feat(covProps(key, tp, null)));
  showSelectedCoverageIcon(key, tp, ll);   // keep the icon visible after openDrawer's clear, incl. when the z11 minzoom hides it on zoom-out
  if(!tp.ref) return;
  const myReq=++_covReq;
  fetch('/map/coverage/poi/'+tp.ref, {headers:{'Accept':'application/json'}})
    .then(r=>r.ok?r.json():null)
    .catch(()=>null)   // detail is an enhancement — the tile props already opened the drawer
    .then(d=>{ if(!d || myReq!==_covReq || isPicking()) return;   // superseded by a newer drawer render, or a picking session started mid-flight
      if(!document.getElementById('drawer').classList.contains('open')) return;   // closed while in flight
      renderDrawerBody(layer, feat(covProps(key, tp, d))); });
}
// Open a coverage POI with NO rendered tile feature at hand (search pick,
// town-card row, ?feature= fallback): fly, fetch the detail, open the drawer.
// Fetch failure still opens a minimal drawer — the pick must never no-op.
export function openCoverageByRef(ref, letter, ll, name){
  const key=LETTER_KEY[letter]; if(!key) return;
  flyToPin([ll[1],ll[0]]);
  const myReq=++_covReq;
  const paint=(d)=>{ if(myReq!==_covReq) return;
      const layer=layerByKey[key], lo={lng:ll[1], lat:ll[0]};
      const p=covProps(key, {ref, n:(d&&d.name)||name, kind:d&&d.kind}, d);
      openDrawer(layer, key==='water' ? waterDrawer(p, lo) : osmDrawer(layer, p, lo, COV_SRC[key]));
      // decision C: if this POI's tile layer isn't drawn right now (Curated
      // mode, experiential letter — or layer toggled off), reveal it with one
      // temporary pin rather than flipping the map mode. A letter's per-country
      // layers share one on/off state (syncCoverageLayers), so "any visible" =
      // this key's coverage is drawn.
      const drawn = COVERAGE_CCS.some(cc=>{
        const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
        return map.getLayer(id) && map.getLayoutProperty(id,'visibility')==='visible';
      });
      if(!drawn) revealPinAt(layer, ll);
      else showSelectedCoverageIcon(key, {kind:d&&d.kind}, lo);   // drawn: keep the icon visible when the z11 minzoom hides it on zoom-out (matches the drawer's unknown-potability droplet for water opened without a tile prop)
    };
  // Non-OSM refs (manual: rider adds, fx: seeds) have no coverage detail —
  // /map/coverage/poi serves node|way only. Open the minimal drawer with the
  // caller-supplied name straight away instead of a guaranteed-404 round-trip.
  if(!/^(node|way)\/\d+$/.test(ref)){ paint(null); return; }
  fetch('/map/coverage/poi/'+ref, {headers:{'Accept':'application/json'}})
    .then(r=>r.ok?r.json():null)
    .catch(()=>null)
    .then(paint);
}
// Transiently widen the scope to Everywhere so a resolved deep-link target
// always renders, then return (region-scoping-design.md §4). persist:false —
// the saved scope returns on the next plain load. Only ever called AFTER a
// target actually resolves (07-20 review finding 9), so it never flips the
// map with nothing to show. No-op when already Everywhere.
export function widenForDeepLink(){
  if(window.CCScope && curScope().kind!=='everywhere'){
    window.CCScope.set({kind:'everywhere', regionIds:[], countryCode:null}, {persist:false});
  }
}
// ?feature= deep-link fallback (coverage-provider.md §6):
// a name that is not in the local index gets ONE search-endpoint lookup —
// exact-name hit preferred, else the server's top-ranked result. Nothing
// found / coverage off → silently keep the plain map (Photon convention).
// The lookup is deliberately UNSCOPED (no covScopeQuery): a deep link must
// resolve its target regardless of the saved scope, then widen to reveal it.
export function openCoverageFeatureByName(name){
  if(!COVERAGE_ON) return;
  fetch('/map/coverage/search?q='+encodeURIComponent(name), {headers:{'Accept':'application/json'}})
    .then(r=>r.ok?r.json():null)
    .catch(()=>null)
    .then(d=>{
      const hits=((d&&d.results)||[]).filter(h=>h && h.n && LETTER_KEY[h.letter] && Array.isArray(h.ll));
      if(!hits.length) return;
      const hit=hits.find(h=>h.n.toLowerCase()===name.toLowerCase())||hits[0];
      // Phase 3: coverage tiles are now scope-filtered, so a coverage-only
      // ?feature target CAN be hidden by a narrow saved scope — widen once
      // the target has actually resolved (the F9 gate below only covers the
      // synchronous local/pending/route resolvers; this is the async arm).
      widenForDeepLink();
      openCoverageByRef(hit.ref, hit.letter, hit.ll, hit.n);
    });
}

// Rail totals (coverage-provider.md §5):
// per-letter coverage counts from /map/coverage/counts, re-fetched on each
// scope change (Phase 3: scope-aware, region-scoping-design.md §7). This one
// scoped count drives BOTH the 'shown' and 'total' sides for a coverage layer
// (see covShownCount).
let _covCounts=null, _covCountReq=0;
// The scope-aware per-letter total, for the rail's 'total' side (render.js's
// layerCounts) — a reader, so _covCounts itself never leaves this module.
export function coverageTotal(letter){ return (_covCounts && _covCounts[letter]) || 0; }
export function fetchCoverageCounts(){
  if(!COVERAGE_ON) return;
  ++_covCountReq;   // invalidate any earlier in-flight scope's response, fetched or not
  if(covScopeIsZero()){
    // myArea derived to zero regions (map-and-search.md §4.5): skip the
    // request entirely — an unscoped fetch would silently return GLOBAL
    // counts — and zero the rail instead.
    _covCounts=null; updateCounts(); return;
  }
  const q=covScopeQuery(), myReq=_covCountReq;
  fetch('/map/coverage/counts'+(q?('?'+q):''), {headers:{'Accept':'application/json'}})
    .then(r=>r.ok?r.json():null)
    .catch(()=>null)
    // Race-guard: a slow scoped-counts response must not overwrite a newer
    // scope's totals (rapid rail switching). Same _historyReq discipline. On a
    // FAILED fetch for the current scope, clear the counts rather than leave the
    // PREVIOUS scope's numbers on the rail while the dots already re-filtered to
    // the new scope (finding 16 — an offline mismatch that self-heals on the
    // next success); an honest blank beats a wrong-scope total.
    .then(d=>{ if(myReq===_covCountReq){ _covCounts=(d && d.counts) || null; updateCounts(); } });
}
// "Shown" for a coverage layer = its in-scope count (the scope-aware
// /counts value), NOT the handful of tiles rendered in the current viewport.
// Coverage is a dense vector-tile layer clustered at low zoom (and, before
// clustering, point-thinned), so a viewport-render count read a confusing
// near-zero at the region/country overview zooms the scope selector fits to
// (All Belgium at ~z8 showed "16/2015", even "0/2015" on a slightly different
// frame). Every in-scope POI IS on the map — revealed progressively as you
// zoom — so the whole scope counts as shown, mirroring the served
// layers (A shows 351/351). 0 when the layer is toggled off or mode-hidden
// (experiential coverage E/I/J in Curated), which the visibility gate covers.
export function covShownCount(key){
  // _covCounts is a per-LETTER scope-aware aggregate (server-side), so it is
  // NOT summed per country — the whole letter's in-scope count is "shown" as
  // soon as any of its per-country layers is drawn. Visibility is uniform
  // across a letter's countries (syncCoverageLayers), so "any visible" gates it.
  if(!COVERAGE_ON) return 0;
  const drawn = COVERAGE_CCS.some(cc=>{
    const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
    return map.getLayer(id) && map.getLayoutProperty(id,'visibility')==='visible';
  });
  if(!drawn) return 0;
  return coverageTotal(KEY_LETTER[key]);
}

export function showSelectedCoverageIcon(key, tp, ll){
  const src=map.getSource('cov-sel'); if(!src||!ll) return;
  // Per-key icon-size ramps, identical to the <key>-<cc>-cov tile layers, as
  // data-driven stop outputs for the overlay's single zoom interpolate.
  const w=key==='water';
  src.setData({type:'FeatureCollection',features:[{type:'Feature',
    geometry:{type:'Point',coordinates:[ll.lng,ll.lat]},
    properties:{_icon:coverageIconId(key,tp),
      _s8:w?0.55:0.42, _s13:w?0.9:0.7, _s18:w?1.3:0.95}}]});
}
export function clearSelectedCoverageIcon(){ const src=map.getSource('cov-sel'); if(src) src.setData({type:'FeatureCollection',features:[]}); }
