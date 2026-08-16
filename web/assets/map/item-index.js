// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The unified searchable-item index: every curated/DB-backed item exactly once,
   plus the two readers built on it (nearbyItems, and trimEnds for contributed
   ride geometry).
   Extracted from map.js by the module split.

   ITEM_INDEX and IDX_IDS are REASSIGNED (rebuild) and MUTATED (a moderation
   decision drops a pending entry), and an ES module import is a read-only live
   binding — an importer can see the new array but cannot install one. So both
   stay private behind itemIndex()/idxIds() readers and the two mutators the
   entry actually needs (§5 recipe step 4). rebuildItemIndex() deliberately
   rebuilds BOTH: IDX_IDS is derived from ITEM_INDEX, and refreshing one without
   the other would let a coverage hit whose curated twin is indexed list twice.

   openLocalFeature/openStayPivot are the entries' `go` handlers, plain imports
   now that places.js has landed (§5 step 6). initItemIndex() went with them: it
   only ever carried the handover, and the index itself is built by
   rebuildItemIndex() from the entry's sidebar-search block. */
import { CATALOG, layerByKey } from './catalog.js';
import { slug, haversine, featurePoint } from './util.js';
import { openLocalFeature, openStayPivot, openPoolFeature } from './places.js';

// The bulk-pool globals, read at BUILD time (never captured at module eval:
// catalog-load fills them asynchronously, and a snapshot taken before that
// would index nothing forever). Keys match layerByKey/osm-pools' OSM_BULK,
// plus water, which osm-pools registers separately.
const POOL_GLOBALS = [
  ['water', 'CC_WATER_OSM'], ['services', 'CC_SERVICES_OSM'],
  ['scenic', 'CC_SCENIC_OSM'], ['history', 'CC_HISTORY_OSM'],
  ['stays', 'CC_STAYS_OSM'], ['shelter', 'CC_SHELTER_OSM'],
  ['transit', 'CC_TRANSIT_OSM'], ['toilets', 'CC_TOILETS_OSM'],
];

// ---- Unified searchable-item index (spec 2026-07-14 §3.1) ----
// Every curated/DB-backed item exactly once: CATALOG features (curated:
// climbs, hazards, routes, curator-pending) then PIVOT stays. Search and
// nearbyItems() both consume this; uncurated OSM coverage is NOT indexed
// here (coverage-provider.md §6) — it's looked up live via
// /map/coverage/search and /map/coverage/nearby (openCoverageFeatureByName(),
// the search box, town-card nearby groups), deduped against this index by
// IDX_IDS/CC_CURATED_REFS so a curated twin never lists twice.
// Dedup: DB-backed entries on letter+id; cross-source physical doubles
// (same letter + normalized name within 100 m) keep the earlier entry —
// build order makes that curated over pivot.
let ITEM_INDEX = [];
// letter:id keys of every DB-backed local-index entry — coverage search/
// nearby hits whose curated twin is already indexed must not list twice
// (dedupe complement to the tile-side covDedupeFilter). Filled right after
// buildItemIndex() runs in the sidebar-search block.
let IDX_IDS = new Set();

// Readers: an importer sees the current array/set without being able to swap it.
export const itemIndex = () => ITEM_INDEX;
export const idxIds = () => IDX_IDS;
// Rebuild BOTH together — IDX_IDS is derived from ITEM_INDEX (see the header).
export function rebuildItemIndex(){
  ITEM_INDEX = buildItemIndex();
  IDX_IDS = new Set(ITEM_INDEX.filter(e=>e.id!=null).map(e=>e.letter+':'+e.id));
  return ITEM_INDEX;
}
// A moderation decision removed a pending submission: its map feature is gone,
// so a search hit that would "do nothing" has to go with it (review W36).
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
  // hlOff = highlight-pulse offset for this entry (same rule as the
  // drawer-open halo at openDrawer): bottom-anchored pins (CATALOG point
  // markers, confirmed OSM/pivot icon pins) centre the pulse on the pin
  // BODY with [0,-16]; canvas dots and line features pulse at the point.
  CATALOG.forEach(layer=>(layer.features||[]).forEach(f=>{ if(!f.name) return;
    // badge = the category ICON, not its letter: the letter is a storage
    // identifier and says nothing to a rider scanning a result list. `letter`
    // below stays — it is what coverage lookups key on.
    push({name:f.name, unnamed:f.unnamed, key:slug(f.name+' '+(layer.label||'')), kind:layer.label||'', badge:layer.icon||'•',
      color:layer.color||'#6b6f5e', letter:layer.letter||'•', ll:featurePoint(f), id:f.id,
      rid:f.rid,   // region membership — search filters to scope like the map (07-20 review finding 3)
      // 07-15 decision A: real signal only — routes carry canonical state;
      // everything else keys on the real v (verified state / rider
      // confirmation, emitted by CatalogProvider for climbs and surface
      // segments too) OR the curated best-of flag. Never the demo 'c'.
      verified: f.state ? f.state==='verified' : !!(f.v || f.cur),
      hlOff: layer.kind==='point' ? [0,-16] : [0,0],
      pend:f.pending?String(f.pending.id):undefined,
      // Open the EXACT resolved feature, not a re-lookup by name — a nameless
      // hazard shares its label with siblings, so openFeatureByName(f.name)
      // would last-match-wins onto the wrong pin (finding 9).
      go:()=>openLocalFeature(layer,f)});
  }));
  (window.CC_STAYS_PIVOT && CC_STAYS_PIVOT.features || []).forEach(f=>{ const p=f.properties;
    if(!p || !p.n) return; const layer=layerByKey.stays; if(!layer) return;
    const c=f.geometry && f.geometry.coordinates; if(!c || c.length<2) return;
    push({name:p.n, key:slug(p.n+' '+(p.town||'')+' '+layer.label), kind:layer.label, badge:layer.icon,
      color:layer.color, letter:layer.letter, ll:[+c[1],+c[0]], id:p.id,
      rid:p.rid,   // pivot stays carry rid via catalog-load's property merge (finding 3)
      verified:!!p.v,   // v = real state/confirmation signal from CatalogProvider
      hlOff: p.v ? [0,-16] : [0,0],   // pin offset keys on the real promotion signal (c is dead, see the re-key step)
      go:()=>openStayPivot(f)});
  });
  /* DB-backed items served through the bulk-OSM pools (a wikidata-seeded
     castle, a rider-added water point). These letters never reach CATALOG's
     feature arrays, so "Bourscheid Castle" — an item of ours with a photo, a
     description and links — was findable on the map and invisible to the
     search box (owner-reported 2026-08-16). Only rows with a DB id are
     indexed: raw OSM coverage stays a live /map/coverage/search lookup, and
     the byId/byName dedup above keeps a curated twin from listing twice. */
  POOL_GLOBALS.forEach(([key, g])=>{
    const layer=layerByKey[key]; if(!layer) return;
    (((window[g]||{}).features)||[]).forEach(f=>{ const p=f.properties;
      if(!p || !p.n || p.id==null) return;
      const c=f.geometry && f.geometry.coordinates; if(!c || c.length<2) return;
      push({name:p.n, key:slug(p.n+' '+(p.town||'')+' '+layer.label), kind:layer.label, badge:layer.icon,
        color:layer.color, letter:layer.letter, ll:[+c[1],+c[0]], id:p.id, rid:p.rid,
        verified:!!p.v, hlOff: p.v ? [0,-16] : [0,0],
        go:()=>openPoolFeature(key, f)});
    });
  });
  return out;
}
// Deliberately scope-EXEMPT (07-20 review finding 3, owner decision):
// opening a town card is an explicit location choice, so its nearby list
// shows what is physically there regardless of the active scope — unlike
// text search, which filters to scope (runS). Keep this asymmetry.
export function nearbyItems(ll, km){
  const out=[];
  ITEM_INDEX.forEach(e=>{ if(!e.ll) return; const dist=haversine(ll, e.ll); if(dist<=km) out.push({e, dist}); });
  return out.sort((a,b)=>a.dist-b.dist);
}
// privacy: drop the first & last 350–750 m of a contributed ride (kills home/start fingerprints).
// startM/endM in metres; haversine() returns km, so compare against m/1000.
export function trimEnds(loop, startM, endM){
  let i=0,d=0; while(i<loop.length-2 && d<startM/1000){ d+=haversine(loop[i],loop[i+1]); i++; }
  let j=loop.length-1,e=0; while(j>i+1 && e<endM/1000){ e+=haversine(loop[j],loop[j-1]); j--; }
  return loop.slice(i, j+1);
}
