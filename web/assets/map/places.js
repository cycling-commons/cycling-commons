// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Places and openers: the town/city info card with its "in the Commons nearby"
   list, and every by-name / by-id way into a drawer — a deep link, a search hit,
   a place-card row, the curator queue.
   Extracted from map.js by 2026-07-26-map-js-module-split-design.md §5 step 6.

   `_placeReq` is the town-card half of the shared drawer-generation convention
   (its coverage half is coverage.js's invalidateCoverageDrawer). It stays
   private — an importer would hold a read-only binding of a counter — and is
   bumped through bumpPlaceReq(). Everything that opens or closes a drawer calls
   BOTH: drawer.js's openDrawer/closeDrawer, renderPlaceCard here, and
   ride-check's results drawer.

   resolveLocalFeature() is deliberately side-effect-free and separate from
   openFeatureByName(): the deep-link auto-widen gate has to ask "does this
   resolve?" BEFORE any scope change, and the two lookups must never drift
   (07-20 review finding 9). */
import { D, tpl } from './i18n.js';
import { escPend, txtOn, haversine, featurePoint } from './util.js';
import { map, flyToPin } from './map-init.js';
import { CATALOG, CITIES, active, layerByKey, LETTER_KEY, mode } from './catalog.js';
import { osmLayers } from './osm-pools.js';
import { nearbyItems, idxIds } from './item-index.js';
import { render } from './render.js';
import { sheet } from './sheet.js';
import { openDrawer, osmDrawer, highlightAt, clearHighlight, revealPinAt } from './drawer.js';
import { COVERAGE_ON, widenForDeepLink, openCoverageByRef,
         invalidateCoverageDrawer } from './coverage.js';
import { showRouteCorrections } from './corrections.js';

// The town-card half of the drawer-generation convention — see the header.
export function bumpPlaceReq(){ _placeReq++; }

// Race guard: EVERY drawer-context render (openDrawer, openPlace/
// renderPlaceCard, the ride-check results drawer, closeDrawer) bumps BOTH
// the coverage generation (coverage.js's invalidateCoverageDrawer()) and
// _placeReq together — one shared drawer-generation convention, two names so
// each call site reads as "this fetch kind is now stale". A stale
// GET /map/coverage/poi/{ref} detail response or a stale
// GET /map/coverage/nearby town-card response (_placeReq) can therefore
// never repaint a drawer that has since moved on — regardless of which
// click path (coverage dot, curated pin, -osm dot, route, place card,
// another town) opened the newer drawer. Before this pairing, _placeReq
// was bumped only in openPlace, so switching from a town card to a plain
// feature drawer (or to a different town) mid-fetch let the stale nearby
// response resurrect the old town card over the new drawer content. The
// enrich repaint itself is a content-only patch (renderDrawerBody), not a
// re-open: no halo restart, no focus steal, no mobile-sheet snap to half.
let _placeReq=0;

// place info card: fly to the town/village, show its info (when known) +
// everything in the Commons within 5 km, grouped by layer. Works for any
// geocoded place (spec 2026-07-14 §3.3): CITIES entries keep their wiki/info
// blurbs; Photon hits pass just {ll}.
export function openPlace(name, meta){
  // Town outside the current scope → transiently widen (persist:false, the
  // deep-link mechanism), so the map matches the scope-exempt town drawer
  // instead of zooming into an area the scope renders empty (owner decision
  // 2026-07-21, map-and-search.md §4.5; opening a town is an explicit
  // location choice — the map should follow it). Bbox containment is the
  // deliberate approximation: a town inside the scope bbox already renders
  // its surroundings, so no widen is needed there. The saved scope returns
  // on the next plain load.
  const sbb = window.CCScope && window.CCScope.bbox ? window.CCScope.bbox() : null;
  if(sbb && (meta.ll[1]<sbb[0] || meta.ll[0]<sbb[1] || meta.ll[1]>sbb[2] || meta.ll[0]>sbb[3])){
    widenForDeepLink();
  }
  // A · Road surface segments are corridor data, not places — near any mapped
  // town they'd flood the card (Spa: 58 rows). Text search still finds them.
  const near = nearbyItems(meta.ll, 5).filter(n=>n.e.letter!=='A');
  renderPlaceCard(name, meta, near);
  // Frame the whole ≤5 km neighbourhood instead of flyToPin's zoom-14 dive —
  // hovering the list must pulse items that are actually on screen. The
  // drawer covers the right edge on desktop (bottom sheet on mobile), hence
  // the asymmetric padding. Framed ONCE, from the local rows: the coverage
  // re-render below must not re-jump the camera.
  if(near.length){
    let minLat=meta.ll[0],maxLat=meta.ll[0],minLng=meta.ll[1],maxLng=meta.ll[1];
    near.forEach(n=>{ if(!n.e.ll) return; const [la,ln]=n.e.ll;
      if(la<minLat)minLat=la; if(la>maxLat)maxLat=la; if(ln<minLng)minLng=ln; if(ln>maxLng)maxLng=ln; });
    const mobile=window.innerWidth<=820;
    map.fitBounds([[minLng,minLat],[maxLng,maxLat]],
      {padding:{top:70, bottom:mobile?300:70, left:70, right:mobile?70:400}, maxZoom:13.5, duration:900, essential:true});
  } else {
    map.flyTo({center:[meta.ll[1],meta.ll[0]], zoom:12.5, offset:[window.innerWidth<=820?0:-150,0], duration:900, essential:true});
  }
  // Coverage tier (coverage-provider.md §5): uncurated OSM
  // within the same 5 km from /map/coverage/nearby, merged behind the local
  // rows per letter group. Photon-style silent degradation — the local card
  // is already on screen; a slow/failed response changes nothing.
  if(!COVERAGE_ON) return;
  const myReq=++_placeReq;
  fetch(`/map/coverage/nearby?lat=${meta.ll[0]}&lng=${meta.ll[1]}&km=5`, {headers:{'Accept':'application/json'}})
    .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
    .then(d=>{ if(myReq!==_placeReq) return;
      if(!document.getElementById('drawer').classList.contains('open')) return;   // card closed while in flight
      renderPlaceCard(name, meta, near, d.groups||[]); })
    .catch(()=>{});
}
// town-card body renderer — split from openPlace so the coverage nearby
// response re-renders the list without re-running the framing. Row order:
// local (curated/served) rows nearest-first, then coverage rows appended
// inside the same letter groups. Coverage items with a curated twin already
// in the local index are dropped (IDX_IDS — dedupe by served item id).
// (_placeReq is declared above; its coverage half lives in coverage.js.)
function renderPlaceCard(name, meta, near, covGroups){
  const all=near.slice();
  (covGroups||[]).forEach(g=>{
    const key=LETTER_KEY[g.letter], layer=key&&layerByKey[key]; if(!layer) return;
    (g.items||[]).forEach(it=>{
      if(!it || !Array.isArray(it.ll)) return;
      if(it.itemId!=null && idxIds().has(g.letter+':'+it.itemId)) return;
      all.push({dist:haversine(meta.ll, it.ll), e:{name:it.n||layer.label, kind:layer.label,
        badge:g.letter, color:layer.color, letter:g.letter, ll:it.ll, hlOff:[0,0], community:!it.curated,
        // it.itemId (curated rows only) routes through the served item's own
        // attributes instead of the OSM-tile fallback — see openCoverageByRef's header.
        go:()=>openCoverageByRef(it.ref, g.letter, it.ll, it.n, it.itemId)}});
    });
  });
  // group rows by letter, keeping the global nearest-first order inside each group
  const byLetter={};
  all.forEach((n,i)=>{ n._i=i; (byLetter[n.e.letter]=byLetter[n.e.letter]||[]).push(n); });
  const letters=Object.keys(byLetter).sort();
  const isComm=n=>n.e.community || n.e.verified===false;
  const list = all.length
    ? letters.map(L=>{ const rows=byLetter[L], e0=rows[0].e;
        // 07-15 decision A: verified/curated rows first, then the community
        // subgroup capped at 3 behind a "show all N" expander. Same collapsed
        // presentation on mobile (decision F) — one code path.
        const ver=rows.filter(n=>!isComm(n)), com=rows.filter(isComm);
        const row=(n,hidden)=>`<li${hidden?` hidden data-more="${L}"`:''}><button class="cc-near" data-i="${n._i}"><span class="cc-near-nm">${escPend(n.e.name)}${isComm(n)?`<span class="cc-comm-tag">${escPend(D.community||'community')}</span>`:''}</span><em>${n.dist<1?Math.round(n.dist*1000)+' m':n.dist.toFixed(1)+' km'}</em></button></li>`;
        let html=`<li class="cc-near-grp"><span class="cc-near-k" style="background:${e0.color};color:${txtOn(e0.color)}">${escPend(e0.badge)}</span>${escPend(e0.kind)} · ${rows.length}</li>`;
        html+=ver.map(n=>row(n,false)).join('');
        html+=com.slice(0,3).map(n=>row(n,false)).join('');
        html+=com.slice(3).map(n=>row(n,true)).join('');
        if(com.length>3) html+=`<li><button class="cc-near-more" data-grp="${L}">${escPend(tpl(D.showAll||'show all {n}',{n:com.length}))}</button></li>`;
        return html;
      }).join('')
    : `<li class="cc-near-empty">${D.nothingHere||'Nothing mapped here yet — be the first to add something.'}</li>`;
  invalidateCoverageDrawer();   // invalidate any in-flight coverage POI detail — this render supersedes it
  document.getElementById('drawerBody').innerHTML =
    `<span class="cc-d-type" style="--c:#3E7D8C;color:#fff">◎ ${meta.t==='City'?(D.city||'City'):(D.town||'Town')}</span>
     <div class="cc-d-name">${escPend(name)}</div>
     ${meta.info?`<div class="cc-city-info">${meta.info}</div>`:''}
     <div class="cc-city-links">${meta.wiki?`<a href="${meta.wiki}" target="_blank" rel="noopener">Wikipedia ↗</a> · `:''}<span class="cc-city-ua">${D.notesNone||'community notes — none yet'}</span></div>
     <h4 class="cc-near-h">${D.nearbyH||'In the Commons nearby · ≤ 5 km'}</h4>
     <ul class="cc-near-list">${list}</ul>`;
  document.querySelectorAll('#drawerBody .cc-near').forEach(b=>{
    const n=all[+b.dataset.i];
    b.onclick=()=>n.e.go();
    b.onmouseenter=()=>highlightAt(n.e.ll, n.e.hlOff); b.onmouseleave=clearHighlight;
  });
  // expander: reveal the collapsed community rows of one group, then retire itself
  document.querySelectorAll('#drawerBody .cc-near-more').forEach(b=>{
    b.onclick=()=>{ document.querySelectorAll(`#drawerBody li[data-more="${b.dataset.grp}"]`).forEach(li=>li.hidden=false);
      b.closest('li').hidden=true; };
  });
  const d=document.getElementById('drawer'); d.classList.add('open'); d.setAttribute('aria-hidden','false');
  d.focus({preventScroll:true});   // move focus into the panel (not the close X — avoids a focus ring on tap/click open)
  if(window.innerWidth<=820) sheet.reset();          // land at half; desktop untouched
}
// city info card — thin CITIES-lookup wrapper kept for existing callers (drawer .cc-city links, search)
export function openCity(name){
  const c = CITIES[name]; if(!c) return;
  openPlace(name, c);
}
// Resolve a name to a CATALOG feature or PIVOT stay WITHOUT side effects —
// shared by openFeatureByName and the deep-link auto-widen gate (07-20
// review finding 9), so "does this deep link resolve?" can be asked before
// any scope change or drawer open, and the two lookups can never drift.
export function resolveLocalFeature(name){
  let found=null;
  CATALOG.forEach(layer=>layer.features.forEach(f=>{ if(f.name===name) found={layer,f}; }));
  if(found) return found;
  // PIVOT stays live in CC_STAYS_PIVOT, not CATALOG — resolve them here so
  // an official Tourisme Wallonie deep-link opens the full stay drawer
  // (name, province, official-registry provenance) instead of falling
  // through to the coverage path, whose /poi endpoint knows only node|way
  // refs and 404s on fx:pivot:/manual: source_refs.
  const pv=(window.CC_STAYS_PIVOT && CC_STAYS_PIVOT.features || [])
    .find(f=>f.properties && f.properties.n===name);
  return pv ? {pivot:pv} : null;
}
// open a specific feature by name (deep-link from e.g. a profile page): activate its layer, draw, zoom in
export function openFeatureByName(name){
  const found=resolveLocalFeature(name);
  if(!found) return false;
  if(found.pivot){
    const pv=found.pivot;
    const c=pv.geometry && pv.geometry.coordinates;
    if(c && c.length>=2) flyToPin([+c[0],+c[1]]);
    openStayPivot(pv);
    return true;
  }
  return openLocalFeature(found.layer, found.f);
}
// Open an ALREADY-resolved CATALOG feature (no name lookup) — the
// side-effecting half of openFeatureByName, shared by the index/place-card
// `go` so a nameless feature opens its exact pin rather than a name-slug guess.
export function openLocalFeature(layer, f){
  if(!active.has(layer.key)){
    active.add(layer.key);
    const t=document.querySelector(`#layers .layer[data-key="${layer.key}"]`); if(t) t.classList.remove('off');
    render();
  }
  openDrawer(layer,f);
  const p=featurePoint(f); if(p) flyToPin([p[1],p[0]]);
  return true;
}
// open a specific route by id, SELECTED (deep-link from the curator Routes desk).
// Curated mode only draws best-of routes; a non-best-of route now gets a
// single reveal pin at its start (07-15 decision C) instead of the old
// force-switch of the whole map into Everything.
export function openRouteById(id){
  const layer=layerByKey['experience']; if(!layer) return false;
  const f=layer.features.find(x=>String(x.id)===String(id));
  if(!f) return false;
  openDrawer(layer,f);
  const p=featurePoint(f); if(p) flyToPin([p[1],p[0]]);
  if(!(mode()==='all'||f.cur) && p) revealPinAt(layer, p);
  showRouteCorrections(id);
  return true;
}

// open a pending submission by id (deep-link from the /moderate queue's "View on map")
export function openPendingById(id){
  const layer = layerByKey.pending; if(!layer) return false;
  const f = layer.features.find(x=>x.pending && String(x.pending.id)===String(id));
  if(!f) return false;
  if(!active.has('pending')){
    active.add('pending');
    const t=document.querySelector('#layers .layer[data-key="pending"]'); if(t) t.classList.remove('off');
    render();
  }
  openDrawer(layer,f);
  const p=featurePoint(f); if(p) flyToPin([p[1],p[0]]);
  return true;
}
// open a PIVOT accommodation point (Tourisme Wallonie, CC-BY) from search — these are bulk
// stays merged into the map, not CATALOG features, so activate the E layer, draw + zoom in
export function openStayPivot(f){
  const layer=layerByKey.stays; if(!layer) return false;
  if(!active.has('stays')){
    active.add('stays');
    const t=document.querySelector('#layers .layer[data-key="stays"]'); if(t) t.classList.remove('off');
    render();
  }
  const c=f.geometry.coordinates;                       // [lng,lat]
  openDrawer(layer, osmDrawer(layer, f.properties, {lng:c[0], lat:c[1]}, (osmLayers.stays||{}).src||''));
  flyToPin(c);
  return true;
}
