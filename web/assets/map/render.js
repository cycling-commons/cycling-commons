// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Layer render, filters, view mode.
   @see docs/specs/map-and-search.md §4, §5 */
import { map, flyToPin, styleReady } from './map-init.js';
import { showTip, hideTip } from './sheet.js';
import { I18N, D, LAYER_L10N, tpl, trVal, DIFF_LABELS, CC_SEASON_LABEL } from './i18n.js';
import { escPend, safeHref, stars, slug, txtOn, gradColor, DIFF_PURPLE, haversine,
         featurePoint, pinPoint, currentSeason, ccUrl, wc, featureSummary } from './util.js';
import { CATALOG, active, layerByKey, mode, LETTER_KEY, KEY_LETTER } from './catalog.js';
import { inScope, curScope } from './scope-ui.js';
import { pinEl, miniIcon } from './icons.js';
import { updateConfMarkers, confShownCount, confTotalCount } from './osm-pools.js';
import { covShownCount, coverageTotal, syncCoverageLayers, covIconFilter,
         COVERAGE_CCS, COVERAGE_ON, COVERAGE_KEYS } from './coverage.js';
import { openDrawer } from './drawer.js';
import { attrMatch, narrowingCount, climbChipsMatch, modeShows } from './filters.js';

export const PREFS = window.CC_PREFS || {bikes: [], styles: []};

/* docs/specs/map-and-search.md §11 — heatmap fetched lazily on first On; season and scope share one filter. */
let heatPending=null;
export async function addHeatmap(){
  if(map.getSource('rideheat')) return;
  if(!heatPending){
    const url=window.CC_HEAT_URL;
    heatPending = url
      ? fetch(url).then(r=>{ if(!r.ok) throw new Error('heat.json HTTP '+r.status); return r.json(); })
      // No CC_HEAT_URL: fall back to whatever the catalog left behind.
      : Promise.resolve((window.CC_ROUTES && window.CC_ROUTES.heat) || []);
  }
  let points;
  try { points = await heatPending; }
  catch(e){ heatPending=null; console.error('Ride heatmap unavailable.', e); return; }
  // A second caller can arrive while the first was awaiting.
  if(map.getSource('rideheat')) return;
  // h = [lat, lng, season, rid] — rid feeds the scope half of updateHeatFilter.
  const feats=points.map(h=>({type:'Feature',properties:{season:h[2],rid:h[3]},
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
  updateHeatFilter();   // Layer is built lazily — apply the current season + scope immediately.
}
// docs/specs/map-and-search.md §4.5, §11 — one heat filter: season facet AND region scope.
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
/* Click-time lookup: a stale closure would reopen the pre-edit feature after community swap. */
export const layerTarget=new Map();     // layer id -> {layer, f}
/* Handler functions stored so map.off() can use the same reference map.on() was given. */
const boundHandlers=new Map();          // layer id -> [[event, fn], …]
function bindLayer(id){
  if(boundLayerIds.has(id)) return;
  const onClick=()=>{ const t=layerTarget.get(id); if(t) openDrawer(t.layer, t.f); };
  const onEnter=()=>map.getCanvas().style.cursor='pointer';
  const onLeave=()=>map.getCanvas().style.cursor='';
  map.on('click',id,onClick);
  map.on('mouseenter',id,onEnter);
  map.on('mouseleave',id,onLeave);
  boundHandlers.set(id,[['click',onClick],['mouseenter',onEnter],['mouseleave',onLeave]]);
  boundLayerIds.add(id);
}
/* Everything a layer id owns, released together. */
function unbindLayer(id){
  const handlers=boundHandlers.get(id);
  if(handlers) handlers.forEach(([type,fn])=>map.off(type,id,fn));
  boundHandlers.delete(id);
  boundLayerIds.delete(id);
  layerTarget.delete(id);
}
export function clearDynamic(){
  // remove casing layers first (they share the base source id), then base layer + source
  dynamicIds.forEach(id=>{ const c=id+'-case'; if(map.getLayer(c)) map.removeLayer(c); });
  dynamicIds.forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); if(map.getSource(id)) map.removeSource(id); });
  // A layer that stops being drawn stops being listened to.
  dynamicIds.forEach(unbindLayer);
  dynamicIds.length=0;
}
// Polyline with a light casing over the tinted basemap.
/* docs/specs/climb-elevation.md §4a — trim stored line to measured summit length. */
export function trimToClimb(latlngs, lengthM){
  const want = Number(lengthM);
  if(!Array.isArray(latlngs) || latlngs.length < 2 || !(want > 0)) return latlngs;
  const R=6371000, rad=d=>d*Math.PI/180;
  const seg=(a,b)=>{ const x=rad(b[1]-a[1])*Math.cos(rad((a[0]+b[0])/2)), y=rad(b[0]-a[0]);
    return Math.sqrt(x*x+y*y)*R; };
  let acc=0;
  for(let i=1;i<latlngs.length;i++){
    const d=seg(latlngs[i-1],latlngs[i]);
    if(acc+d >= want){
      const t=(want-acc)/(d||1);
      const end=[latlngs[i-1][0]+t*(latlngs[i][0]-latlngs[i-1][0]),
                 latlngs[i-1][1]+t*(latlngs[i][1]-latlngs[i-1][1])];
      return latlngs.slice(0,i).concat([end]);
    }
    acc+=d;
  }
  return latlngs;   // Line never reaches the stated length: draw all of it.
}

export function drawClimbLine(id, latlngs, grad, layer, f){
  if(!map.getSource(id)) map.addSource(id,{type:'geojson',lineMetrics:true,data:{type:'Feature',properties:{},
    geometry:{type:'LineString',coordinates:latlngs.map(p=>[p[1],p[0]])}}});
  const caseW=['interpolate',['linear'],['zoom'],9,7,13,11,16,17];
  const lineW=['interpolate',['linear'],['zoom'],9,4.5,13,7,16,12];
  if(!map.getLayer(id+'-case')) map.addLayer({id:id+'-case',type:'line',source:id,
    layout:{'line-cap':'round','line-join':'round'},
    paint:{'line-color':'#FBF4E4','line-width':caseW,'line-opacity':.95}});
  /* Hard bands (`step`), not `interpolate` — same edges as the profile bars. */
  const n=grad.length;
  const expr=['step',['line-progress'],gradColor(grad[0])];
  for(let i=1;i<n;i++){ expr.push(i/n); expr.push(gradColor(grad[i])); }
  if(!map.getLayer(id)) map.addLayer({id,type:'line',source:id,
    layout:{'line-cap':'round','line-join':'round'},
    paint:{'line-width':lineW,'line-gradient':expr}});
  dynamicIds.push(id);
  layerTarget.set(id, {layer, f});
  bindLayer(id);
}
// Selected K route: full brand orange and slightly wider; siblings dim.
export const ROUTE_BASE_COLOR='#FD986E';   // 60% #FF5A1F pre-blended over #FBF4E4
export const ROUTE_BASE_W=5, ROUTE_BASE_CASE_W=9;
// Selected route: orange halo under the surface line so both stay visible.
export const ROUTE_SEL_W=['interpolate',['linear'],['zoom'],9,7,13,10,16,13];
export const ROUTE_SEL_CASE_W=['interpolate',['linear'],['zoom'],9,10,13,14,16,18];
export let selectedRouteLayerId=null;
export const routeLineIds=()=>map.getStyle().layers.map(l=>l.id).filter(id=>/^experience-\d+$/.test(id));
/* Stacking authority — last lifted is highest: routes → surfaces → climbs → Mapillary. */
export function liftInfoLayersAboveRoutes(){
  const liftGroup=id=>{ if(map.getLayer(id+'-case')) map.moveLayer(id+'-case'); if(map.getLayer(id)) map.moveLayer(id); };
  // consolidated A-layer (C3): one shared casing + one layer per surface class
  if(map.getLayer('surface-case')) map.moveLayer('surface-case');
  surfaceClsLayerIds().forEach(id=>{ if(map.getLayer(id)) map.moveLayer(id); });
  // quality ticks stay above the class lines they stitch over
  if(map.getLayer('surface-q-case')) map.moveLayer('surface-q-case');
  if(map.getLayer('surface-q')) map.moveLayer('surface-q');
  dynamicIds.filter(id=>id.startsWith('route-climbs-')).forEach(liftGroup);
  ['mly-cov','mly-img'].forEach(id=>{ if(map.getLayer(id)) map.moveLayer(id); });
}
/* Surface selection halo: dedicated geojson source (segments share one consolidated source). */
export function showSurfaceSelection(path){
  const data={type:'Feature',properties:{},geometry:{type:'LineString',coordinates:(path||[]).map(p=>[p[1],p[0]])}};
  const src=map.getSource('surface-sel-src');
  if(src){ src.setData(data); }
  else {
    map.addSource('surface-sel-src',{type:'geojson',data});
    map.addLayer({id:'surface-sel',type:'line',source:'surface-sel-src',
      layout:{'line-cap':'round','line-join':'round'},
      paint:{'line-color':'#FF5A1F','line-width':10,'line-opacity':.45,'line-blur':2}});
  }
  // Under the class lines, so the segment's own colour stays readable inside the halo.
  if(map.getLayer('surface-sel') && map.getLayer('surface-cls-paved')) map.moveLayer('surface-sel','surface-case');
  map.setLayoutProperty('surface-sel','visibility','visible');
}
export function clearSurfaceSelection(){
  if(map.getLayer('surface-sel')) map.setLayoutProperty('surface-sel','visibility','none');
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
  // Lift selected-route halo, then put climbs / surface / Mapillary back on top.
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
// docs/specs/route-domain.md §7 — located-correction geometry. Path is [lat,lng] points.
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
    // Routes: pre-blended lighter orange at full opacity, not a translucent line.
    paint:{'line-color':layer.key==='experience'?ROUTE_BASE_COLOR:color,'line-width':5,'line-opacity':1}});
  dynamicIds.push(id);
  layerTarget.set(id, {layer, f});
  bindLayer(id);
}
/* A · Road surface: colour = class. Road type is a separate pale core (not a colour here). */
/* Colour = class; dash = quality ticks. Exception: unverified red dash is the meaning. */
export const SURFACE_STYLE={
  paved:{color:'#4E6E66'},                           // asphalt/concrete — slate
  gravel:{color:'#C8923A'},                          // gravel/compacted — ochre
  pave:{color:'#6E7B96'},                            // sett/cobbles (pavé) — slate-grey
  dirt:{color:'#6E5849'},                            // dirt — brown
  rock:{color:'#5F5A54'},                            // rock — dark grey
  unverified:{color:'#D92D20',dash:[2.5,2.5],cap:'butt'} // OSM has no surface tag — red dashes over the white casing ("needs a tag")
};
export const surfaceStyle=cls=>SURFACE_STYLE[cls]||{color:'#4E8C84'};
// One GeoJSON source for all A segments + shared casing + one line layer per class.
export const SURFACE_CLS=Object.keys(SURFACE_STYLE).concat('other');   // 'other' = unknown class → default solid teal
export const surfaceClsLayerIds=()=>SURFACE_CLS.map(c=>'surface-cls-'+c);
/* Quality tones for curated ticks — same palette as surface-tiles.js SM_TONE. */
const CURATED_SM_TONE={excellent:'#1E8E4F',good:'#5FA845',intermediate:'#D9A62E',bad:'#D4763B',very_bad:'#C2402F'};
export function renderSurfaceLayer(layer, visible){
  const feats=[];
  /* Neither the rung gate nor the scope gate. Road surface is an OVERLAY, not
     a data layer, and it has to agree with the OSM skin underneath it, because
     the skin hides itself wherever we hold an item for that way. Any rule that
     drops our line while leaving the skin hidden does not fall back to OSM: it
     leaves the road blank, and the basemap shows through
     (owner-reported 2026-08-31, a road that "went orange" after a third
     confirmation).

     The skin honours neither rung nor scope, so neither do we. That is the
     whole of the agreement, and it is why this is two absent gates rather than
     a second dedupe list: a list computed here could only name the way each
     segment is filed under, never the ways it spans, so it would trade a
     vanishing road for a doubled one. */
  if(visible) layer.features.forEach((f,i)=>{
    const sm=(f.smoothness||'').toLowerCase().replace(/\s+/g,'_');
    feats.push({type:'Feature',
      properties:{idx:i, cls:SURFACE_STYLE[f.surfaceClass]?f.surfaceClass:'other',
        ...(CURATED_SM_TONE[sm]?{sm}:{})},
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
    map.on('mousemove',id,e=>{ const f=featAt(e); if(f) showTip(f.headline||featureSummary(f,D)||f.name, e.lngLat); });   
    map.on('mouseleave',id,()=>{ map.getCanvas().style.cursor=''; hideTip(); });
  });
  // Quality ticks over our lines: same stitch as the OSM skin, from z13.
  map.addLayer({id:'surface-q-case',type:'line',source:'surface-src',
    minzoom:13,
    filter:['has','sm'],
    layout:{'line-cap':'butt'},
    paint:{
      'line-color':'#FBF4E4',
      'line-width':7,
      'line-dasharray':[0.6*4.5/7, 2.8*4.5/7],
      'line-opacity':0.9}});
  map.addLayer({id:'surface-q',type:'line',source:'surface-src',
    minzoom:13,
    filter:['has','sm'],
    layout:{'line-cap':'butt'},
    paint:{
      'line-color':['match',['get','sm'],...Object.entries(CURATED_SM_TONE).flat(),'rgba(0,0,0,0)'],
      'line-width':4.5,
      'line-dasharray':[0.6,2.8],
      'line-opacity':0.95}});
  return feats.length;
}
/* chipSet null = no filter, not empty selection (empty would hide everything). */
export const chipSet=id=>{
  const el=document.getElementById(id);
  if(!el) return null;
  const s=new Set(); el.querySelectorAll('.chip.on').forEach(c=>s.add(c.dataset.v)); return s;
};
export let activeSurface=chipSet('sqf'), activeTraffic=chipSet('trf');
// Effort / accessibility chip vocab mirrors CatalogFormRegistry.
export const ALL_EFFORT=new Set(['Steady','Challenging','Tough','Very steep']);
export const ALL_ACCESS=new Set(['Step-free access','Handbike-friendly','Wheelchair-accessible']);
// ALL_SURF must include 'Broken / loose' — a four-value list hid that class.
export const ALL_SURF=new Set(['Smooth','Good','Worn','Rough','Broken / loose']);
export const ALL_TRAF=new Set(['Traffic-free','Quiet','Moderate','Busy']);
/* attrMatch lives in filters.js (pure; answers 'why is my data missing?'). */
export { attrMatch };
export let activeEffort=chipSet('effortf'), activeAccess=chipSet('accessf');

// Chip facets and the preference toggle are owned here but driven from the rail.
export function syncFacetChips(){
  activeSurface=chipSet('sqf'); activeTraffic=chipSet('trf');
  activeEffort=chipSet('effortf'); activeAccess=chipSet('accessf');
}
export const prefFilterEnabled = () => prefFilterOn;
export function setPrefFilter(on){ prefFilterOn=!!on; }
// Stays-accessibility as one exported predicate (osm-pools.js and coverage.js).
export const staysAccessible = p => attrMatch(p.accessibility, activeAccess, ALL_ACCESS);
// Stays coverage tiles narrow on the accessibility filter; clustered stays in updateConfMarkers.
export function applyStaysAccessFilter(){
  // docs/specs/coverage-provider.md §6 — coverage stays' icons narrow on the flat `acc` tile prop.
  const extra = (!activeAccess || activeAccess.size===ALL_ACCESS.size) ? null
    : ['any', ['!', ['has', 'acc']], ['in', ['get','acc'], ['literal', Array.from(activeAccess)]]];
  // Stays split per country: narrow every stays-<cc>-cov icon layer.
  COVERAGE_CCS.forEach(cc=>{
    const id = cc ? 'stays-'+cc+'-cov' : 'stays-cov';
    if(map.getLayer(id)) map.setFilter(id, covIconFilter(extra));
  });
}

// docs/specs/map-and-search.md §4.4 — bike-type pref filter; unknown ≠ unsuitable.
export const PREF_FILTER_KEY = 'cc-pref-filter:'+(PREFS.uid||'anon');
export let prefFilterOn = PREFS.bikes.length>0 && (localStorage.getItem(PREF_FILTER_KEY)||'on')==='on';
export function prefMatch(f){
  if(!prefFilterOn || !PREFS.bikes.length) return true;
  if(!f.bikeTypes || !f.bikeTypes.length) return true;          // undeclared → keep
  return f.bikeTypes.some(t=>PREFS.bikes.includes(t));
}
/* Chip state in the shape filters.js takes, rebuilt each pass. */
export function chipState(){
  return {
    surface: {active: activeSurface, all: ALL_SURF},
    traffic: {active: activeTraffic, all: ALL_TRAF},
    effort: {active: activeEffort, all: ALL_EFFORT},
    access: {active: activeAccess, all: ALL_ACCESS},
    prefFilterOn: prefFilterOn && PREFS.bikes.length > 0,
  };
}

/* How many catalog features the chip filters alone are keeping off the map. */
let _chipHidden = 0;
export function hiddenByFilters(){
  return {filters: narrowingCount(chipState()), hidden: _chipHidden};
}

/* `tally` only from render()'s own walk (each active layer once). */
export function featureVisible(layer, f, tally){
  /* docs/specs/map-and-search.md (The pending layer is exempt from the region scope) — server-scoped work queue. */
  if(layer.pendingLayer) return true;
  /* docs/specs/map-and-search.md §4.2 — three rungs: all / confirmed / curated. */
  let show = modeShows(mode(), layer, f);   // filters.js owns the rung rule; one reader for render, legend and deep links
  if(show) show = inScope(f.rid);   // docs/specs/map-and-search.md §4.5 — region scope gate; chips below are the rider's.
  if(show && layer.key==='experience' && !prefMatch(f)){ show=false; if(tally) tally.hidden++; }
  if(show && layer.key==='climbs'){
    // All three share attrMatch's narrowing rule (no hidden predates-attribute climbs).
    if(!climbChipsMatch(f, chipState())){ show=false; if(tally) tally.hidden++; }
  }
  return show;
}
// legend count = shown/total: in Curated only confirmed/curated count; in Everything everything does
export function layerCounts(layer){
  const covTotal=coverageTotal(layer.letter);
  // Both tiers' totals are scope-aware so an out-of-scope region reads 0/0, not 0/<global>.
  const curated=layer.pendingLayer ? layer.features.length : layer.features.filter(f=>inScope(f.rid)).length;
  /* Three tiers count a layer: render() features, coverage tiles, curated pool pins. */
  const shown=layer.features.filter(f=>featureVisible(layer,f)).length
    + covShownCount(layer.key) + confShownCount(layer.key);
  return {shown, total:curated+covTotal+confTotalCount(layer.key)};
}
export function updateCounts(){
  CATALOG.forEach(layer=>{
    const c=layerCounts(layer), el=document.querySelector(`#layers .layer[data-key="${layer.key}"] .ct`);
    if(el) el.textContent=`${c.shown}/${c.total}`;
  });
  /* Legend refresh when scope, mode, layer toggle, or best-of changes. */
  document.dispatchEvent(new CustomEvent('cc:counts'));
}
export function render(){
  // Style not ready: skip — map 'load' runs render() itself.
  if(!styleReady()) return;
  markers.forEach(m=>m.remove()); markers=[];
  clearDynamic();
  // docs/specs/map-and-search.md §12 — utility coverage lightly in Curated; experiential only in Everything.
  syncCoverageLayers();
  // Fresh tally per pass: the pill must describe THIS frame, not the last one.
  const tally={hidden:0};
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
        if(!featureVisible(layer,f,tally)) return;
        if(f.route){                                    
          /* lineGrad colours the line; `grad` draws the chart bars. */
          /* Line colour from the bars so the same stretch matches the strip (docs/specs/climb-elevation.md §4a). */
          const lineG = (f.grad && f.grad.length) ? f.grad : f.lineGrad;
          /* docs/specs/climb-elevation.md §4a — draw the climb, not overshoot past the summit. */
          const climbRoute = trimToClimb(f.route, f.length);
          if(lineG) drawClimbLine(`route-${layer.key}-${i}`, climbRoute, lineG, layer, f);
          else drawLine(`route-${layer.key}-${i}`, climbRoute, layer.color, layer, f);
          /* Summit marker: pin sits at the foot, so without this a climb has no visible end. */
          const summitAt = climbRoute && climbRoute.length ? climbRoute[climbRoute.length-1] : null;
          if(summitAt){
            const fEl=document.createElement('div');
            fEl.className='cc-summit';
            fEl.textContent=D.climbFinish||'SUMMIT';
            fEl.title=`${f.name} · ${D.climbFinish||'SUMMIT'}`;
            fEl.tabIndex=0; fEl.setAttribute('role','button');
            fEl.setAttribute('aria-label', `${f.name} — ${D.climbFinish||'SUMMIT'}`);
            // stopPropagation: see the steep-marker handler below.
            const openHere=e=>{ e.stopPropagation(); openDrawer(layer,f); flyToPin([summitAt[1],summitAt[0]]); };
            fEl.addEventListener('click',openHere);
            fEl.addEventListener('keydown',e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); openHere(e); }});
            const fm=new maplibregl.Marker({element:fEl,anchor:'bottom'})
              .setLngLat([summitAt[1],summitAt[0]]).addTo(map);
            markers.push(fm);
          }
          if(f.steep){
            const sEl=document.createElement('div');
            sEl.className='cc-steep'; sEl.textContent=f.steep.pct;
            sEl.title=`${D.steepest||'Steepest pitch'} · ${f.steep.pct}`;
            // stopPropagation: MapLibre hit-tests canvas under DOM markers; races _historyReq otherwise.
            sEl.addEventListener('click',e=>{ e.stopPropagation(); openDrawer(layer,f); flyToPin([f.steep.at[1],f.steep.at[0]]); });
            const sm=new maplibregl.Marker({element:sEl,anchor:'center'})
              .setLngLat([f.steep.at[1],f.steep.at[0]]).addTo(map);
            markers.push(sm);
          }
          /* docs/specs/climb-elevation.md §5a — rider steep marker, distinct from the measured one. */
          if(f.steepPoint && f.steepPoint.at){
            const rEl=document.createElement('div');
            rEl.className='cc-steep cc-steep-rider';
            rEl.textContent=f.steepPoint.pct || (D.riderRamp||'ramp');
            rEl.title=[D.riderSteepest||'Steepest point, marked by a rider',
                       f.steepPoint.pct, f.steepPoint.note].filter(Boolean).join(' · ');
            rEl.addEventListener('click',e=>{ e.stopPropagation(); openDrawer(layer,f); flyToPin([f.steepPoint.at[1],f.steepPoint.at[0]]); });
            const rm=new maplibregl.Marker({element:rEl,anchor:'center'})
              .setLngLat([f.steepPoint.at[1],f.steepPoint.at[0]]).addTo(map);
            markers.push(rm);
          }
        }
        const el = pinEl(layer,f.cur,f);
        el.style.cursor='pointer';
        el.tabIndex=0; el.setAttribute('role','button');
        const summary = featureSummary(f, D);
        el.setAttribute('aria-label', summary ? `${f.name} — ${summary}` : f.name);
        const start = pinPoint(f);   // climbs: pin sits at the start (foot) — util.js owns the rule
        const lngLat=[start[1],start[0]];
        // stopPropagation: same click-bleed-through as the steep marker.
        el.addEventListener('click', e=>{ e.stopPropagation(); openDrawer(layer,f); flyToPin(lngLat); });
        const tipText = summary ? `${f.name} · ${summary}` : f.name;
        el.addEventListener('mouseenter', ()=>showTip(tipText, lngLat));
        el.addEventListener('mouseleave', hideTip);
        el.addEventListener('focus', ()=>showTip(tipText, lngLat));
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
        // Full visibility rule: same as the legend — never inline a subset of featureVisible().
        if(!featureVisible(layer,f)) return;
        drawLine(`${layer.key}-${i}`, f.geom.path, layer.color, layer, f);
        n++;
      });
      return;
    }
  });
  // confirmed/validated points are clustered (count bubble → category icon pins); unverified stay as dots
  updateConfMarkers();
  // stacking, bottom → top: ride lines, climb lines, road-surface lines, then Mapillary on top
  const liftGroup=id=>{ if(map.getLayer(id+'-case')) map.moveLayer(id+'-case'); if(map.getLayer(id)) map.moveLayer(id); };
  dynamicIds.filter(id=>id.startsWith('experience-')).forEach(liftGroup);    
  liftInfoLayersAboveRoutes();                                               // Re-apply selection styling after render() rebuilds dynamic layers.
  if(selectedRouteLayerId && map.getLayer(selectedRouteLayerId)) highlightRoute(selectedRouteLayerId);
  /* The tools drawer no longer shows a count (map/index.html.twig): the number
     it displayed was "Commons features currently drawn", which read as
     "places" and was neither the OSM coverage underneath nor everything
     visible. Guarded rather than deleted, so a surface that wants a live count
     can add the element back and get one. */
  const countEl = document.getElementById('count');
  if (countEl) countEl.textContent = n;
  _chipHidden=tally.hidden;
  /* Event rather than a direct call: this module must not import the chrome. */
  document.dispatchEvent(new CustomEvent('cc:filters', {detail: hiddenByFilters()}));
  updateCounts();   
  updateZoomHint();
}

/* Zoom hint when coverage is on but the tileset/icon floor hides POIs. */
export function updateZoomHint(){
  const el = document.getElementById('zoomHint');
  if(!el) return;
  const z = map.getZoom();
  const anyCoverage = COVERAGE_KEYS.some(([key]) => active.has(key));
  /* docs/specs/map-and-search.md (The pending layer is exempt from the region scope) — different copy for curator vs rider. */
  const isCurator = !!window.CC_IS_CURATOR;
  const pendingLayer = layerByKey['pending'];
  const pendingOn = !!pendingLayer && active.has(pendingLayer.key)
    && (pendingLayer.features || []).length > 0
    && !!curScope() && curScope().kind !== 'everywhere';
  const pendingMsg = isCurator
    ? (I18N.pendingFollowsAreas || 'Pins waiting for review ignore the region filter: they follow the areas you moderate')
    : (I18N.pendingYoursAnywhere || 'Your pins waiting for review show wherever you added them, even outside this region');
  /* Surface-skin zoom floor is explained on the row (and a toast), not this corner. */
  const msg = pendingOn ? pendingMsg
    : !anyCoverage ? ''
    : z < 6 ? (I18N.zoomForCoverage || 'Zoom in to see the full-coverage layers')
    : z < 9 ? (I18N.zoomForPlaces || 'Shown as density here, zoom in for individual places')
    : '';
  el.textContent = msg;
  el.hidden = !msg;

  /* Curator border + hint only when CC_IS_CURATOR — a rider's own pending pins are not curator mode. */
  const mapEl = document.getElementById('map');
  if (mapEl) mapEl.classList.toggle('cc-curator-mode', pendingOn && isCurator);
}
