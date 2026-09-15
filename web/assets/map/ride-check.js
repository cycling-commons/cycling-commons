// SPDX-License-Identifier: AGPL-3.0-only
/* Ride-check: "what is along my GPX?" (docs/specs/map-and-search.md §9).
   Draws only the track (`ridecheck`, `ridecheck-case`; render()'s clearDynamic
   never touches them). The places it lists are drawn by the normal map: pool
   pins through osm-pools.js, coverage icons through the coverage tiles and the
   `cov-sel` overlay. The server parses the GPX in memory and stores nothing. */
import { map, flyToPin } from './map-init.js';
import { I18N, D, tpl } from './i18n.js';
import { escPend, txtOn } from './util.js';
import { uKm, uM, uElev } from './units.js';
import { layerGlyph } from './icons.js';
import { openCoverageByRef, invalidateCoverageDrawer } from './coverage.js';
import { itemIndex } from './item-index.js';
import { CATALOG, layerByKey, LETTER_KEY, active, mode } from './catalog.js';
import { sheet } from './sheet.js';
import { closeDrawer, highlightAt, clearHighlight, setDrawerReturn, mapToast } from './drawer.js';
import { openRouteById, bumpPlaceReq } from './places.js';
import { rideScopeFor, scopeKey } from './ride-scope.js';
import { listedPlaceKeys, ringOffset, createRideModeMemo } from './ride-places.js';
import { setListedPlaces, poolPinDrawn } from './osm-pools.js';
import { liftModeFor, applyMode } from './panels.js';
import { render, featureVisible, chipsPass, staysAccessible, showPlaceAnyway, releaseShownAnyway } from './render.js';

export function initRideCheck(){
    if(!window.CC_RIDECHECK) return;                       // anonymous: no control rendered
    const pick=document.getElementById('rcPick'), fileIn=document.getElementById('rcFile'),
          radiusSel=document.getElementById('rcRadius'), status=document.getElementById('rcStatus');
    if(!pick || !fileIn || !radiusSel || !status) return;
    let _file=null, _busy=false, _last=null, _prevScope=null;
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
      ['ridecheck','ridecheck-case'].forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); });
      if(map.getSource('ridecheck')) map.removeSource('ridecheck');
      setListedPlaces([]);                                  // the pools cluster every place again
      clearHighlight();
    }
    /* The ride decides the scope while it is loaded (docs/specs/map-and-search.md
       §4.5). A rider who drops a GPX of the Ardennes while scoped to Flanders
       means to look at the Ardennes; leaving the old scope up shows an empty
       map over a drawn track. A scope holds a SET of region ids, so a ride
       crossing three provinces takes all three rather than picking a winner and
       leaving the last 30 km unscoped. Two countries have no common region
       scope, so that widens to Everywhere instead. Never persisted: the rider's
       own scope goes back the moment the ride is cleared. */
    function applyRideScope(regions){
      const S=window.CCScope;
      const next=rideScopeFor(regions);
      if(!S || !next) return;                                    // ride outside every onboarded region: leave the scope alone
      const cur=S.get();
      if(_prevScope==null) _prevScope=cur;
      if(scopeKey(cur)===scopeKey(next)){ noteRideScope(); return; }   // already looking there
      S.set(next,{persist:false});
      noteRideScope();
    }
    function restoreScope(){
      const prev=_prevScope; _prevScope=null;
      if(prev && window.CCScope) window.CCScope.set(prev,{persist:false});
      else repaintScopeHeader();
    }
    /* The view mode works the same way (docs/specs/map-and-search.md §9): a row
       that lifts it is remembered, and Clear puts the rider's mode back. A mode
       the rider picks while the ride is loaded is theirs and stays. Never
       persisted either way (liftModeFor and the restore both pass persist:false). */
    const rideMode=createRideModeMemo();
    document.querySelectorAll('#mode button').forEach(b=>b.addEventListener('click',()=>rideMode.riderChose()));
    function restoreMode(){
      const back=rideMode.restore(mode());
      if(back) applyMode(back,{persist:false});
    }
    /* Open one listed place so the map really draws it. The view mode lifts
       with the deep-link rule; a place the rider's own filter chips hide is
       shown anyway, that one place only, until the drawer moves on or the ride
       is cleared (showPlaceAnyway in render.js; the chips never change). One
       toast names every reason the place was hidden. */
    function openListed(layer, f, go){
      // Stays are drawn by their pool, whose one chip is accessibility (osm-pools.js).
      const chipHidden = layer.key==='stays' ? !staysAccessible(f) : !chipsPass(layer, f);
      if(chipHidden) showPlaceAnyway(layer.letter, f.id);
      const from=mode();
      const lifted=liftModeFor(layer, f, chipHidden ? {also:D.toastFilterToo||'your filter hides it too'} : undefined);
      if(lifted) rideMode.lifted(from, mode());
      else if(chipHidden) mapToast(D.toastShownAnyway||'Shown anyway · your filter hides this place', {center:true});
      go();
    }
    function repaintScopeHeader(){
      if(window.CCScopeHeader) window.CCScopeHeader.paint(window.CC_I18N||{});
    }
    /* Say it in the chip: a scope that moves without a word is the same
       confusion from the other side. */
    function noteRideScope(){
      repaintScopeHeader();
      const el=document.getElementById('regionLine');
      if(el) el.textContent=el.textContent+' · '+(I18N.rcScopeFromRide||'from your ride');
    }

    /* Whether the normal map draws a bottom-anchored pin for this index entry
       right now, so the hover ring may sit on the pin body. */
    function pinDrawn(entry){
      if(!entry || !entry.layer) return false;
      if(entry.poolKey) return poolPinDrawn(entry.poolKey, entry.id);
      return active.has(entry.layer.key) && featureVisible(entry.layer, entry.modeF||{});
    }
    function showLayer(k){
      if(active.has(k)) return;
      active.add(k);
      const t=document.querySelector(`#layers .layer[data-key="${k}"]`); if(t) t.classList.remove('off');
      render();
    }
    function clearRideCheck(){
      clearOverlay();
      _last=null; _file=null; say('');
      setDrawerReturn(null);
      releaseShownAnyway(null, null);
      restoreMode();
      restoreScope();
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
      // Every place opened from here offers the way back to this summary.
      setDrawerReturn({label:D.rideSummary||'Ride summary', go:()=>{ if(_last) renderRideDrawer(_last); }});
      // Before the fitBounds below: cc:scopechange re-fits to the scope bbox,
      // and the ride's own framing must have the last word.
      applyRideScope(d.regions);
      setListedPlaces(listedPlaceKeys(d.groups));
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
      // Drawer-aware framing, same as openPlace.
      const mobile=window.innerWidth<=820;
      map.fitBounds([[minLng,minLat],[maxLng,maxLat]],
        {padding:{top:70, bottom:mobile?300:70, left:70, right:mobile?70:400}, duration:900, essential:true});
      renderRideDrawer(d);
    }
    function renderRideDrawer(d){
      const asc=d.ascentM!=null?` · ↑ ${uElev(d.ascentM)}`:'';
      // Radius is a fixed metre set; uM writes it short and promotes 1000 m.
      const radius=uM(d.radiusM);
      const kColor=(layerByKey.experience||{}).color||'#FF5A1F';
      let html=`<span class="cc-d-type" style="--c:#3A3A33;color:#fff">➜ ${D.rideCheck||'Ride check'}</span>
       <div class="cc-d-name">${D.alongRide||'Along your ride'}</div>
       <div class="cc-city-info">${uKm(d.distanceKm)}${asc} · ${tpl(D.rideMeta||'within {r} of the track', {r:radius})}</div>
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
          html+=`<li class="cc-near-grp"><span class="cc-near-k" style="background:${meta.color};color:${txtOn(meta.color)}">${layerGlyph(meta)||'•'}</span>${escPend(meta.label)} · ${g.items.length}${g.truncated?` ${D.capped||'(capped)'}`:''}</li>`;
          html+=g.items.map((it,i)=>`<li><button class="cc-near" data-rc-g="${g.letter}" data-rc-i="${i}"><span class="cc-near-nm">${escPend(it.name||meta.label)}</span><em>${tpl(D.kmOff||'{a} along · {b} off', {a:uKm(it.alongKm), b:uM(it.distM)})}</em></button></li>`).join('');
        });
        html+=`</ul>`;
      } else {
        html+=`<div class="cc-near-empty">${tpl(D.nothingWithin||'Nothing in the Commons within {r} of this ride yet.', {r:radius})}</div>`;
      }
      /* Open coverage, own heading. Uncurated OSM C/D/G/H, already deduped
         server-side against served items. Rows carry `ref`, not item id. */
      const cov=(d.coverage||[]).filter(g=>g.items.length);
      if(cov.length){
        html+=`<h4 class="cc-near-h">${D.alongTrackCovH||'Open coverage along the track'}</h4><ul class="cc-near-list">`;
        cov.forEach(g=>{
          const meta=CATALOG.find(l=>l.letter===g.letter)||{color:'#6b6f5e',label:g.letter};
          html+=`<li class="cc-near-grp"><span class="cc-near-k" style="background:${meta.color};color:${txtOn(meta.color)}">${layerGlyph(meta)||'•'}</span>${escPend(meta.label)} · ${g.items.length}${g.truncated?` ${D.capped||'(capped)'}`:''}</li>`;
          html+=g.items.map((it,i)=>`<li><button class="cc-near" data-rc-c="${g.letter}" data-rc-i="${i}"><span class="cc-near-nm">${escPend(it.name||meta.label)}</span><em>${tpl(D.kmOff||'{a} along · {b} off', {a:uKm(it.alongKm), b:uM(it.distM)})}</em></button></li>`).join('');
        });
        html+=`</ul><div class="cc-near-note">${D.covArmNote||'From open data — not yet checked by a rider.'}</div>`;
      }
      const body=document.getElementById('drawerBody');
      invalidateCoverageDrawer(); bumpPlaceReq();   // supersede in-flight coverage detail + town nearby
      releaseShownAnyway(null, null);               // back on the summary: the place shown anyway leaves the map
      body.innerHTML=html;
      document.getElementById('rcClearBtn').onclick=clearRideCheck;
      const groupsByLetter=Object.fromEntries(d.groups.map(g=>[g.letter,g]));
      // One index pass, not one .find() per row.
      const idxByKey=new Map(itemIndex().map(x=>[x.letter+':'+x.id, x]));
      body.querySelectorAll('[data-rc-route]').forEach(b=>{ b.onclick=()=>{
        const layer=layerByKey.experience, f=layer && layer.features.find(x=>String(x.id)===String(b.dataset.rcRoute));
        if(layer && f) openListed(layer, f, ()=>openRouteById(b.dataset.rcRoute));
        else openRouteById(b.dataset.rcRoute);
      }; });
      body.querySelectorAll('[data-rc-g]').forEach(b=>{
        const it=(groupsByLetter[b.dataset.rcG]||{items:[]}).items[+b.dataset.rcI]; if(!it) return;
        const entry=idxByKey.get(b.dataset.rcG+':'+it.id);
        /* A place the view mode hides lifts the mode with the deep-link rule
           (docs/specs/map-and-search.md §8), to the lowest rung that draws it;
           one the chips hide is shown anyway (openListed). */
        b.onclick=()=>{
          if(!entry){ flyToPin([it.ll[1],it.ll[0]]); highlightAt(it.ll); return; }
          openListed(entry.layer, entry.modeF, ()=>entry.go());
        };
        b.onmouseenter=()=>highlightAt((entry && entry.ll) || it.ll, ringOffset(pinDrawn(entry), entry && entry.hlOff));
        b.onmouseleave=clearHighlight;
      });
      const covByLetter=Object.fromEntries(cov.map(g=>[g.letter,g]));
      body.querySelectorAll('[data-rc-c]').forEach(b=>{
        const it=(covByLetter[b.dataset.rcC]||{items:[]}).items[+b.dataset.rcI]; if(!it) return;
        /* The point is a normal coverage tile icon. Its layer comes on and the
           view mode lifts to one that draws coverage (a coverage point carries
           no confirmation, so modeShows judges it as `{}`), then the drawer
           opens through openCoverageByRef, whose `cov-sel` overlay keeps the
           icon drawn. openCoverageByRef still opens a minimal drawer on 404;
           no ref falls back to fly. */
        b.onclick=()=>{
          const k=LETTER_KEY[b.dataset.rcC], layer=k && layerByKey[k];
          if(!it.ref || !layer){ flyToPin([it.ll[1],it.ll[0]]); highlightAt(it.ll); return; }
          showLayer(k);
          const from=mode();
          if(liftModeFor(layer, {})) rideMode.lifted(from, mode());
          openCoverageByRef(it.ref, b.dataset.rcC, it.ll, it.name);
        };
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
