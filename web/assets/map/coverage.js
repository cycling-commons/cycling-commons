// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Uncurated OSM coverage from PMTiles (docs/specs/coverage-provider.md §4–§6).
   Gated on COVERAGE_ON — a real CC_COVERAGE_URL plus a loaded pmtiles lib.
   Absent either, every export is a no-op. */
import { map, flyToPin } from './map-init.js';
import { showTip, hideTip } from './sheet.js';
import { layerByKey, active, mode, LETTER_KEY, KEY_LETTER } from './catalog.js';
import { curScope } from './scope-ui.js';
import { mintKindIcons, miniIcon, SERVICE_GLYPH, coverageIconId, kindImageId, covIconSizes, DISC_SIZES, DROP_SIZES } from './icons.js';
import { updateCounts, applyStaysAccessFilter } from './render.js';
import { openDrawer, renderDrawerBody, osmDrawer, waterDrawer, revealPinAt } from './drawer.js';
import { isPicking } from './picking.js';
import { osmLayers } from './osm-pools.js';
import { viewDirection, OSM_REF } from './osm-tags.js';

// Coverage tiles (docs/specs/coverage-provider.md §6). [rail key, lowercase letter]
// must stay in step with catalog.js LETTER_KEY (covKeysTest.cjs).
export const COVERAGE_KEYS=[['water','b'],['toilets','c'],['services','d'],['transit','f'],['shelter','g'],['stays','o'],['scenic','p'],['history','q']];
// Per-country source-layers (docs/specs/coverage-provider.md §4): `<letter>_<cc>`, unstamped in `zz`.
export const COVERAGE_CCS = (Array.isArray(window.CC_COVERAGE_COUNTRIES) && window.CC_COVERAGE_COUNTRIES.length)
  ? window.CC_COVERAGE_COUNTRIES.map(c=>c.toLowerCase()).concat(['zz'])
  : [null];   // [null] = single unsplit '<letter>' layer (tiles predate the per-country split)
export const COVERAGE_ON = typeof window.CC_COVERAGE_URL==='string' && !!window.CC_COVERAGE_URL && typeof pmtiles!=='undefined';
export const COV_SRC={
  services:'OpenStreetMap (shop=bicycle / amenity=bicycle_repair_station / compressed_air)',
  scenic:'OpenStreetMap (tourism=viewpoint / natural=peak / waterway=waterfall)',
  history:'OpenStreetMap (historic=castle/fort/ruins/monument/memorial/…)',
  stays:'OpenStreetMap (tourism=camp_site/hostel/guest_house/chalet/hotel/…)',
  shelter:'OpenStreetMap (shelter_type=picnic/weather/field/…)',
  transit:'OpenStreetMap (railway=station / railway=halt)',
  toilets:'OpenStreetMap (amenity=toilets)'
};
// Curated-ref dedupe (docs/specs/coverage-provider.md §6).
export const covDedupeFilter=()=>['!',['in',['get','ref'],['literal', Array.from(window.CC_CURATED_REFS||[])]]];
/* Coverage scope filter (docs/specs/map-and-search.md §4.5): inverse of the
   leak-safe rule for served data — a prop-less feature RENDERS (weekly tiles
   lag region stamping). A cc-bearing rid-less row HIDES under a region scope.
   Delegates to CCScope.coverageTileFilter; null = Everywhere. */
export function covScopeFilter(){
  return window.CCScope && window.CCScope.coverageTileFilter ? window.CCScope.coverageTileFilter() : null;
}
export function covBaseFilter(){
  const f=['all', covDedupeFilter()]; const sc=covScopeFilter(); if(sc) f.push(sc); return f;
}
// Icon filter (docs/specs/coverage-provider.md §4): scope + dedupe + optional extra.
// `!has point_count` is a no-op — tippecanoe no longer emits clustered features.
export function covIconFilter(extra){
  const f=covBaseFilter(); f.push(['!',['has','point_count']]); if(extra) f.push(extra); return f;
}
// Heat filter: scope only. Density is not a clickable feature.
export function covHeatFilter(){
  return covScopeFilter() || null;
}
export function updateCoverageScopeFilter(){
  if(!COVERAGE_ON) return;
  COVERAGE_KEYS.forEach(([key])=>{
    COVERAGE_CCS.forEach(cc=>{
      const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
      if(map.getLayer(id)){
        if(key==='stays'){ if(cc===COVERAGE_CCS[0]) applyStaysAccessFilter(); }  // once per key; it loops CCS itself
        else map.setFilter(id, covIconFilter());
      }
      const heatId = cc ? key+'-'+cc+'-heat' : key+'-heat';
      if(map.getLayer(heatId)) map.setFilter(heatId, covHeatFilter());
    });
  });
}
// myArea with an empty derived region set (docs/specs/map-and-search.md §4.5): skip unscoped fetches.
export function covScopeIsZero(){
  const p=window.CCScope && window.CCScope.coverageParams();
  return !!(p && Array.isArray(p.rids) && !p.rids.length);
}
// Active-scope rids/cc as a query fragment (docs/specs/map-and-search.md §4.5); '' for Everywhere.
export function covScopeQuery(){
  if(!window.CCScope) return '';
  const p=window.CCScope.coverageParams(), parts=[];
  if(p.rids && p.rids.length) parts.push('rids='+p.rids.join(','));
  if(p.cc) parts.push('cc='+encodeURIComponent(p.cc));
  return parts.join('&');
}
// Community tier (docs/specs/map-and-search.md §12): C/D/G/H/M in both modes
// (dimmed in Curated); E/I/J stay Everything-only.
export const COV_UTILITY=new Set(['B','C','D','F','G']);
export function syncCoverageLayers(){
  if(!COVERAGE_ON) return;
  COVERAGE_KEYS.forEach(([key])=>{
    const utility=COV_UTILITY.has(KEY_LETTER[key]);
    /* Coverage stays out of Confirmed: that mode means someone checked this. */
    const show=active.has(key) && (mode()==='all' || (utility && mode()==='curated'));
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
  mintKindIcons();
  map.addSource('coverage',{type:'vector', url:'pmtiles://'+window.CC_COVERAGE_URL});
  const iconLayerIds=[]; const iconLayerKey=new Map();
  COVERAGE_KEYS.forEach(([key, letter])=>{
    // One icon layer per (letter, country). cc===null is the pre-split `<letter>` fallback.
    COVERAGE_CCS.forEach(cc=>{
      const srcLayer = cc ? letter+'_'+cc : letter;
      const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
      // Letter B: kind in the glyph (data-provider-hierarchy.md §6.3). The
      // tile says `food` for the shop/eatery half and `potable` as yes / no /
      // absent (tiles before 2026-09-04 said true / false; false reads as
      // unknown, since it covered both). The ids are minted by mintKindIcons.
      const isFood = ['match',['to-string',['get','food']],['true','1','yes'],true,false];
      const isPotable = ['match',['to-string',['get','potable']],['yes','true','1'],true,false];
      const isNotPotable = ['==',['to-string',['get','potable']],'no'];
      const icon = key==='water'
        ? ['case', isFood,
            ['case', isPotable, kindImageId('B','food_water'), kindImageId('B','food')],
            ['case', isPotable, kindImageId('B','tap'), isNotPotable, kindImageId('B','no'), kindImageId('B','unk')]]
        : key==='services'
          ? ['match',['get','kind'],
              'shop', miniIcon('services'),
              'station', miniIcon('services', SERVICE_GLYPH.station, 'station'),
              'pump', miniIcon('services', SERVICE_GLYPH.pump, 'pump'),
              miniIcon('services')]
          : miniIcon(key);
      const heatId = cc ? key+'-'+cc+'-heat' : key+'-heat';
      // addLayer rejects `filter: null`; omit the key (default = unfiltered).
      const heatSpec={id:heatId, type:'heatmap', source:'coverage', 'source-layer':srcLayer,
        maxzoom: 9,
        layout:{visibility:'none'},
        paint:{
          'heatmap-weight':0.6,
          'heatmap-intensity':['interpolate',['linear'],['zoom'],6,0.9,9,1.3],
          'heatmap-radius':['interpolate',['linear'],['zoom'],6,16,9,28],
          'heatmap-opacity':['interpolate',['linear'],['zoom'],6,0.6,8,0.6,9,0],   // crossfade into icons at z9
          'heatmap-color':['interpolate',['linear'],['heatmap-density'],
            0,'rgba(0,0,0,0)',
            0.25,'rgba(150,110,190,0.32)',
            0.6,'rgba(112,72,158,0.58)',
            1,'#5B2A86']}};
      { const hf=covHeatFilter(); if(hf) heatSpec.filter=hf; }
      map.addLayer(heatSpec);
      // Icons from z9 (docs/specs/coverage-provider.md §4); z6–8 tiles are thinned.
      map.addLayer({id, type:'symbol', source:'coverage', 'source-layer':srcLayer,
        minzoom: 9,
        filter:covIconFilter(),   // dedupe + scope
        layout:{visibility:'none','icon-image':icon,'icon-allow-overlap':true,
          // One ramp per shape: the food discs size like every other disc.
          'icon-size': key==='water'
            ? ['interpolate',['linear'],['zoom'],
                8,['case',isFood,DISC_SIZES[0],DROP_SIZES[0]],
                13,['case',isFood,DISC_SIZES[1],DROP_SIZES[1]],
                18,['case',isFood,DISC_SIZES[2],DROP_SIZES[2]]]
            : ['interpolate',['linear'],['zoom'],8,DISC_SIZES[0],13,DISC_SIZES[1],18,DISC_SIZES[2]]}});
      iconLayerIds.push(id); iconLayerKey.set(id, key);
    });
  });
  /* One handler set over all icon layers — MapLibre hit-tests each mousemove listener. */
  const keyOf = e => iconLayerKey.get(e.features[0].layer.id);
  map.on('click', iconLayerIds, e=>{ const f0=e.features[0], tp=f0.properties, c=f0.geometry.coordinates;
    openCoverageDrawer(keyOf(e), tp, {lng:c[0], lat:c[1]}); flyToPin([c[0],c[1]]); });
  map.on('mouseenter', iconLayerIds, ()=>map.getCanvas().style.cursor='pointer');
  map.on('mousemove', iconLayerIds, e=>{ const p=e.features[0].properties; showTip(p.n||p.t||(layerByKey[keyOf(e)]||{}).label||'Item', e.lngLat); });
  map.on('mouseleave', iconLayerIds, ()=>{ map.getCanvas().style.cursor=''; hideTip(); });
  // Selected-POI overlay: tile minzoom hides the icon on zoom-out; the pulse would ring empty.
  if(!map.getSource('cov-sel')){
    map.addSource('cov-sel',{type:'geojson',data:{type:'FeatureCollection',features:[]}});
    map.addLayer({id:'cov-sel-icon',type:'symbol',source:'cov-sel',
      // One zoom interpolate (MapLibre forbids two); per-feature stops keep water vs rest ramps.
      layout:{'icon-image':['get','_icon'],'icon-allow-overlap':true,
        'icon-size':['interpolate',['linear'],['zoom'],
          8,['get','_s8'],13,['get','_s13'],18,['get','_s18']]}});
  }
}
export function covProps(key, tp, d){
  const p={ srcType:'osm' };
  if(tp.t) p.t=tp.t;
  if(tp.n) p.n=tp.n;
  const cc=(tp.cctok||'').replace(/\|/g,'');   // "|nl|" → NL; water verify links are per-country
  if(cc) p.cc=cc.toUpperCase();
  if(tp.ref) p.ref=tp.ref;
  if(key==='services' && tp.kind) p.serviceKind=tp.kind;
  // potable (docs/specs/coverage-provider.md §4): yes / no / absent, coerced
  // like the icon match. A pre-2026-09-04 tile's boolean `false` stays
  // undefined here: it covered both "tagged no" and "nobody said", and the
  // detail response below settles which.
  if(key==='water' && tp.potable!=null){
    const v=tp.potable;
    if(v===true || v==='true' || v===1 || v==='1' || v==='yes') p.osmPotable=true;
    else if(v==='no') p.osmPotable=false;
  }
  if(key==='water' && (tp.food===true || tp.food==='true' || tp.food===1 || tp.food==='1')) p.osmFood=true;
  if(d){
    if(d.name) p.n=d.name;
    if(key==='services' && d.kind) p.serviceKind=d.kind;
    const tags=d.tags||{};
    // The deep-link and search paths build `tp` by hand ({ref, n, kind}), so
    // they carry no `potable` and the drawer used to fall back to "tagged
    // drinkable in OSM" for every water POI they opened, including ones
    // tagged drinking_water=no. Derive it from the tags the detail response
    // does carry, using the SAME rule the tile expression uses
    // (pipeline/coverage/tiles.py, osm-data-architecture.md §5): drinkable
    // unless OSM says otherwise. The pin and the panel have to agree, and the
    // pin is drawn from that rule.
    if(key==='water' && p.osmPotable===undefined && (tags.drinking_water!=null || tags.amenity!=null)){
      p.osmPotable = tags.drinking_water==='yes'
        || (tags.amenity==='drinking_water' && tags.drinking_water==null);
      // Whether OSM SAID it or we inferred it from `amenity=drinking_water`.
      // The pin is the same blue either way, and it should be: a mapped tap
      // with nothing said against it is worth riding to. The sentence must
      // not claim a tag that is not there, though.
      p.osmPotableTagged = tags.drinking_water!=null;
    }
    // What this pin IS, for the source line. Letter B is water AND food, so a
    // pin here is as likely to be a bakery as a tap and the line may not
    // assume either.
    if(key==='water'){
      if(tags.amenity) p.osmAmenity=tags.amenity;
      if(tags.shop) p.osmShop=tags.shop;
      // The same rule as the tile's `food` flag (pipeline/coverage/tiles.py).
      if(tags.shop!=null || ['cafe','fast_food','restaurant','bar','pub'].includes(tags.amenity)) p.osmFood=true;
    }
    const web=tags.website||tags['contact:website'];
    if(web) p.web=web;
    // coverage-provider.md §7 - the detail endpoint says only WHETHER a
    // Commons file is resolvable. The live state comes from the no-store
    // photo endpoint, which the drawer polls.
    if(d.photo) p.hasPhoto = 1;
    if(key==='scenic'){
      if(tags.ele!=null) p.ele=tags.ele;
      if(tags.height!=null) p.drop=tags.height;
      if(tags.direction!=null) p.viewDir=viewDirection(tags.direction);
    }
    if(key==='water' && tags.drinking_water==='no') p.osmPotable=false;   // hydrated tag wins over tile boolean
    if(d.curated){
      if(d.curated.itemId!=null) p.id=d.curated.itemId;
      Object.assign(p, d.curated.fields||{});
    }
  }
  return p;
}

let _covReq=0;
export function invalidateCoverageDrawer(){ _covReq++; }
export function openCoverageDrawer(key, tp, ll){
  if(isPicking()) return;   // docs/specs/route-domain.md §7: a picking click must not start a detail fetch
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
      const p=covProps(key, tp, d);
      renderDrawerBody(layer, feat(p));
      // The detail response can settle a kind the tile could not (a
      // pre-tri-state tile's `false`, a food stop that also gives water).
      showSelectedCoverageIcon(key, p, ll); });
}
export function openCoverageByRef(ref, letter, ll, name, itemId){
  const key=LETTER_KEY[letter]; if(!key) return;
  flyToPin([ll[1],ll[0]]);
  if(itemId!=null){   // served item row: open that record, not the tile-derived path
    const pool=osmLayers[key];
    const f=pool && pool.data && pool.data.features.find(x=>x.properties && x.properties.id===itemId);
    if(f){
      const layer=layerByKey[key], lo={lng:ll[1], lat:ll[0]}, p=f.properties;
      openDrawer(layer, key==='water' ? waterDrawer(p, lo) : osmDrawer(layer, p, lo, COV_SRC[key]));
      const drawn = COVERAGE_CCS.some(cc=>{
        const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
        return map.getLayer(id) && map.getLayoutProperty(id,'visibility')==='visible';
      });
      if(!drawn) revealPinAt(layer, ll);
      else showSelectedCoverageIcon(key, Object.assign({kind:p.serviceKind}, p), lo);   // the kind rules read the record's own fields
      return;
    }
  }
  const myReq=++_covReq;
  const paint=(d)=>{ if(myReq!==_covReq) return; paintCoverageDetail(key, ref, ll, name, d); };
  if(!OSM_REF.test(ref)){ paint(null); return; }   // non-OSM refs have no /map/coverage/poi
  fetch('/map/coverage/poi/'+ref, {headers:{'Accept':'application/json'}})
    .then(r=>r.ok?r.json():null)
    .catch(()=>null)
    .then(paint);
}
/* Drawer + pin for one coverage POI, from its detail response (`d`, null when
   the fetch failed or the ref is not an OSM one). Shared by the search path and
   the ?ref= deep link, which differ only in how they learn the letter and ll. */
function paintCoverageDetail(key, ref, ll, name, d){
  const layer=layerByKey[key], lo={lng:ll[1], lat:ll[0]};
  const p=covProps(key, {ref, n:(d&&d.name)||name, kind:d&&d.kind}, d);
  openDrawer(layer, key==='water' ? waterDrawer(p, lo) : osmDrawer(layer, p, lo, COV_SRC[key]));
  // Layer off or Curated-hidden: one temporary pin rather than flipping map mode.
  const drawn = COVERAGE_CCS.some(cc=>{
    const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
    return map.getLayer(id) && map.getLayoutProperty(id,'visibility')==='visible';
  });
  // `p` carries the kind facts the tile would have (osmFood, osmPotable), so
  // a deep-linked bakery draws the food glyph and not the unknown tap.
  if(!drawn) revealPinAt(layer, ll);
  else showSelectedCoverageIcon(key, Object.assign({kind:d&&d.kind}, p), lo);
}
/* ?ref=node/462149319 deep link (docs/specs/map-and-search.md §8). Unlike
   ?feature=<name> this names one POI: thousands of scenic views are called
   "Viewpoint" and none of them could be shared. The detail endpoint is the only
   thing that knows the letter and the coordinates, so one fetch resolves the
   whole link, and the scope widens only once it has actually resolved. */
export function openCoverageByOsmRef(ref){
  if(!COVERAGE_ON || !OSM_REF.test(String(ref ?? ''))) return;
  const myReq=++_covReq;
  fetch('/map/coverage/poi/'+ref, {headers:{'Accept':'application/json'}})
    .then(r=>r.ok?r.json():null)
    .catch(()=>null)
    .then(d=>{
      if(myReq!==_covReq || !d || !Array.isArray(d.ll) || d.ll.length!==2) return;
      const key=LETTER_KEY[d.letter]; if(!key) return;
      widenForDeepLink(d.ll);
      flyToPin([d.ll[1], d.ll[0]]);
      paintCoverageDetail(key, ref, d.ll, d.name, d);
    });
}
// A resolved deep link outside the scope moves the scope to the target's
// COUNTRY, transiently (persist:false), never to Everywhere: drawing every item
// on Earth to show one of them is what made the browser sluggish (owner,
// 2026-09-06; docs/specs/map-and-search.md §4.5). `ll` is [lat, lng]; a
// target outside every onboarded region leaves the scope alone.
export function widenForDeepLink(ll){
  if(!window.CCScope || !Array.isArray(ll) || ll.length!==2) return;
  const r=window.CCScope.regionOfPoint(+ll[1], +ll[0]);
  if(!r) return;
  const s=curScope();
  if(s && s.kind!=='everywhere' && s.regionIds && s.regionIds.indexOf(r.id)!==-1) return;
  const cc=window.CCScope.countryAt(+ll[0], +ll[1]);
  if(cc) window.CCScope.setCountry(cc, {persist:false});
}
// [lat, lng] of a catalogue feature, whichever shape it carries.
export function featureLL(f){
  if(!f) return null;
  if(Array.isArray(f.ll) && f.ll.length===2) return [+f.ll[0], +f.ll[1]];
  const c=f.geometry && f.geometry.coordinates;
  if(Array.isArray(c) && c.length>=2 && typeof c[0]==='number') return [+c[1], +c[0]];
  if(f.geom && Array.isArray(f.geom.ll)) return [+f.geom.ll[0], +f.geom.ll[1]];
  return null;
}
// ?feature= fallback (docs/specs/coverage-provider.md §6): one unscoped search lookup, then widen.
export function openCoverageFeatureByName(name){
  if(!COVERAGE_ON) return;
  fetch('/map/coverage/search?q='+encodeURIComponent(name), {headers:{'Accept':'application/json'}})
    .then(r=>r.ok?r.json():null)
    .catch(()=>null)
    .then(d=>{
      const hits=((d&&d.results)||[]).filter(h=>h && h.n && LETTER_KEY[h.letter] && Array.isArray(h.ll));
      if(!hits.length) return;
      const hit=hits.find(h=>h.n.toLowerCase()===name.toLowerCase())||hits[0];
      widenForDeepLink(hit.ll);
      openCoverageByRef(hit.ref, hit.letter, hit.ll, hit.n, hit.itemId);
    });
}

// Rail totals (docs/specs/coverage-provider.md §5): scoped /map/coverage/counts.
let _covCounts=null, _covCountReq=0;
export function coverageTotal(letter){ return (_covCounts && _covCounts[letter]) || 0; }
export function fetchCoverageCounts(){
  if(!COVERAGE_ON) return;
  ++_covCountReq;
  if(covScopeIsZero()){
    _covCounts=null; updateCounts(); return;
  }
  const q=covScopeQuery(), myReq=_covCountReq;
  fetch('/map/coverage/counts'+(q?('?'+q):''), {headers:{'Accept':'application/json'}})
    .then(r=>r.ok?r.json():null)
    .catch(()=>null)
    .then(d=>{ if(myReq===_covCountReq){ _covCounts=(d && d.counts) || null; updateCounts(); } });  // null on fail: blank beats a wrong-scope total
}
// "Shown" = in-scope /counts, not viewport tiles (docs/specs/coverage-provider.md §5).
export function covShownCount(key){
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
  const [s8,s13,s18]=covIconSizes(key,tp);
  src.setData({type:'FeatureCollection',features:[{type:'Feature',
    geometry:{type:'Point',coordinates:[ll.lng,ll.lat]},
    properties:{_icon:coverageIconId(key,tp), _s8:s8, _s13:s13, _s18:s18}}]});
}
export function clearSelectedCoverageIcon(){ const src=map.getSource('cov-sel'); if(src) src.setData({type:'FeatureCollection',features:[]}); }
