// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Ride-check: "what is along my GPX?"
   (map-and-search.md §9; coverage arm: map-and-search.md §9).

   Extracted from map.js by the module split. Its own
   sources/layers are namespaced `ridecheck*` and are never touched by render()'s
   clearDynamic, so the track survives a repaint.

   Every dep this module once took injected is a plain import now that drawer.js
   and places.js have landed (§5 step 6), so initRideCheck() takes none. The
   drawer-generation pairing it needs — invalidate the coverage detail AND the
   town-card nearby fetch together — is now called directly, the same two lines
   drawer.js's openDrawer/closeDrawer run.
 */
import { map, flyToPin } from './map-init.js';
import { I18N, D, tpl } from './i18n.js';
import { escPend, txtOn } from './util.js';
import { uKm, uM, uElev } from './units.js';
import { coverageIconId } from './icons.js';
import { openCoverageByRef, invalidateCoverageDrawer } from './coverage.js';
import { itemIndex } from './item-index.js';
import { CATALOG, layerByKey } from './catalog.js';
import { sheet } from './sheet.js';
import { closeDrawer, highlightAt, clearHighlight } from './drawer.js';
import { openRouteById, bumpPlaceReq } from './places.js';

// ---- Ride-check (spec 2026-07-14 §4.3): riders-only "what's along my GPX?" ----
// Track overlay uses its own source/layer ids (render()'s clearDynamic never
// touches them); results render in the standard right-hand drawer, exactly
// like the town card (same .cc-near list styling). Closing the drawer keeps
// the track on the map — the rail status offers "results" (re-open) and
// "clear"; Clear tears everything down. Read-only indication — the server
// parses the GPX in memory and stores nothing (notice in the rail control).
// Coverage letters ride-check surfaces, mapped to the layer key whose icon they
// draw with. Utility only (C/D/G/H) — the design leaves E/I/J to the curated
// arm, where they overlap most and add corridor noise.
const COV_KEY={C:'water', D:'services', G:'transit', H:'shelter'};

export function initRideCheck(){
    if(!window.CC_RIDECHECK) return;                       // anonymous: no control rendered
    const pick=document.getElementById('rcPick'), fileIn=document.getElementById('rcFile'),
          radiusSel=document.getElementById('rcRadius'), status=document.getElementById('rcStatus');
    if(!pick || !fileIn || !radiusSel || !status) return;
    let _file=null, _busy=false, _last=null;
    const say=msg=>{ status.hidden=!msg; status.textContent=msg||''; };
    function loadedStatus(d){
      status.hidden=false;
      status.innerHTML=`${uKm(d.distanceKm)} · <a class="cc-ride-lnk" data-act="show">${I18N.rcResults||'results'}</a> · <a class="cc-ride-lnk" data-act="clear">${I18N.rcClear||'clear'}</a>`;
    }
    status.addEventListener('click',e=>{ const a=e.target.closest('[data-act]'); if(!a) return;
      if(a.dataset.act==='show' && _last) renderRideDrawer(_last);
      else if(a.dataset.act==='clear') clearRideCheck();
    });
    function rideDrawerShowing(){ return !!document.getElementById('rcClearBtn'); }
    function clearOverlay(){
      ['ridecheck','ridecheck-case','ridecheck-cov'].forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); });
      ['ridecheck','ridecheck-cov'].forEach(id=>{ if(map.getSource(id)) map.removeSource(id); });
      clearHighlight();
    }
    /* Corridor coverage POIs as their OWN small-icon overlay
       (map-and-search.md §9, refined by the split
       design §7). Not a panel-only list, and deliberately not the coverage tile
       layers: an uploaded ride routinely leaves the rider's region scope, the
       coverage layer may be toggled off, and Curated mode hides the
       experiential letters — so relying on the tiles would list refill points
       the rider cannot see. Drawn unconditionally instead, with the same icons
       and the same `_s8`/`_s13`/`_s18` size ramp as the cov-sel overlay, which
       is what keeps them visibly SMALLER than a curated spot pin. */
    function drawCoverageOverlay(d){
      const groups=d.coverage||[];
      const features=[];
      groups.forEach(g=>{
        const key=COV_KEY[g.letter]; if(!key) return;
        const water=key==='water';
        g.items.forEach(it=>{
          // The tile-icon minter needs the same shape a tile feature's props
          // have; a ride-check row carries no potability/kind, so both fall back
          // to the "unknown" variants — the honest reading of what we know here.
          features.push({type:'Feature',
            geometry:{type:'Point',coordinates:[it.ll[1],it.ll[0]]},
            properties:{_icon:coverageIconId(key,{}),
              _s8:water?0.55:0.42, _s13:water?0.9:0.7, _s18:water?1.3:0.95}});
        });
      });
      if(!features.length) return;
      map.addSource('ridecheck-cov',{type:'geojson',data:{type:'FeatureCollection',features}});
      map.addLayer({id:'ridecheck-cov',type:'symbol',source:'ridecheck-cov',
        layout:{'icon-image':['get','_icon'],'icon-allow-overlap':true,
          // ONE zoom interpolate (MapLibre forbids two) with per-feature stop
          // outputs, exactly as cov-sel-icon does it.
          'icon-size':['interpolate',['linear'],['zoom'],
            8,['get','_s8'],13,['get','_s13'],18,['get','_s18']]}});
    }
    function clearRideCheck(){
      clearOverlay();
      _last=null; _file=null; say('');
      if(rideDrawerShowing()) closeDrawer();
    }
    function post(){
      if(!_file || _busy) return;
      _busy=true; pick.disabled=true; say(I18N.rcChecking||'Checking…');
      const body=new FormData();
      body.append('gpx', _file); body.append('radius', radiusSel.value); body.append('_token', CC_RIDECHECK.token);
      fetch(CC_RIDECHECK.url, {method:'POST', credentials:'same-origin', headers:{'Accept':'application/json'}, body})
        .then(r=>r.json().then(d=>({ok:r.ok, d})))
        .then(({ok, d})=>{ if(!ok) throw new Error(d.error||'ride-check failed'); loadedStatus(d); renderRideCheck(d); })
        .catch(err=>{ clearRideCheck(); say(err.message||I18N.rcError||'Could not check this ride — please try again.'); })
        .finally(()=>{ _busy=false; pick.disabled=false; });
    }
    function renderRideCheck(d){
      clearOverlay();
      _last=d;
      const coords=d.track.map(p=>[p[1],p[0]]);            // [lat,lng] → [lng,lat]
      map.addSource('ridecheck',{type:'geojson',data:{type:'Feature',properties:{},geometry:{type:'LineString',coordinates:coords}}});
      map.addLayer({id:'ridecheck-case',type:'line',source:'ridecheck',
        layout:{'line-cap':'round','line-join':'round'},
        paint:{'line-color':'#FBF4E4','line-width':8,'line-opacity':.95}});
      map.addLayer({id:'ridecheck',type:'line',source:'ridecheck',
        layout:{'line-cap':'butt','line-join':'round'},
        paint:{'line-color':'#3A3A33','line-width':4,'line-opacity':1,'line-dasharray':[2,1.6]}});
      let minLat=90,maxLat=-90,minLng=180,maxLng=-180;
      d.track.forEach(p=>{ if(p[0]<minLat)minLat=p[0]; if(p[0]>maxLat)maxLat=p[0]; if(p[1]<minLng)minLng=p[1]; if(p[1]>maxLng)maxLng=p[1]; });
      // drawer-aware framing, same as openPlace: the results drawer covers the
      // right edge on desktop, the bottom on mobile
      const mobile=window.innerWidth<=820;
      map.fitBounds([[minLng,minLat],[maxLng,maxLat]],
        {padding:{top:70, bottom:mobile?300:70, left:70, right:mobile?70:400}, duration:900, essential:true});
      drawCoverageOverlay(d);
      renderRideDrawer(d);
    }
    function renderRideDrawer(d){
      const asc=d.ascentM!=null?` · ↑ ${uElev(d.ascentM)}`:'';
      // The radius is one of a fixed set of metre values; uM writes it short
      // (250 m / 820 ft) and promotes the 1000 m option to 1 km / 0.6 mi.
      const radius=uM(d.radiusM);
      const kColor=(layerByKey.experience||{}).color||'#FF5A1F';
      let html=`<span class="cc-d-type" style="--c:#3A3A33;color:#fff">➜ ${D.rideCheck||'Ride check'}</span>
       <div class="cc-d-name">${D.alongRide||'Along your ride'}</div>
       <div class="cc-city-info">${uKm(d.distanceKm)}${asc} · ${tpl(D.rideMeta||'within {r} of the track — indication only, nothing stored.', {r:radius})}</div>
       <div class="cc-ride-actions"><button class="cc-ride-btn" id="rcClearBtn" type="button">✕ ${D.clearRide||'Clear ride'}</button></div>`;
      if(d.routes.length){
        html+=`<h4 class="cc-near-h">${D.rideFollows||'Your ride follows'}</h4><ul class="cc-near-list">`
          +d.routes.map(r=>`<li><button class="cc-near" data-rc-route="${r.id}"><span class="cc-near-k" style="background:${kColor};color:${txtOn(kColor)}">K</span><span class="cc-near-nm">${escPend(r.name)}</span><em>${tpl(D.kmShared||'{n} shared', {n:uKm(r.sharedKm)})}</em></button></li>`).join('')
          +`</ul>`;
      }
      html+=`<h4 class="cc-near-h">${D.alongTrackH||'In the Commons along the track'}</h4>`;
      if(d.groups.length){
        html+=`<ul class="cc-near-list">`;
        d.groups.forEach(g=>{
          const meta=CATALOG.find(l=>l.letter===g.letter)||{color:'#6b6f5e',label:g.letter};
          html+=`<li class="cc-near-grp"><span class="cc-near-k" style="background:${meta.color};color:${txtOn(meta.color)}">${meta.icon||'•'}</span>${escPend(meta.label)} · ${g.items.length}${g.truncated?` ${D.capped||'(capped)'}`:''}</li>`;
          html+=g.items.map((it,i)=>`<li><button class="cc-near" data-rc-g="${g.letter}" data-rc-i="${i}"><span class="cc-near-nm">${escPend(it.name||meta.label)}</span><em>${tpl(D.kmOff||'{a} along · {b} off', {a:uKm(it.alongKm), b:uM(it.distM)})}</em></button></li>`).join('');
        });
        html+=`</ul>`;
      } else {
        html+=`<div class="cc-near-empty">${tpl(D.nothingWithin||'Nothing in the Commons within {r} of this ride yet.', {r:radius})}</div>`;
      }
      /* Open coverage, as its own section under its own heading
. Deliberately distinct
         from "In the Commons along the track": these are uncurated OSM points,
         C/D/G/H only, already deduped server-side against the served items
         above, so nothing is listed twice. Rows carry `ref` rather than the
         curated arm's item id — see the service's groupByLetter docblock. */
      const cov=(d.coverage||[]).filter(g=>g.items.length);
      if(cov.length){
        html+=`<h4 class="cc-near-h">${D.alongTrackCovH||'Open coverage along the track'}</h4><ul class="cc-near-list">`;
        cov.forEach(g=>{
          const meta=CATALOG.find(l=>l.letter===g.letter)||{color:'#6b6f5e',label:g.letter};
          html+=`<li class="cc-near-grp"><span class="cc-near-k" style="background:${meta.color};color:${txtOn(meta.color)}">${meta.icon||'•'}</span>${escPend(meta.label)} · ${g.items.length}${g.truncated?` ${D.capped||'(capped)'}`:''}</li>`;
          html+=g.items.map((it,i)=>`<li><button class="cc-near" data-rc-c="${g.letter}" data-rc-i="${i}"><span class="cc-near-nm">${escPend(it.name||meta.label)}</span><em>${tpl(D.kmOff||'{a} along · {b} off', {a:uKm(it.alongKm), b:uM(it.distM)})}</em></button></li>`).join('');
        });
        html+=`</ul><div class="cc-near-note">${D.covArmNote||'From open data — not yet checked by a rider.'}</div>`;
      }
      const body=document.getElementById('drawerBody');
      invalidateCoverageDrawer(); bumpPlaceReq();   // invalidate any in-flight coverage POI detail + town-card nearby fetch — this render supersedes them
      body.innerHTML=html;
      document.getElementById('rcClearBtn').onclick=clearRideCheck;
      const groupsByLetter=Object.fromEntries(d.groups.map(g=>[g.letter,g]));
      body.querySelectorAll('[data-rc-route]').forEach(b=>{ b.onclick=()=>openRouteById(b.dataset.rcRoute); });
      body.querySelectorAll('[data-rc-g]').forEach(b=>{
        const it=(groupsByLetter[b.dataset.rcG]||{items:[]}).items[+b.dataset.rcI]; if(!it) return;
        const entry=itemIndex().find(x=>x.letter===b.dataset.rcG && x.id===it.id);
        b.onclick=()=>{ if(entry) entry.go(); else { flyToPin([it.ll[1],it.ll[0]]); highlightAt(it.ll); } };
        b.onmouseenter=()=>highlightAt(it.ll, entry && entry.hlOff);
        b.onmouseleave=clearHighlight;
      });
      const covByLetter=Object.fromEntries(cov.map(g=>[g.letter,g]));
      body.querySelectorAll('[data-rc-c]').forEach(b=>{
        const it=(covByLetter[b.dataset.rcC]||{items:[]}).items[+b.dataset.rcI]; if(!it) return;
        // openCoverageByRef flies, fetches /map/coverage/poi/{ref} and still
        // opens a minimal drawer when that 404s, so a row can never no-op. A
        // row without a ref (an older payload) falls back to fly + highlight
        // rather than doing nothing.
        b.onclick=()=>{ if(it.ref) openCoverageByRef(it.ref, b.dataset.rcC, it.ll, it.name); else { flyToPin([it.ll[1],it.ll[0]]); highlightAt(it.ll); } };
        b.onmouseenter=()=>highlightAt(it.ll);
        b.onmouseleave=clearHighlight;
      });
      const dr=document.getElementById('drawer'); dr.classList.add('open'); dr.setAttribute('aria-hidden','false');
      dr.focus({preventScroll:true});
      if(window.innerWidth<=820) sheet.reset();        // land at half; desktop untouched
    }
    pick.onclick=()=>fileIn.click();
    fileIn.addEventListener('change',()=>{ const f=fileIn.files && fileIn.files[0]; if(!f) return;
      _file=f; post(); fileIn.value='';                     // allow re-picking the same file
    });
    radiusSel.addEventListener('change',()=>{ if(_file) post(); });
}
