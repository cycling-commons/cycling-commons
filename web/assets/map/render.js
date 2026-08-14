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
         featurePoint, pinPoint, currentSeason, ccUrl, wc, featureSummary } from './util.js';
import { CATALOG, active, layerByKey, mode, LETTER_KEY, KEY_LETTER } from './catalog.js';
import { inScope, curScope } from './scope-ui.js';
import { pinEl, miniIcon } from './icons.js';
import { updateConfMarkers, confShownCount, confTotalCount } from './osm-pools.js';
import { covShownCount, coverageTotal, syncCoverageLayers, covIconFilter,
         COVERAGE_CCS, COVERAGE_ON, COVERAGE_KEYS } from './coverage.js';
import { openDrawer } from './drawer.js';

export const PREFS = window.CC_PREFS || {bikes: [], styles: []};

/* Seasonal ride-heatmap (illustrative — built from sample GPX rides).

   Built lazily on the first heatmap-On click (review W43), and since
   2026-08-09 FETCHED lazily too. The ~6,600 points used to arrive inside
   catalog.json: the source was built on demand, but its bytes were on the
   critical path regardless, for a layer that is Off by default and that most
   visitors never turn on (frontend review 2026-08-09, second architectural
   item). They have their own endpoint now — window.CC_HEAT_URL — fetched once,
   the first time somebody asks for the layer.

   Async, so the caller awaits before flipping visibility on a layer that may
   not exist yet. A failed fetch leaves the layer unbuilt and says so: the
   toggle then does nothing visible, which is the same outcome as an empty
   heat set and better than a half-built layer. */
let heatPending=null;
export async function addHeatmap(){
  if(map.getSource('rideheat')) return;
  if(!heatPending){
    const url=window.CC_HEAT_URL;
    heatPending = url
      ? fetch(url).then(r=>{ if(!r.ok) throw new Error('heat.json HTTP '+r.status); return r.json(); })
      // No URL handed over (an older template, or a page that never set it):
      // fall back to whatever the catalog left behind rather than throwing.
      : Promise.resolve((window.CC_ROUTES && window.CC_ROUTES.heat) || []);
  }
  let points;
  try { points = await heatPending; }
  catch(e){ heatPending=null; console.error('Ride heatmap unavailable.', e); return; }
  // A second caller can arrive while the first was awaiting.
  if(map.getSource('rideheat')) return;
  // h = [lat, lng, season, rid] (CatalogProvider::heat()) — rid feeds the
  // scope half of updateHeatFilter() (07-20 review finding 5).
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
/* What a bound layer's click OPENS is looked up here at click time, not
   captured in the closure. The closure captured `f` from the FIRST render, so
   after community.js swapped a feature on an approved edit, clicking the line
   reopened the pre-edit record until reload (frontend review 2026-08-09 #3).
   Every render overwrites its id's entry, so the handler always sees the
   feature currently drawn under that id. */
export const layerTarget=new Map();     // layer id -> {layer, f}
/* The handler FUNCTIONS, so they can be taken off again. map.off() needs the
   same reference map.on() was given, so without this the bindings could only
   ever accumulate: one set per distinct layer id, for as long as the page is
   open. Nothing misbehaved — a handler for a removed layer simply never fires
   — but a rider who spends an evening moving around the map builds a registry
   of dead listeners, and every one of them is queried on every interaction.
   (Frontend review 2026-08-09, the third architectural item.) */
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
/* Everything a layer id owns, released together: the three handlers, its
   membership of boundLayerIds (which scope-ui's selectableLayers() reads, and
   which used to carry stale ids across a render) and its click target. */
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
  // A layer that stops being drawn stops being listened to. Re-drawing the
  // same id re-binds it, and the click target is read at click time either
  // way, so the stale-closure fix above is untouched.
  dynamicIds.forEach(unbindLayer);
  dynamicIds.length=0;
}
// draw a polyline with a light casing so it stays visible over the tinted basemap
// climb line coloured by gradient (line-gradient over the route)
/* Cut a stored line down to the climb it describes.

   `length` is measured to the summit (climb-elevation.md §4a); the drawn line
   may continue past it. Returns the original when there is no measured length
   (a climb not yet re-measured) or when the line already stops at the summit,
   so nothing is hidden that was not already excluded from every published
   figure. The final vertex is interpolated so the line ends exactly at the
   summit rather than at the nearest vertex before it. */
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
  return latlngs;   // the line never reaches the stated length: draw all of it
}

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
  layerTarget.set(id, {layer, f});
  bindLayer(id);
}
// K route selection styling: the selected route gets the full brand orange
// and a slightly wider line; every sibling route dims so the selection is
// unmistakable. Cleared when the drawer closes or a non-route feature opens.
export const ROUTE_BASE_COLOR='#FD986E';   // 60% #FF5A1F pre-blended over #FBF4E4
export const ROUTE_BASE_W=5, ROUTE_BASE_CASE_W=9;
// A SELECTED route reads as an orange halo that the road-surface line
// (3→8 px by zoom, over an 8 px cream case) sits ON TOP of — so you see the
// highlight AND the surface on it. The halo therefore has to clear the
// surface's 8 px case, but only just: the earlier stops (13 px line / 18 px
// case at z13) overshot that constraint into a blob that dwarfed the 5 px
// siblings (owner-reported 2026-08-09). Selection is carried mostly by the
// full brand orange + dimmed siblings anyway; the width bump is a nudge.
export const ROUTE_SEL_W=['interpolate',['linear'],['zoom'],9,7,13,10,16,13];
export const ROUTE_SEL_CASE_W=['interpolate',['linear'],['zoom'],9,10,13,14,16,18];
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
  // quality ticks stay above the class lines they stitch over
  if(map.getLayer('surface-q-case')) map.moveLayer('surface-q-case');
  if(map.getLayer('surface-q')) map.moveLayer('surface-q');
  dynamicIds.filter(id=>id.startsWith('route-climbs-')).forEach(liftGroup);
  ['mly-cov','mly-img'].forEach(id=>{ if(map.getLayer(id)) map.moveLayer(id); });
}
/* The selected SURFACE segment, made visible as a shape (owner 2026-08-13:
   "in the map you can't see how long the surface item is you selected").
   One dedicated geojson source updated on drawer-open with the selected
   segment's own path, drawn as a bright halo under the class line; cleared by
   closeDrawer()/the next open. A paint-property dance like highlightRoute's
   is impossible here — every segment shares one consolidated source. */
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
  // Under the class lines, so the segment's own colour stays readable inside
  // the halo.
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
  layerTarget.set(id, {layer, f});
  bindLayer(id);
}
/* A · Road surface — colour + pattern by surface class, and NOTHING else.
   (solid paved · dashed gravel · dotted pavé)

   `cycleway` was a colour here until 2026-08-12 and should not have been: road
   TYPE is a different question from what is under the tyres, and a scale that
   answers both answers neither. Most Dutch cycleways are asphalt, some are not,
   and the purple line could not say which. Road type is its own channel now —
   a pale core drawn inside whatever surface colour the way has (CYCLEWAY_CORE
   below, surface-tiles.js). */
/* SOLID colour per class (owner 2026-08-14): the dash channel now belongs to
   the QUALITY ticks alone — a gravel road can be smooth or rough, and an
   amber tick over dashed ochre was two dash patterns fighting on one line.
   Colour answers "what is it", the stitch answers "how does it ride". The one
   exception is `unverified`, whose red dash IS its meaning ("nobody has
   said") — it draws no quality ticks, so nothing competes. */
export const SURFACE_STYLE={
  paved:{color:'#4E6E66'},                           // asphalt/concrete — slate
  gravel:{color:'#C8923A'},                          // gravel/compacted — ochre
  pave:{color:'#6E7B96'},                            // sett/cobbles (pavé) — slate-grey
  dirt:{color:'#6E5849'},                            // dirt — brown
  rock:{color:'#5F5A54'},                            // rock — dark grey
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
/* Quality tones for the curated ticks — the A form's five smoothness values.
   Kept in step with surface-tiles.js SM_TONE (the OSM-skin ticks), which maps
   OSM's eight raw values onto the same palette; not imported from there
   because surface-tiles imports THIS module. */
const CURATED_SM_TONE={excellent:'#1E8E4F',good:'#5FA845',intermediate:'#D9A62E',bad:'#D4763B',very_bad:'#C2402F'};
export function renderSurfaceLayer(layer, visible){
  const feats=[];
  if(visible) layer.features.forEach((f,i)=>{
    if(!(mode()==='confirmed' ? (f.v||f.cur) : ((mode()==='all')||!layer.exp||f.cur))) return;   // same visibility rule as featureVisible()
    if(!inScope(f.rid)) return;                        // region scope gate (map-and-search.md §4.5)
    // The recorded smoothness rides along as a tone key, so OUR items get the
    // same quality ticks the OSM skin has — a rider who just recorded a road
    // as Excellent saw no ticks on it while the legend promised them "where
    // recorded" (owner-reported 2026-08-14).
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
    map.on('mousemove',id,e=>{ const f=featAt(e); if(f) showTip(f.headline||featureSummary(f,D)||f.name, e.lngLat); });   // surface type (e.g. "Asphalt · Excellent") on hover
    map.on('mouseleave',id,()=>{ map.getCanvas().style.cursor=''; hideTip(); });
  });
  // The quality ticks over OUR lines — same stitch as the OSM skin's surfq-
  // layers (surface-tiles.js): short butt-capped dashes in the smoothness
  // tone, only where a smoothness is recorded, from z13 (below that they
  // would be confetti). Wider than the skin's because the curated lines
  // underneath are wider — and with a cream casing tick underneath, because
  // the excellent/good greens sit on the paved slate and vanish without one
  // (the skin's thin light lines don't have that problem). The casing's dash
  // is the tick's scaled by the width ratio: dash units are line-widths, so
  // equal physical periods keep the two patterns in step.
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
// Keyed per ACCOUNT (uuid), not globally: a shared browser must not hand one
// rider's "off" to the next (same shared-device rule as the view-mode key).
// Anonymous visitors never read it — PREFS.bikes is empty without a profile.
export const PREF_FILTER_KEY = 'cc-pref-filter:'+(PREFS.uid||'anon');
export let prefFilterOn = PREFS.bikes.length>0 && (localStorage.getItem(PREF_FILTER_KEY)||'on')==='on';
export function prefMatch(f){
  if(!prefFilterOn || !PREFS.bikes.length) return true;
  if(!f.bikeTypes || !f.bikeTypes.length) return true;          // undeclared → keep
  return f.bikeTypes.some(t=>PREFS.bikes.includes(t));
}
export function featureVisible(layer, f){
  /* The PENDING layer is a work queue, not a view of a region. It is already
     scoped server-side to the curator's own moderation area, and putting it
     through the map's region gate as well meant a curator whose map happened to
     be scoped elsewhere saw "Pending review 0/0" and concluded there was
     nothing to do (owner-reported 2026-08-12: submissions were only findable by
     arriving from the desk, whose link widens the scope to Everywhere).

     Two scopes for one question is one too many, and the server's is the one
     with authority. */
  if(layer.pendingLayer) return true;
  /* Three rungs of human endorsement (owner 2026-08-12):
       all       — everything we hold, including imports nobody has checked;
       confirmed — anything a human vouched for: a rider who stood there and
                   confirmed it, a curator who verified it, or a curated pick
                   (which is why Confirmed CONTAINS Curated rather than sitting
                   beside it);
       curated   — the editor's best-of only.
     `f.v` is the server's own "somebody vouched" flag (CatalogProvider), the
     same one the drawer reads for the "?" badge, so the filter and the badge
     can never tell a rider different things. */
  let show = mode()==='confirmed'
    ? (!!f.v || !!f.cur)
    : (layer.key==='experience' ? (mode()==='all'||f.cur) : ((mode()==='all') || !layer.exp || f.cur));       // experiential layers filter to curated; K honours cur in Curated (best-of), all in Everything
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
  // The pending layer answers to the curator's moderation area, not the map's
  // region scope (see featureVisible) — so its total is its whole set.
  const curated=layer.pendingLayer ? layer.features.length : layer.features.filter(f=>inScope(f.rid)).length;
  /* Three tiers draw a layer, so three tiers count it: the features render()
     walks, the coverage tiles, and the curated POOL pins (osm-pools.js), which
     live outside both and were counted nowhere — the rail said 0/1481 under a
     map showing pins (owner-reported 2026-08-12). */
  const shown=layer.features.filter(f=>featureVisible(layer,f)).length
    + covShownCount(layer.key) + confShownCount(layer.key);
  return {shown, total:curated+covTotal+confTotalCount(layer.key)};
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
          /* lineGrad colours the LINE; grad draws the chart's bars. Two series
             on purpose: bars sit at fixed bin boundaries so columns stay
             comparable between climbs, while the line is coloured by the
             sustained gradient AT each point. That is what puts the darkest
             stretch where the steepest-ramp marker is — colouring from fixed
             bins left La Redoute's marker reading 17% at 970 m while the
             darkest band sat at 1100 m reading 15%, two different pieces of
             road (owner-reported 2026-08-05). Falls back to the bars for a
             climb not yet re-measured. */
          /* The line takes its colour from the BARS, so the same stretch of
             road is the same colour in both places (owner, 2026-08-05). They
             used to be separate series — the bars at fixed bin boundaries, the
             line from a window sliding every ~25 m — and the two disagreed
             about the colour band on up to half the bars of a climb, which is
             indefensible when they are two pictures of one profile.

             `lineGrad` is kept in the payload: it is the finer measure, and it
             is what the steepest-ramp marker is derived from, so it explains
             why the marker can sit on a bar that is not the darkest — a 100 m
             ramp inside a 200 m bin is genuinely averaged down by its bar. */
          const lineG = (f.grad && f.grad.length) ? f.grad : f.lineGrad;
          /* Draw the CLIMB, not necessarily the whole stored line. A line that
             runs past its summit is longer than the climb, and line-progress
             spreads the colour bands over whatever geometry is drawn — so
             rendering the full route stretched every band AND showed a
             descending blue tail beside a chart and a length that both stopped
             at the summit (owner-reported 2026-08-05). The overshoot stays in
             `route`: it is a contribution, and §4a warns rather than discarding
             it. It is simply not part of the climb. */
          const climbRoute = trimToClimb(f.route, f.length);
          if(lineG) drawClimbLine(`route-${layer.key}-${i}`, climbRoute, lineG, layer, f);
          else drawLine(`route-${layer.key}-${i}`, climbRoute, layer.color, layer, f);
          /* The summit, marked. The pin sits at the FOOT (pinPoint), so without
             this a climb has no visible end at all — the line just stops, and on
             a pass whose last kilometres are gentle it is genuinely unclear
             whether it stopped at the top or ran out of data.

             It also fixes selecting one climb of several. Climbs that share a
             valley town share a foot EXACTLY — Grimsel and Susten both start at
             Innertkirchen, so their pins stack and only the upper one can be
             clicked. Summits are always distinct, so every climb now has one
             target that is unambiguously its own (owner-reported 2026-08-07). */
          const summitAt = climbRoute && climbRoute.length ? climbRoute[climbRoute.length-1] : null;
          if(summitAt){
            const fEl=document.createElement('div');
            fEl.className='cc-summit';
            fEl.textContent=D.climbFinish||'SUMMIT';
            fEl.title=`${f.name} · ${D.climbFinish||'SUMMIT'}`;
            fEl.tabIndex=0; fEl.setAttribute('role','button');
            fEl.setAttribute('aria-label', `${f.name} — ${D.climbFinish||'SUMMIT'}`);
            // stopPropagation: see the note on the steep marker's handler below.
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
          /* The RIDER's steepest point, alongside ours rather than instead of
             it. Ours is the steepest sustained 100 m the elevation model can
             see; theirs is where the wall actually is, on a road they have
             ridden — and the model cannot answer that at all, because a hairpin
             smaller than one DEM cell is invisible to it at any window width
             (climb-elevation.md §5a). Distinct icon so the two are never
             mistaken for each other. */
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
        // stopPropagation: see the note above the steep-marker's click handler —
        // same click-bleed-through-to-the-map-canvas issue, same fix.
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
  updateZoomHint();
}

/* Say why the map is empty when the reason is the zoom.
   The coverage tileset is built z6-14 and its icon layers start at z9, so
   below z6 there is no coverage data to draw and between z6 and z9 there is
   only the density blur. Both are deliberate, and both look exactly like
   missing data: the owner reported "no POIs" twice, once at z6.6 and once at
   z5.5, and each took a dig through the pipeline, the tiles and the scope
   filter to land on "that is the zoom". The map should have said so.
   Only shown while at least one full-coverage layer is on, so it never
   nags a rider who has deliberately turned them all off. */
export function updateZoomHint(){
  const el = document.getElementById('zoomHint');
  if(!el) return;
  const z = map.getZoom();
  const anyCoverage = COVERAGE_KEYS.some(([key]) => active.has(key));
  /* The pending layer follows the CURATOR'S AREAS, not the map's region scope
     (featureVisible), so scoping the map to one region and still seeing pins
     somewhere else is correct and looks exactly like a bug - the owner read it
     as one on 2026-08-14 while scoped to Free State with a queue in North
     Holland. Say which it is; the alternative is re-scoping the queue, which is
     the thing that made "Pending review 0/0" on 2026-08-12. */
  const pendingLayer = CATALOG.find(l => l.pendingLayer);
  const pendingOff = !!pendingLayer && active.has(pendingLayer.key)
    && (pendingLayer.features || []).length > 0
    && !!curScope() && curScope().kind !== 'everywhere';
  const msg = pendingOff ? (I18N.pendingFollowsAreas || 'Pending review follows your moderation areas, not the map scope')
    : !anyCoverage ? ''
    : z < 6 ? (I18N.zoomForCoverage || 'Zoom in to see the full-coverage layers')
    : z < 9 ? (I18N.zoomForPlaces || 'Shown as density here, zoom in for individual places')
    : '';
  el.textContent = msg;
  el.hidden = !msg;
}
