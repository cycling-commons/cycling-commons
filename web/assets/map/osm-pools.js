// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The bulk-OSM POI pools and the confirmed-pin cluster machinery.

   These pools no longer DRAW the uncurated points — that moved to the coverage
   tile layers (coverage.js). What survives here is the registry the rest of the
   map still reads off them: the confirmed (validated/simulated) subset, which
   renders as clustered DOM pins, and the per-layer OSM source strings the
   drawer falls back to.
   Extracted from map.js by 2026-07-26-map-js-module-split-design.md §5.

   Cluster rendering is deliberately reconciled on moveend/idle only, never per
   render frame — see updateConfMarkers()'s call site in the entry.

   Drawer, tip and stays-accessibility hooks arrive through initOsmPools(deps):
   their owning modules (drawer.js, sheet.js, render.js) are still inside the
   entry at this point in the split, and each becomes a plain import as it lands
   (§5 steps 5-6). initOsmPools() has no side effect of its own — it is a
   dependency handover, which is why the entry may call it early. */
import { map, flyToPin } from './map-init.js';
import { showTip, hideTip } from './sheet.js';
import { layerByKey, active } from './catalog.js';
import { inScope } from './scope-ui.js';
import { mintWaterDrops, pinEl, clusterEl } from './icons.js';
import { staysAccessible } from './render.js';

// Injected by initOsmPools() until their owning modules exist (see the header).
let openDrawer, osmDrawer, waterDrawer;

export function initOsmPools(deps){
  ({openDrawer, osmDrawer, waterDrawer} = deps);
}

// Water POI registry entry + confirmed-pin data (display moved to the
// coverage tile layer, addCoverage() — this only feeds osmLayers so
// setupConfClusters()/updateConfMarkers() keep rendering confirmed water pins).
export function addWaterOsm(){
  if(!window.CC_WATER_OSM || osmLayers['water']) return;
  osmLayers['water']={data:CC_WATER_OSM, water:true};
  mintWaterDrops();
}

// registry of the bulk-OSM POI pools: feeds the confirmed-pin cluster
// machinery (setupConfClusters/updateConfMarkers) and the per-layer drawer
// source strings osmDrawer() falls back to.
export const osmLayers = {};

// Bulk-OSM POI registry entry + confirmed-pin data (display moved to the
// coverage tile layer, addCoverage() — this only feeds osmLayers so
// setupConfClusters()/updateConfMarkers() keep rendering confirmed pins, and
// the per-layer drawer source string osmDrawer() falls back to).
export function addOsmDots(key, data, srcDesc){
  if(!data || osmLayers[key]) return;
  osmLayers[key]={data, src:srcDesc};
}
// --- clustering for confirmed (validated/simulated) points: count bubble at low zoom → icon pins when spread ---
export const confState = {};   // srcId -> {key, layer, info, onScreen:{}}
export function setupConfClusters(){
  Object.keys(osmLayers).forEach(key=>{
    const info=osmLayers[key]; if(!info || !info.data) return;
    const confirmed = info.data.features.filter(f=>f.properties.v);
    const srcId=key+'-conf';
    if(!confirmed.length || map.getSource(srcId)) return;
    map.addSource(srcId,{type:'geojson', cluster:true, clusterRadius:48, clusterMaxZoom:13,
      data:{type:'FeatureCollection', features:confirmed.filter(f=>inScope(f.properties.rid))}});
    // invisible layer so the clustered source loads tiles (querySourceFeatures needs rendered tiles)
    map.addLayer({id:srcId+'-hit', type:'circle', source:srcId, paint:{'circle-radius':0,'circle-opacity':0}});
    confState[srcId]={key, layer:layerByKey[key], info, onScreen:{}, confirmed};
  });
}
// Region scope changed → rebuild each cluster source from its full confirmed
// set, keeping only in-scope features, so cluster counts + leaf pins match the
// scope (region-scoping-design.md §4). updateConfMarkers repaints on the
// resulting sourcedata/idle.
export function refilterClusters(){
  Object.keys(confState).forEach(srcId=>{
    const st=confState[srcId], src=map.getSource(srcId);
    if(src) src.setData({type:'FeatureCollection', features:st.confirmed.filter(f=>inScope(f.properties.rid))});
  });
}
export function confLeafPin(st, p, co){
  const lngLat=[co[0],co[1]], llo={lat:co[1],lng:co[0]};
  const drawerF = st.info.water ? waterDrawer(p, llo) : osmDrawer(st.layer, p, llo, st.info.src);
  const el=pinEl(st.layer, true, p);
  el.style.cursor='pointer'; el.tabIndex=0; el.setAttribute('role','button');
  el.setAttribute('aria-label', drawerF.name+' — '+drawerF.headline);
  // stopPropagation (click-to-scope, 2026-07-22-scope-selector-scale-design.md
  // §C): this DOM marker has no backing rendered layer at its pixel (the
  // '<key>-conf-hit' source layer is a zero-radius circle, purely so the
  // clustered source loads tiles) — without it the click bubbles to the map's
  // generic click handler, which would see "no feature here" and re-scope the
  // map right behind this pin's own drawer-open action.
  el.addEventListener('click', e=>{ e.stopPropagation(); openDrawer(st.layer, drawerF); flyToPin(lngLat); });
  el.addEventListener('mouseenter', ()=>showTip(drawerF.name+' · '+drawerF.headline, lngLat));
  el.addEventListener('mouseleave', hideTip);
  el.addEventListener('focus', ()=>showTip(drawerF.name+' · '+drawerF.headline, lngLat));
  el.addEventListener('blur', hideTip);
  return el;
}
export function updateConfMarkers(){
  Object.keys(confState).forEach(srcId=>{
    const st=confState[srcId], on=st.onScreen;
    if(!active.has(st.key)){ for(const k in on) on[k].remove(); st.onScreen={}; return; }
    if(!map.getSource(srcId) || !map.isSourceLoaded(srcId)) return;
    const feats=map.querySourceFeatures(srcId), next={};
    for(const f of feats){
      const co=f.geometry.coordinates, p=f.properties;
      const key = p.cluster ? 'c'+p.cluster_id : 'l'+co[0].toFixed(5)+','+co[1].toFixed(5);
      if(next[key]) continue;
      // C2-T8: confirmed (clustered) stays pins respect the accessibility filter too —
      // only individual leaf pins are checked (a clustered bubble isn't re-aggregated;
      // the dataset is small enough that this is a non-issue in practice).
      if(!p.cluster && st.key==='stays' && !staysAccessible(p)) continue;
      let m=on[key];
      if(!m){
        if(p.cluster){
          const el=clusterEl(st.layer, p.point_count_abbreviated); el.style.cursor='pointer';
          // MapLibre ≥3: getClusterExpansionZoom returns a Promise (the old
          // callback form is silently ignored — the click did nothing).
          // stopPropagation: same click-to-scope note as confLeafPin above —
          // this bubble has no rendered layer under it either.
          el.addEventListener('click', e=>{ e.stopPropagation(); map.getSource(srcId).getClusterExpansionZoom(p.cluster_id).then(z=>map.easeTo({center:co, zoom:z+0.2})).catch(()=>{}); });
          m=new maplibregl.Marker({element:el, anchor:'center'}).setLngLat(co).addTo(map);
        } else {
          m=new maplibregl.Marker({element:confLeafPin(st, p, co), anchor:'bottom'}).setLngLat(co).addTo(map);
        }
      }
      next[key]=m;
    }
    for(const k in on){ if(!next[k]) on[k].remove(); }
    st.onScreen=next;
  });
}

// The bulk OSM POI pools (layer key, data collection, OSM source note).
// Display moved to the coverage tile layer (addCoverage()); this table now
// only feeds the addOsmDots() registry loop below — the confirmed-pin data
// + per-layer drawer source strings osmDrawer() falls back to. Read straight
// from the inlined CC_*_OSM globals (available synchronously), since
// osmLayers is only populated later on map 'load'.
export const OSM_BULK = [
  ['services', window.CC_SERVICES_OSM, 'OpenStreetMap (shop=bicycle / amenity=bicycle_repair_station / compressed_air)'],
  ['scenic',   window.CC_SCENIC_OSM,   'OpenStreetMap (tourism=viewpoint / natural=peak / waterway=waterfall)'],
  ['history',  window.CC_HISTORY_OSM,  'OpenStreetMap (historic=castle/fort/ruins/monument/memorial/…)'],
  ['stays',    window.CC_STAYS_OSM,    'OpenStreetMap (tourism=camp_site/hostel/guest_house/chalet/hotel/…)'],
  ['shelter',  window.CC_SHELTER_OSM,  'OpenStreetMap (shelter_type=picnic/weather/field/…)'],
  ['transit',  window.CC_TRANSIT_OSM,  'OpenStreetMap (railway=station / railway=halt)']
];
