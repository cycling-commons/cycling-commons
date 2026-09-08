// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Curated pool pins and clusters. Uncurated points live on coverage tiles.
   @see docs/specs/map-and-search.md §5 */
import { map, flyToPin } from './map-init.js';
import { showTip, hideTip } from './sheet.js';
import { D } from './i18n.js';
import { layerByKey, active, mode } from './catalog.js';
import { inScope } from './scope-ui.js';
import { mintKindIcons, pinEl, clusterEl } from './icons.js';
import { staysAccessible } from './render.js';
import { openDrawer, osmDrawer, waterDrawer } from './drawer.js';

export function addWaterOsm(){
  if(!window.CC_WATER_OSM || osmLayers['water']) return;
  osmLayers['water']={data:CC_WATER_OSM, water:true};
  mintKindIcons();
}

// docs/specs/map-and-search.md §4.2, §4.5 — scope + Confirmed (`v`/`cur`); Best of / Everything draw the whole pool.
export function poolVisible(f){
  const p = f.properties || {};
  if(!inScope(p.rid)) return false;
  return mode() !== 'confirmed' || !!p.v || !!p.cur;
}

export function confShownCount(key){
  const st = confState[key+'-conf'];
  if(!st) return 0;

  return st.confirmed.filter(poolVisible).length;
}

// Scope only, never mode — a total that shrank with the filter would read n/n.
// Disjoint from coverage (docs/specs/coverage-provider.md §6).
export function confTotalCount(key){
  const st = confState[key+'-conf'];
  if(!st) return 0;

  return st.confirmed.filter(f => inScope((f.properties||{}).rid)).length;
}

export const osmLayers = {};

export function addOsmDots(key, data, srcDesc){
  if(!data || osmLayers[key]) return;
  osmLayers[key]={data, src:srcDesc};
}
export const confState = {};
export function setupConfClusters(){
  Object.keys(osmLayers).forEach(key=>{
    const info=osmLayers[key]; if(!info || !info.data) return;
    // Every curated pool item gets a pin; verified vs not is styling (confLeafPin), not presence.
    const curated = info.data.features;
    const srcId=key+'-conf';
    if(!curated.length || map.getSource(srcId)) return;
    map.addSource(srcId,{type:'geojson', cluster:true, clusterRadius:48, clusterMaxZoom:13,
      data:{type:'FeatureCollection', features:curated.filter(poolVisible)}});
    map.addLayer({id:srcId+'-hit', type:'circle', source:srcId, paint:{'circle-radius':0,'circle-opacity':0}});
    confState[srcId]={key, layer:layerByKey[key], info, onScreen:{}, confirmed:curated};
  });
}
// Join an approved item into its pool without reload. Idempotent by id; replace on edit.
export function addCuratedFeature(key, feature){
  const info = osmLayers[key];
  if(!info || !info.data || !feature || !feature.properties) return false;
  const id = feature.properties.id;
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
    setupConfClusters();
  }
  updateConfMarkers();
  return true;
}

/* Tab-return refresh (catalog-load.js, map-and-search.md §2): the page's data
   variables were replaced by a fresh payload, but every pool still held the
   arrays it took at boot, and every pin its element, so an approval made on
   the desk kept the old icon and the old drawer until a reload (owner
   2026-09-08: "it should invalidate the old drawer and icon cache"). Swap each
   pool's data, re-seed its cluster source, and drop the on-screen markers so
   they are minted again from the new properties. */
const POOL_GLOBALS = {
  water:'CC_WATER_OSM', services:'CC_SERVICES_OSM', scenic:'CC_SCENIC_OSM', history:'CC_HISTORY_OSM',
  stays:'CC_STAYS_OSM', shelter:'CC_SHELTER_OSM', transit:'CC_TRANSIT_OSM', toilets:'CC_TOILETS_OSM'
};
export function refreshPools(){
  Object.keys(POOL_GLOBALS).forEach(k=>{
    const data = window[POOL_GLOBALS[k]];
    if(!data || !Array.isArray(data.features)) return;
    const info = osmLayers[k];
    if(!info){
      // Empty at boot, filled now: the same entry the boot makes.
      if(k==='water') addWaterOsm();
      else { const row = OSM_BULK.find(r=>r[0]===k); addOsmDots(k, data, row ? row[2] : 'OpenStreetMap'); }
      return;
    }
    info.data = data;
    const srcId = k+'-conf', st = confState[srcId];
    if(!st) return;
    st.info = info;
    st.confirmed = data.features;
    const src = map.getSource(srcId);
    if(src) src.setData({type:'FeatureCollection', features: data.features.filter(poolVisible)});
    for(const m in st.onScreen) st.onScreen[m].remove();
    st.onScreen = {};
  });
  mintKindIcons();
  setupConfClusters();
  updateConfMarkers();
}

// docs/specs/map-and-search.md §4.2, §4.5 — rebuild cluster sources when scope or view mode changes.
export function refilterClusters(){
  Object.keys(confState).forEach(srcId=>{
    const st=confState[srcId], src=map.getSource(srcId);
    if(src) src.setData({type:'FeatureCollection', features:st.confirmed.filter(poolVisible)});
  });
}
export function confLeafPin(st, p, co){
  const lngLat=[co[0],co[1]], llo={lat:co[1],lng:co[0]};
  const drawerF = st.info.water ? waterDrawer(p, llo) : osmDrawer(st.layer, p, llo, st.info.src);
  // docs/specs/map-and-search.md §12 — unconfirmed items get the community pin, not absence.
  const verified = !!p.v;
  const el=pinEl(st.layer, verified, p);
  if(!verified) el.classList.add('community');
  el.style.cursor='pointer'; el.tabIndex=0; el.setAttribute('role','button');
  const tip = drawerF.name+' · '+drawerF.headline
    + (verified ? '' : ' · '+(D.needsCheck||'not confirmed yet — check it if you ride past'));
  el.setAttribute('aria-label', drawerF.name+' — '+drawerF.headline
    + (verified ? '' : ' — '+(D.needsCheck||'not confirmed yet')));
  // stopPropagation: no rendered layer under this DOM pin — without it the map would re-scope (docs/specs/map-and-search.md §4.5).
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
      if(!p.cluster && st.key==='stays' && !staysAccessible(p)) continue;
      let m=on[key];
      if(!m){
        if(p.cluster){
          const el=clusterEl(st.layer, p.point_count_abbreviated); el.style.cursor='pointer';
          // MapLibre ≥3: getClusterExpansionZoom is a Promise (callback form is ignored).
          // stopPropagation: same click-to-scope note as confLeafPin.
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

export const OSM_BULK = [
  ['services', window.CC_SERVICES_OSM, 'OpenStreetMap (shop=bicycle / amenity=bicycle_repair_station / compressed_air)'],
  ['scenic',   window.CC_SCENIC_OSM,   'OpenStreetMap (tourism=viewpoint / natural=peak / waterway=waterfall)'],
  ['history',  window.CC_HISTORY_OSM,  'OpenStreetMap (historic=castle/fort/ruins/monument/memorial/…)'],
  ['stays',    window.CC_STAYS_OSM,    'OpenStreetMap (tourism=camp_site/hostel/guest_house/chalet/hotel/…)'],
  ['shelter',  window.CC_SHELTER_OSM,  'OpenStreetMap (shelter_type=picnic/weather/field/…)'],
  ['transit',  window.CC_TRANSIT_OSM,  'OpenStreetMap (railway=station / railway=halt)'],
  ['toilets',  window.CC_TOILETS_OSM,  'OpenStreetMap (amenity=toilets)']
];
