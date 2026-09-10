// SPDX-License-Identifier: AGPL-3.0-only
/* Region / country / My-area spotlight (docs/specs/map-and-search.md §4.5).
   Shared `_spotReq` race token so a scope switch mid-fetch cannot leave two
   spotlights. Paints progressively: outline first, neighbour tier when it arrives. */
import { map } from './map-init.js';

const CC_REGIONS = window.CC_REGIONS || [];

// Dim outside the active named region + a dashed outline (docs/specs/map-and-search.md §4.5).
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
  // Three-tier: fullUnion punches one dissolved hole (earcut artifacts otherwise);
  // adjFeatures are each neighbour's own boundary so borders BETWEEN them show.
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

  // Tier 1 — two-tone mask as soon as the active region's boundary lands.
  mainP.then(d=>{
      const g = d && d.geometry;
      if(req!==_spotReq || !g || !map.getStyle()) return;   // superseded or gone
      clearSpotlight();   // no-op on this pass; guards against a re-entrant repaint
      drawSpotlightMask(g, null, null);
    }).catch(e=>console.warn('Region boundary unavailable:', e));

  // Tier 2 — upgrade when neighbours + dissolved union arrive; bail leaves tier 1 standing.
  Promise.all([mainP, adjP, fullP]).then(([d, adjFeatures, f])=>{
      const g = d && d.geometry;
      if(req!==_spotReq || !g || !map.getStyle()) return;
      if(!adjFeatures.length || !f || !f.geometry) return;
      clearSpotlight();
      drawSpotlightMask(g, adjFeatures, f.geometry);
    }).catch(()=>{});   // tier 1 already logged a main-boundary failure; adj/full self-degrade to null
}

// Dim outside `g` + dashed outline. adjFeatures + fullUnion = three-tier neighbour band.
function drawSpotlightMask(g, adjFeatures, fullUnion){
  const outerRings = geo => (geo.type==='MultiPolygon' ? geo.coordinates : [geo.coordinates]).map(p=>p[0]);
  // Shoelace; >0 is CCW. World ring is CCW, so holes MUST be CW — MapLibre
  // classifies holes by winding, not position. PostGIS winding is inconsistent.
  const area = ring => { let a=0; for(let i=0,n=ring.length,j=n-1;i<n;j=i++){ a += ring[j][0]*ring[i][1]-ring[i][0]*ring[j][1]; } return a; };
  const asHole = ring => area(ring) > 0 ? ring.slice().reverse() : ring;
  const world=[[-180,-85],[180,-85],[180,85],[-180,85],[-180,-85]];
  // Dark-mask holes from the dissolved blob so no two holes touch.
  const holeSrc = fullUnion || g;
  const mask={type:'Feature',geometry:{type:'Polygon',coordinates:[world,...outerRings(holeSrc).map(asHole)]}};
  map.addSource('region-mask',{type:'geojson',data:mask});
  map.addSource('region',{type:'geojson',data:{type:'Feature',geometry:g}});
  map.addLayer({id:'region-mask',type:'fill',source:'region-mask',paint:{'fill-color':'#101E16','fill-opacity':0.22}});
  // Middle tone over neighbours only (gated on fullUnion so they are holes, not double-dark).
  if(adjFeatures && adjFeatures.length && fullUnion){
    map.addSource('region-adj-mask',{type:'geojson',data:{type:'FeatureCollection',features:adjFeatures}});
    map.addLayer({id:'region-adj-mask',type:'fill',source:'region-adj-mask',paint:{'fill-color':'#101E16','fill-opacity':0.13}});
    map.addLayer({id:'region-adj-line',type:'line',source:'region-adj-mask',paint:{'line-color':'#C8923A','line-width':1.5,'line-dasharray':[2,1.5],'line-opacity':0.7}});
  }
  map.addLayer({id:'region-line',type:'line',source:'region',paint:{'line-color':'#C8923A','line-width':2.5,'line-dasharray':[2,1.4],'line-opacity':0.95}});
}
// Country spotlight (docs/specs/map-and-search.md §4.5): whole-country outline via ST_Union.
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

// My-area spotlight (docs/specs/map-and-search.md §4.5): local soft circle, same source/layer ids + _spotReq.
export function setCircleSpotlight(center, rkm){
  if(!map.getStyle()) return;
  ++_spotReq;
  clearSpotlight();
  if(!center || !rkm) return;
  const n=64, lat=center[0];
  const cosLat=Math.max(0.01, Math.cos(lat*Math.PI/180));   // pole safety: cos(lat)→0 would blow dLng
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
