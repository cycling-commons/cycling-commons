// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The bulk-OSM POI pools and the confirmed-pin cluster machinery.

   These pools no longer DRAW the uncurated points — that moved to the coverage
   tile layers (coverage.js). What survives here is the registry the rest of the
   map still reads off them: the confirmed (validated/simulated) subset, which
   renders as clustered DOM pins, and the per-layer OSM source strings the
   drawer falls back to.
   Extracted from map.js by the module split.

   Cluster rendering is deliberately reconciled on moveend/idle only, never per
   render frame — see updateConfMarkers()'s call site in the entry.

   Every dep this module once took injected is a plain import now that drawer.js
   has landed (§5 steps 5-6). initOsmPools() went with them: it only ever carried
   the handover, and this module has no side effect of its own — the pools are
   built from the entry's map 'load' handler. */
import { map, flyToPin } from './map-init.js';
import { showTip, hideTip } from './sheet.js';
import { D } from './i18n.js';
import { layerByKey, active, mode } from './catalog.js';
import { inScope } from './scope-ui.js';
import { mintWaterDrops, pinEl, clusterEl } from './icons.js';
import { staysAccessible } from './render.js';
import { openDrawer, osmDrawer, waterDrawer } from './drawer.js';

// Water POI registry entry + confirmed-pin data (display moved to the
// coverage tile layer, addCoverage() — this only feeds osmLayers so
// setupConfClusters()/updateConfMarkers() keep rendering confirmed water pins).
export function addWaterOsm(){
  if(!window.CC_WATER_OSM || osmLayers['water']) return;
  osmLayers['water']={data:CC_WATER_OSM, water:true};
  mintWaterDrops();
}

/* What of a pool is on screen, in one place.

   These pools are the CURATED items (catalog.json's C/D/E/G/H/I/J/M) and they
   draw as clustered DOM pins, outside render()'s featureVisible() walk — which
   is why the rail counted them nowhere and read "0/1481" in Confirmed while the
   map plainly showed pins (owner-reported 2026-08-12).

   Two gates, and both belong here rather than at each setData call:
     - the region scope, as before;
     - the view mode: **Confirmed** means somebody vouched for it, so an
       approved-but-unconfirmed item (the faded "help confirm" pin) is not in
       it. Best of and Everything both draw the whole pool, because a utility
       is not an editorial pick and never filtered to `cur`. */
export function poolVisible(f){
  const p = f.properties || {};
  if(!inScope(p.rid)) return false;
  return mode() !== 'confirmed' || !!p.v || !!p.cur;
}

/** How many of a layer's pool pins are drawn right now — the rail's count. */
export function confShownCount(key){
  const st = confState[key+'-conf'];
  if(!st) return 0;

  return st.confirmed.filter(poolVisible).length;
}

/* And how many the layer HOLDS in scope, for the other half of `shown/total`.
   Scope only, never the mode: a total that shrank with the filter would make
   every mode read n/n and tell a rider nothing about what they are not seeing.
   Disjoint from the coverage total by construction — the coverage tiles drop
   every ref we serve as an item (coverage-provider.md §6), so adding the two
   counts each place once. */
export function confTotalCount(key){
  const st = confState[key+'-conf'];
  if(!st) return 0;

  return st.confirmed.filter(f => inScope((f.properties||{}).rid)).length;
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
    // EVERY curated item in the pool earns our pin, not only the confirmed
    // ones. These payloads (catalog.json's C/D/E/G/H/I/J/M) are the CURATED
    // set — every feature carries a real DB item id; the OSM reference data
    // lives in the coverage tiles instead. Filtering on `v` here meant an
    // approved rider contribution had no marker at all until somebody
    // confirmed it, so the map showed an OSM droplet where a Commons item
    // stood (docs/specs/photo-uploads.md §5, owner call 2026-07-31).
    //
    // The verified/unverified distinction survives in the pin's styling, not
    // in whether it exists: see confLeafPin.
    const curated = info.data.features;
    const srcId=key+'-conf';
    if(!curated.length || map.getSource(srcId)) return;
    map.addSource(srcId,{type:'geojson', cluster:true, clusterRadius:48, clusterMaxZoom:13,
      data:{type:'FeatureCollection', features:curated.filter(poolVisible)}});
    // invisible layer so the clustered source loads tiles (querySourceFeatures needs rendered tiles)
    map.addLayer({id:srcId+'-hit', type:'circle', source:srcId, paint:{'circle-radius':0,'circle-opacity':0}});
    confState[srcId]={key, layer:layerByKey[key], info, onScreen:{}, confirmed:curated};
  });
}
/* Add one item to a curated pool and repaint it, without a page reload.

   A curator approving a submission removes its pending pin, and until now the
   place it approved appeared nowhere until the map was reloaded: the pools are
   built once, from the catalog fetch at boot. The decision response carries the
   approved item as the same GeoJSON feature the catalog would have served
   (CatalogProvider::featureForItem), so it can simply join its pool.

   Idempotent by item id, so a double decision (or a later catalog refetch)
   cannot draw the same place twice. */
export function addCuratedFeature(key, feature){
  const info = osmLayers[key];
  if(!info || !info.data || !feature || !feature.properties) return false;
  const id = feature.properties.id;
  // REPLACE when it is already there, not "already fine, nothing to do".
  // Approving an EDIT sends the same id back with the change applied, and the
  // early return meant the map kept rendering the pre-edit properties until
  // the next full page load (2026-08-03).
  const at = info.data.features.findIndex(f => f.properties && f.properties.id === id);
  if(at >= 0) info.data.features[at] = feature;
  else info.data.features.push(feature);

  const srcId = key + '-conf';
  const st = confState[srcId];
  if(st){
    st.confirmed = info.data.features;
    const src = map.getSource(srcId);
    if(src) src.setData({type:'FeatureCollection', features: st.confirmed.filter(poolVisible)});
  } else {
    // The pool had no features at boot, so it has no cluster source yet —
    // setupConfClusters() skips empty pools. It has one now.
    setupConfClusters();
  }
  updateConfMarkers();
  return true;
}

// Region scope OR view mode changed → rebuild each cluster source from its full
// confirmed set, keeping what poolVisible() allows, so cluster counts and leaf
// pins match both gates (map-and-search.md §4.5, §4.2). updateConfMarkers
// repaints on the resulting sourcedata/idle.
export function refilterClusters(){
  Object.keys(confState).forEach(srcId=>{
    const st=confState[srcId], src=map.getSource(srcId);
    if(src) src.setData({type:'FeatureCollection', features:st.confirmed.filter(poolVisible)});
  });
}
export function confLeafPin(st, p, co){
  const lngLat=[co[0],co[1]], llo={lat:co[1],lng:co[0]};
  const drawerF = st.info.water ? waterDrawer(p, llo) : osmDrawer(st.layer, p, llo, st.info.src);
  // Three states, one vocabulary. A confirmed item gets the curated mark; an
  // approved-but-unconfirmed one gets the dashed, faded `community` pin the
  // app already uses wherever `verified === false` (search rows, the reveal
  // pin) — which reads as "this is ours, nobody has checked it yet". That is
  // the invitation: ride past it and confirm it.
  const verified = !!p.v;
  const el=pinEl(st.layer, verified, p);
  if(!verified) el.classList.add('community');
  el.style.cursor='pointer'; el.tabIndex=0; el.setAttribute('role','button');
  const tip = drawerF.name+' · '+drawerF.headline
    + (verified ? '' : ' · '+(D.needsCheck||'not confirmed yet — check it if you ride past'));
  el.setAttribute('aria-label', drawerF.name+' — '+drawerF.headline
    + (verified ? '' : ' — '+(D.needsCheck||'not confirmed yet')));
  // stopPropagation (click-to-scope, map-and-search.md §4.5
  // §C): this DOM marker has no backing rendered layer at its pixel (the
  // '<key>-conf-hit' source layer is a zero-radius circle, purely so the
  // clustered source loads tiles) — without it the click bubbles to the map's
  // generic click handler, which would see "no feature here" and re-scope the
  // map right behind this pin's own drawer-open action.
  el.addEventListener('click', e=>{ e.stopPropagation(); openDrawer(st.layer, drawerF); flyToPin(lngLat); });
  el.addEventListener('mouseenter', ()=>showTip(tip, lngLat));
  el.addEventListener('mouseleave', hideTip);
  el.addEventListener('focus', ()=>showTip(tip, lngLat));
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
  ['transit',  window.CC_TRANSIT_OSM,  'OpenStreetMap (railway=station / railway=halt)'],
  ['toilets',  window.CC_TOILETS_OSM,  'OpenStreetMap (amenity=toilets)']
];
