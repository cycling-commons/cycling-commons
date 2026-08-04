// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The render loop and everything that decides what a frame contains: the
   dynamic-layer bookkeeping, line and climb drawing, the surface layer, the
   route highlight, every feature filter (mode, scope, preferences, chip
   facets), the legend counts, and the lazily-built ride heatmap.
   Extracted from map.js by the module split.

   render() is the one function that repaints served features; every scope,
   mode, layer-toggle and facet change funnels into it. It clears and rebuilds
   the dynamic ids each pass, which is why ride-check and the coverage tiles
   deliberately namespace their own sources outside its reach.

   Every dep this module once took injected is gone (§5 step 6): openDrawer is a
   plain import, and openLocalFeature turned out to be dead weight — it was
   injected at the render.js extraction and never called. initRender() went with
   them; it only ever carried the handover. */
import { map, flyToPin, styleReady } from './map-init.js';
import { showTip, hideTip } from './sheet.js';
import { I18N, D, LAYER_L10N, tpl, trVal, DIFF_LABELS, CC_SEASON_LABEL } from './i18n.js';
import { escPend, safeHref, stars, slug, txtOn, gradColor, DIFF_PURPLE, haversine,
         featurePoint, pinPoint, currentSeason, ccUrl, wc } from './util.js';
import { CATALOG, active, layerByKey, mode, LETTER_KEY, KEY_LETTER } from './catalog.js';
import { inScope, curScope } from './scope-ui.js';
import { pinEl, miniIcon } from './icons.js';
import { updateConfMarkers } from './osm-pools.js';
import { covShownCount, coverageTotal, syncCoverageLayers, covIconFilter,
         COVERAGE_CCS, COVERAGE_ON } from './coverage.js';
import { openDrawer } from './drawer.js';

export const PREFS = window.CC_PREFS || {bikes: [], styles: []};

// seasonal ride-heatmap (illustrative — built from sample GPX rides, served as catalog.json's L layer).
// Built LAZILY on the first heatmap-On click (review W43): ~6,600 features
// allocated + tiled at load for a layer that defaults Off was pure startup
// cost; the toggle handler below calls this before flipping visibility.
export function addHeatmap(){
  if(!window.CC_ROUTES || map.getSource('rideheat')) return;
  // h = [lat, lng, season, rid] (CatalogProvider::heat()) — rid feeds the
  // scope half of updateHeatFilter() (07-20 review finding 5).
  const feats=CC_ROUTES.heat.map(h=>({type:'Feature',properties:{season:h[2],rid:h[3]},
    geometry:{type:'Point',coordinates:[h[1],h[0]]}}));
  map.addSource('rideheat',{type:'geojson',data:{type:'FeatureCollection',features:feats}});
  map.addLayer({id:'rideheat',type:'heatmap',source:'rideheat',layout:{visibility:'none'},paint:{
    'heatmap-weight':0.8,
    'heatmap-intensity':['interpolate',['linear'],['zoom'],8,0.8,13,1.8],
    'heatmap-radius':['interpolate',['linear'],['zoom'],8,8,13,22],
    'heatmap-opacity':0.82,
    'heatmap-color':['interpolate',['linear'],['heatmap-density'],
      0,'rgba(0,0,0,0)',
      0.12,'rgba(255,221,128,0.55)',
      0.30,'#FFC43D',
      0.50,'#FF9A1F',
      0.70,'#FF5A1F',
      0.88,'#E23617',
      1,'#FFF1C8']
  }});
  updateHeatFilter();   // the layer is built lazily — apply the current season + scope immediately
}
// The ONE heat filter: season facet AND region scope combined (07-20 review
// finding 5 — heat used to be the only served layer the scope never reached,
// and season/scope each overwrote the other's setFilter). A rid-less point
// shows only in Everywhere, the same leak-safe default as inScope(); the
// coalesce(-1) keeps the 'in' needle typed when rid is absent.
export function updateHeatFilter(){
  if(!map.getLayer('rideheat')) return;
  const clauses=[];
  const sc=document.querySelector('#season .chip.on');
  if(sc && sc.dataset.s!=='all') clauses.push(['==',['get','season'],sc.dataset.s]);
  const s=curScope();
  if(s && s.kind!=='everywhere') clauses.push(['in',['coalesce',['get','rid'],-1],['literal',s.regionIds]]);
  map.setFilter('rideheat', clauses.length ? (clauses.length===1 ? clauses[0] : ['all'].concat(clauses)) : null);
}

export let markers = [];
export const dynamicIds=[];
export const boundLayerIds=new Set();   // delegated click/hover handlers are attached once per id
export function clearDynamic(){
  // remove casing layers first (they share the base source id), then base layer + source
  dynamicIds.forEach(id=>{ const c=id+'-case'; if(map.getLayer(c)) map.removeLayer(c); });
  dynamicIds.forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); if(map.getSource(id)) map.removeSource(id); });
  dynamicIds.length=0;
}
// draw a polyline with a light casing so it stays visible over the tinted basemap
// climb line coloured by gradient (line-gradient over the route)
export function drawClimbLine(id, latlngs, grad, layer, f){
  if(!map.getSource(id)) map.addSource(id,{type:'geojson',lineMetrics:true,data:{type:'Feature',properties:{},
    geometry:{type:'LineString',coordinates:latlngs.map(p=>[p[1],p[0]])}}});
  const caseW=['interpolate',['linear'],['zoom'],9,7,13,11,16,17];
  const lineW=['interpolate',['linear'],['zoom'],9,4.5,13,7,16,12];
  if(!map.getLayer(id+'-case')) map.addLayer({id:id+'-case',type:'line',source:id,
    layout:{'line-cap':'round','line-join':'round'},
    paint:{'line-color':'#FBF4E4','line-width':caseW,'line-opacity':.95}});
  /* Hard bands, not a blend.

     `interpolate` faded each colour into the next, so a climb read as a smooth
     wash and you could not see where one gradient band ended and the next
     began — while the profile strip beside it shows exactly that, as discrete
     bars (owner-reported 2026-08-04). `step` gives the line the same edges the
     bars have.

     The stops are i/n, not i/(n-1): each sample IS a slice of the climb, not a
     point on it, so n samples are n equal bands. Interpolating between
     end-points quietly implied the first and last bars were half-width. */
  const n=grad.length;
  const expr=['step',['line-progress'],gradColor(grad[0])];
  for(let i=1;i<n;i++){ expr.push(i/n); expr.push(gradColor(grad[i])); }
  if(!map.getLayer(id)) map.addLayer({id,type:'line',source:id,
    layout:{'line-cap':'round','line-join':'round'},
    paint:{'line-width':lineW,'line-gradient':expr}});
  dynamicIds.push(id);
  if(!boundLayerIds.has(id)){
    map.on('click',id,()=>openDrawer(layer,f));
    map.on('mouseenter',id,()=>map.getCanvas().style.cursor='pointer');
    map.on('mouseleave',id,()=>map.getCanvas().style.cursor='');
    boundLayerIds.add(id);
  }
}
// K route selection styling: the selected route gets the full brand orange
// and a slightly wider line; every sibling route dims so the selection is
// unmistakable. Cleared when the drawer closes or a non-route feature opens.
export const ROUTE_BASE_COLOR='#FD986E';   // 60% #FF5A1F pre-blended over #FBF4E4
export const ROUTE_BASE_W=5, ROUTE_BASE_CASE_W=9;
// A SELECTED route reads as a wide orange halo that the road-surface line
// (3→8 px by zoom, over an 8 px cream case) sits ON TOP of — so you see the
// highlight AND the surface on it. Kept comfortably wider than the surface's
// 8 px case at every zoom so the orange shows on both sides of the surface line.
export const ROUTE_SEL_W=['interpolate',['linear'],['zoom'],9,8,13,13,16,17];
export const ROUTE_SEL_CASE_W=['interpolate',['linear'],['zoom'],9,12,13,18,16,23];
export let selectedRouteLayerId=null;
export const routeLineIds=()=>map.getStyle().layers.map(l=>l.id).filter(id=>/^experience-\d+$/.test(id));
/* THE stacking authority. Every layer named here is moved to the top in turn,
   so the LAST one lifted ends up highest — the call order below IS the z-order,
   bottom to top:

     route lines  →  road surfaces  →  climb lines  →  Mapillary

   Climbs go ABOVE surfaces (2026-08-03, owner). They were lifted first and so
   ended up underneath, and an 8 px teal surface line swallowed the climb it
   describes — on La Redoute only a sliver of the gradient line showed at the
   edges. A climb is a named thing a rider came to look at; a surface segment
   is a property of the road beneath it, and the narrower climb line still
   leaves the surface colour visible on both sides.

   Do not try to fix stacking at draw time instead: this function runs at the
   end of every render AND after every selection move, so a moveLayer in
   drawClimbLine is silently overridden here a moment later. One authority. */
export function liftInfoLayersAboveRoutes(){
  const liftGroup=id=>{ if(map.getLayer(id+'-case')) map.moveLayer(id+'-case'); if(map.getLayer(id)) map.moveLayer(id); };
  // consolidated A-layer (C3): one shared casing + one layer per surface class
  if(map.getLayer('surface-case')) map.moveLayer('surface-case');
  surfaceClsLayerIds().forEach(id=>{ if(map.getLayer(id)) map.moveLayer(id); });
  dynamicIds.filter(id=>id.startsWith('route-climbs-')).forEach(liftGroup);
  ['mly-cov','mly-img'].forEach(id=>{ if(map.getLayer(id)) map.moveLayer(id); });
}
export function highlightRoute(selId){
  selectedRouteLayerId=selId;
  routeLineIds().forEach(id=>{
    const on=id===selId;
    map.setPaintProperty(id,'line-color',on?'#FF5A1F':ROUTE_BASE_COLOR);
    map.setPaintProperty(id,'line-opacity',on?1:0.4);
    map.setPaintProperty(id,'line-width',on?ROUTE_SEL_W:ROUTE_BASE_W);
    if(map.getLayer(id+'-case')){
      map.setPaintProperty(id+'-case','line-opacity',on?0.95:0.35);
      map.setPaintProperty(id+'-case','line-width',on?ROUTE_SEL_CASE_W:ROUTE_BASE_CASE_W);
    }
  });
  // Lift the SELECTED route (a wide orange halo) above its dimmed siblings,
  // then put the info layers (climbs / surface / Mapillary) back on top — the
  // surface line is narrower than the halo, so it sits ON the selected route
  // and you see the highlight AND the surfaces together.
  if(map.getLayer(selId+'-case')) map.moveLayer(selId+'-case');
  if(map.getLayer(selId)) map.moveLayer(selId);
  liftInfoLayersAboveRoutes();
}
export function clearRouteHighlight(){
  if(selectedRouteLayerId===null) return;
  selectedRouteLayerId=null;
  routeLineIds().forEach(id=>{
    map.setPaintProperty(id,'line-color',ROUTE_BASE_COLOR);
    map.setPaintProperty(id,'line-opacity',1);
    map.setPaintProperty(id,'line-width',ROUTE_BASE_W);
    if(map.getLayer(id+'-case')){
      map.setPaintProperty(id+'-case','line-opacity',0.95);
      map.setPaintProperty(id+'-case','line-width',ROUTE_BASE_CASE_W);
    }
  });
}
// --- Located-correction geometry (spec §16 S4). Path is [lat,lng] points. ---
export function _cumLen(path){ // cumulative planar length per vertex + total (deg is fine at this scale)
  const cum=[0]; for(let i=1;i<path.length;i++){ const dx=path[i][1]-path[i-1][1], dy=path[i][0]-path[i-1][0]; cum.push(cum[i-1]+Math.hypot(dx,dy)); } return cum;
}
// nearest point on the polyline to click [lat,lng] → {frac, at:[lat,lng]}
export function nearestOnPath(path, click){
  const cum=_cumLen(path), total=cum[cum.length-1]||1; let best=null;
  for(let i=1;i<path.length;i++){
    const ax=path[i-1][1], ay=path[i-1][0], bx=path[i][1], by=path[i][0];
    const dx=bx-ax, dy=by-ay, len2=dx*dx+dy*dy||1e-12;
    let t=((click[1]-ax)*dx+(click[0]-ay)*dy)/len2; t=Math.max(0,Math.min(1,t));
    const px=ax+t*dx, py=ay+t*dy, d2=(click[1]-px)**2+(click[0]-py)**2;
    if(!best||d2<best.d2){ best={d2, at:[py,px], frac:(cum[i-1]+t*Math.hypot(dx,dy))/total}; }
  }
  return best;
}
export function fracToLatLng(path, frac){
  const cum=_cumLen(path), total=cum[cum.length-1]||1, target=frac*total;
  for(let i=1;i<path.length;i++){ if(cum[i]>=target){ const seg=cum[i]-cum[i-1]||1e-12, t=(target-cum[i-1])/seg;
    return [path[i-1][0]+t*(path[i][0]-path[i-1][0]), path[i-1][1]+t*(path[i][1]-path[i-1][1])]; } }
  return path[path.length-1];
}
// slice the path between two fractions → [lat,lng] sub-path (for the highlighted stretch)
export function sliceByFrac(path, a, b){
  if(a>b){ const t=a; a=b; b=t; }
  const out=[fracToLatLng(path,a)]; const cum=_cumLen(path), total=cum[cum.length-1]||1;
  for(let i=0;i<path.length;i++){ const f=cum[i]/total; if(f>a && f<b) out.push(path[i]); }
  out.push(fracToLatLng(path,b)); return out;
}
export function drawLine(id, latlngs, color, layer, f){
  if(!map.getSource(id)) map.addSource(id,{type:'geojson',data:{type:'Feature',properties:{},
    geometry:{type:'LineString',coordinates:latlngs.map(p=>[p[1],p[0]])}}});
  if(!map.getLayer(id+'-case')) map.addLayer({id:id+'-case',type:'line',source:id,
    layout:{'line-cap':'round','line-join':'round'},
    paint:{'line-color':'#FBF4E4','line-width':9,'line-opacity':.95}});
  if(!map.getLayer(id)) map.addLayer({id,type:'line',source:id,
    layout:{'line-cap':'round','line-join':'round'},
    // Routes render in a PRE-BLENDED lighter orange at full opacity (60%
    // brand #FF5A1F over the cream basemap) instead of a translucent line:
    // translucent lines stacked wherever routes share a road, making some
    // segments read darker orange than others. Selection styling (full
    // brand color + dimmed siblings) lives in highlightRoute().
    paint:{'line-color':layer.key==='experience'?ROUTE_BASE_COLOR:color,'line-width':5,'line-opacity':1}});
  dynamicIds.push(id);
  if(!boundLayerIds.has(id)){
    map.on('click',id,()=>openDrawer(layer,f));
    map.on('mouseenter',id,()=>map.getCanvas().style.cursor='pointer');
    map.on('mouseleave',id,()=>map.getCanvas().style.cursor='');
    boundLayerIds.add(id);
  }
}
// A · Road surface — colour + pattern by surface class (solid paved · dashed gravel · dotted pavé)
export const SURFACE_STYLE={
  cycleway:{color:'#3E9C8A'},                        // smooth RAVeL asphalt — solid teal
  paved:{color:'#4E6E66'},                           // asphalt/concrete — solid slate
  gravel:{color:'#C8923A',dash:[2,1.5],cap:'butt'},  // gravel/compacted — dashed ochre
  pave:{color:'#6E7B96',dash:[1,1.5],cap:'butt'},    // sett/cobbles (pavé) — square slate-grey dashes (matches the legend; distinct from brown ground)
  dirt:{color:'#6E5849',dash:[2,1.5],cap:'butt'},    // dirt — dashed brown
  rock:{color:'#5F5A54',dash:[1,2],cap:'butt'},      // rock — rough technical, dark grey dots
  unverified:{color:'#D92D20',dash:[2.5,2.5],cap:'butt'} // OSM has no surface tag — red dashes over the white casing ("needs a tag")
};
export const surfaceStyle=cls=>SURFACE_STYLE[cls]||{color:'#4E8C84'};
// Consolidated A-layer rendering (frontend review 2026-07-12 C3+C4): ONE
// GeoJSON source for ALL segments + one shared casing layer + one line layer
// per surface class (dash/cap can't vary per feature within a layer), instead
// of a source and two layers PER SEGMENT (~350 sources / ~700 layers, each an
// individual draw call) with four listeners each (~1400 hit-tests per pointer
// move). Re-renders are a single setData; listeners bind once per class layer
// and resolve the clicked feature via properties.idx.
export const SURFACE_CLS=Object.keys(SURFACE_STYLE).concat('other');   // 'other' = unknown class → default solid teal
export const surfaceClsLayerIds=()=>SURFACE_CLS.map(c=>'surface-cls-'+c);
export function renderSurfaceLayer(layer, visible){
  const feats=[];
  if(visible) layer.features.forEach((f,i)=>{
    if(!((mode()==='all')||!layer.exp||f.cur)) return;   // same visibility rule as featureVisible()
    if(!inScope(f.rid)) return;                        // region scope gate (map-and-search.md §4.5)
    feats.push({type:'Feature',
      properties:{idx:i, cls:SURFACE_STYLE[f.surfaceClass]?f.surfaceClass:'other'},
      geometry:{type:'LineString',coordinates:f.geom.path.map(p=>[p[1],p[0]])}});
  });
  const data={type:'FeatureCollection',features:feats};
  if(map.getSource('surface-src')){ map.getSource('surface-src').setData(data); return feats.length; }
  map.addSource('surface-src',{type:'geojson',data});
  map.addLayer({id:'surface-case',type:'line',source:'surface-src',
    layout:{'line-cap':'round','line-join':'round'},
    paint:{'line-color':'#FBF4E4','line-width':8,'line-opacity':.9}});
  const w=['interpolate',['linear'],['zoom'],9,3,13,5,16,8];
  const featAt=e=>layer.features[e.features[0].properties.idx];
  SURFACE_CLS.forEach(cls=>{
    const st=surfaceStyle(cls), id='surface-cls-'+cls;
    const paint={'line-color':st.color,'line-width':w,'line-opacity':1};
    if(st.dash) paint['line-dasharray']=st.dash;
    map.addLayer({id,type:'line',source:'surface-src',
      filter:['==',['get','cls'],cls],
      layout:{'line-cap':st.cap||'round','line-join':'round'},paint});
    map.on('click',id,e=>{ const f=featAt(e); if(f) openDrawer(layer,f); });
    map.on('mouseenter',id,()=>map.getCanvas().style.cursor='pointer');
    map.on('mousemove',id,e=>{ const f=featAt(e); if(f) showTip(f.headline||f.name, e.lngLat); });   // surface type (e.g. "Asphalt · Excellent") on hover
    map.on('mouseleave',id,()=>{ map.getCanvas().style.cursor=''; hideTip(); });
  });
  return feats.length;
}
/* null = that chip group is not on the page, which means NO filter — not "an
   empty selection", which would mean "hide everything". The distinction is
   load-bearing: the sq/tr predicate below is exact-match with no all-on
   escape, so an absent #sqf returning an empty Set would make every climb
   invisible with nothing on screen to explain why. The rail can legitimately
   ship without a facet group (2026-08-02: the unfinished ones were removed),
   so every reader of these Sets has to treat null as pass-through. */
export const chipSet=id=>{
  const el=document.getElementById(id);
  if(!el) return null;
  const s=new Set(); el.querySelectorAll('.chip.on').forEach(c=>s.add(c.dataset.v)); return s;
};
export let activeSurface=chipSet('sqf'), activeTraffic=chipSet('trf');
// C2-T8 (spec §W2, D2): filters for the new difficulty/suitability attributes —
// climbs' 'effort' (CatalogFormRegistry Climbs.effort) and stays' 'accessibility'
// (CatalogFormRegistry WhereToSleep.accessibility). Vocab lists mirror the registry.
export const ALL_EFFORT=new Set(['Steady','Challenging','Tough','Very steep']);
export const ALL_ACCESS=new Set(['Step-free access','Handbike-friendly','Wheelchair-accessible']);
// sq/tr mirror CatalogFormRegistry Climbs.sq / Climbs.tr — ALL FIVE road
// qualities, including 'Broken / loose'. The chip list used to stop at four,
// and because sq/tr were exact-match with no all-on escape, a climb edited to
// the fifth value did not merely fail the filter: it disappeared from the map
// with every chip lit. Any value added to the registry has to be added here
// AND to the chips in map/index.html.twig.
export const ALL_SURF=new Set(['Smooth','Good','Worn','Rough','Broken / loose']);
export const ALL_TRAF=new Set(['Traffic-free','Quiet','Moderate','Busy']);
// "Narrowing" semantics, deliberately different from the pre-existing sq/tr chips above
// (which always require a matching value, hiding any climb missing sq/tr regardless of
// chip state): most existing items predate effort/accessibility, so with every chip on
// (the default) nothing is filtered — including items with no value for the attribute.
// As soon as a rider deselects at least one option, items with no value are hidden too,
// since they can't be confirmed to match the narrowed selection.
/* Unknown is not a verdict.

   An item with NO value for the attribute is not filtered out — we know
   nothing about it, and hiding it would state something we have not been
   told. Only an item that HAS a value can be judged, and it survives if any
   of its values is still selected. This is the same rule prefMatch() already
   applies to route bike types ("unknown ≠ unsuitable").

   It replaces the earlier "narrowing" rule, where deselecting one chip also
   hid every valueless item. On stays that was indefensible: 0 of 291 carry
   `accessibility`, so unticking one box emptied the layer and told the rider
   there are no accessible stays, when what we actually have is no data.
   (2026-08-03, owner.) */
export function attrMatch(value, activeSet, allSet){
  if(!activeSet) return true;                    // group not on the page → no filter
  if(activeSet.size===allSet.size) return true;  // nothing narrowed → nothing hidden
  // A multiselect attribute (stays' accessibility) arrives as a list: the item
  // matches if it carries ANY of the selected options. A rider filtering for
  // "handbike-friendly" wants every stay that is handbike-friendly, not the
  // ones that are ONLY that.
  if(Array.isArray(value)) return value.length ? value.some(v=>activeSet.has(v)) : true;
  return value ? activeSet.has(value) : true;
}
export let activeEffort=chipSet('effortf'), activeAccess=chipSet('accessf');

// The chip facets and the preference toggle are OWNED here but DRIVEN from the
// rail, which is panels.js territory and still in the entry. An ES module
// import is a read-only binding, so the entry re-reads and flips them through
// these rather than assigning across the boundary (§5 recipe step 4).
export function syncFacetChips(){
  activeSurface=chipSet('sqf'); activeTraffic=chipSet('trf');
  activeEffort=chipSet('effortf'); activeAccess=chipSet('accessf');
}
export const prefFilterEnabled = () => prefFilterOn;
export function setPrefFilter(on){ prefFilterOn=!!on; }
// The stays-accessibility test as ONE exported predicate. osm-pools.js and
// coverage.js both need it and both used to take it injected; exporting the Set
// instead would hand them a snapshot that goes stale on the next chip click.
export const staysAccessible = p => attrMatch(p.accessibility, activeAccess, ALL_ACCESS);
// stays' coverage tile layer narrows on the accessibility filter above;
// confirmed/clustered stays are filtered in updateConfMarkers().
export function applyStaysAccessFilter(){
  // Coverage stays' individual icons narrow on the flat `acc` tile prop
  // (coverage-provider.md §6) — the dedupe + region-scope arms (covIconFilter)
  // are the base and must survive every setFilter. The acc narrow composes
  // over the single coverage icon layer only (no cluster bubbles exist for
  // coverage, per coverage-provider.md §4).
  // Same "unknown is not a verdict" rule as attrMatch: a coverage stay with no
  // `acc` prop at all survives every narrowing — only a stay that HAS one is
  // judged by it. `!has acc` is the tile-expression spelling of "no value".
  const extra = (!activeAccess || activeAccess.size===ALL_ACCESS.size) ? null
    : ['any', ['!', ['has', 'acc']], ['in', ['get','acc'], ['literal', Array.from(activeAccess)]]];
  // stays split per country (Task 4): narrow every stays-<cc>-cov icon layer,
  // not just a single hardcoded id. cc===null is the pre-split 'stays-cov' id.
  COVERAGE_CCS.forEach(cc=>{
    const id = cc ? 'stays-'+cc+'-cov' : 'stays-cov';
    if(map.getLayer(id)) map.setFilter(id, covIconFilter(extra));
  });
}

// Preference prefilter (spec 2026-07-14): riders' saved bike types filter
// the routes layer. Unknown ≠ unsuitable — a route with NO declared
// bikeTypes stays visible; only a declared non-overlap hides it. Off for
// anonymous visitors (PREFS.bikes empty) and toggleable via the rail chip
// (#prefFilter), persisted in localStorage.
export let prefFilterOn = PREFS.bikes.length>0 && (localStorage.getItem('cc-pref-filter')||'on')==='on';
export function prefMatch(f){
  if(!prefFilterOn || !PREFS.bikes.length) return true;
  if(!f.bikeTypes || !f.bikeTypes.length) return true;          // undeclared → keep
  return f.bikeTypes.some(t=>PREFS.bikes.includes(t));
}
export function featureVisible(layer, f){
  let show = layer.key==='experience' ? (mode()==='all'||f.cur) : ((mode()==='all') || !layer.exp || f.cur);       // experiential layers filter to curated; K honours cur in Curated (best-of), all in Everything
  if(show) show = inScope(f.rid);   // region scope gate (map-and-search.md §4.5)
  if(show && layer.key==='experience') show = prefMatch(f);
  if(show && layer.key==='climbs'){
    // All three now share attrMatch's narrowing semantics. sq/tr used to
    // "always require" a matching value, which hid every climb that predates
    // the attribute or carries a value the chips never listed — a filter that
    // deletes data it cannot describe. With every chip on, nothing is hidden.
    show = attrMatch(f.sq, activeSurface, ALL_SURF)
      && attrMatch(f.tr, activeTraffic, ALL_TRAF)
      && attrMatch(f.effort, activeEffort, ALL_EFFORT);
  }
  return show;
}
// legend count = shown/total: in Curated only confirmed/curated count; in Everything everything does
export function layerCounts(layer){
  const covTotal=coverageTotal(layer.letter);
  // BOTH tiers' totals are scope-aware: coverage covTotal is a server-side
  // per-scope count, and the curated features are gated to the active scope
  // (inScope) too — so an out-of-scope region reads 0/0, not 0/<global>. Without
  // this the curated total is the whole in-memory set: e.g. Wallonia's 351
  // surfaces / 15 climbs leaked into Gelderland's rail as "0/351", "0/15" even
  // though none are in Gelderland (owner-reported 2026-07-24). Everywhere still
  // shows the global total (inScope returns true for every rid there).
  const curated=layer.features.filter(f=>inScope(f.rid)).length;
  const shown=layer.features.filter(f=>featureVisible(layer,f)).length + covShownCount(layer.key);
  return {shown, total:curated+covTotal};
}
export function updateCounts(){
  CATALOG.forEach(layer=>{
    const c=layerCounts(layer), el=document.querySelector(`#layers .layer[data-key="${layer.key}"] .ct`);
    if(el) el.textContent=`${c.shown}/${c.total}`;
  });
}
export function render(){
  // Style-load race (surfaced by the consolidated A-source, C3): the initial
  // best-of fetch can resolve BEFORE map 'load', and addSource/addLayer throw
  // on a not-yet-loaded style. Skip early calls — the 'load' handler runs
  // render() itself, and it sees all state mutated so far (f.cur, mode, …).
  if(!styleReady()) return;
  markers.forEach(m=>m.remove()); markers=[];
  clearDynamic();
  // Community tier (07-15 decision B): utility coverage draws lightly in
  // Curated; experiential coverage only in Everything. See syncCoverageLayers.
  syncCoverageLayers();
  let n=0;
  CATALOG.forEach(layer=>{
    // The consolidated surface source is persistent (never torn down by
    // clearDynamic), so an inactive A layer must explicitly render empty.
    if(layer.kind==='surface'){
      n+=renderSurfaceLayer(layer, active.has(layer.key));
      return;
    }
    if(!active.has(layer.key)) return;
    if(layer.kind==='point'){
      layer.features.forEach((f,i)=>{
        if(!featureVisible(layer,f)) return;
        if(f.route){                                    // climbs: draw the gradient-coloured road line + steepest marker
          if(f.grad) drawClimbLine(`route-${layer.key}-${i}`, f.route, f.grad, layer, f);
          else drawLine(`route-${layer.key}-${i}`, f.route, layer.color, layer, f);
          if(f.steep){
            const sEl=document.createElement('div');
            sEl.className='cc-steep'; sEl.textContent=f.steep.pct;
            sEl.title=`${D.steepest||'Steepest pitch'} · ${f.steep.pct}`;
            // stopPropagation: without it this click bubbles to the map container and
            // ALSO fires the underlying route/line layer's own map.on('click', id, …)
            // handler (MapLibre hit-tests canvas-rendered layers under DOM markers
            // regardless of what DOM element the click actually landed on) — which can
            // open a second, unrelated feature's drawer right behind this one and win
            // the C1-T3 history race with an empty (wrong-item) response. See loadItemHistory.
            sEl.addEventListener('click',e=>{ e.stopPropagation(); openDrawer(layer,f); flyToPin([f.steep.at[1],f.steep.at[0]]); });
            const sm=new maplibregl.Marker({element:sEl,anchor:'center'})
              .setLngLat([f.steep.at[1],f.steep.at[0]]).addTo(map);
            markers.push(sm);
          }
        }
        const el = pinEl(layer,f.cur,f);
        el.style.cursor='pointer';
        el.tabIndex=0; el.setAttribute('role','button');
        el.setAttribute('aria-label', `${f.name} — ${f.headline}`);
        const start = pinPoint(f);   // climbs: pin sits at the start (foot) — util.js owns the rule
        const lngLat=[start[1],start[0]];
        // stopPropagation: see the note above the steep-marker's click handler —
        // same click-bleed-through-to-the-map-canvas issue, same fix.
        el.addEventListener('click', e=>{ e.stopPropagation(); openDrawer(layer,f); flyToPin(lngLat); });
        el.addEventListener('mouseenter', ()=>showTip(`${f.name} · ${f.headline}`, lngLat));
        el.addEventListener('mouseleave', hideTip);
        el.addEventListener('focus', ()=>showTip(`${f.name} · ${f.headline}`, lngLat));
        el.addEventListener('blur', hideTip);
        el.addEventListener('keydown', e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); openDrawer(layer,f); flyToPin(lngLat); }});
        const m=new maplibregl.Marker({element:el,anchor:'bottom'})
          .setLngLat(lngLat)
          .addTo(map);
        markers.push(m); n++;
      });
      return;
    }
    if(layer.kind==='line'){
      layer.features.forEach((f,i)=>{
        // The FULL visibility rule, same as the legend counts with: mode/cur
        // (Route domain phase 4), the region scope gate, and the bike-pref
        // prefilter. Review 07-20 finding 1: this branch used to re-implement
        // only the mode/cur half, so route lines leaked into out-of-scope
        // views (map showed 11 while the legend said 0/11) — never inline a
        // subset of featureVisible() here.
        if(!featureVisible(layer,f)) return;
        drawLine(`${layer.key}-${i}`, f.geom.path, layer.color, layer, f);
        n++;
      });
      return;
    }
    // (the old 'area' render branch was dead — no CATALOG entry has that kind,
    // and it lacked the getSource guard its siblings have; removed, review W35)
  });
  // confirmed/validated points are clustered (count bubble → category icon pins); unverified stay as dots
  updateConfMarkers();
  // stacking, bottom → top: ride lines, climb lines, road-surface lines, then Mapillary on top
  const liftGroup=id=>{ if(map.getLayer(id+'-case')) map.moveLayer(id+'-case'); if(map.getLayer(id)) map.moveLayer(id); };
  dynamicIds.filter(id=>id.startsWith('experience-')).forEach(liftGroup);    // ride lines (bottom of the three)
  liftInfoLayersAboveRoutes();                                               // climbs, surface, Mapillary above them
  // render() rebuilds every dynamic layer from scratch, which drops the
  // selection styling + z-order — re-apply it so toggling a layer (surface),
  // switching best-of facets, or changing mode never loses the highlighted
  // route. Guarded: if the selection was filtered out (e.g. not in the current
  // best-of), highlightRoute's moveLayer/setPaint calls simply no-op.
  if(selectedRouteLayerId && map.getLayer(selectedRouteLayerId)) highlightRoute(selectedRouteLayerId);
  document.getElementById('count').textContent=n;
  updateCounts();   // legend shows shown/total, refreshed on mode + layer changes
}
