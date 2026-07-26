// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Region / country / My-area spotlight: dim the world outside the active scope
   and outline it (region-scoping-design.md §4, three-tier neighbours per
   2026-07-24-region-adjacency-and-click-refinement-design.md §8).
   Extracted from map.js by 2026-07-26-map-js-module-split-design.md §5.

   All three entry points share the `_spotReq` race token and the
   region-mask / region-adj-mask / region source+layer ids, so a scope switch
   mid-fetch can never leave two spotlights on the map. `clearSpotlight()` is
   the single teardown — internal, because every caller that clears also
   immediately repaints, and the three entry points already do that themselves.

   NOTE for whoever picks up backlog item 10: setSpotlight() issues SEVEN
   concurrent boundary requests (the region, each neighbour, the dissolved
   union) and the mask lands ~3.4 s later — ~0.6 s of that server, the rest
   spent parsing ~300 KB of polygon JSON and tessellating the mask. The requests
   do NOT serialize and the endpoints are already ETagged, cached and simplified,
   so neither session_write_close() nor more caching helps. setSpotlight() paints
   PROGRESSIVELY instead: the active region's outline as soon as its own boundary
   lands, the neighbour tier when the rest arrive — worth 0.4-0.7 s on a real
   network, nothing on localhost. See the tier comments below. */
import { map } from './map-init.js';

const CC_REGIONS = window.CC_REGIONS || [];

// Region spotlight — dim everything OUTSIDE the active named region + a dashed
// outline (region-scoping-design.md §4). Served from our own DB via the
// cacheable boundary endpoint (replaced the old Nominatim fetch). Re-callable:
// clears the previous spotlight first, so it follows the scope selector; a
// race token stops a slow response repainting after a newer scope switch.
let _spotReq = 0;
function clearSpotlight(){
  ['region-mask','region-adj-mask','region-adj-line','region-line'].forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); });
  ['region-mask','region-adj-mask','region'].forEach(id=>{ if(map.getSource(id)) map.removeSource(id); });
}
export function setSpotlight(slug){
  if(!map.getStyle()) return;
  const req = ++_spotReq;
  clearSpotlight();
  if(!slug) return;   // country / everywhere: no single-region spotlight
  // Three-tier spotlight (item 4 Part C, 2026-07-24-region-adjacency-and-click-refinement-design.md §8):
  // the active region's adj neighbours (CC_REGIONS) render at a lighter mask
  // tone than the outside world, so the regions the rider can jump to are
  // visible. Two data sources:
  //   fullUnion = ST_Union(active + neighbours) off /map/scope/boundary — the
  //     dark-mask holes as ONE dissolved blob, so no two holes touch (punching
  //     active + each neighbour as separate rings makes earcut spit artifacts).
  //   adjFeatures = each neighbour's OWN boundary (/map/region/<slug>/boundary) —
  //     the middle-tone fill + per-region borders, so the borders BETWEEN
  //     neighbours show, not just the band perimeter a dissolved union would give.
  // Every fetch degrades to null/[] (clean two-tone) on failure so it can never
  // lose the active spotlight.
  const reg = CC_REGIONS.find(r=>r.slug===slug);
  const adjIds = (reg && reg.adj) || [];
  const adjSlugs = adjIds.map(id=>{ const r=CC_REGIONS.find(x=>x.id===id); return r&&r.slug; }).filter(Boolean);
  const boundaryOf = s => fetch(`/map/region/${encodeURIComponent(s)}/boundary`)
    .then(r=> r.ok ? r.json() : null).catch(()=>null);
  const fullP = adjIds.length
    ? fetch(`/map/scope/boundary?rids=${[reg.id,...adjIds].join(',')}`)
        .then(r=> r.status===204||!r.ok ? null : r.json()).catch(()=>null)
    : Promise.resolve(null);
  const adjP = adjSlugs.length
    ? Promise.all(adjSlugs.map(boundaryOf)).then(fs=>fs.filter(Boolean))
    : Promise.resolve([]);
  const mainP = fetch(`/map/region/${encodeURIComponent(slug)}/boundary`)
    .then(r=>{ if(!r.ok) throw new Error('boundary HTTP '+r.status); return r.json(); });

  // Tier 1 — paint the two-tone mask as soon as the ACTIVE region's own boundary
  // lands, without waiting for the six that only feed the neighbour tier.
  //
  // This buys nothing on localhost, where every response arrives in ~4 ms, and a
  // localhost measurement is what wrongly killed this once already. Throttled,
  // cold cache, it is worth 0.4-0.7 s of earlier feedback on every real network
  // (2026-07-26; active-region-only vs all-seven): fast 4G 196 vs 845 ms, slow 4G
  // 528 vs 964 ms, slow 3G 2185 vs 2859 ms. Riders are on phones.
  //
  // Note this is orthogonal to backlog item 10: a scope change also blocks the
  // main thread for ~5.4 s in applyScope, which delays BOTH tiers equally. Fixing
  // that is a separate and larger job; this only removes the network wait.
  mainP.then(d=>{
      const g = d && d.geometry;
      if(req!==_spotReq || !g || !map.getStyle()) return;   // superseded or gone
      clearSpotlight();   // no-op on this pass; guards against a re-entrant repaint
      drawSpotlightMask(g, null, null);
    // decorative only — the map works without the boundary, but log why it's missing (W34)
    }).catch(e=>console.warn('Region boundary unavailable:', e));

  // Tier 2 — upgrade to the three-tier spotlight when the neighbours and the
  // dissolved union arrive. Repaints from scratch because drawSpotlightMask adds
  // its sources unconditionally; clearing 2-4 layers costs far less than the
  // tessellation that follows. Bails when there is nothing to upgrade TO, leaving
  // tier 1's two-tone standing — which is also the no-neighbours case.
  Promise.all([mainP, adjP, fullP]).then(([d, adjFeatures, f])=>{
      const g = d && d.geometry;
      if(req!==_spotReq || !g || !map.getStyle()) return;
      if(!adjFeatures.length || !f || !f.geometry) return;
      clearSpotlight();
      drawSpotlightMask(g, adjFeatures, f.geometry);
    }).catch(()=>{});   // tier 1 already logged a main-boundary failure; adj/full self-degrade to null
}

// Shared mask painter: dim the world outside `g` + a dashed outline. Used by
// the named-region and country spotlights; the My-area circle keeps its own
// soft-edge variant (region-scoping-design.md §4 anti-border cue). When
// `adjFeatures` (each neighbour's own boundary Feature) + `fullUnion` (dissolved
// active+neighbours) are given (single-region three-tier spotlight,
// 2026-07-24-region-adjacency-and-click-refinement-design.md §8), the neighbours
// are punched out of the dark mask (via fullUnion — never touching rings), given
// a lighter middle tone, and each individually outlined.
function drawSpotlightMask(g, adjFeatures, fullUnion){
  const outerRings = geo => (geo.type==='MultiPolygon' ? geo.coordinates : [geo.coordinates]).map(p=>p[0]);
  // Signed ring area (shoelace); >0 is CCW. The world ring below is CCW, so every
  // hole MUST wind the opposite way (CW). MapLibre's fill classifies a ring as a
  // hole vs a new filled shape by its winding, NOT its position: a same-wound hole
  // is painted as a solid dark wedge reaching to the far world-rectangle edge —
  // visible only when zoomed out enough to see it. PostGIS emits ring winding
  // inconsistently across regions, so this triggered intermittently. Force CW.
  const area = ring => { let a=0; for(let i=0,n=ring.length,j=n-1;i<n;j=i++){ a += ring[j][0]*ring[i][1]-ring[i][0]*ring[j][1]; } return a; };
  const asHole = ring => area(ring) > 0 ? ring.slice().reverse() : ring;
  const world=[[-180,-85],[180,-85],[180,85],[-180,85],[-180,-85]];
  // Dark mask holes come from the dissolved active+adjacent blob (fullUnion) so
  // no two holes touch; without it, just the active region (clean two-tone).
  const holeSrc = fullUnion || g;
  const mask={type:'Feature',geometry:{type:'Polygon',coordinates:[world,...outerRings(holeSrc).map(asHole)]}};
  map.addSource('region-mask',{type:'geojson',data:mask});
  map.addSource('region',{type:'geojson',data:{type:'Feature',geometry:g}});
  map.addLayer({id:'region-mask',type:'fill',source:'region-mask',paint:{'fill-color':'#101E16','fill-opacity':0.22}});
  // Middle tone over the neighbours ONLY (active is not among adjFeatures, so it
  // stays fully clear). Gated on fullUnion too: without the dark holes punched,
  // this would double-darken the neighbours instead of lightening them. Each
  // neighbour is a SEPARATE feature, so the fainter dashed outline traces every
  // region's own edges — the borders BETWEEN neighbours, not just the band
  // perimeter. Neighbours tessellate (no interior overlap), so the fill does not
  // double up along their shared edges. Outline is thinner + more transparent
  // than the active region-line below.
  if(adjFeatures && adjFeatures.length && fullUnion){
    map.addSource('region-adj-mask',{type:'geojson',data:{type:'FeatureCollection',features:adjFeatures}});
    map.addLayer({id:'region-adj-mask',type:'fill',source:'region-adj-mask',paint:{'fill-color':'#101E16','fill-opacity':0.13}});
    map.addLayer({id:'region-adj-line',type:'line',source:'region-adj-mask',paint:{'line-color':'#C8923A','line-width':1.5,'line-dasharray':[2,1.5],'line-opacity':0.7}});
  }
  map.addLayer({id:'region-line',type:'line',source:'region',paint:{'line-color':'#C8923A','line-width':2.5,'line-dasharray':[2,1.4],'line-opacity':0.95}});
}
// Country spotlight (2026-07-22-coverage-scope-rendering-design.md §B): the
// whole-country outline via /map/scope/boundary's ST_Union, so a country scope
// greys the rest of the world exactly like a single named region does.
export function setCountrySpotlight(cc){
  if(!map.getStyle()) return;
  const req = ++_spotReq;
  clearSpotlight();
  if(!cc) return;
  fetch(`/map/scope/boundary?cc=${encodeURIComponent(cc)}`)
    .then(r=>{ if(r.status===204) return null; if(!r.ok) throw new Error('scope boundary HTTP '+r.status); return r.json(); })
    .then(d=>{ const g=d&&d.geometry;
      if(req!==_spotReq||!g||!map.getStyle()||map.getSource('region')) return;
      drawSpotlightMask(g);
    }).catch(e=>console.warn('Scope boundary unavailable:', e));
}

// My-area spotlight (region-scoping-design.md §4 / §9.1 Phase 4): a locally
// computed soft circle — zero fetch, unlike the named-region boundary. The
// deliberately fuzzy edge (line-blur) is §4's anti-border message made visible.
// Reuses the SAME source/layer ids as setSpotlight so clearSpotlight() and the
// scope-switch respotlight path keep working, and bumps the same _spotReq race
// token so a still-in-flight setSpotlight() fetch from a prior region scope
// sees its req superseded and never repaints over this circle.
export function setCircleSpotlight(center, rkm){
  if(!map.getStyle()) return;
  ++_spotReq;
  clearSpotlight();
  if(!center || !rkm) return;
  const n=64, lat=center[0];
  // Pole safety (region-scoping-design.md §4): cos(lat) -> 0 near +/-90 would blow dLng up to Infinity/NaN.
  const cosLat=Math.max(0.01, Math.cos(lat*Math.PI/180));
  const dLat = rkm/111.32, dLng = rkm/(111.32*cosLat);
  const ring=[];
  for(let i=0;i<=n;i++){ const a=2*Math.PI*i/n; ring.push([center[1]+dLng*Math.cos(a), lat+dLat*Math.sin(a)]); }
  const world=[[-180,-85],[180,-85],[180,85],[-180,85],[-180,-85]];
  const mask={type:'Feature',geometry:{type:'Polygon',coordinates:[world, ring]}};   // world minus the circle = the dimmed outside
  map.addSource('region-mask',{type:'geojson',data:mask});
  map.addSource('region',{type:'geojson',data:{type:'Feature',geometry:{type:'Polygon',coordinates:[ring]}}});
  map.addLayer({id:'region-mask',type:'fill',source:'region-mask',paint:{'fill-color':'#101E16','fill-opacity':0.22}});
  map.addLayer({id:'region-line',type:'line',source:'region',paint:{'line-color':'#C8923A','line-width':2.5,'line-blur':3,'line-opacity':0.95}});
}
