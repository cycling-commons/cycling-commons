// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The sidebar search: one box over towns, the unified item index, and the live
   coverage lookup, with keyboard navigation and the out-of-scope widen prompt.
   Extracted from map.js by the module split.

   The whole thing was already wrapped in `if(sBox && sRes)`, so it became
   initSearchUi()'s body verbatim — not one line was re-indented.

   SEARCH_IDX is built INSIDE that init, from a fully-populated CATALOG, and it
   is private. The one thing outside needs from it is the moderation drop: when a
   curator's decision removes a pending pin, its search hit has to disappear too
   (review W36), or the rider gets a result that does nothing. community.js calls
   dropPendingFromSearch() for that. The null guard is real and deliberate — the
   index does not exist until initSearchUi() has run, and a decision cannot
   arrive before then, but the guard costs nothing and says so. */
import { I18N, D, tpl } from './i18n.js';
import { escPend, slug, txtOn } from './util.js';
import { CITIES, layerByKey, LETTER_KEY } from './catalog.js';
import { inScope, scopeLabel } from './scope-ui.js';
import { itemIndex, idxIds, rebuildItemIndex, dropPendingFromIndex } from './item-index.js';
import { openPlace, openCity } from './places.js';
import { COVERAGE_ON, covScopeIsZero, covScopeQuery, openCoverageByRef } from './coverage.js';

// Assigned by initSearchUi() once SEARCH_IDX exists; null until then.
let _searchDropPending=null;

// Drop a decided pending submission from the search index (review W36) — see
// the header for why this is a function rather than an exported binding.
export function dropPendingFromSearch(id){
  if(_searchDropPending) _searchDropPending(id);
}

export function initSearchUi(){
  /* ---------- sidebar search: towns + named Commons features (local index, no geocoder) ---------- */
  const sBox=document.getElementById('search'), sRes=document.getElementById('searchRes');
  if(sBox && sRes){
    const escH = s => String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    // built once — CATALOG is fully populated (climbs + rides folded in) by this point.
    // Towns first, then the unified deduplicated item index (spec 2026-07-14 §3.1):
    // ITEM_INDEX covers CATALOG features + PIVOT stays + every bulk-OSM pool
    // exactly once, so the old per-pool push loops (which double-indexed every
    // PIVOT stay via the catalog-load concat, and missed water) are gone.
    const SEARCH_IDX=[];
    Object.keys(CITIES).forEach(name=>{ const big=CITIES[name].t==='City';   // big cities stand apart from hamlets: ochre ◉ "City" vs teal ◎ "Town"
      SEARCH_IDX.push({name, key:slug(name), kind: big?(D.city||'City'):(D.town||'Town'), badge: big?'◉':'◎', color: big?'#C8923A':'#3E7D8C', town:true, go:()=>openCity(name)}); });
    rebuildItemIndex();   // ITEM_INDEX + its derived IDX_IDS, together (item-index.js)
    itemIndex().forEach(e=>{ if(!e.unnamed) SEARCH_IDX.push(e); });   // nameless POIs list in place cards, not in text search
    // pending entries carry their submission id so hidePendingPin can drop
    // them from the index after a moderation decision (review W36) — the
    // feature disappears from the map, and a search hit that "does nothing"
    // must disappear with it. Both lists share the entry objects.
    _searchDropPending=id=>{
      for(let i=SEARCH_IDX.length-1;i>=0;i--){ if(SEARCH_IDX[i].pend===String(id)) SEARCH_IDX.splice(i,1); }
      dropPendingFromIndex(id);
    };
    let sMatches=[], sHL=-1;
    const closeS=()=>{ sRes.hidden=true; sRes.innerHTML=''; sMatches=[]; sHL=-1; if(sBox.getAttribute('aria-expanded')!=='false') sBox.setAttribute('aria-expanded','false'); };
    const hlS=()=>sRes.querySelectorAll('button').forEach((b,i)=>b.classList.toggle('hl',i===sHL));
    // One-tap ladder to the next-wider scope — shared by the widen chip's
    // click and keyboard paths (07-20 review info b). cc:scopechange re-runs
    // applyScope; the re-query surfaces the wider Photon bbox, the local rows
    // the narrower scope hid, and the next rung's chip.
    // Widen one rung, then re-run all three result sources against the wider
    // scope: Photon (wider bbox), the local index (runS re-gates on inScope),
    // and coverage — which since Phase 3 is scope-filtered at the source
    // (map-and-search.md §4.5), so the wider rung's rows only appear once
    // runCoverageSearch re-fetches with the new rids/cc. runS runs last so it
    // renders the freshly-updated Photon + (imminently) coverage hits.
    function widenSearch(){ if(!window.CCScope) return; window.CCScope.widen(); runPhoton(sBox.value); runCoverageSearch(sBox.value); runS(); }
    function pickS(i){ const m=sMatches[i]; if(!m) return;
      if(m.widen){ m.go(); return; }   // widen chip: dropdown stays open, results re-query
      // Scope row: sets the map's scope (fit + relabel via cc:scopechange,
      // Task 3/5), not a fly-to — closeS()/blur() here (not inside go, so
      // widen keeps its own no-close contract) mirror applyScopeRow in the
      // task-6 brief without duplicating a second click/keydown path.
      if(m.scope){ m.go(); closeS(); sBox.blur(); return; }
      sBox.value=m.name; closeS();
      if(window.innerWidth<=820){ const ap=document.querySelector('.app'); if(ap) ap.classList.remove('sheet-open'); }  // clear the filter sheet on mobile
      m.go(); }
    // 07-15 decision A: community (unverified) rows carry a dimmed sub-tag so
    // verified vs community reads at a glance. Towns and pending rows never do.
    const commRow=m=>!m.town && !m.pend && (m.community || m.verified===false);
    const sRow=(m,i)=>`<li role="option"><button data-i="${i}"><span class="sw" style="background:${m.color};color:${txtOn(m.color)}">${escH(m.badge)}</span><span class="snm">${escH(m.name)}${commRow(m)?`<span class="scomm">${escH(D.community||'community')}</span>`:''}</span><span class="sub">${escH(m.kind)}</span></button></li>`;
    // Scopes section (map-and-search.md §4.5): a
    // scope row renders like any other search row (same <li role="option">/
    // <button data-i> shape as sRow) so it drops into the existing sMatches/
    // data-i/go model untouched — no new keyboard or click wiring needed,
    // only pickS gains one branch (below) to route it through CCScope
    // instead of a fly-to. Reuses --clay (already in map.css :root) via
    // inline style, same idiom sRow uses for m.color — no new CSS needed.
    // Escaped with escPend per task-6 brief (escH doesn't escape `'`, and
    // region/country labels can contain one, e.g. Val-d'Or-style names).
    const SCOPE_COLOR='#B5532E';   // = --clay (map.css :root) — retune both together, this file hardcodes hex throughout (no CSS-var reads at runtime)
    // s-scope has no CSS rule (styling comes from the shared .sw/.snm/.sub
    // classes above) — it's a DOM hook only, for Task 9's browser
    // verification to select scope rows apart from place rows.
    const scopeRow=(m,i)=>`<li role="option"><button data-i="${i}" class="s-scope"><span class="sw" style="background:${SCOPE_COLOR};color:${txtOn(SCOPE_COLOR)}">${m.kind==='country'?'◆':'◇'}</span><span class="snm">${escPend(m.name)}</span><span class="sub">${escPend(m.kind==='country'?(D.wholeCountry||'Whole country'):(D.region||'Region'))}</span></button></li>`;
    // Any-town live place search via Photon (spec 2026-07-14 §3.2) — Photon,
    // not Nominatim: Nominatim's usage policy forbids type-ahead. Wallonia
    // bbox, place types only, ≥3 chars, 350 ms debounce, one in-flight request
    // (stale ones aborted). On error/timeout search silently degrades to the
    // local index + hardcoded quick-picks — no toast. Host already in CSP.
    let _phAbort=null, _phHits=[], _phQ='';
    const PH_BASE='https://photon.komoot.io/api/?limit=6'
      +'&osm_tag=place:city&osm_tag=place:town&osm_tag=place:village&osm_tag=place:hamlet&osm_tag=place:municipality';
    function runPhoton(qRaw){
      const q=qRaw.trim();
      if(q.length<3){ _phHits=[]; _phQ=''; return; }
      if(_phAbort) _phAbort.abort();
      const ctl=new AbortController(); _phAbort=ctl;
      // Photon bbox + country gate derive from the active scope (region-scoping-
      // design.md §4/§7 Phase 2), replacing the hardcoded Wallonia bbox / BE gate:
      // a named region/country biases + filters to its country; Everywhere drops
      // both and searches worldwide. The widen ladder just changes the scope.
      const pp = window.CCScope ? window.CCScope.photonParams() : {bbox:null, countrycode:null};
      const url = PH_BASE + (pp.bbox ? '&bbox='+pp.bbox.join(',') : '') + '&q='+encodeURIComponent(q);
      fetch(url, {signal:ctl.signal})
        .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(d=>{
          if(ctl.signal.aborted) return;
          const seen=new Set(Object.keys(CITIES).map(n=>slug(n)));   // quick-picks win over their Photon twin
          _phHits=(d.features||[])
            .filter(f=>f && f.properties && f.properties.name && f.geometry && Array.isArray(f.geometry.coordinates))
            // The bbox is a coarse pre-filter that spills over borders; countrycode
            // is the precise gate (Mazy BE stays, Malzy FR goes) — but only when the
            // scope has a country. Everywhere (no countrycode) admits worldwide hits.
            .filter(f=>!pp.countrycode || String(f.properties.countrycode||'').toLowerCase()===pp.countrycode)
            .filter(f=>{ const k=slug(f.properties.name); if(!k || seen.has(k)) return false; seen.add(k); return true; })
            .map(f=>{ const c=f.geometry.coordinates, name=f.properties.name;
              return {name, key:slug(name), kind:'Town', badge:'◎', color:'#3E7D8C', town:true, ph:1,
                go:()=>openPlace(name, {ll:[+c[1],+c[0]]})}; });
          _phQ=slug(q);
          if(!sRes.hidden) runS();   // merge into the open dropdown
        })
        .catch(()=>{});   // abort / network / quota — degrade silently
    }
    // Coverage search (coverage-provider.md §5/§6): the local
    // index only spans the served pool; everything else comes from
    // /map/coverage/search. Photon's conventions apply — ≥2 chars, one
    // in-flight request (stale ones aborted), silent degradation, results
    // merged into the open dropdown behind the local rows of each letter group.
    let _covAbort=null, _covHits=[], _covSQ='';
    function runCoverageSearch(qRaw){
      const q=qRaw.trim();
      if(!COVERAGE_ON || q.length<2){ _covHits=[]; _covSQ=''; return; }
      if(_covAbort) _covAbort.abort();
      // myArea derived to zero regions (map-and-search.md §4.5): an unscoped
      // fetch would silently return GLOBAL results, so skip it and contribute
      // no coverage rows — same "in scope: nothing" rule as fetchCoverageCounts.
      if(covScopeIsZero()){
        _covHits=[]; _covSQ=slug(q);
        if(!sRes.hidden) runS();
        return;
      }
      const ctl=new AbortController(); _covAbort=ctl;
      // Scope the search to the active region (Phase 3, map-and-search.md §4.5
      // §6): the server returns only in-scope rows, so the coverage results
      // mirror the scope-filtered tiles. Everywhere adds no params.
      const sq=covScopeQuery();
      fetch('/map/coverage/search?q='+encodeURIComponent(q)+(sq?('&'+sq):''), {signal:ctl.signal, headers:{'Accept':'application/json'}})
        .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(d=>{
          if(ctl.signal.aborted) return;
          _covHits=(d.results||[])
            .filter(h=>h && h.n && LETTER_KEY[h.letter] && Array.isArray(h.ll))
            .filter(h=>!(h.itemId!=null && idxIds().has(h.letter+':'+h.itemId)))   // curated twin already indexed locally
            .map(h=>{ const layer=layerByKey[LETTER_KEY[h.letter]];
              return {name:h.n, key:slug(h.n), kind:layer.label, badge:layer.icon, color:layer.color,
                letter:h.letter, ll:h.ll, cov:1, community:!h.curated,
                // h.itemId (curated hits only) routes through the served
                // item's own attributes instead of the OSM-tile fallback —
                // see openCoverageByRef's header.
                go:()=>openCoverageByRef(h.ref, h.letter, h.ll, h.n, h.itemId)}; });
          _covSQ=slug(q);
          if(!sRes.hidden) runS();   // merge into the open dropdown
        })
        .catch(()=>{});   // abort / network / 429 — degrade to the local index
    }
    function runS(){
      const q=slug(sBox.value.trim());
      if(!q){ closeS(); return; }
      const starts=[], has=[];                                  // prefix matches rank above substring matches
      for(const it of SEARCH_IDX){
        // Scope-first search (map-and-search.md §4.5, 07-20 review
        // finding 3): served rows filter to the active scope with exactly the
        // map's gate — a hidden pin must not resurface as a search row that
        // opens a drawer over an empty spot. Out-of-scope rows come back via
        // the widen chip. Towns are exempt (places, not scoped features —
        // the hardcoded CITIES list generalises per scope in Phase 5).
        if(!it.town && !inScope(it.rid)) continue;
        const i=it.key.indexOf(q); if(i===0) starts.push(it); else if(i>0) has.push(it);
      }
      const ranked=starts.concat(has);
      // Grouped display (spec 2026-07-14 §3.3): places first, then items by
      // letter A–K, 30 rows total (the old flat slice(0,8) hid most matches
      // around a populous town). sMatches stays flat in display order so the
      // existing keyboard navigation is untouched; group headers aren't options.
      const towns=ranked.filter(m=>m.town), items=ranked.filter(m=>!m.town);
      if(_phQ===q) towns.push(..._phHits.slice(0, Math.max(0, 6-towns.length)));   // geocoded towns behind local ones
      const byLetter={};
      items.forEach(m=>{ (byLetter[m.letter]=byLetter[m.letter]||[]).push(m); });
      // Coverage matches slot into the same letter groups, behind local rows
      // (same freshness handshake as Photon's _phQ: only merge results that
      // answer THIS query). Scope-filtered at the SOURCE (Phase 3,
      // map-and-search.md §4.5): runCoverageSearch sends the active
      // scope's rids/cc, so _covHits already mirror the scope-filtered tiles —
      // the widen chip re-runs the fetch rung by rung. (The served local rows
      // above are gated client-side by inScope() since they aren't re-fetched.)
      if(_covSQ===q) _covHits.forEach(m=>{ (byLetter[m.letter]=byLetter[m.letter]||[]).push(m); });
      // decision A ordering: verified/curated rows first inside each letter
      // group, community after. Array.prototype.sort is stable (ES2019), so the
      // prefix-before-substring ranking survives within each tier.
      Object.keys(byLetter).forEach(L=>byLetter[L].sort((a,b)=>(commRow(a)?1:0)-(commRow(b)?1:0)));
      // Scopes group (task-6 brief; map-and-search.md §4.5
      // §A): matching regions + country rungs, ranked by CCScope.searchScopes
      // (prefix before substring; a country rung outranks its own regions on
      // a tie), rendered above Places. Each hit becomes a row shaped exactly
      // like every other sMatches entry — {..., go} — so it needs no bespoke
      // click/keydown handling; only pickS's new m.scope branch (above)
      // distinguishes "apply scope" from "fly to place".
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
        // Group headers are role="presentation" <li>s with no <button> inside
        // (unchanged from the pre-existing Places/letter headers) — hlS's
        // querySelectorAll('button') walk and sMatches both skip them
        // naturally, so a header can never receive keyboard highlight. The
        // Scopes header reuses this same convention rather than a new one.
        html+=`<li class="sgrp" role="presentation">${escH(g.label)}</li>`;
        rows.forEach(m=>{ html+=(m.scope?scopeRow:sRow)(m, sMatches.length); sMatches.push(m); });
      }
      sRes.hidden=false;
      // Only mutate aria-expanded on a real open/close transition: setting an
      // attribute on the element being IME-composed restarts composition on
      // Android Chrome, re-anchoring the caret at 0 — typed text comes out
      // reversed ("spa" → "aps").
      if(sBox.getAttribute('aria-expanded')!=='true') sBox.setAttribute('aria-expanded','true');
      // Widen chip (map-and-search.md §4.5, the Craigslist lesson): a
      // one-tap ladder to the next-wider scope, always offered while the scope
      // can widen. It sits under the results, so it auto-surfaces exactly when
      // they are sparse — no settings dig. It is a REAL option (07-20 review
      // info b): it carries data-i and joins sMatches as a pseudo-entry so
      // ArrowDown/Enter reach it like any row; realCount keeps the empty-state
      // message keyed on actual matches, not the chip.
      const realCount=sMatches.length;
      let widenHtml='';
      if(window.CCScope && window.CCScope.canWiden()){
        const nw = window.CCScope.nextWider();
        const wl = (nw && nw.kind==='everywhere') ? (I18N.searchEverywhere||'Search everywhere')
          : tpl(I18N.searchWiden||'Search in {area} instead', {area:scopeLabel(nw)});
        widenHtml = `<li class="search-widen" role="option"><button type="button" data-widen="1" data-i="${sMatches.length}">${escH(wl)}</button></li>`;
        sMatches.push({widen:true, go:widenSearch});
      }
      sRes.innerHTML = (realCount ? html : `<li class="search-empty">${D.noMatch||'No match in the Commons yet.'}</li>`) + widenHtml;
    }
    // one delegated listener + a short debounce (review W41): the per-keystroke
    // cost was a full index scan, an innerHTML rebuild AND fresh per-result
    // listeners — the pattern that degrades linearly as the catalog grows.
    sRes.addEventListener('click', e=>{
      // Widen chip: step the scope one rung wider (cc:scopechange re-runs
      // applyScope), then re-query so the wider Photon bbox + fresh chip appear.
      // stopPropagation: runS() rebuilds the dropdown, detaching this button, so
      // the document close-listener would otherwise see a detached target
      // (closest('#searchRes')===null) and close the just-reopened dropdown.
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
    document.addEventListener('click', e=>{ if(e.target!==sBox && !e.target.closest('#searchRes')) closeS(); });
  }
}
