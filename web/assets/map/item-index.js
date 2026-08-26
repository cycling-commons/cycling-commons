// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Unified searchable-item index (docs/specs/map-and-search.md §7.1): every
   curated/DB-backed item once. ITEM_INDEX/IDX_IDS stay private behind readers
   because an ES import cannot reassign them. rebuildItemIndex() rebuilds both. */
import { CATALOG, layerByKey } from './catalog.js';
import { slug, haversine, featurePoint } from './util.js';
import { openLocalFeature, openStayPivot, openPoolFeature } from './places.js';
import { layerGlyph } from './icons.js';

// Bulk-pool globals, read at BUILD time (catalog-load fills them asynchronously).
const POOL_GLOBALS = [
  ['water', 'CC_WATER_OSM'], ['services', 'CC_SERVICES_OSM'],
  ['scenic', 'CC_SCENIC_OSM'], ['history', 'CC_HISTORY_OSM'],
  ['stays', 'CC_STAYS_OSM'], ['shelter', 'CC_SHELTER_OSM'],
  ['transit', 'CC_TRANSIT_OSM'], ['toilets', 'CC_TOILETS_OSM'],
];

let ITEM_INDEX = [];
// letter:id of every DB-backed entry — coverage hits whose curated twin is indexed must not list twice.
let IDX_IDS = new Set();

export const itemIndex = () => ITEM_INDEX;
export const idxIds = () => IDX_IDS;
export function rebuildItemIndex(){
  ITEM_INDEX = buildItemIndex();
  IDX_IDS = new Set(ITEM_INDEX.filter(e=>e.id!=null).map(e=>e.letter+':'+e.id));
  return ITEM_INDEX;
}
// Drop a pending submission the map feature is gone for.
export function dropPendingFromIndex(id){
  for(let i=ITEM_INDEX.length-1;i>=0;i--){ if(ITEM_INDEX[i].pend===String(id)) ITEM_INDEX.splice(i,1); }
}
export function buildItemIndex(){
  const out=[], byId=new Set(), byName=new Map();   // byName: 'letter:slug' -> [ll,…]
  function push(e){
    if(e.id!=null){ const k=e.letter+':'+e.id; if(byId.has(k)) return; byId.add(k); }
    if(e.name && e.ll && !e.unnamed){   // unnamed entries share a type label — never name-dedup them
      const nk=e.letter+':'+slug(e.name), seen=byName.get(nk)||[];
      if(seen.some(p=>haversine(p, e.ll)<=0.1)) return;   // same place, another source
      seen.push(e.ll); byName.set(nk, seen);
    }
    out.push(e);
  }
  CATALOG.forEach(layer=>(layer.features||[]).forEach(f=>{ if(!f.name) return;
    push({name:f.name, unnamed:f.unnamed, key:slug(f.name+' '+(layer.label||'')), kind:layer.label||'', badge:layerGlyph(layer)||'•',
      color:layer.color||'#6b6f5e', letter:layer.letter||'•', ll:featurePoint(f), id:f.id,
      rid:f.rid,
      // Real signal only (docs/specs/map-and-search.md §12) — never the demo 'c'.
      verified: f.state ? f.state==='verified' : !!(f.v || f.cur),
      hlOff: layer.kind==='point' ? [0,-16] : [0,0],   // bottom-anchored pins: pulse on the pin body
      pend:f.pending?String(f.pending.id):undefined,
      go:()=>openLocalFeature(layer,f)});   // exact feature, not a name re-lookup
  }));
  (window.CC_STAYS_PIVOT && CC_STAYS_PIVOT.features || []).forEach(f=>{ const p=f.properties;
    if(!p || !p.n) return; const layer=layerByKey.stays; if(!layer) return;
    const c=f.geometry && f.geometry.coordinates; if(!c || c.length<2) return;
    push({name:p.n, key:slug(p.n+' '+(p.town||'')+' '+layer.label), kind:layer.label, badge:layerGlyph(layer),
      color:layer.color, letter:layer.letter, ll:[+c[1],+c[0]], id:p.id,
      rid:p.rid,
      verified:!!p.v,
      hlOff: p.v ? [0,-16] : [0,0],
      go:()=>openStayPivot(f)});
  });
  /* DB-backed items in bulk-OSM pools (id present). Raw OSM stays a live /map/coverage/search lookup. */
  POOL_GLOBALS.forEach(([key, g])=>{
    const layer=layerByKey[key]; if(!layer) return;
    (((window[g]||{}).features)||[]).forEach(f=>{ const p=f.properties;
      if(!p || !p.n || p.id==null) return;
      const c=f.geometry && f.geometry.coordinates; if(!c || c.length<2) return;
      push({name:p.n, key:slug(p.n+' '+(p.town||'')+' '+layer.label), kind:layer.label, badge:layerGlyph(layer),
        color:layer.color, letter:layer.letter, ll:[+c[1],+c[0]], id:p.id, rid:p.rid,
        verified:!!p.v, hlOff: p.v ? [0,-16] : [0,0],
        go:()=>openPoolFeature(key, f)});
    });
  });
  return out;
}
// Town-card nearby is scope-exempt: opening a town is an explicit location choice.
export function nearbyItems(ll, km){
  const out=[];
  ITEM_INDEX.forEach(e=>{ if(!e.ll) return; const dist=haversine(ll, e.ll); if(dist<=km) out.push({e, dist}); });
  return out.sort((a,b)=>a.dist-b.dist);
}
// Privacy trim of contributed ride geometry (docs/specs/map-and-search.md §11).
export function trimEnds(loop, startM, endM){   // startM/endM metres; haversine returns km
  let i=0,d=0; while(i<loop.length-2 && d<startM/1000){ d+=haversine(loop[i],loop[i+1]); i++; }
  let j=loop.length-1,e=0; while(j>i+1 && e<endM/1000){ e+=haversine(loop[j],loop[j-1]); j--; }
  return loop.slice(i, j+1);
}
