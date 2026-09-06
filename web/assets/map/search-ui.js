// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Sidebar search: towns, item index, coverage lookup, widen chip.
   @see docs/specs/map-and-search.md §7 */
import { I18N, D, tpl } from './i18n.js';
import { escPend, slug, txtOn } from './util.js';
import { CITIES, layerByKey, LETTER_KEY } from './catalog.js';
import { inScope, scopeLabel } from './scope-ui.js';
import { itemIndex, idxIds, rebuildItemIndex, dropPendingFromIndex } from './item-index.js';
import { openPlace, openCity } from './places.js';
import { COVERAGE_ON, covScopeIsZero, covScopeQuery, openCoverageByRef } from './coverage.js';
import { layerGlyph } from './icons.js';

let _searchDropPending=null;

export function dropPendingFromSearch(id){
  if(_searchDropPending) _searchDropPending(id);
}

export function initSearchUi(){
  const sBox=document.getElementById('search'), sRes=document.getElementById('searchRes');
  if(sBox && sRes){
    const escH = s => String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    // docs/specs/map-and-search.md §7.1 — towns first, then the unified item index.
    const SEARCH_IDX=[];
    Object.keys(CITIES).forEach(name=>{ const big=CITIES[name].t==='City';
      SEARCH_IDX.push({name, key:slug(name), kind: big?(D.city||'City'):(D.town||'Town'), badge: big?'◉':'◎', color: big?'#C8923A':'#3E7D8C', town:true, go:()=>openCity(name)}); });
    rebuildItemIndex();
    itemIndex().forEach(e=>{ if(!e.unnamed) SEARCH_IDX.push(e); });  // docs/specs/map-and-search.md §7.1 — unnamed POIs stay off text search
    _searchDropPending=id=>{
      for(let i=SEARCH_IDX.length-1;i>=0;i--){ if(SEARCH_IDX[i].pend===String(id)) SEARCH_IDX.splice(i,1); }
      dropPendingFromIndex(id);
    };
    let sMatches=[], sHL=-1;
    /* Worldwide reach for THIS search only (docs/specs/map-and-search.md §4.5):
       the scope stays where it is while the list looks everywhere, and picking
       a hit moves the scope to that hit's country. Drawing every item on
       Earth to search them was what made the browser sluggish (owner,
       2026-09-06), so Everywhere lives here and nowhere else. */
    let _worldwide=false;
    /* The switch beside the title says the reach out loud and lets the rider
       set it before typing. Same state as the results' last row. */
    const reachBtn=document.getElementById('searchReach');
    const everywhereLabel = reachBtn ? reachBtn.textContent.trim() : (I18N.everywhereLabel||'Everywhere');
    const setReach=(on)=>{
      _worldwide=!!on;
      if(reachBtn){
        reachBtn.setAttribute('aria-pressed', _worldwide?'true':'false');
        // A true toggle: while the reach is on, the chip names the scope you
        // would go back to; off, it names the reach you could switch on.
        const s = window.CCScope ? window.CCScope.get() : null;
        const back = (s && s.kind==='myArea') ? (I18N.myAreaLabel||'My area') : (scopeLabel(s) || everywhereLabel);
        reachBtn.textContent = _worldwide ? back : everywhereLabel;
      }
      // The title says where the search looks: "Search everywhere" while the
      // reach is on, the scope's own name again when it is off (the header
      // module owns that string, so it repaints it).
      const title=document.getElementById('searchTitle');
      if(title){
        if(_worldwide) title.textContent = I18N.searchEverywhere||'Search everywhere';
        else if(window.CCScopeHeader) window.CCScopeHeader.paint(I18N);
      }
    };
    const closeS=()=>{ sRes.hidden=true; sRes.innerHTML=''; sMatches=[]; sHL=-1; setReach(false); if(sBox.getAttribute('aria-expanded')!=='false') sBox.setAttribute('aria-expanded','false'); };
    if(reachBtn) reachBtn.addEventListener('click', ()=>{
      setReach(!_worldwide);
      sBox.focus();
      if(sBox.value.trim()){ runPhoton(sBox.value); runCoverageSearch(sBox.value); runS(); }
    });
    const hlS=()=>sRes.querySelectorAll('button').forEach((b,i)=>b.classList.toggle('hl',i===sHL));
    function widenSearch(){
      if(!window.CCScope) return;
      if(window.CCScope.canWiden()) window.CCScope.widen(); else setReach(true);
      runPhoton(sBox.value); runCoverageSearch(sBox.value); runS();
    }
    /* A hit found worldwide sits in some country: look there before opening it. */
    function followHit(m){
      if(!_worldwide || !window.CCScope || !Array.isArray(m.ll)) return;
      if(m.rid!=null && inScope(m.rid)) return;
      const cc=window.CCScope.countryAt(+m.ll[0], +m.ll[1]);
      const s=window.CCScope.get();
      if(cc && !(s && s.kind==='country' && s.countryCode===cc)) window.CCScope.setCountry(cc);
    }
    function pickS(i){ const m=sMatches[i]; if(!m) return;
      if(m.widen){ m.go(); return; }
      if(m.scope){ m.go(); closeS(); sBox.blur(); return; }
      followHit(m);
      sBox.value=m.name; closeS();
      m.go(); }
    // docs/specs/map-and-search.md §12 — community sub-tag; towns and pending never.
    const commRow=m=>!m.town && !m.pend && (m.community || m.verified===false);
    const sRow=(m,i)=>`<li role="option"><button data-i="${i}"><span class="sw" style="background:${m.color};color:${txtOn(m.color)}">${m.badge}</span><span class="snm">${escH(m.name)}${commRow(m)?`<span class="scomm">${escH(D.community||'community')}</span>`:''}</span><span class="sub">${escH(m.kind)}</span></button></li>`;
    const SCOPE_COLOR='#B5532E';
    const scopeRow=(m,i)=>`<li role="option"><button data-i="${i}" class="s-scope"><span class="sw" style="background:${SCOPE_COLOR};color:${txtOn(SCOPE_COLOR)}">${m.kind==='country'?'◆':'◇'}</span><span class="snm">${escPend(m.name)}</span><span class="sub">${escPend(m.kind==='country'?(D.wholeCountry||'Whole country'):(D.region||'Region'))}</span></button></li>`;
    // docs/specs/map-and-search.md §7.2 — Photon, never Nominatim; silent degrade.
    let _phAbort=null, _phHits=[], _phQ='';
    const PH_BASE='https://photon.komoot.io/api/?limit=6'
      +'&osm_tag=place:city&osm_tag=place:town&osm_tag=place:village&osm_tag=place:hamlet&osm_tag=place:municipality';
    function runPhoton(qRaw){
      const q=qRaw.trim();
      if(q.length<3){ _phHits=[]; _phQ=''; return; }
      if(_phAbort) _phAbort.abort();
      const ctl=new AbortController(); _phAbort=ctl;
      const pp = (window.CCScope && !_worldwide) ? window.CCScope.photonParams() : {bbox:null, countrycode:null};
      const url = PH_BASE + (pp.bbox ? '&bbox='+pp.bbox.join(',') : '') + '&q='+encodeURIComponent(q);
      fetch(url, {signal:ctl.signal})
        .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(d=>{
          if(ctl.signal.aborted) return;
          const seen=new Set(Object.keys(CITIES).map(n=>slug(n)));
          _phHits=(d.features||[])
            .filter(f=>f && f.properties && f.properties.name && f.geometry && Array.isArray(f.geometry.coordinates))
            // docs/specs/map-and-search.md §7.2 — countrycode is the precise gate; bbox spills borders.
            .filter(f=>!pp.countrycode || String(f.properties.countrycode||'').toLowerCase()===pp.countrycode)
            .filter(f=>{ const k=slug(f.properties.name); if(!k || seen.has(k)) return false; seen.add(k); return true; })
            .map(f=>{ const c=f.geometry.coordinates, name=f.properties.name;
              return {name, key:slug(name), kind:'Town', badge:'◎', color:'#3E7D8C', town:true, ph:1,
                go:()=>openPlace(name, {ll:[+c[1],+c[0]]})}; });
          _phQ=slug(q);
          if(!sRes.hidden) runS();
        })
        .catch(()=>{});
    }
    // docs/specs/coverage-provider.md §5 — live lookup; abort in-flight; silent degrade.
    let _covAbort=null, _covHits=[], _covSQ='';
    function runCoverageSearch(qRaw){
      const q=qRaw.trim();
      if(!COVERAGE_ON || q.length<2){ _covHits=[]; _covSQ=''; return; }
      if(_covAbort) _covAbort.abort();
      // docs/specs/map-and-search.md §4.5 — fail-closed: unscoped myArea must not fetch global rows.
      if(covScopeIsZero()){
        _covHits=[]; _covSQ=slug(q);
        if(!sRes.hidden) runS();
        return;
      }
      const ctl=new AbortController(); _covAbort=ctl;
      const sq=_worldwide ? '' : covScopeQuery();
      fetch('/map/coverage/search?q='+encodeURIComponent(q)+(sq?('&'+sq):''), {signal:ctl.signal, headers:{'Accept':'application/json'}})
        .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(d=>{
          if(ctl.signal.aborted) return;
          _covHits=(d.results||[])
            .filter(h=>h && h.n && LETTER_KEY[h.letter] && Array.isArray(h.ll))
            .filter(h=>!(h.itemId!=null && idxIds().has(h.letter+':'+h.itemId)))
            .map(h=>{ const layer=layerByKey[LETTER_KEY[h.letter]];
              return {name:h.n, key:slug(h.n), kind:layer.label, badge:layerGlyph(layer), color:layer.color,
                letter:h.letter, ll:h.ll, cov:1, community:!h.curated,
                go:()=>openCoverageByRef(h.ref, h.letter, h.ll, h.n, h.itemId)}; });
          _covSQ=slug(q);
          if(!sRes.hidden) runS();
        })
        .catch(()=>{});
    }
    function runS(){
      const q=slug(sBox.value.trim());
      if(!q){ closeS(); return; }
      const starts=[], has=[];
      for(const it of SEARCH_IDX){
        // docs/specs/map-and-search.md §4.5 — hidden pins must not resurface as
        // search rows, unless this search was widened to the whole world.
        if(!_worldwide && !it.town && !inScope(it.rid)) continue;
        const i=it.key.indexOf(q); if(i===0) starts.push(it); else if(i>0) has.push(it);
      }
      const ranked=starts.concat(has);
      // docs/specs/map-and-search.md §7.3 — places first, then A–K, cap 30.
      const towns=ranked.filter(m=>m.town), items=ranked.filter(m=>!m.town);
      if(_phQ===q) towns.push(..._phHits.slice(0, Math.max(0, 6-towns.length)));
      const byLetter={};
      items.forEach(m=>{ (byLetter[m.letter]=byLetter[m.letter]||[]).push(m); });
      if(_covSQ===q) _covHits.forEach(m=>{ (byLetter[m.letter]=byLetter[m.letter]||[]).push(m); });
      Object.keys(byLetter).forEach(L=>byLetter[L].sort((a,b)=>(commRow(a)?1:0)-(commRow(b)?1:0)));
      // A · one row per route name (up to "·"); other letters keep same-named distinct places.
      if(byLetter.A){
        const seen=new Set();
        byLetter.A=byLetter.A.filter(m=>{ const k=String(m.name||'').split(' · ')[0]; if(seen.has(k)) return false; seen.add(k); return true; });
      }
      const scopeHits = window.CCScope ? window.CCScope.searchScopes(sBox.value) : [];
      const groups=scopeHits.length ? [{label:D.scopes||'Scopes', rows:scopeHits.map(s=>({
        scope:true, kind:s.kind, name:s.label,
        go:()=> s.kind==='country' ? window.CCScope.setCountry(s.cc) : window.CCScope.setRegion(s.slug),
      }))}] : [];
      if(towns.length) groups.push({label:D.places||'Places', rows:towns});
      Object.keys(byLetter).sort().forEach(L=>groups.push({label:`${L} · ${byLetter[L][0].kind}`, rows:byLetter[L]}));
      const CAP=30;
      sMatches=[]; sHL=-1;
      let html='';
      for(const g of groups){
        if(sMatches.length>=CAP) break;
        const rows=g.rows.slice(0, CAP-sMatches.length);
        html+=`<li class="sgrp" role="presentation">${escH(g.label)}</li>`;
        rows.forEach(m=>{ html+=(m.scope?scopeRow:sRow)(m, sMatches.length); sMatches.push(m); });
      }
      sRes.hidden=false;
      // docs/specs/map-and-search.md §7.3 — IME: write aria-expanded only on real open/close.
      if(sBox.getAttribute('aria-expanded')!=='true') sBox.setAttribute('aria-expanded','true');
      const realCount=sMatches.length;
      let widenHtml='';
      if(window.CCScope && !_worldwide){
        // One rung up while there is one; at the widest scope, the whole world,
        // for this search only.
        const nw = window.CCScope.nextWider();
        const wl = nw ? tpl(I18N.searchWiden||'Search in {area} instead', {area:scopeLabel(nw)})
          : (I18N.searchEverywhere||'Search everywhere');
        widenHtml = `<li class="search-widen" role="option"><button type="button" data-widen="1" data-i="${sMatches.length}">${escH(wl)}</button></li>`;
        sMatches.push({widen:true, go:widenSearch});
      }
      sRes.innerHTML = (realCount ? html : `<li class="search-empty">${D.noMatch||'No match in the Commons yet.'}</li>`) + widenHtml;
    }
    sRes.addEventListener('click', e=>{
      // stopPropagation: runS() rebuilds the dropdown; without it the document closer sees a detached target and closes.
      if(e.target.closest('button[data-widen]')){ e.stopPropagation(); widenSearch(); return; }
      const b=e.target.closest('button[data-i]'); if(b) pickS(+b.dataset.i);
    });
    let _sDeb=null, _phDeb=null, _covDeb=null;
    sBox.addEventListener('input', ()=>{ clearTimeout(_sDeb); _sDeb=setTimeout(runS,150);
      clearTimeout(_phDeb); _phDeb=setTimeout(()=>runPhoton(sBox.value),350);
      clearTimeout(_covDeb); _covDeb=setTimeout(()=>runCoverageSearch(sBox.value),250); });
    sBox.addEventListener('keydown', e=>{
      if(sRes.hidden){ if(e.key==='ArrowDown') runS(); return; }
      if(e.key==='ArrowDown'){ e.preventDefault(); sHL=Math.min(sHL+1, sMatches.length-1); hlS(); }
      else if(e.key==='ArrowUp'){ e.preventDefault(); sHL=Math.max(sHL-1, 0); hlS(); }
      else if(e.key==='Enter'){ e.preventDefault(); pickS(sHL<0?0:sHL); }
      else if(e.key==='Escape'){ closeS(); }
    });
    // The reach switch is part of the search: its click must not count as "elsewhere".
    document.addEventListener('click', e=>{ if(e.target!==sBox && !e.target.closest('#searchRes, #searchReach')) closeS(); });
  }
}
