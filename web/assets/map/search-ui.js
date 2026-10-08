// SPDX-License-Identifier: AGPL-3.0-only
/* Sidebar search: towns, item index, coverage lookup, widen chip.
   @see docs/specs/map-and-search.md §7 */
import { I18N, D, tpl } from './i18n.js';
import { escPend, slug, txtOn, photonLang, COORD_COLOR } from './util.js';
import { CATALOG, CITIES, layerByKey, LETTER_KEY } from './catalog.js';
import { inScope, scopeLabel, liftScopeForHit, noteCoverageSearchHit } from './scope-ui.js';
import { itemIndex, idxIds, rebuildItemIndex, dropPendingFromIndex } from './item-index.js';
import { openPlace, openCity, openFeatureById, openRouteById } from './places.js';
import { COVERAGE_ON, covScopeIsZero, covScopeQuery, openCoverageByRef } from './coverage.js';
import { layerGlyph, kindGlyphSvg } from './icons.js';
import { mapToast } from './drawer.js';
import { unseenMark } from './desk-seen.js';

let _searchDropPending=null;

export function dropPendingFromSearch(id){
  if(_searchDropPending) _searchDropPending(id);
}

export function initSearchUi(){
  const sBox=document.getElementById('search'), sRes=document.getElementById('searchRes');
  if(sBox && sRes){
    const escH = s => String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    // docs/specs/map-and-search.md §7.1 — towns first, then the unified item index.
    const TOWNS=Object.keys(CITIES).map(name=>{ const big=CITIES[name].t==='City';
      return {name, key:slug(name), kind: big?(D.city||'City'):(D.town||'Town'), badge: big?'◉':'◎', color: big?'#C8923A':'#3E7D8C', town:true, go:()=>openCity(name)}; });
    rebuildItemIndex();
    /* The item half follows the catalog: every region the map loads later (a
       new scope, the worldwide document) rebuilds the item index
       (__ccApplyCatalog), and the list is taken again from the new index. */
    let _idxFrom=null, _searchIdx=[];
    const searchIdx=()=>{
      const idx=itemIndex();
      if(idx!==_idxFrom){
        _idxFrom=idx;
        _searchIdx=TOWNS.concat(idx.filter(e=>!e.unnamed));  // docs/specs/map-and-search.md §7.1 — unnamed POIs stay off text search
      }
      return _searchIdx;
    };
    _searchDropPending=id=>{
      const list=searchIdx();
      for(let i=list.length-1;i>=0;i--){ if(list[i].pend===String(id)) list.splice(i,1); }
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
        // A long region name is cut by CSS; the tooltip keeps the whole of it.
        reachBtn.title = _worldwide ? back : (I18N.searchEverywhere||'Search everywhere');
      }
      // The title says where the search looks. scope-header.js is the one
      // writer of that heading, so it is asked to repaint with the reach.
      if(window.CCScopeHeader) window.CCScopeHeader.paint(I18N, {worldwide:_worldwide});
    };
    /* Routes in the results are the rider's choice (owner 2026-09-28): a
       checkbox, off until ticked, remembered in this browser. It adds route
       rows to both halves of the list, the routes the map holds and the
       server's worldwide hits; it never hides anything else. */
    const ROUTES_KEY='cc-search-routes';
    const routesBox=document.getElementById('searchRoutes');
    let _routes=false;
    try { _routes = localStorage.getItem(ROUTES_KEY)==='1'; } catch(e){ /* private mode: off */ }
    // The example in the empty box names routes while they are in the list.
    const paintRoutes=()=>{
      const ph = _routes ? sBox.dataset.placeholderRoutes : sBox.dataset.placeholder;
      if(ph) sBox.placeholder=ph;
    };
    if(routesBox) routesBox.checked=_routes;
    paintRoutes();
    if(routesBox) routesBox.addEventListener('change', ()=>{
      _routes=routesBox.checked;
      try { localStorage.setItem(ROUTES_KEY, _routes?'1':'0'); } catch(e){ /* not remembered */ }
      paintRoutes();
      sBox.focus();
      if(sBox.value.trim()){ runItemSearch(sBox.value); runS(); }
    });
    const closeS=()=>{ sRes.hidden=true; sRes.innerHTML=''; sMatches=[]; sHL=-1; setReach(false); if(sBox.getAttribute('aria-expanded')!=='false') sBox.setAttribute('aria-expanded','false'); };
    if(reachBtn) reachBtn.addEventListener('click', ()=>{
      setReach(!_worldwide);
      sBox.focus();
      if(sBox.value.trim()){ runPhoton(sBox.value); runCoverageSearch(sBox.value); runItemSearch(sBox.value); runS(); }
    });
    /* The list takes the panel's empty space and nothing more (owner
       2026-09-28): 300 px (map.css), plus whatever the panel leaves unused
       below its last section. A taller list would push the Region section
       out of view. Measured each time the list opens or the window resizes. */
    const scrollParent=el=>{ for(let p=el.parentElement; p; p=p.parentElement){ const o=getComputedStyle(p).overflowY; if(o==='auto'||o==='scroll') return p; } return null; };
    const fitResults=()=>{
      sRes.style.maxHeight='';
      if(sRes.hidden) return;
      const pane=scrollParent(sRes);
      if(!pane) return;
      // Where the content really ends: a scroll box never reports less
      // height than it has, so its scrollHeight cannot say what is empty.
      let bottom=-Infinity;
      for(const c of pane.children){ if(c.offsetParent!==null) bottom=Math.max(bottom, c.getBoundingClientRect().bottom); }
      const box=pane.getBoundingClientRect(), padBottom=parseFloat(getComputedStyle(pane).paddingBottom)||0;
      const spare=Math.floor(box.top+pane.clientHeight-padBottom-bottom);
      if(spare<=0) return;
      const grown=parseFloat(getComputedStyle(sRes).maxHeight)+spare;
      sRes.style.maxHeight=grown+'px';
      // A margin that collapses through a section is in no box above, but the
      // panel scrolls for it: take back exactly what now overflows.
      const over=pane.scrollHeight-pane.clientHeight;
      if(over>0) sRes.style.maxHeight=Math.max(0, grown-over)+'px';
    };
    window.addEventListener('resize', fitResults);
    const hlS=()=>sRes.querySelectorAll('button').forEach((b,i)=>b.classList.toggle('hl',i===sHL));
    function widenSearch(){
      if(!window.CCScope) return;
      if(window.CCScope.canWiden()) window.CCScope.widen(); else setReach(true);
      runPhoton(sBox.value); runCoverageSearch(sBox.value); runItemSearch(sBox.value); runS();
    }
    /* A hit the rider's scope does not draw sits in some region: look there
       before opening it (docs/specs/map-and-search.md §4.5, §8). The hit's own
       region, never its country, transiently, and the hit's own framing has the
       last word; closing its drawer puts the rider's scope back. A hit already
       in scope moves nothing (liftScopeForHit answers that itself). */
    function followHit(m){
      if(!Array.isArray(m.ll)) return;                 // a town row has no point here: openPlace lifts for it
      liftScopeForHit(m.ll, m.rid);
    }
    function pickS(i){ const m=sMatches[i]; if(!m) return;
      if(m.widen){ m.go(); return; }
      if(m.scope){ m.go(); closeS(); sBox.blur(); return; }
      followHit(m);
      // Explicit selection (map-and-search.md §4.5b): only a Photon town hit
      // carries a country to check - a catalogue/coverage row is already
      // inside an onboarded region, and a pasted coordinate names no country.
      if(m.ph) noteCoverageSearchHit(m.countrycode, m.country);
      sBox.value=m.name; closeS();
      m.go(); }
    // docs/specs/map-and-search.md §12 — community sub-tag; towns and pending never.
    // Three tiers (map-and-search.md §12, owner 2026-09-06): "community" is a
    // row our community has confirmed, "unconfirmed" one nobody here has
    // checked yet, "OSM" the imported baseline. Towns and pending rows carry none.
    const unconfRow=m=>!m.town && !m.pend && !m.osm && (m.community || m.verified===false);
    const confRow=m=>!m.town && !m.pend && !m.osm && !unconfRow(m) && m.verified!==undefined;
    const secondRow=m=>!m.town && !confRow(m);
    const tierTag=m=>confRow(m) ? `<span class="scomm">${escH(D.community||'community')}</span>`
      : unconfRow(m) ? `<span class="scomm">${escH(D.unconfirmed||'unconfirmed')}</span>`
      : (m.osm ? `<span class="scomm">${escH(D.osmTag||'OSM')}</span>` : '');
    // A pending submission this curator has not opened carries the unseen bar (moderation-and-contribution.md §5.2f).
    /* The right column says where the place is when we know it: the group
       heading already names the category, and five castles can share one name. */
    const sRow=(m,i)=>{ const u=unseenMark(m, I18N.unseen||'Not opened yet');
      const sub=m.where||m.kind;
      return `<li role="option"${u.attr}><button data-i="${i}" title="${escH(m.name+(m.where ? ' · '+m.where : ''))}"><span class="sw" style="background:${m.color};color:${txtOn(m.color)}">${m.badge}</span><span class="snm">${escH(m.name)}${tierTag(m)}${u.note}</span><span class="sub">${escH(sub)}</span></button></li>`; };
    const SCOPE_COLOR='#B5532E';
    // A scope hit is a jump, and the registry is searched whole so "More
    // regions…" can reach any region by name. A region in another country
    // than the one on the map says so, or "Provence-Alpes-Côte d'Azur" under
    // a Dutch scope reads as a stale result (owner, 2026-09-06).
    const scopeSub=m=>{
      if(m.kind==='country') return D.wholeCountry||'Whole country';
      const s=window.CCScope ? window.CCScope.get() : null;
      const foreign = m.cc && !(s && s.countryCode===m.cc);
      return (D.region||'Region') + (foreign ? ` · ${m.cc}` : '');
    };
    const scopeRow=(m,i)=>`<li role="option"><button data-i="${i}" class="s-scope"><span class="sw" style="background:${SCOPE_COLOR};color:${txtOn(SCOPE_COLOR)}">${m.kind==='country'?'◆':'◇'}</span><span class="snm">${escPend(m.name)}</span><span class="sub">${escPend(scopeSub(m))}</span></button></li>`;
    /* docs/specs/map-and-search.md §7.4: a pasted pair IS the destination.
       Right-click on the map copies a spot as "52.367612, 5.239157"
       (map-init.js), so the search box reads that same text back, through the
       one parser the contribute wizard uses (contribute/coords.js). */
    const coordPoint = raw => (window.Cc && window.Cc.parseLatLng) ? window.Cc.parseLatLng(raw) : null;
    function coordHit(raw){
      const p=coordPoint(raw); if(!p) return null;
      const name=window.Cc.formatLatLng(p.lat, p.lng);
      // No scope test: the rider named the point, so openPlace widens for it.
      return {name, key:slug(name), kind:D.goToPoint||'Go to this point', badge:'\u2295', color:COORD_COLOR,
        coord:true, ll:[p.lat, p.lng], go:()=>openPlace(name, {ll:[p.lat, p.lng], point:true})};
    }
    // docs/specs/map-and-search.md §7.2 — Photon, never Nominatim; silent degrade.
    let _phAbort=null, _phHits=[], _phQ='';
    const PH_BASE='https://photon.komoot.io/api/?limit=6'
      +'&osm_tag=place:city&osm_tag=place:town&osm_tag=place:village&osm_tag=place:hamlet&osm_tag=place:municipality';
    function runPhoton(qRaw){
      const q=qRaw.trim();
      // An exact point needs no geocoder; an in-flight name lookup must not
      // land its towns on top of the coordinate row either.
      if(coordPoint(q)){ if(_phAbort) _phAbort.abort(); _phHits=[]; _phQ=''; return; }
      if(q.length<3){ _phHits=[]; _phQ=''; return; }
      if(_phAbort) _phAbort.abort();
      const ctl=new AbortController(); _phAbort=ctl;
      const pp = (window.CCScope && !_worldwide) ? window.CCScope.photonParams() : {bbox:null, countrycode:null};
      const url = PH_BASE + '&lang=' + photonLang(document.documentElement.lang) + (pp.bbox ? '&bbox='+pp.bbox.join(',') : '') + '&q='+encodeURIComponent(q);
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
            .map(f=>{ const c=f.geometry.coordinates, p=f.properties, name=p.name;
              // docs/specs/map-and-search.md §6.5: the element ref is what the town card's Wikipedia lookup is keyed by.
              const osmType={N:'node', W:'way', R:'relation'}[p.osm_type];
              const osm=(osmType && /^\d+$/.test(String(p.osm_id||''))) ? osmType+'/'+p.osm_id : null;
              // country/countrycode carried through for the "not covered yet"
              // banner (map-and-search.md §4.5b): Photon already answers in the
              // rider's own language, so the country name needs no lookup here.
              return {name, key:slug(name), kind:'Town', badge:'◎', color:'#3E7D8C', town:true, ph:1,
                country:p.country||null, countrycode:p.countrycode||null,
                go:()=>openPlace(name, {ll:[+c[1],+c[0]], osm})}; });
          _phQ=slug(q);
          if(!sRes.hidden) runS();
        })
        .catch(()=>{});
    }
    // docs/specs/coverage-provider.md §5 — live lookup; abort in-flight; silent degrade.
    let _covAbort=null, _covHits=[], _covSQ='';
    function runCoverageSearch(qRaw){
      const q=qRaw.trim();
      if(coordPoint(q)){ if(_covAbort) _covAbort.abort(); _covHits=[]; _covSQ=''; return; }
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
              // A scenic or history hit shows its kind's glyph: a castle, not the category's temple front.
              return {name:h.n, key:slug(h.n), kind:layer.label, badge:(h.kind && kindGlyphSvg(h.letter, h.kind, layer.color, 15)) || layerGlyph(layer), color:layer.color,
                letter:h.letter, ll:h.ll, cov:1, osm:!h.curated, itemId:h.itemId, where:h.region||'',
                // Our own item opens as itself (its region loads first), never as an OSM point.
                go:()=>h.itemId!=null ? openApiHit(h.letter, h.itemId, h.rid) : openCoverageByRef(h.ref, h.letter, h.ll, h.n)}; });
          _covSQ=slug(q);
          if(!sRes.hidden) runS();
        })
        .catch(()=>{});
    }
    /* Our own items worldwide (docs/specs/map-and-search.md §7.1): the map
       holds only the regions it shows, so a worldwide reach asks the public
       search, the same answer a consumer of /v1/search gets. A hit's own
       region loads when it is picked (CCCatalog.ensureRegion), then the place
       opens from the rows that region brought. Local rows come first; a hit
       the map already holds is not listed twice. */
    let _apiAbort=null, _apiHits=[], _apiQ='';
    const regionIdOf = slugKey => { const r=(window.CC_REGIONS||[]).find(x=>x.slug===slugKey); return r ? r.id : null; };
    // A point to frame and to read the region off: the point itself, or the middle vertex of a line.
    const geomLL = g => {
      if(!g || !g.coordinates) return null;
      let c=g.coordinates;
      while(Array.isArray(c[0]) && Array.isArray(c[0][0])) c=c[0];
      if(Array.isArray(c[0])) c=c[Math.floor(c.length/2)];
      return (c && c.length>=2) ? [+c[1], +c[0]] : null;
    };
    /* Every layer of our own items, by letter: climbs (N) and routes (R) as
       much as the pool kinds. LETTER_KEY names only the OpenStreetMap pools,
       so a worldwide climb used to be dropped here (owner 2026-10-02:
       "it does not find the Mortirolo climb"). */
    const layerOfLetter = letter => CATALOG.find(l => l.letter===letter && !l.overlay && !l.pendingLayer) || null;
    const openApiHit = (letter, id, rid) => {
      const open = () => letter==='R' ? openRouteById(id) : openFeatureById(id);
      const done = () => { if(!open()) mapToast(D.linkGone || 'This place is no longer on the map.'); };
      if(window.CCCatalog) window.CCCatalog.ensureRegion(rid==null ? 0 : rid).then(done); else done();
    };
    function runItemSearch(qRaw){
      const q=qRaw.trim();
      if(_apiAbort) _apiAbort.abort();
      // Three letters: the server's name index starts there (/v1/search q).
      if(!_worldwide || coordPoint(q) || q.length<3){ _apiHits=[]; _apiQ=''; return; }
      const ctl=new AbortController(); _apiAbort=ctl;
      fetch('/v1/search?limit=30'+(_routes?'&routes=include':'')+'&q='+encodeURIComponent(q), {signal:ctl.signal, headers:{'Accept':'application/geo+json'}})
        .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(d=>{
          if(ctl.signal.aborted) return;
          _apiHits=(d.features||[])
            .filter(f=>f && f.properties && f.properties.name && layerOfLetter(f.properties.letter))
            .filter(f=>!idxIds().has(f.properties.letter+':'+f.properties.id))
            .map(f=>{ const p=f.properties, layer=layerOfLetter(p.letter), rid=p.region_id ? regionIdOf(p.region_id) : null;
              return {name:p.name, key:slug(p.name), kind:layer.label, badge:layerGlyph(layer), color:layer.color,
                letter:p.letter, ll:geomLL(f.geometry), rid:rid==null ? undefined : rid, id:p.id, verified:p.tier==='curated',
                go:()=>openApiHit(p.letter, p.id, rid)}; })
            .filter(m=>m.ll);
          _apiQ=slug(q);
          if(!sRes.hidden) runS();
        })
        .catch(()=>{});
    }
    function runS(){
      const q=slug(sBox.value.trim());
      if(!q){ closeS(); return; }
      const starts=[], has=[];
      for(const it of searchIdx()){
        // docs/specs/map-and-search.md §4.5 — hidden pins must not resurface as
        // search rows, unless this search was widened to the whole world.
        if(!_worldwide && !it.town && !inScope(it.rid)) continue;
        if(!_routes && it.letter==='R') continue;
        const i=it.key.indexOf(q); if(i===0) starts.push(it); else if(i>0) has.push(it);
      }
      const ranked=starts.concat(has);
      // docs/specs/map-and-search.md §7.3 — places first, then A–K, cap 30.
      const towns=ranked.filter(m=>m.town), items=ranked.filter(m=>!m.town);
      if(_phQ===q) towns.push(..._phHits.slice(0, Math.max(0, 6-towns.length)));
      const byLetter={};
      items.forEach(m=>{ (byLetter[m.letter]=byLetter[m.letter]||[]).push(m); });
      if(_covSQ===q) _covHits.forEach(m=>{ (byLetter[m.letter]=byLetter[m.letter]||[]).push(m); });
      // One place, one row: our own item can come back from both searches.
      const covIds = _covSQ===q ? new Set(_covHits.filter(m=>m.itemId!=null).map(m=>m.letter+':'+m.itemId)) : new Set();
      if(_worldwide && _apiQ===q) _apiHits.forEach(m=>{ if(covIds.has(m.letter+':'+m.id)) return; (byLetter[m.letter]=byLetter[m.letter]||[]).push(m); });
      Object.keys(byLetter).forEach(L=>byLetter[L].sort((a,b)=>(secondRow(a)?1:0)-(secondRow(b)?1:0)));
      // A · one row per route name (up to "·"); other letters keep same-named distinct places.
      if(byLetter.A){
        const seen=new Set();
        byLetter.A=byLetter.A.filter(m=>{ const k=String(m.name||'').split(' · ')[0]; if(seen.has(k)) return false; seen.add(k); return true; });
      }
      // Scope hits stay inside the country on the map unless the reach is on:
      // a search in the Netherlands must not surface a French region (owner,
      // 2026-09-06). With the reach on, any region or country can be typed.
      const scopeHits = (window.CCScope ? window.CCScope.searchScopes(sBox.value) : []).filter(h=>{
        if(_worldwide) return true;
        const cur=window.CCScope.get();
        const ccs = cur && cur.kind==='myArea' && cur.myArea ? cur.myArea.countryCodes : (cur && cur.countryCode ? [cur.countryCode] : []);
        return !!h.cc && ccs.indexOf(h.cc)!==-1;
      });
      const groups=[];
      // The typed point leads the list: it is the one row that is certainly what was asked for.
      const coord=coordHit(sBox.value);
      if(coord) groups.push({label:D.coordinates||'Coordinates', rows:[coord]});
      if(scopeHits.length) groups.push({label:D.scopes||'Scopes', rows:scopeHits.map(s=>({
        scope:true, kind:s.kind, name:s.label, cc:s.cc,
        go:()=> s.kind==='country' ? window.CCScope.setCountry(s.cc) : window.CCScope.setRegion(s.slug),
      }))});
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
      if(window.CCScope && !_worldwide && !coord){
        // One rung up while there is one; at the widest scope, the whole world,
        // for this search only.
        const nw = window.CCScope.nextWider();
        const wl = nw ? tpl(I18N.searchWiden||'Search in {area} instead', {area:scopeLabel(nw)})
          : (I18N.searchEverywhere||'Search everywhere');
        widenHtml = `<li class="search-widen" role="option"><button type="button" data-widen="1" data-i="${sMatches.length}">${escH(wl)}</button></li>`;
        sMatches.push({widen:true, go:widenSearch});
      }
      sRes.innerHTML = (realCount ? html : `<li class="search-empty">${D.noMatch||'No match in the Commons yet.'}</li>`) + widenHtml;
      fitResults();
    }
    sRes.addEventListener('click', e=>{
      // stopPropagation: runS() rebuilds the dropdown; without it the document closer sees a detached target and closes.
      if(e.target.closest('button[data-widen]')){ e.stopPropagation(); widenSearch(); return; }
      const b=e.target.closest('button[data-i]'); if(b) pickS(+b.dataset.i);
    });
    let _sDeb=null, _phDeb=null, _covDeb=null;
    sBox.addEventListener('input', ()=>{ clearTimeout(_sDeb); _sDeb=setTimeout(runS,150);
      clearTimeout(_phDeb); _phDeb=setTimeout(()=>runPhoton(sBox.value),350);
      clearTimeout(_covDeb); _covDeb=setTimeout(()=>{ runCoverageSearch(sBox.value); runItemSearch(sBox.value); },250); });
    /* Back in a box that still holds a query (after picking a hit, or after
       the panel closed): the list comes back without retyping. */
    const reopenS=()=>{ if(!sRes.hidden || !sBox.value.trim()) return;
      runPhoton(sBox.value); runCoverageSearch(sBox.value); runItemSearch(sBox.value); runS(); };
    sBox.addEventListener('focus', reopenS);
    sBox.addEventListener('click', reopenS);
    sBox.addEventListener('keydown', e=>{
      if(sRes.hidden){ if(e.key==='ArrowDown') runS(); return; }
      if(e.key==='ArrowDown'){ e.preventDefault(); sHL=Math.min(sHL+1, sMatches.length-1); hlS(); }
      else if(e.key==='ArrowUp'){ e.preventDefault(); sHL=Math.max(sHL-1, 0); hlS(); }
      else if(e.key==='Enter'){ e.preventDefault(); pickS(sHL<0?0:sHL); }
      else if(e.key==='Escape'){ closeS(); }
    });
    // The reach switch is part of the search: its click must not count as "elsewhere".
    document.addEventListener('click', e=>{ if(e.target!==sBox && !e.target.closest('#searchRes, #searchReach, .search-routes')) closeS(); });
  }
}
