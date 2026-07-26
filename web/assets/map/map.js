// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
  // §13: shared HTML-escaper for real (user-authored) pending-submission text —
  // stored-XSS-in-curator-session risk now that submissions come from real users.
  const escPend = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  // Defense-in-depth for interpolated hrefs. A stay's user-editable `web`
  // attribute reaches r.links[].href and is interpolated into <a href="…">;
  // the server-side Url constraint (App\Form\CatalogFieldConstraints) is the
  // primary guard, but this also neutralises any pre-fix `javascript:`/`data:`
  // value already persisted. Allow only http(s) and site-relative URLs, then
  // attribute-escape; anything else collapses to '#' (review 2026-07-07, #3).
  const safeHref = u => { const s = String(u ?? '').trim(); return (/^https?:\/\//i.test(s) || (s.startsWith('/') && !s.startsWith('//'))) ? escPend(s) : '#'; };
  // C1-T4 (spec W6): every served feature now carries `srcType` — the item's
  // real ItemSource enum value (osm/pivot/wikidata/auto/user/manual), from
  // CatalogProvider. This maps it to the plain-English label shown on the
  // drawer's "Source ·" line, so a rider-added/edited item never reads as
  // OpenStreetMap just because it happens to live in a bulk-OSM layer.
  // Locale bundle injected by the map shell (MapController::mapI18n via
  // window.CC_I18N); every lookup keeps its English fallback so map.js still
  // works standalone. `D` is the drawer namespace; tpl() fills {name} slots.
  const I18N = window.CC_I18N || {};
  const PREFS = window.CC_PREFS || {bikes: [], styles: []};
  // Region scope (region-scoping-design.md §4 / §7 Phase 2): the area the map +
  // search filter to, owned by window.CCScope (scope.js). Registry injected by
  // the shell (window.CC_REGIONS: id/slug/cc/bbox); display labels are the rail
  // buttons' own text. Default scope = today's Wallonia behaviour.
  const CC_REGIONS = window.CC_REGIONS || [];
  const _regionById = new Map(CC_REGIONS.map(r => [r.id, r]));
  const slugOfRegion = id => { const r = _regionById.get(id); return r ? r.slug : null; };
  const _defaultScope = (() => {
    // My area wins whenever a base location is set (region-scoping-design.md §4 /
    // §9.1 Phase 4, owner decision) — an explicit ?scope= URL still beats it
    // (CCScope.init handles that precedence). myAreaAvailable() reads the source
    // (CC_MY_AREA payload / anon 'cc-my-area' circle) directly, not the registry,
    // so it's safe to probe before init() and resolves to the same source there.
    if (window.CCScope && window.CCScope.myAreaAvailable()) return {kind: 'myArea', regionIds: [], countryCode: null};
    const w = CC_REGIONS.find(r => r.slug === 'wallonia') || CC_REGIONS[0];
    return w ? {kind: 'region', regionIds: [w.id], countryCode: w.countryCode} : {kind: 'everywhere', regionIds: [], countryCode: null};
  })();
  if (window.CCScope) {
    window.CCScope.init(CC_REGIONS, _defaultScope);
    // Re-paint the header here too (2026-07-23 flash fix) — mostly a no-op by
    // the time this runs, since scope-header.js already did the real (early,
    // pre-first-paint) resolve + paint before map.js's script even started
    // downloading (see writeScopeHeader()'s own doc comment below for why
    // THIS call can't be the one that kills the flash: map.js only runs
    // post-catalog-fetch). Kept as a defensive safety net for the case
    // scope-header.js didn't run.
    writeScopeHeader();
  }
  // Reveal the My-area rail button once we know a source exists (Phase 4); it
  // ships hidden so a rider with no base location (and no anon circle) never
  // sees a dead control.
  { const myBtn = document.getElementById('myAreaBtn'); if (myBtn && window.CCScope && window.CCScope.myAreaAvailable()) myBtn.hidden = false; }
  const curScope = () => window.CCScope ? window.CCScope.get() : _defaultScope;
  // A served feature is in scope when its region id (rid) is in the active
  // scope. A region/country scope hides rid-less or out-of-region features
  // (leak-safe default for authoritative served data); Everywhere shows all.
  // Coverage TILES scope through covScopeFilter()/covScopeQuery() instead
  // (Phase 3) — with the inverse prop-less rule, since the tile artifact lags.
  const inScope = rid => { const s = curScope(); return !s || s.kind === 'everywhere' || s.regionIds.indexOf(rid) !== -1; };
  const LAYER_L10N = I18N.layers || {};
  const D = I18N.d || {};
  const tpl = (s, vars) => String(s).replace(/\{(\w+)\}/g, (m, k) => vars[k] != null ? vars[k] : m);
  // Canonical stored value → localized label, merged over every field's
  // choices map (CC_FIELD_SCHEMA) — for values rendered outside schemaRows
  // (headlines, difficulty badge, surface mix). Per-field lookups stay exact.
  const VALUE_TR = {};
  Object.values(window.CC_FIELD_SCHEMA || {}).forEach(fs => (fs || []).forEach(f => { if (f.choices) Object.assign(VALUE_TR, f.choices); }));
  const trVal = v => VALUE_TR[v] || v;
  const SOURCE_LABELS = {
    osm:'OpenStreetMap', pivot:'Tourisme Wallonie (CC-BY)', wikidata:'Wikidata',
    auto:D.srcAuto||'Derived by the pipeline', user:D.srcRider||'Rider-contributed', manual:D.srcRider||'Rider-contributed'
  };
  const sourceLabel = raw => SOURCE_LABELS[raw] || null;
  // C1-T3: race-guard token for the drawer's async "Recent changes" fetch —
  // bumped on every openDrawer() call so a slow response from a since-replaced
  // drawer never paints stale history over whatever is open now.
  let _historyReq = 0;
  const _scopeBb = window.CCScope ? window.CCScope.bbox() : null;
  const map = new maplibregl.Map({
    container:'map', style:'https://tiles.openfreemap.org/styles/liberty',
    // Initial viewport = the active scope's bbox (Wallonia by default; a saved
    // Flanders/Brussels scope reopens there). Everywhere has NO bbox (null), so
    // it — like a missing registry — opens on the old hardcoded Wallonia
    // literal: a deliberate anchor view, not a scope (review 07-20 info c).
    bounds: _scopeBb ? [[_scopeBb[0],_scopeBb[1]],[_scopeBb[2],_scopeBb[3]]] : [[2.84,49.45],[6.41,50.85]],
    fitBoundsOptions:{padding:24}, attributionControl:false
  });
  map.addControl(new maplibregl.AttributionControl({customAttribution:'© OpenStreetMap contributors · ODbL'}),'bottom-right');
  map.addControl(new maplibregl.NavigationControl({showCompass:false}),'bottom-left');
  // Live zoom readout — a MapLibre control so it stacks above the nav control
  // (bottom-left) with the framework's own positioning, no absolute-layout
  // guesswork. Useful context now that the scope selector fits to region/country
  // bboxes at different zooms (e.g. All Belgium ~z7, at the coverage minzoom 6).
  map.addControl({
    onAdd(m){
      const d=document.createElement('div');
      d.className='maplibregl-ctrl zoom-badge';
      d.setAttribute('aria-hidden','true');   // decorative; the value is not actionable AT/via keyboard
      const upd=()=>{ d.textContent='z'+m.getZoom().toFixed(1); };
      m.on('zoom', upd); upd();
      this._d=d; this._upd=upd; this._m=m;
      return d;
    },
    onRemove(){ this._m.off('zoom', this._upd); this._d.remove(); },
  },'bottom-left');

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
  function setSpotlight(slug){
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
    Promise.all([
      fetch(`/map/region/${encodeURIComponent(slug)}/boundary`)
        .then(r=>{ if(!r.ok) throw new Error('boundary HTTP '+r.status); return r.json(); }),
      adjP, fullP,
    ]).then(([d, adjFeatures, f])=>{
        const g = d && d.geometry;
        if(req!==_spotReq||!g||!map.getStyle()||map.getSource('region')) return;   // superseded or gone
        drawSpotlightMask(g, adjFeatures, f && f.geometry);
      // decorative only — the map works without the boundary, but log why it's missing (W34)
      }).catch(e=>console.warn('Region boundary unavailable:', e));
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
  function setCountrySpotlight(cc){
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
  function setCircleSpotlight(center, rkm){
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

  // The data-scope token a scope maps to (matches the rail buttons' data-scope).
  function scopeToken(s){
    if(!s||s.kind==='everywhere') return 'everywhere';
    if(s.kind==='myArea') return 'myarea';   // bare literal — matches #myAreaBtn, never coordinates
    if(s.kind==='country') return 'country:'+s.countryCode;
    const slug = slugOfRegion(s.regionIds[0]);
    return slug ? 'region:'+slug : 'everywhere';
  }
  // Dynamic header label — resolved from the region REGISTRY (CCScope.label(),
  // scope.js), never the rendered rail button (2026-07-23 flash fix: the old
  // `document.querySelector('#regionScope button[data-scope=...]')` only ever
  // found a match once renderScopeChips() had run — long after first paint —
  // which is why every visitor briefly saw the server-rendered "Wallonia"
  // fallback; it also meant a stale header stuck around if the chips
  // re-rendered after applyScope). '' (not null) for everywhere/myArea/
  // unresolvable, same contract as before: callers such as tpl()'s {area}
  // interpolation and the header-write guard below expect a string.
  function scopeLabel(s){
    return (window.CCScope && window.CCScope.label(s)) || '';
  }
  // Header/kicker/search-title rewrite for the active scope (2026-07-23 flash
  // fix): extracted out of applyScope() so it can run from TWO places — once
  // immediately after CCScope.init() below, and again from applyScope() on
  // every later scope change. The actual paint is scope-header.js's
  // window.CCScopeHeader.paint() (one implementation, not a copy here):
  // map.js's own execution is gated behind the /map/catalog.json fetch
  // (catalog-load.js) — a real network round trip — so even code at the very
  // top of THIS script only runs once that resolves, well after the browser
  // has already painted the server-rendered "Wallonia, Belgium" fallback
  // (browser-verified: ~130-220ms on the dev stack). scope-header.js loads as
  // a plain blocking <script> right after scope.js and BEFORE
  // catalog-load.js's fetch even starts (templates/map/index.html.twig), so
  // ITS OWN call to paint() (at the bottom of that file) is what runs the
  // true "before first paint" fix; this call here is what keeps the header
  // correct on every SUBSEQUENT scope change, once the map/rail exist.
  function writeScopeHeader(){
    if(window.CCScopeHeader) window.CCScopeHeader.paint(I18N);
  }
  // Reveal + focus the sidebar feature-search box (owner fix 2's overflow
  // chip, 2026-07-23): re-queries the DOM fresh rather than closing over the
  // `sBox` const declared far below, next to the `sRes` search-results wiring
  // — renderScopeChips() first runs long before that line executes, so
  // capturing `sBox` here would hit the temporal-dead-zone. Reuses the same
  // mobile 'sheet-open' reveal + <=820 breakpoint the filter-sheet handle
  // already uses rather than inventing a second show/hide mechanism.
  function focusSearchBox(){
    if(window.innerWidth<=820){ const ap=document.querySelector('.app'); if(ap) ap.classList.add('sheet-open'); }
    const el=document.getElementById('search');
    if(el){ el.scrollIntoView({block:'nearest'}); el.focus(); }
  }
  // Contextual scope chips (2026-07-22-scope-selector-scale-design.md §B): the
  // home country's regions + its All-<country> rung, or the onboarded country
  // rungs as the cold-start fallback. Replaces the flat all-regions wall.
  // Reuses escPend (top of file) rather than a third hand-rolled escaper —
  // it's the same house idiom the search results list (the `escH` helper next
  // to the search-results renderer) and the drawer's pending-submission
  // renderer (security-architecture.md §4.2) already use for building
  // interactive lists into innerHTML: string-concat + a shared HTML-escaper +
  // one delegated/rebind pass, not a third one-off.
  function renderScopeChips(){
    const host=document.getElementById('scopeChips');
    if(!host||!window.CCScope) return;
    if(!window.CCScopeChips){ console.warn('CCScopeChips missing — scope chips not rendered'); return; }
    // Every DECISION below the model call lives in scope-chips.js, where it is unit
    // tested (web/tests/js/scope-chips.test.cjs). What stays here is serialization
    // and DOM binding only — deliberately, so a chip-selection change never again
    // needs a browser to catch (2026-07-23-map-js-phase0-extraction-design.md).
    const c=(map&&map.getCenter)?map.getCenter():null;
    const m=window.CCScopeChips.chipModel({
      scope: curScope(),
      isDefault: !!(window.CCScope.isDefault && window.CCScope.isDefault()),
      activeRegions: window.CCScope.regions(),
      registry: CC_REGIONS,
      inferredCountry: window.CCScope.inferHomeCountry(),
      scopeCenter: window.CCScope.scopeCenter(),
      mapCenter: c?[c.lng,c.lat]:null,
      myArea: window.CC_MY_AREA||null,
    }, window.CCScope);
    // Direction word lookup stays here: the model returns semantic keys, never
    // translated strings, so it has no opinion about the active locale.
    const DIRK={n:'compassN',ne:'compassNe',e:'compassE',se:'compassSe',
                s:'compassS',sw:'compassSw',w:'compassW',nw:'compassNw'};
    // Foreign chips (a region of another country than the active scope) show a
    // "· NL" country cue; native chips stay bare. The cue is part of the button
    // TEXT, so it also reaches the compass aria-label below — the country is in the
    // accessible name, not conveyed by styling alone
    // (2026-07-23-cross-border-chips-design.md §3.3).
    // Raw (unescaped) cue text: "Limburg · NL" for a foreign chip, bare label otherwise.
    // ONE source of the cue format — both the visible button text (via cueLabel, which
    // escapes) and the compass aria-label (escaped once at attribute insertion) use it.
    const cueText=x=>x.foreign?`${x.label} · ${(x.cc||'').toUpperCase()}`:x.label;
    const cueLabel=x=>escPend(cueText(x));
    const regionBtn=r=>`<button data-scope="region:${escPend(r.slug)}">${cueLabel(r)}</button>`;
    const countryBtn=k=>`<button data-scope="country:${escPend(k.cc)}">${escPend(k.label)}</button>`;
    const moreBtn=()=>`<button type="button" class="cc-scope-more" id="scopeMoreBtn">${escPend(D.scopesMore||'More regions…')}</button>`;
    let html='';
    if(m.mode==='countries'){
      m.countries.forEach(k=>{ html+=countryBtn(k); });
    } else if(m.mode==='compass'){
      html+=`<div class="cc-compass" role="group" aria-label="${escPend(D.compassGroup||'Nearby regions')}">`;
      m.rows.forEach(row=>row.forEach(cell=>{
        if(cell.kind==='empty'){ html+='<div class="cc-compass-cell empty" aria-hidden="true"></div>'; return; }
        if(cell.kind==='center'){
          html+=`<div class="cc-compass-cell cc-compass-center"><button data-scope="region:${escPend(cell.slug)}">${cueLabel(cell)}</button></div>`;
          return;
        }
        // The direction is spelled out in the aria-label, never conveyed by grid
        // position alone (review requirement).
        const aria=tpl(D.compassLabel||'{dir}: {region}',{dir:D[DIRK[cell.dir]]||cell.dir,region:cueText(cell)});
        html+=`<div class="cc-compass-cell"><button data-scope="region:${escPend(cell.slug)}" aria-label="${escPend(aria)}">${cueLabel(cell)}</button></div>`;
      }));
      html+='</div>';
      m.overflow.forEach(r=>{ html+=regionBtn(r); });
      // More/All-country stay BELOW the grid in plain linear flow — neither is a
      // geographic neighbour, so neither may occupy a compass cell.
      if(m.more) html+=`<div class="cc-compass-more">${moreBtn()}</div>`;
      html+=`<div class="cc-compass-more">${countryBtn(m.country)}</div>`;
    } else {
      m.chips.forEach(r=>{ html+=regionBtn(r); });
      if(m.more) html+=moreBtn();
      html+=countryBtn(m.country);
    }
    host.innerHTML=html;
    // Grid mode gets its own box (a CSS-grid column of the 3x3 grid + the linear
    // More/All-country row below it); the linear list stays `display:contents` so its
    // buttons flex alongside My-area/Everywhere exactly as before (map.css).
    host.classList.toggle('cc-grid', m.mode==='compass');
    // (re)bind the freshly-rendered chips to CCScope, same contract as the static ones.
    host.querySelectorAll('button[data-scope]').forEach(b=>b.onclick=()=>{
      const tok=b.dataset.scope;
      if(tok.startsWith('country:')) window.CCScope.setCountry(tok.slice(8));
      else if(tok.startsWith('region:')) window.CCScope.setRegion(tok.slice(7));
    });
    { const more=document.getElementById('scopeMoreBtn'); if(more) more.onclick=focusSearchBox; }
    // Self-mark the active chip (do NOT call applyScope here — the cc:scopechange
    // handler already calls applyScope; calling it back would recurse/double-work).
    const tok=scopeToken(curScope());
    host.querySelectorAll('button[data-scope]').forEach(x=>x.classList.toggle('on', x.dataset.scope===tok));
  }
  // Apply a scope: active rail button + dynamic header + spotlight (single named
  // region only) + viewport + re-render (scope-filtered from Task 7). fit:false
  // on the initial paint — the map constructor already opened on the scope bbox.
  function applyScope(s, opts){
    document.querySelectorAll('#regionScope button').forEach(x=>x.classList.toggle('on', x.dataset.scope===scopeToken(s)));
    writeScopeHeader();   // header/kicker/search-title (`s` IS window.CCScope.get() here — see writeScopeHeader() above)
    if(s&&s.kind==='myArea'&&s.myArea) setCircleSpotlight(s.myArea.center, s.myArea.radiusKm);
    else if(s&&s.kind==='country') setCountrySpotlight(s.countryCode);
    else setSpotlight(s&&s.kind==='region'&&s.regionIds.length===1 ? slugOfRegion(s.regionIds[0]) : null);
    refilterClusters(); updateConfMarkers();   // served-POI clusters follow scope (no-ops until setupConfClusters runs)
    updateHeatFilter();                         // ride-heat follows scope too (no-op until the lazy layer exists)
    updateCoverageScopeFilter();                // coverage tile dots follow scope (Phase 3; no-op until addCoverage runs)
    if(!opts||opts.fit!==false){                // a user scope change, not the initial paint
      // Coverage rail totals become scope-aware (Phase 3, region-scoping-design.md
      // §7 counts decision): re-fetch with the new rids/cc so the legend's
      // 'total' side matches the now scope-filtered 'shown' dots. Init doesn't
      // need this call — the standalone fetchCoverageCounts() below runs once
      // the layers exist, already reading the initial scope.
      fetchCoverageCounts();
      const bb = window.CCScope && window.CCScope.bbox(); if(bb) map.fitBounds([[bb[0],bb[1]],[bb[2],bb[3]]],{padding:24});
      // One render per scope switch (review 07-20 info d): in Everything mode
      // refreshBestOf() IS the render (its non-curated branch renders
      // synchronously); in Curated we render now for instant A–J + K feedback
      // while the region-ranked best-of fetch is in flight — applyBestOf
      // re-renders K when it lands.
      if(mode==='curated') render();
      refreshBestOf();                          // re-fetch best-of with the new &region= (init fetch is the standalone call below)
    } else {
      render();                                 // initial paint — climbs/routes/surface via featureVisible / renderSurfaceLayer
    }
  }
  // optional satellite base — Esri World Imagery (added below the data layers, hidden by default)
  function addSatellite(){
    if(map.getSource('satellite')) return;
    map.addSource('satellite',{type:'raster',tileSize:256,
      tiles:['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'],
      maxzoom:19, attribution:'Imagery © Esri, Maxar, Earthstar Geographics'});
    map.addLayer({id:'satellite',type:'raster',source:'satellite',layout:{visibility:'none'}});
  }
  // seasonal ride-heatmap (illustrative — built from sample GPX rides, served as catalog.json's L layer).
  // Built LAZILY on the first heatmap-On click (review W43): ~6,600 features
  // allocated + tiled at load for a layer that defaults Off was pure startup
  // cost; the toggle handler below calls this before flipping visibility.
  function addHeatmap(){
    if(!window.CC_ROUTES || map.getSource('rideheat')) return;
    // h = [lat, lng, season, rid] (CatalogProvider::heat()) — rid feeds the
    // scope half of updateHeatFilter() (07-20 review finding 5).
    const feats=CC_ROUTES.heat.map(h=>({type:'Feature',properties:{season:h[2],rid:h[3]},
      geometry:{type:'Point',coordinates:[h[1],h[0]]}}));
    map.addSource('rideheat',{type:'geojson',data:{type:'FeatureCollection',features:feats}});
    map.addLayer({id:'rideheat',type:'heatmap',source:'rideheat',layout:{visibility:'none'},paint:{
      'heatmap-weight':0.8,
      'heatmap-intensity':['interpolate',['linear'],['zoom'],8,0.8,13,1.8],
      'heatmap-radius':['interpolate',['linear'],['zoom'],8,8,13,22],
      'heatmap-opacity':0.82,
      'heatmap-color':['interpolate',['linear'],['heatmap-density'],
        0,'rgba(0,0,0,0)',
        0.12,'rgba(255,221,128,0.55)',
        0.30,'#FFC43D',
        0.50,'#FF9A1F',
        0.70,'#FF5A1F',
        0.88,'#E23617',
        1,'#FFF1C8']
    }});
    updateHeatFilter();   // the layer is built lazily — apply the current season + scope immediately
  }
  // The ONE heat filter: season facet AND region scope combined (07-20 review
  // finding 5 — heat used to be the only served layer the scope never reached,
  // and season/scope each overwrote the other's setFilter). A rid-less point
  // shows only in Everywhere, the same leak-safe default as inScope(); the
  // coalesce(-1) keeps the 'in' needle typed when rid is absent.
  function updateHeatFilter(){
    if(!map.getLayer('rideheat')) return;
    const clauses=[];
    const sc=document.querySelector('#season .chip.on');
    if(sc && sc.dataset.s!=='all') clauses.push(['==',['get','season'],sc.dataset.s]);
    const s=curScope();
    if(s && s.kind!=='everywhere') clauses.push(['in',['coalesce',['get','rid'],-1],['literal',s.regionIds]]);
    map.setFilter('rideheat', clauses.length ? (clauses.length===1 ? clauses[0] : ['all'].concat(clauses)) : null);
  }
  // ---------- Mapillary street-level imagery ----------
  // Public Mapillary client token (MLY|...). Replace the placeholder, preferably in config.js to enable the layer.
  const MAPILLARY_TOKEN = window.MAPILLARY_TOKEN || 'MLY|PASTE_TOKEN_HERE';
  const MLY_ENABLED = /^MLY\|/.test(MAPILLARY_TOKEN) && !/PASTE_TOKEN_HERE/.test(MAPILLARY_TOKEN);
  const MLY_GREEN = '#05CB63';
  let mlyOn=false, mlyMarker=null, mlyViewer=null, mlyLoading=null;

  // coverage line source/layer — Mapillary sequence vector tiles, hidden until toggled on
  function addMapillary(){
    if(!MLY_ENABLED || map.getSource('mly')) return;
    map.addSource('mly',{type:'vector',
      tiles:[`https://tiles.mapillary.com/maps/vtp/mly1_public/2/{z}/{x}/{y}?access_token=${MAPILLARY_TOKEN}`],
      minzoom:6, maxzoom:14});
    map.addLayer({id:'mly-cov',type:'line',source:'mly','source-layer':'sequence',
      layout:{visibility:'none','line-cap':'round','line-join':'round'},
      paint:{'line-color':MLY_GREEN,'line-opacity':0.7,
        'line-width':['interpolate',['linear'],['zoom'],10,1.5,14,3,16,4]}});
    // individual image points (the little Mapillary dots) — each carries its image id in the tile properties
    map.addLayer({id:'mly-img',type:'circle',source:'mly','source-layer':'image',
      layout:{visibility:'none'},
      paint:{'circle-color':MLY_GREEN,'circle-opacity':0.9,
        'circle-stroke-color':'#0b3d22','circle-stroke-width':1,
        'circle-radius':['interpolate',['linear'],['zoom'],13,2,16,4,19,6]}});
    // click a dot, or anywhere on a coverage line → open the nearest image straight from the tiles (no Graph API)
    map.on('click','mly-img',e=>openMapillaryAtPoint(e.point));
    map.on('click','mly-cov',e=>openMapillaryAtPoint(e.point));
    ['mly-img','mly-cov'].forEach(id=>{
      map.on('mouseenter',id,()=>{ if(mlyOn) map.getCanvas().style.cursor='pointer'; });
      map.on('mouseleave',id,()=>{ map.getCanvas().style.cursor=''; });
    });
  }

  function openMapillaryDock(){          // slide the dock up immediately + show the loading state
    const dock=document.getElementById('mlyDock');
    dock.classList.add('open','loading'); dock.setAttribute('aria-hidden','false');
    { const ap=document.querySelector('.app'); ap.classList.add('dock-open'); ap.classList.remove('sheet-open'); }   // dock owns the bottom on mobile → hide the filters peek
    const ld=document.getElementById('mlyLoad'); if(ld) ld.innerHTML=`<span class="mly-spin"></span>${I18N.mlyLoading||'Loading street-level…'}`;
  }
  function mlyDockMessage(msg){           // swap the spinner for a short message (nothing found / error)
    document.getElementById('mlyDock').classList.add('loading');
    const ld=document.getElementById('mlyLoad'); if(ld) ld.textContent=msg;
  }
  // find the nearest rendered Mapillary image point to a screen pixel and read its id from the tile
  function nearestImageId(point){
    if(!map.getLayer('mly-img')) return null;
    for(const r of [8,16,30]){
      const fs=map.queryRenderedFeatures([[point.x-r,point.y-r],[point.x+r,point.y+r]],{layers:['mly-img']});
      if(fs.length){
        let best=fs[0],bd=Infinity;
        for(const f of fs){ const p=map.project(f.geometry.coordinates); const dx=p.x-point.x,dy=p.y-point.y,dd=dx*dx+dy*dy; if(dd<bd){bd=dd;best=f;} }
        return best.properties&&best.properties.id;
      }
    }
    return null;
  }
  // open the image nearest a clicked point — straight from the vector tiles, no Graph-API call
  function openMapillaryAtPoint(point){
    openMapillaryDock();
    const id=nearestImageId(point);
    if(id!=null) openMapillaryImage(String(id));
    else mlyDockMessage(I18N.mlyZoom||'Zoom in and click a green dot to open street-level here.');
  }
  // (legacy) resolve the nearest image via the Graph API — kept as a fallback, no longer wired to clicks
  async function openMapillaryAt(lngLat){
    openMapillaryDock();                  // instant feedback — don't make the user wait on the fetch
    const d=0.0009; // ~100 m bbox half-size
    const bbox=[lngLat.lng-d,lngLat.lat-d,lngLat.lng+d,lngLat.lat+d].join(',');
    try{
      const r=await fetch(`https://graph.mapillary.com/images?access_token=${MAPILLARY_TOKEN}&fields=id&bbox=${bbox}&limit=1`);
      const j=await r.json();
      const img=j.data&&j.data[0];
      if(!img){ mlyDockMessage(I18N.mlyNone||'No street-level imagery here.'); return; }
      openMapillaryImage(img.id);
    }catch(_){ mlyDockMessage(I18N.mlyNone||'No street-level imagery here.'); }
  }
  // one reusable popup — same accumulation concern as the contextmenu popup (W11)
  let _mlyPopupInst=null;
  function mlyPopup(lngLat,msg){
    if(!_mlyPopupInst) _mlyPopupInst=new maplibregl.Popup({closeButton:false,className:'pop'});
    _mlyPopupInst.setLngLat(lngLat)
      .setHTML(`<div class="pop"><div class="pop-co">Mapillary</div>${msg}</div>`).addTo(map);
  }

  // inject mapillary-js (JS + CSS) once, on first open; resolves when ready
  function loadMapillaryJs(){
    if(window.mapillary) return Promise.resolve();
    if(mlyLoading) return mlyLoading;
    // SRI-pinned like maplibre-gl in the template (review W2): a MITM-ed or
    // compromised unpkg response must not run in the origin that holds the
    // curator session + moderation CSRF token.
    mlyLoading=new Promise((res,rej)=>{
      const css=document.createElement('link'); css.rel='stylesheet';
      css.href='https://unpkg.com/mapillary-js@4.1.2/dist/mapillary.css';
      css.integrity='sha384-IamMZxz60pSNzUk3cW2nl3uYUdpiDoQpLJrBoAAezEE+QaOYCVA9rfi/8Cx0x15T';
      css.crossOrigin='anonymous'; document.head.appendChild(css);
      const js=document.createElement('script');
      js.src='https://unpkg.com/mapillary-js@4.1.2/dist/mapillary.js';
      js.integrity='sha384-1AlAxcgdzKreJ5f2K+t7SW3pEE0ek9u3lUwlZXX+v+FtEk1tWSg6DBVLb3ZKhpB+';
      js.crossOrigin='anonymous';
      js.onload=()=>res(); js.onerror=()=>rej(new Error('mapillary-js failed to load'));
      document.head.appendChild(js);
    });
    return mlyLoading;
  }

  // open (or move to) an image id in the bottom dock; drops a "you are here" marker
  async function openMapillaryImage(imageId){
    const dock=document.getElementById('mlyDock');
    dock.classList.add('open'); dock.setAttribute('aria-hidden','false');
    { const ap=document.querySelector('.app'); ap.classList.add('dock-open'); ap.classList.remove('sheet-open'); }
    try{ await loadMapillaryJs(); }
    catch(_){ mlyClose(); mlyPopup(map.getCenter(),'Viewer failed to load.'); return; }
    if(!mlyViewer){
      mlyViewer=new mapillary.Viewer({accessToken:MAPILLARY_TOKEN,container:'mlyView',imageId});
      mlyViewer.on('image',ev=>{
        document.getElementById('mlyDock').classList.remove('loading');   // photo rendered → hide the spinner
        const ll=ev.image&&ev.image.lngLat; if(!ll) return;
        if(!mlyMarker){
          const el=document.createElement('div'); el.className='cc-pin cur cam';
          el.style.setProperty('--c',MLY_GREEN); el.innerHTML='<span>📷</span>';
          mlyMarker=new maplibregl.Marker({element:el,anchor:'bottom'}).setLngLat([ll.lng,ll.lat]).addTo(map);
        } else mlyMarker.setLngLat([ll.lng,ll.lat]);
      });
    } else { mlyViewer.moveTo(imageId).catch(()=>{}); }
  }
  function mlyClose(){
    const dock=document.getElementById('mlyDock');
    dock.classList.remove('open','full','loading'); dock.setAttribute('aria-hidden','true');
    document.querySelector('.app').classList.remove('dock-open');   // restore the filters peek
    if(mlyMarker){ mlyMarker.remove(); mlyMarker=null; }
  }

  // water-droplet icons, minted once for the coverage C tile layer: blue =
  // tagged drinkable (tile prop `potable`, coverage-provider.md
  // §4), grey = potability unknown/untagged — the "confirm on the spot"
  // variant.
  function mintWaterDrops(){
    if(map.hasImage('water-drop')) return;
    const S=2, W=14*S, H=18*S, cx=W/2;
    const drop=(fill,stroke)=>{
      const cv=document.createElement('canvas'); cv.width=W; cv.height=H;
      const x=cv.getContext('2d');
      x.beginPath(); x.moveTo(cx,S);
      x.bezierCurveTo(W-S, H*0.46, W*0.80, H-S, cx, H-S);
      x.bezierCurveTo(W*0.20, H-S, S, H*0.46, cx, S);
      x.closePath();
      x.fillStyle=fill; x.fill();
      x.lineWidth=1.4*S; x.strokeStyle=stroke; x.stroke();
      return new Uint8Array(x.getImageData(0,0,W,H).data.buffer);
    };
    map.addImage('water-drop', {width:W, height:H, data:drop('#3E8FB0','#0d2b3a')}, {pixelRatio:S});
    map.addImage('water-drop-unk', {width:W, height:H, data:drop('#7F8C93','#2b3338')}, {pixelRatio:S});
  }

  // Water POI registry entry + confirmed-pin data (display moved to the
  // coverage tile layer, addCoverage() — this only feeds osmLayers so
  // setupConfClusters()/updateConfMarkers() keep rendering confirmed water pins).
  function addWaterOsm(){
    if(!window.CC_WATER_OSM || osmLayers['water']) return;
    osmLayers['water']={data:CC_WATER_OSM, water:true};
    mintWaterDrops();
  }

  // registry of the bulk-OSM POI pools: feeds the confirmed-pin cluster
  // machinery (setupConfClusters/updateConfMarkers) and the per-layer drawer
  // source strings osmDrawer() falls back to.
  const osmLayers = {};
  // Star glyphs for 1-5 ratings, shared by schemaRows' 'rating' kind.
  const stars=n=>'★★★★★'.slice(0,n)+'☆☆☆☆☆'.slice(0,5-n);
  // Registry-driven attribute rows (spec: 2026-07-13-registry-driven-drawer-fields).
  // CC_FIELD_SCHEMA[letter] is the server's per-type display-field list
  // [{key,label,kind}] with labels already localised. For each field: a value
  // row when set, otherwise a muted "add" prompt to the /improve edit-bridge.
  // opts.skip = field keys a builder renders structurally (e.g. routes' difficulty
  // badge) so they are not double-rendered here.
  function schemaRows(letter, src, id, opts){
    const schema = (window.CC_FIELD_SCHEMA || {})[letter] || [];
    const skip = (opts && opts.skip) || [];
    const fixed = (opts && opts.fixed) || {};   // key -> assumed default when UNSET: shown as a value row instead of an "add" prompt; a stored value wins
    const rows = [];
    schema.forEach(f=>{
      if(skip.indexOf(f.key) >= 0) return;
      const v = src[f.key];
      const has = Array.isArray(v) ? v.length > 0 : (v != null && v !== '');
      // Stored values are canonical English; f.choices (schema-provided, per
      // field) maps them to the rider's locale for display only.
      const cv = f.choices || {};
      const tv = x => cv[x] != null ? cv[x] : x;
      if(has){
        if(f.kind === 'multiselect'){
          const list = Array.isArray(v) ? v : [v];
          rows.push({label:f.label, html:true, value:list.map(t=>`<span class="cc-chip">${escPend(tv(t))}</span>`).join('')});
        } else if(f.kind === 'rating'){
          rows.push({label:f.label, value: /^[1-5]$/.test(String(v)) ? stars(Number(v)) : v});
        } else if(f.kind === 'url'){
          rows.push({label:f.label, value:String(v).replace(/^https?:\/\//,'').replace(/\/$/,''), links:[{label:D.visitSite||'Visit site', href:v}]});
        } else {
          rows.push({label:f.label, value:tv(v)});
        }
      } else if(fixed[f.key] != null){
        rows.push({label:f.label, value:tv(fixed[f.key])});
      } else if(id != null){
        const href = `/improve?item=${encodeURIComponent(id)}&type=${encodeURIComponent(letter)}&field=${encodeURIComponent(f.key)}`;
        rows.push({label:f.label, html:true, empty:true, value:`<a class="cc-d-add" href="${href}">＋ ${D.add||'add'}</a>`});
      }
    });
    return rows;
  }
  // drawer card for a generic bulk-OSM point — shared by the dot click handler and the confirmed pin
  function osmDrawer(layer, p, ll, src){
    const lbl=(layer||{}).label||D.place||'Place';
    const pivot=p.src==='pivot';   // official Tourisme Wallonie accommodation (CC-BY), not OSM
    // C1-T4 (W6): p.srcType is the item's real ItemSource value from CatalogProvider.
    // A rider-added/edited item (user/manual) must read as rider-contributed even
    // when served through a bulk-OSM layer — the per-fact "Type"/"Listed" method
    // tags below are unchanged (Phase C2 scope), only the headline + source line
    // are corrected here.
    const community = p.srcType==='user' || p.srcType==='manual';
    const originLbl = pivot?'Tourisme Wallonie':(community?sourceLabel(p.srcType):'OSM');
    // D · services carries a serviceKind (shop/station/pump) — when present, the localized
    // kind label takes precedence over the raw OSM p.t value for the type row + headline
    // (e.g. EN 'Repair station' → 'Self-service station'; FR/NL/DE get real translations
    // instead of the raw English t). Every other layer (and services items with no/unknown
    // serviceKind) falls back to today's p.t||lbl behaviour, unchanged.
    const kindLbl = p.serviceKind && ({shop:D.kindShop, station:D.kindStation, pump:D.kindPump}[p.serviceKind] || lbl);
    const typeLbl = kindLbl || p.t || lbl;
    let rec=[{label:D.type||'Type', value:typeLbl, method: pivot?'Tourisme Wallonie':'OSM'}];
    if(p.town && layer.letter!=='E') rec.push({label:D.town||'Town', value:p.town});  // stays' 'town' comes from the schema (labelled "Town / commune")
    // Province renders only when the item actually carries one (curated PIVOT/
    // harvest rows). Coverage POIs are Belgium-wide with region_id NULL by design
    // (coverage-provider.md §2) — the old 'Wallonia' fallback mislabelled every
    // Flanders POI, so no prov means no row until region attribution exists.
    if(p.prov) rec.push({label:D.province||'Province', value:p.prov});
    if(pivot) rec.push({label:D.listed||'Listed', value:D.officialRegistry||'Official Tourisme Wallonie registry', method:'official'});
    // The simulated demo Status/Rating rows are gone — simulated flags die
    // (map-and-search.md §12); their payload keys stay for byte-stability but
    // nothing reads them.
    if(p.web) rec.push({label:D.website||'Website', value:p.web.replace(/^https?:\/\//,'').replace(/\/$/,''), links:[{label:D.visitSite||'Visit site',href:p.web}]});
    // Registry-driven attribute rows (single source of truth = CatalogFormRegistry,
    // served as CC_FIELD_SCHEMA). Filled rows replace any structural row of the
    // same label (e.g. a curated 'Type' overriding the raw OSM one); unset fields
    // become "add" prompts. 'web' dedupes by the shared "Website" label below.
    // Stations/pumps are unmanned and inherently 24/7 (spec §5) — the /improve
    // form has no openingHours field for them, so instead of an "add" prompt
    // (which would deep-link to a field that doesn't exist) the drawer states
    // the implied fact: Opening hours · 24/7.
    const unmanned = p.serviceKind==='station' || p.serviceKind==='pump';
    const attrRows = schemaRows((layer||{}).letter, p, p.id, unmanned ? {fixed:{openingHours:'24/7'}} : undefined);
    const attrLabels = new Set(attrRows.filter(r=>!r.empty).map(r=>r.label));
    rec = rec.filter(r=>!attrLabels.has(r.label)).concat(attrRows);
    // source is shown once, in the bottom cc-d-src line (linkified there) — like every other drawer
    const d={name:p.n||p.t||lbl, headline:typeLbl+' · '+originLbl, cur:!!p.v, geom:{ll:[ll.lat,ll.lng]}, record:rec,
      source: pivot?'Tourisme Wallonie (TW) — CC-BY 4.0 · PIVOT / Géoportail de la Wallonie'
        :(community?sourceLabel(p.srcType):(src||sourceLabel(p.srcType)||'OpenStreetMap'))};
    if(p.id!=null) d.id=p.id;          // real DB item id — the edit-bridge's `?item=` target
    if(p.desc) d.desc=p.desc;
    if(p.descTr) d.descTr=1;
    let photo=p.photo; if(typeof photo==='string'){ try{ photo=JSON.parse(photo); }catch(e){ photo=null; } }
    if(photo) d.photo=photo;
    return d;
  }
  // drawer card for a water point — shared by the droplet click handler and the confirmed pin
  function waterDrawer(p, ll){
    // C2-T7 (spec §W2): 'potable'/'type' are WaterFood registry fields
    // (CatalogFormRegistry::for(WaterFood)) — when a rider has set them, they
    // take priority over the generic OSM-derived guess below (same
    // dedup-by-label rule as the other POI drawers/climbs).
    // The simulated middle branch (demo potable flag) is gone — simulated
    // flags die (map-and-search.md §12). v:1 is existence/verification, not a
    // potability statement: without a rider-set potable field the honest row
    // is the OSM-derived fallback — and that fallback must not claim
    // "drinkable" for a fountain OSM tags as NOT potable (p.osmPotable===false,
    // derived in covProps from the tile `potable` prop / tags.drinking_water).
    const potable = p.potable
      ? {label:D.potable||'Potable', value:trVal(p.potable)}
      : (p.osmPotable===false
          ? {label:D.potable||'Potable', value:D.potableOsmNo||'Tagged not drinkable in OSM — not utility-verified; avoid unless confirmed on the spot', method:'unverified'}
          : {label:D.potable||'Potable', value:D.potableOsm||'Tagged drinkable in OSM — not utility-verified; confirm on the spot', method:'unverified'});
    // C1-T4 (W6): see osmDrawer — a rider-added/edited water point isn't OSM.
    const community = p.srcType==='user' || p.srcType==='manual';
    const rec=[{label:D.type||'Type', value:(p.type||p.t) ? trVal(p.type||p.t) : (D.drinkingWater||'Drinking water'), method: p.type?undefined:'OSM'}, potable,
      {label:D.verify||'Verify', value:D.verifyWater||'Cross-check tap-water quality with the regional utility / fountain directory', links:[{label:'SWDE · Wallonia',href:'https://www.swde.be'},{label:'eaupotable.info',href:'https://eaupotable.info/nl/be-belgie'}]}];
    // Registry-driven rows for the remaining WaterFood fields (seasonal/note/
    // bottleFill/cost). 'type' and 'potable' are rendered structurally above.
    rec.push(...schemaRows('C', p, p.id, {skip:['type','potable']}));
    const d={name:p.n||p.t||D.drinkingWater||'Drinking water', headline:(D.headlineDrinking||'drinking water')+' · '+(community?sourceLabel(p.srcType):'OSM'), cur:!!p.v, geom:{ll:[ll.lat,ll.lng]},
      record:rec,
      source: community?sourceLabel(p.srcType):'OpenStreetMap (amenity=drinking_water / drinking_water=yes)'};
    if(p.id!=null) d.id=p.id;          // real DB item id — the edit-bridge's `?item=` target
    return d;
  }
  // Bike-services (D) items carry a serviceKind (shop/station/pump) — distinct glyphs
  // per kind, layered onto the same category-colour disc treatment as every other
  // marker. shop reuses the layer's own icon (⚙) so staffed shops read exactly as
  // before; station/pump are new. Shared by both the unverified symbol-layer icons
  // (miniIcon below) and the confirmed/curated DOM pins (pinGlyph below).
  // station/pump deliberately use plain BMP symbols (⚒ hammer-and-pick, ⊕ circled-plus/
  // "add air") rather than the full-colour emoji 🛠/💨: ⚒ shares its Miscellaneous
  // Symbols block with ⚙ (shop, already drawn from there); ⊕ is a Mathematical
  // Operators-block character — both are plain BMP symbols, so they render from
  // any standard system/UI font, with no
  // dependency on a colour-emoji font being installed (verified: this dev box has none
  // installed at all — `fc-list | grep -i emoji` is empty — so 🛠/💨, and even the
  // pre-existing 💧/⛺/🚆/⛑/📷/🏛/⛰ layer icons, all silhouette as blank tofu boxes here).
  const SERVICE_GLYPH={shop:'⚙', station:'⚒', pump:'⊕'};
  // small recognisable marker for UNVERIFIED items: paper disc + category-colour ring + the category glyph.
  // glyph/suffix let a layer mint more than one disc variant (e.g. services' per-serviceKind icons) off the
  // same colour/id scheme — suffix keeps the cache id distinct so each variant is registered once.
  function miniIcon(key, glyph, suffix){
    const id='mini-'+key+(suffix?('-'+suffix):'');
    if(map.hasImage(id)) return id;
    const layer=layerByKey[key], color=(layer||{}).color||'#6b6f5e';
    const r=parseInt(color.slice(1,3),16),g=parseInt(color.slice(3,5),16),b=parseInt(color.slice(5,7),16);
    const dark=(0.299*r+0.587*g+0.114*b)<150;          // dark disc → white glyph, light disc → ink glyph
    const S=2, D=24*S, R=D/2;
    const cv=document.createElement('canvas'); cv.width=D; cv.height=D; const x=cv.getContext('2d');
    // solid category-colour disc + dark hairline so it reads on light basemaps (like the water droplet does)
    x.beginPath(); x.arc(R,R,R-2.5*S,0,Math.PI*2);
    x.fillStyle=color; x.fill();
    x.lineWidth=1.6*S; x.strokeStyle='rgba(20,22,14,.85)'; x.stroke();
    // category glyph as a flat silhouette (white on dark discs, ink on light) — matches the pins' icon treatment
    const gc=document.createElement('canvas'); gc.width=D; gc.height=D; const gx=gc.getContext('2d');
    gx.font=`${12.5*S}px "Apple Color Emoji","Noto Color Emoji","Segoe UI Emoji","Noto Sans Symbols2",system-ui,sans-serif`;
    gx.textAlign='center'; gx.textBaseline='middle';
    gx.fillText(glyph || (layer||{}).icon||'•', R, R+1*S);
    const gd=gx.getImageData(0,0,D,D), gp=gd.data;
    for(let i=0;i<gp.length;i+=4){ if(gp[i+3]>25){ gp[i]=dark?255:20; gp[i+1]=dark?255:22; gp[i+2]=dark?255:14; gp[i+3]=255; } }
    gx.putImageData(gd,0,0); x.drawImage(gc,0,0);
    map.addImage(id,{width:D,height:D,data:new Uint8Array(x.getImageData(0,0,D,D).data.buffer)},{pixelRatio:S});
    return id;
  }
  // Bulk-OSM POI registry entry + confirmed-pin data (display moved to the
  // coverage tile layer, addCoverage() — this only feeds osmLayers so
  // setupConfClusters()/updateConfMarkers() keep rendering confirmed pins, and
  // the per-layer drawer source string osmDrawer() falls back to).
  function addOsmDots(key, data, srcDesc){
    if(!data || osmLayers[key]) return;
    osmLayers[key]={data, src:srcDesc};
  }
  // --- clustering for confirmed (validated/simulated) points: count bubble at low zoom → icon pins when spread ---
  const confState = {};   // srcId -> {key, layer, info, onScreen:{}}
  function setupConfClusters(){
    Object.keys(osmLayers).forEach(key=>{
      const info=osmLayers[key]; if(!info || !info.data) return;
      const confirmed = info.data.features.filter(f=>f.properties.v);
      const srcId=key+'-conf';
      if(!confirmed.length || map.getSource(srcId)) return;
      map.addSource(srcId,{type:'geojson', cluster:true, clusterRadius:48, clusterMaxZoom:13,
        data:{type:'FeatureCollection', features:confirmed.filter(f=>inScope(f.properties.rid))}});
      // invisible layer so the clustered source loads tiles (querySourceFeatures needs rendered tiles)
      map.addLayer({id:srcId+'-hit', type:'circle', source:srcId, paint:{'circle-radius':0,'circle-opacity':0}});
      confState[srcId]={key, layer:layerByKey[key], info, onScreen:{}, confirmed};
    });
  }
  // Region scope changed → rebuild each cluster source from its full confirmed
  // set, keeping only in-scope features, so cluster counts + leaf pins match the
  // scope (region-scoping-design.md §4). updateConfMarkers repaints on the
  // resulting sourcedata/idle.
  function refilterClusters(){
    Object.keys(confState).forEach(srcId=>{
      const st=confState[srcId], src=map.getSource(srcId);
      if(src) src.setData({type:'FeatureCollection', features:st.confirmed.filter(f=>inScope(f.properties.rid))});
    });
  }
  function clusterEl(layer, count){
    const d=document.createElement('div');
    d.className='cc-cluster'; d.style.setProperty('--c', layer.color); d.textContent=count;
    return d;
  }
  function confLeafPin(st, p, co){
    const lngLat=[co[0],co[1]], llo={lat:co[1],lng:co[0]};
    const drawerF = st.info.water ? waterDrawer(p, llo) : osmDrawer(st.layer, p, llo, st.info.src);
    const el=pinEl(st.layer, true, p);
    el.style.cursor='pointer'; el.tabIndex=0; el.setAttribute('role','button');
    el.setAttribute('aria-label', drawerF.name+' — '+drawerF.headline);
    // stopPropagation (click-to-scope, 2026-07-22-scope-selector-scale-design.md
    // §C): this DOM marker has no backing rendered layer at its pixel (the
    // '<key>-conf-hit' source layer is a zero-radius circle, purely so the
    // clustered source loads tiles) — without it the click bubbles to the map's
    // generic click handler, which would see "no feature here" and re-scope the
    // map right behind this pin's own drawer-open action.
    el.addEventListener('click', e=>{ e.stopPropagation(); openDrawer(st.layer, drawerF); flyToPin(lngLat); });
    el.addEventListener('mouseenter', ()=>showTip(drawerF.name+' · '+drawerF.headline, lngLat));
    el.addEventListener('mouseleave', hideTip);
    el.addEventListener('focus', ()=>showTip(drawerF.name+' · '+drawerF.headline, lngLat));
    el.addEventListener('blur', hideTip);
    return el;
  }
  function updateConfMarkers(){
    Object.keys(confState).forEach(srcId=>{
      const st=confState[srcId], on=st.onScreen;
      if(!active.has(st.key)){ for(const k in on) on[k].remove(); st.onScreen={}; return; }
      if(!map.getSource(srcId) || !map.isSourceLoaded(srcId)) return;
      const feats=map.querySourceFeatures(srcId), next={};
      for(const f of feats){
        const co=f.geometry.coordinates, p=f.properties;
        const key = p.cluster ? 'c'+p.cluster_id : 'l'+co[0].toFixed(5)+','+co[1].toFixed(5);
        if(next[key]) continue;
        // C2-T8: confirmed (clustered) stays pins respect the accessibility filter too —
        // only individual leaf pins are checked (a clustered bubble isn't re-aggregated;
        // the dataset is small enough that this is a non-issue in practice).
        if(!p.cluster && st.key==='stays' && !attrMatch(p.accessibility, activeAccess, ALL_ACCESS)) continue;
        let m=on[key];
        if(!m){
          if(p.cluster){
            const el=clusterEl(st.layer, p.point_count_abbreviated); el.style.cursor='pointer';
            // MapLibre ≥3: getClusterExpansionZoom returns a Promise (the old
            // callback form is silently ignored — the click did nothing).
            // stopPropagation: same click-to-scope note as confLeafPin above —
            // this bubble has no rendered layer under it either.
            el.addEventListener('click', e=>{ e.stopPropagation(); map.getSource(srcId).getClusterExpansionZoom(p.cluster_id).then(z=>map.easeTo({center:co, zoom:z+0.2})).catch(()=>{}); });
            m=new maplibregl.Marker({element:el, anchor:'center'}).setLngLat(co).addTo(map);
          } else {
            m=new maplibregl.Marker({element:confLeafPin(st, p, co), anchor:'bottom'}).setLngLat(co).addTo(map);
          }
        }
        next[key]=m;
      }
      for(const k in on){ if(!next[k]) on[k].remove(); }
      st.onScreen=next;
    });
  }
  // The bulk OSM POI pools (layer key, data collection, OSM source note).
  // Display moved to the coverage tile layer (addCoverage()); this table now
  // only feeds the addOsmDots() registry loop below — the confirmed-pin data
  // + per-layer drawer source strings osmDrawer() falls back to. Read straight
  // from the inlined CC_*_OSM globals (available synchronously), since
  // osmLayers is only populated later on map 'load'.
  const OSM_BULK = [
    ['services', window.CC_SERVICES_OSM, 'OpenStreetMap (shop=bicycle / amenity=bicycle_repair_station / compressed_air)'],
    ['scenic',   window.CC_SCENIC_OSM,   'OpenStreetMap (tourism=viewpoint / natural=peak / waterway=waterfall)'],
    ['history',  window.CC_HISTORY_OSM,  'OpenStreetMap (historic=castle/fort/ruins/monument/memorial/…)'],
    ['stays',    window.CC_STAYS_OSM,    'OpenStreetMap (tourism=camp_site/hostel/guest_house/chalet/hotel/…)'],
    ['shelter',  window.CC_SHELTER_OSM,  'OpenStreetMap (shelter_type=picnic/weather/field/…)'],
    ['transit',  window.CC_TRANSIT_OSM,  'OpenStreetMap (railway=station / railway=halt)']
  ];
  // ---- Coverage tiles (coverage-provider.md §6) ----
  // Uncurated OSM coverage renders from ONE PMTiles vector source ('coverage',
  // one tile layer per catalogue letter, OSM-arch: osm-data-architecture.md §5)
  // instead of inlined GeoJSON pools. Gated on window.CC_COVERAGE_URL (injected
  // by MapController only when COVERAGE_TILES is on and the manifest resolves)
  // AND on the pmtiles protocol lib actually having loaded — absent either, the
  // map keeps today's pool-only behaviour (Photon-style silent degradation).
  const COVERAGE_KEYS=[['water','c'],['services','d'],['stays','e'],['transit','g'],['shelter','h'],['scenic','i'],['history','j']];
  // Coverage tiles split their source-layers per country (Task 1:
  // 2026-07-22-coverage-scope-rendering-design.md §A): a letter's rows live in
  // '<letter>_<cc>' (lowercase cc), with unstamped rows in the 'zz' bucket. The
  // published manifest (window.CC_COVERAGE_COUNTRIES, §D) lists the real
  // countries; we always append 'zz' so unstamped rows still render. Each
  // (letter, cc) pair becomes its own icon + cluster layer. [null] is the
  // pre-split fallback: a single unsplit '<letter>' source-layer, so a tile
  // artifact built before the per-country split still renders.
  const COVERAGE_CCS = (Array.isArray(window.CC_COVERAGE_COUNTRIES) && window.CC_COVERAGE_COUNTRIES.length)
    ? window.CC_COVERAGE_COUNTRIES.map(c=>c.toLowerCase()).concat(['zz'])
    : [null];   // [null] = single unsplit '<letter>' layer (tiles predate the per-country split)
  const LETTER_KEY={C:'water',D:'services',E:'stays',G:'transit',H:'shelter',I:'scenic',J:'history'};
  const COVERAGE_ON = typeof window.CC_COVERAGE_URL==='string' && !!window.CC_COVERAGE_URL && typeof pmtiles!=='undefined';
  // Per-layer OSM source notes for the drawer's Source line — same wording as
  // OSM_BULK above (water goes through waterDrawer, which owns its own string).
  const COV_SRC={
    services:'OpenStreetMap (shop=bicycle / amenity=bicycle_repair_station / compressed_air)',
    scenic:'OpenStreetMap (tourism=viewpoint / natural=peak / waterway=waterfall)',
    history:'OpenStreetMap (historic=castle/fort/ruins/monument/memorial/…)',
    stays:'OpenStreetMap (tourism=camp_site/hostel/guest_house/chalet/hotel/…)',
    shelter:'OpenStreetMap (shelter_type=picnic/weather/field/…)',
    transit:'OpenStreetMap (railway=station / railway=halt)'
  };
  // Curated-ref dedupe (osm-data-architecture.md §8): any object already served
  // as an item draws once, as curated — its coverage twin is filtered out. A
  // ref identifies one point, so this arm applies cleanly to every coverage icon.
  const covDedupeFilter=()=>['!',['in',['get','ref'],['literal', Array.from(window.CC_CURATED_REFS||[])]]];
  // Region scope filter for the coverage tile layers (Phase 3,
  // region-scoping-design.md §6). Scope keys are pipe-delimited membership
  // TOKENS, not scalars: ridtok = "|<region_id>|" (empty when unstamped), cctok
  // = "|<cc>|" — one token pair per feature, never unioned. Coverage renders as
  // individual points only, no clusters (2026-07-24-coverage-no-cluster-design.md
  // §2), so `'|id|' in ridtok` answers "is THIS point in scope?" exactly, per
  // feature — no cluster-member aggregation to worry about (finding 2 is moot).
  //
  // DELIBERATELY the inverse of the leak-safe rule updateHeatFilter()/inScope()
  // use for served data: a PROP-LESS feature (ridtok AND cctok both empty)
  // RENDERS instead of hiding. Rationale: the coverage PMTiles is a
  // separately-built, weekly-rebuilt artifact (§8 risk 2) — a transition/unsplit
  // row carries no tokens, and hiding-all would blank the map, so empty tokens →
  // render unfiltered (fallback ladder, never hide-all). But a cc-bearing
  // rid-less row (cctok non-empty, ridtok empty) is NOT prop-less, so under a
  // region scope it HIDES — matching /counts, which excludes region_id-NULL rows
  // (finding 5: the tile used to leak these via the old `!has rid` arm). It
  // reappears only under its country scope, admitted by the cctok arm. coalesce
  // keeps the test safe against a stale pre-token tile (absent → '' → prop-less →
  // render). Returns null for Everywhere (no filter).
  // Delegates to the pure, unit-tested builder in scope.js (web/tests/js) so the
  // token/prop-less/coalesce logic lives in one place with real tests, not buried
  // in this IIFE. Falls back to a null filter if scope.js is somehow absent.
  function covScopeFilter(){
    return window.CCScope && window.CCScope.coverageTileFilter ? window.CCScope.coverageTileFilter() : null;
  }
  // Icon base = scope + the curated-ref dedupe (icons can be exact curated twins).
  function covBaseFilter(){
    const f=['all', covDedupeFilter()]; const sc=covScopeFilter(); if(sc) f.push(sc); return f;
  }
  // The coverage icon layer's filter (no clustering —
  // 2026-07-24-coverage-no-cluster-design.md §2): scope + curated-ref dedupe
  // plus any per-layer extra (stays' accessibility narrow). `!has point_count`
  // is kept as a harmless no-op — tippecanoe no longer emits clustered
  // features, so every feature already satisfies it.
  function covIconFilter(extra){
    const f=covBaseFilter(); f.push(['!',['has','point_count']]); if(extra) f.push(extra); return f;
  }
  // The coverage HEAT layer's filter: scope ONLY
  // (2026-07-24-coverage-overview-heatmap-design.md §3.2). No curated-ref dedupe,
  // no stays-accessibility narrow, no `!has point_count` arm — a density surface
  // is not a clickable/exact feature; it only needs in-scope points to contribute
  // density. Returns the scope expression, or null (Everywhere → unfiltered, all
  // points), which setFilter accepts.
  function covHeatFilter(){
    return covScopeFilter() || null;
  }
  // Re-apply the composed filter to every coverage icon layer on a scope
  // change, so each tracks scope like every served layer. stays' icon layer
  // re-composes through applyStaysAccessFilter (it owns the acc extra).
  function updateCoverageScopeFilter(){
    if(!COVERAGE_ON) return;
    COVERAGE_KEYS.forEach(([key])=>{
      COVERAGE_CCS.forEach(cc=>{
        const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
        if(map.getLayer(id)){
          // stays owns an acc extra; applyStaysAccessFilter re-composes every
          // stays-<cc>-cov icon layer itself (it loops COVERAGE_CCS), so calling
          // it once per key is enough — guard so it fires only on the first cc.
          if(key==='stays'){ if(cc===COVERAGE_CCS[0]) applyStaysAccessFilter(); }
          else map.setFilter(id, covIconFilter());
        }
        const heatId = cc ? key+'-'+cc+'-heat' : key+'-heat';
        if(map.getLayer(heatId)) map.setFilter(heatId, covHeatFilter());
      });
    });
  }
  // A myArea scope whose derived region set is empty (map-and-search.md §4.5):
  // coverageParams() returns rids:[] (an empty array, NOT null) as an explicit
  // "in scope: nothing" sentinel, distinct from Everywhere's rids:null. Callers
  // that would otherwise build an unscoped '' query — and silently fall back to
  // GLOBAL /counts or /search results — check this first and skip the fetch.
  function covScopeIsZero(){
    const p=window.CCScope && window.CCScope.coverageParams();
    return !!(p && Array.isArray(p.rids) && !p.rids.length);
  }
  // Active-scope coverage params (rids/cc) as a query fragment
  // (region-scoping-design.md §6); '' for Everywhere so the URL — and the
  // shared HTTP-cache key — stays scope-free. Callers prepend '?' or '&'.
  function covScopeQuery(){
    if(!window.CCScope) return '';
    const p=window.CCScope.coverageParams(), parts=[];
    if(p.rids && p.rids.length) parts.push('rids='+p.rids.join(','));
    if(p.cc) parts.push('cc='+encodeURIComponent(p.cc));
    return parts.join('&');
  }
  // Community tier on the map (07-15 decision B, rebased in
  // map-and-search.md §12): utility letters C/D/G/H draw
  // in BOTH modes — at 0.55 opacity in Curated so verified pins keep visual
  // priority — while experiential letters E/I/J stay Everything-only (Curated
  // remains best-of for them).
  const COV_UTILITY=new Set(['C','D','G','H']);
  const KEY_LETTER={water:'C',services:'D',stays:'E',transit:'G',shelter:'H',scenic:'I',history:'J'};
  function syncCoverageLayers(){
    if(!COVERAGE_ON) return;
    COVERAGE_KEYS.forEach(([key])=>{
      // on/off + Curated dim are per-letter decisions; apply them uniformly to
      // every per-country layer of this letter.
      const utility=COV_UTILITY.has(KEY_LETTER[key]);
      const show=active.has(key) && (mode==='all' || utility);
      const dim=(mode==='curated' && utility)?0.55:1;
      COVERAGE_CCS.forEach(cc=>{
        const id = cc ? key+'-'+cc+'-cov' : key+'-cov'; if(!map.getLayer(id)) return;
        map.setLayoutProperty(id,'visibility', show?'visible':'none');
        map.setPaintProperty(id,'icon-opacity', dim);
        const heatId = cc ? key+'-'+cc+'-heat' : key+'-heat';
        if(map.getLayer(heatId)) map.setLayoutProperty(heatId,'visibility', show?'visible':'none');
      });
    });
  }
  function addCoverage(){
    if(!COVERAGE_ON || map.getSource('coverage')) return;
    maplibregl.addProtocol('pmtiles', new pmtiles.Protocol().tile);
    mintWaterDrops();
    map.addSource('coverage',{type:'vector', url:'pmtiles://'+window.CC_COVERAGE_URL});
    COVERAGE_KEYS.forEach(([key, letter])=>{
      // One icon layer per (letter, country): the source-layer is
      // '<letter>_<cc>' (lowercase cc; 'zz' = unstamped rows), and the layer ids
      // carry the cc so scope filters / visibility toggles address each country.
      // A missing '<letter>_<cc>' source-layer (e.g. no unstamped rows) renders
      // nothing — the correct "empty bucket" outcome, no special-casing. cc===null
      // is the pre-split fallback: the plain '<letter>' source-layer + '<key>-cov'
      // ids, so a tile artifact built before the split still renders.
      COVERAGE_CCS.forEach(cc=>{
        const srcLayer = cc ? letter+'_'+cc : letter;
        const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
        // Reuse the existing canvas-minted icons: droplet variants for C (keyed
        // on the flat `potable` tile prop, tolerant of bool/num/string encoding),
        // the per-serviceKind discs for D (tile prop `kind`), miniIcon elsewhere.
        // Icons are shared across a letter's countries, so they stay keyed on `key`.
        const icon = key==='water'
          ? ['match',['to-string',['get','potable']],['yes','true','1'],'water-drop','water-drop-unk']
          : key==='services'
            ? ['match',['get','kind'],
                'shop', miniIcon('services'),
                'station', miniIcon('services', SERVICE_GLYPH.station, 'station'),
                'pump', miniIcon('services', SERVICE_GLYPH.pump, 'pump'),
                miniIcon('services')]
            : miniIcon(key);
        // Overview density heatmap (2026-07-24-coverage-overview-heatmap-design.md §3.2):
        // mirrors the icon layer on the same source-layer but renders z6-9 as a
        // heatmap (maxzoom 9), handing off to the individual icons (minzoom 9) so
        // the actual spots are visible from z9 (owner request 2026-07-24).
        // Scope-only filter → phantom-free (only in-scope points add density).
        // Per-CATEGORY hue: each letter's heat carries its OWN colour (the rail
        // colour), so the stacked layers alpha-blend into a MULTI-colour coverage
        // density — water blue, stays orange, … mixing where categories overlap
        // (owner request 2026-07-24). The ramp is the same hue from transparent to
        // translucent (never toward black or white), and the low top alpha keeps
        // it a soft, see-through blur.
        // ONE clean hue for the overview density blur (owner decision 2026-07-24).
        // A blended multi-colour heatmap is dominated by the densest letters
        // (stays/history) and averages to mud, so category distinction comes from
        // the coloured ICONS (which now appear from z9); the heat just answers
        // "where is coverage dense". Every letter's heat is the SAME teal, so the
        // stacked layers accumulate to *more teal* — never brown. Teal is distinct
        // from the ride heatmap's warm gold; no pale top stop (a pale/white max
        // punched white "holes" in a dense surface).
        const heatId = cc ? key+'-'+cc+'-heat' : key+'-heat';
        // covHeatFilter() is null under an Everywhere scope (unfiltered — every
        // point contributes density). setFilter() accepts null, but addLayer()
        // does NOT: `filter: null` fails style validation and MapLibre drops the
        // whole layer ("layers.<id>.filter: array expected, null found"), so a
        // first paint in Everywhere used to lose every coverage heat layer.
        // Omit the key entirely instead — the layer spec's own default is
        // unfiltered, which is exactly what null means here.
        const heatSpec={id:heatId, type:'heatmap', source:'coverage', 'source-layer':srcLayer,
          maxzoom: 9,
          layout:{visibility:'none'},
          paint:{
            'heatmap-weight':0.6,
            'heatmap-intensity':['interpolate',['linear'],['zoom'],6,0.9,9,1.3],
            'heatmap-radius':['interpolate',['linear'],['zoom'],6,16,9,28],
            // semi-transparent; fades to 0 at z9 for the crossfade into the icons.
            'heatmap-opacity':['interpolate',['linear'],['zoom'],6,0.6,8,0.6,9,0],
            'heatmap-color':['interpolate',['linear'],['heatmap-density'],
              0,'rgba(0,0,0,0)',
              0.25,'rgba(150,110,190,0.32)',
              0.6,'rgba(112,72,158,0.58)',
              1,'#5B2A86']}};
        { const hf=covHeatFilter(); if(hf) heatSpec.filter=hf; }
        map.addLayer(heatSpec);
        // Individual coverage icons (no clustering — 2026-07-24-coverage-no-cluster-design.md
        // §2); scope-filtered exactly. minzoom 9 so the spots are visible from the
        // region-fit zoom (z6-8 tiles are thinned, so z9-10 icons are a sample that
        // densifies to complete at z11+; the rail counts stay the exact total).
        map.addLayer({id, type:'symbol', source:'coverage', 'source-layer':srcLayer,
          minzoom: 9,
          filter:covIconFilter(),   // dedupe + scope
          layout:{visibility:'none','icon-image':icon,'icon-allow-overlap':true,
            'icon-size': key==='water'
              ? ['interpolate',['linear'],['zoom'],8,0.55,13,0.9,18,1.3]
              : ['interpolate',['linear'],['zoom'],8,0.42,13,0.7,18,0.95]}});
        map.on('click',id,e=>{ const f0=e.features[0], tp=f0.properties, c=f0.geometry.coordinates;
          openCoverageDrawer(key, tp, {lng:c[0], lat:c[1]}); flyToPin([c[0],c[1]]); });   // exact feature coords, same halo rule as addOsmDots
        map.on('mouseenter',id,()=>map.getCanvas().style.cursor='pointer');
        map.on('mousemove',id,e=>{ const p=e.features[0].properties; showTip(p.n||p.t||(layerByKey[key]||{}).label||'Item', e.lngLat); });
        map.on('mouseleave',id,()=>{ map.getCanvas().style.cursor=''; hideTip(); });
      });
    });
    // Selected-POI icon overlay (fix 2026-07-22): a coverage POI's individual
    // icon is drawn only by the tile <key>-<cc>-cov layer, which the z11 minzoom
    // hides on zoom-out — but the selection pulse (a coord-anchored DOM
    // marker) stays, leaving an "empty pulsing halo". This single-feature GeoJSON
    // overlay redraws the SELECTED POI's icon on top, independent of the tile
    // minzoom, so it stays visible at every zoom. Sits under the DOM pulse,
    // which then rings the icon as intended. Same icon-image + size ramps as the
    // tile icon layers so there's no visual jump where the two overlap at high zoom.
    if(!map.getSource('cov-sel')){
      map.addSource('cov-sel',{type:'geojson',data:{type:'FeatureCollection',features:[]}});
      map.addLayer({id:'cov-sel-icon',type:'symbol',source:'cov-sel',
        // ONE zoom interpolate (MapLibre forbids two), with per-feature stop
        // outputs (_s8/_s13/_s18) so water vs the rest keep their exact tile ramps.
        layout:{'icon-image':['get','_icon'],'icon-allow-overlap':true,
          'icon-size':['interpolate',['linear'],['zoom'],
            8,['get','_s8'],13,['get','_s13'],18,['get','_s18']]}});
    }
  }
  // Adapt coverage properties (tile props + optionally the detail payload) into
  // the property bag osmDrawer/waterDrawer already consume. Coverage POIs are
  // always OSM-sourced; a curated twin (detail.curated — normally suppressed by
  // the dedupe filter, but reachable via deep links/search) binds the drawer to
  // the real item id so the edit bridge, confirm panel and registry rows work.
  function covProps(key, tp, d){
    const p={ srcType:'osm' };
    if(tp.t) p.t=tp.t;
    if(tp.n) p.n=tp.n;
    if(key==='services' && tp.kind) p.serviceKind=tp.kind;
    // C · water potability (coverage-provider.md §4 `potable` tile prop): the
    // pipeline pre-computes it as OSM drinking_water='yes' OR (bare
    // amenity=drinking_water with no contradicting tag); false covers every
    // other case, including an explicit drinking_water='no'. Tolerant
    // coercion matches the icon-choice expression (mintWaterDrops' paint
    // match on ['yes','true','1']) since the tile value's wire type isn't
    // guaranteed. Threaded through immediately so the open-now paint never
    // claims "Tagged drinkable in OSM" for a fountain OSM does not attest as
    // drinkable — waterDrawer reads p.osmPotable.
    if(key==='water' && tp.potable!=null){
      const v=tp.potable;
      p.osmPotable = v===true || v==='true' || v===1 || v==='1' || v==='yes';
    }
    if(d){
      if(d.name) p.n=d.name;
      if(key==='services' && d.kind) p.serviceKind=d.kind;
      const tags=d.tags||{};
      const web=tags.website||tags['contact:website'];
      if(web) p.web=web;
      // The whitelisted raw tag (CoverageRepository::TAG_WHITELIST) is more
      // precise than the tile's precomputed boolean once hydrated — an
      // explicit 'no' is definitive.
      if(key==='water' && tags.drinking_water==='no') p.osmPotable=false;
      if(d.curated){
        if(d.curated.itemId!=null) p.id=d.curated.itemId;
        Object.assign(p, d.curated.fields||{});
      }
    }
    return p;
  }
  // Open a coverage POI drawer from tile props immediately, then hydrate from
  // GET /map/coverage/poi/{ref} — the open-now-enrich-later pattern the drawer
  // already uses for history/confirmations (coverage-provider.md §6).
  // Race guard: EVERY drawer-context render (openDrawer, openPlace/
  // renderPlaceCard, the ride-check results drawer, closeDrawer) bumps BOTH
  // _covReq and _placeReq together — one shared drawer-generation convention,
  // two names so each call site reads as "this fetch kind is now stale". A
  // stale GET /map/coverage/poi/{ref} detail response (_covReq) or a stale
  // GET /map/coverage/nearby town-card response (_placeReq) can therefore
  // never repaint a drawer that has since moved on — regardless of which
  // click path (coverage dot, curated pin, -osm dot, route, place card,
  // another town) opened the newer drawer. Before this pairing, _placeReq
  // was bumped only in openPlace, so switching from a town card to a plain
  // feature drawer (or to a different town) mid-fetch let the stale nearby
  // response resurrect the old town card over the new drawer content. The
  // enrich repaint itself is a content-only patch (renderDrawerBody), not a
  // re-open: no halo restart, no focus steal, no mobile-sheet snap to half.
  let _covReq=0, _placeReq=0;
  function openCoverageDrawer(key, tp, ll){
    // Picking guard (spec §16 S1, same as openDrawer): during a stretch-picking
    // session the route drawer stays open (minimised) with the suggest form's
    // typed state — a picking click that also lands on a coverage dot must not
    // start a detail fetch whose repaint would wipe that form. openDrawer's own
    // _pick bail-out only stops the initial paint, not the fetch, so bail here.
    if(_pick) return;
    const layer=layerByKey[key];
    const feat=p=>key==='water' ? waterDrawer(p, ll) : osmDrawer(layer, p, ll, COV_SRC[key]);
    openDrawer(layer, feat(covProps(key, tp, null)));
    showSelectedCoverageIcon(key, tp, ll);   // keep the icon visible after openDrawer's clear, incl. when the z11 minzoom hides it on zoom-out
    if(!tp.ref) return;
    const myReq=++_covReq;
    fetch('/map/coverage/poi/'+tp.ref, {headers:{'Accept':'application/json'}})
      .then(r=>r.ok?r.json():null)
      .catch(()=>null)   // detail is an enhancement — the tile props already opened the drawer
      .then(d=>{ if(!d || myReq!==_covReq || _pick) return;   // superseded by a newer drawer render, or a picking session started mid-flight
        if(!document.getElementById('drawer').classList.contains('open')) return;   // closed while in flight
        renderDrawerBody(layer, feat(covProps(key, tp, d))); });
  }
  // Open a coverage POI with NO rendered tile feature at hand (search pick,
  // town-card row, ?feature= fallback): fly, fetch the detail, open the drawer.
  // Fetch failure still opens a minimal drawer — the pick must never no-op.
  function openCoverageByRef(ref, letter, ll, name){
    const key=LETTER_KEY[letter]; if(!key) return;
    flyToPin([ll[1],ll[0]]);
    const myReq=++_covReq;
    const paint=(d)=>{ if(myReq!==_covReq) return;
        const layer=layerByKey[key], lo={lng:ll[1], lat:ll[0]};
        const p=covProps(key, {ref, n:(d&&d.name)||name, kind:d&&d.kind}, d);
        openDrawer(layer, key==='water' ? waterDrawer(p, lo) : osmDrawer(layer, p, lo, COV_SRC[key]));
        // decision C: if this POI's tile layer isn't drawn right now (Curated
        // mode, experiential letter — or layer toggled off), reveal it with one
        // temporary pin rather than flipping the map mode. A letter's per-country
        // layers share one on/off state (syncCoverageLayers), so "any visible" =
        // this key's coverage is drawn.
        const drawn = COVERAGE_CCS.some(cc=>{
          const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
          return map.getLayer(id) && map.getLayoutProperty(id,'visibility')==='visible';
        });
        if(!drawn) revealPinAt(layer, ll);
        else showSelectedCoverageIcon(key, {kind:d&&d.kind}, lo);   // drawn: keep the icon visible when the z11 minzoom hides it on zoom-out (matches the drawer's unknown-potability droplet for water opened without a tile prop)
      };
    // Non-OSM refs (manual: rider adds, fx: seeds) have no coverage detail —
    // /map/coverage/poi serves node|way only. Open the minimal drawer with the
    // caller-supplied name straight away instead of a guaranteed-404 round-trip.
    if(!/^(node|way)\/\d+$/.test(ref)){ paint(null); return; }
    fetch('/map/coverage/poi/'+ref, {headers:{'Accept':'application/json'}})
      .then(r=>r.ok?r.json():null)
      .catch(()=>null)
      .then(paint);
  }
  // Transiently widen the scope to Everywhere so a resolved deep-link target
  // always renders, then return (region-scoping-design.md §4). persist:false —
  // the saved scope returns on the next plain load. Only ever called AFTER a
  // target actually resolves (07-20 review finding 9), so it never flips the
  // map with nothing to show. No-op when already Everywhere.
  function widenForDeepLink(){
    if(window.CCScope && curScope().kind!=='everywhere'){
      window.CCScope.set({kind:'everywhere', regionIds:[], countryCode:null}, {persist:false});
    }
  }
  // ?feature= deep-link fallback (coverage-provider.md §6):
  // a name that is not in the local index gets ONE search-endpoint lookup —
  // exact-name hit preferred, else the server's top-ranked result. Nothing
  // found / coverage off → silently keep the plain map (Photon convention).
  // The lookup is deliberately UNSCOPED (no covScopeQuery): a deep link must
  // resolve its target regardless of the saved scope, then widen to reveal it.
  function openCoverageFeatureByName(name){
    if(!COVERAGE_ON) return;
    fetch('/map/coverage/search?q='+encodeURIComponent(name), {headers:{'Accept':'application/json'}})
      .then(r=>r.ok?r.json():null)
      .catch(()=>null)
      .then(d=>{
        const hits=((d&&d.results)||[]).filter(h=>h && h.n && LETTER_KEY[h.letter] && Array.isArray(h.ll));
        if(!hits.length) return;
        const hit=hits.find(h=>h.n.toLowerCase()===name.toLowerCase())||hits[0];
        // Phase 3: coverage tiles are now scope-filtered, so a coverage-only
        // ?feature target CAN be hidden by a narrow saved scope — widen once
        // the target has actually resolved (the F9 gate below only covers the
        // synchronous local/pending/route resolvers; this is the async arm).
        widenForDeepLink();
        openCoverageByRef(hit.ref, hit.letter, hit.ll, hit.n);
      });
  }
  let _styleReady=false;   // flipped in the 'load' handler below; render() no-ops until then
  map.on('load',()=>{ _styleReady=true; addSatellite(); addMapillary(); addWaterOsm(); addCoverage();   // heatmap is lazy (W43)
    OSM_BULK.forEach(([key, data, src])=>addOsmDots(key, data, src));
    // renderScopeChips() must wait until here (not right after CCScope.init near
    // the top of the file): it reads `curScope`, a const declared BELOW that call
    // site in this same top-level script, so calling it any earlier hits the TDZ.
    // (`scopeToken` is a hoisted function declaration and would have been fine —
    // curScope alone forces the deferral.) The 'load' handler already runs the
    // one-time initial applyScope, so it is also the natural first chip render.
    // renderScopeChips()/applyScope() order (2026-07-23 flash fix): no longer
    // coupled. scopeLabel() used to resolve by querying the rail button for the
    // incoming scope's data-scope token, so applyScope() needed the chips already
    // in the DOM or it would skip the header/kicker/search-title rewrites and
    // leave the server-rendered fallback text on screen. scopeLabel() now calls
    // CCScope.label() (registry-based, scope.js), so either function may run
    // first — kept in this order anyway to avoid unrelated churn (the chip-anchor
    // logic, CCScope.scopeCenter(), was deliberately made order-independent
    // already; see its own doc comment in scope.js).
    renderScopeChips(); applyScope(curScope(), {fit:false}); setupConfClusters();
    // Reconcile cluster/leaf markers only when the map SETTLES, never on every render frame:
    // querySourceFeatures() + DOM marker diffing across all clustered layers, run per-frame during a
    // flyTo, is what made zooming/flying stutter. MapLibre repositions the existing markers smoothly on
    // its own mid-animation; we only need to add/remove on moveend (motion stops) and idle (tiles loaded).
    let _confRAF=null;
    const scheduleConfMarkers=()=>{ if(_confRAF) return; _confRAF=requestAnimationFrame(()=>{ _confRAF=null; updateConfMarkers(); }); };
    map.on('moveend', scheduleConfMarkers); map.on('idle', scheduleConfMarkers);
    // Coverage counts fetched once at load (and again on each scope change via
    // applyScope). No moveend/idle refresh: the coverage 'shown' is the
    // scope-aware count (covShownCount), not a viewport-render count, so it
    // never changes on pan/zoom.
    if(COVERAGE_ON) fetchCoverageCounts();
    render();
    // Deep links (?feature/?pending/?route) point at a specific object a narrow
    // scope might filter out (region-scoping-design.md §4): widen to Everywhere
    // so the target always renders. Transient — the saved scope returns on the
    // next plain load; the handlers below flyTo the target. ONLY when the target
    // actually resolves (07-20 review finding 9): a stale or mistyped id must
    // not flip the whole map to Everywhere with nothing to show. This gate
    // covers the SYNCHRONOUS resolvers (local feature / pending / route); a
    // coverage-only ?feature resolves async and now (Phase 3, coverage tiles
    // scope-filtered) widens inside openCoverageFeatureByName on its own hit —
    // the resolvers here are exactly the ones the open calls use, so gate and
    // open can never disagree.
    const _dl = new URLSearchParams(location.search);
    const fp=_dl.get('feature'), pp=_dl.get('pending'), rp=_dl.get('route');
    const _dlHit =
      (fp && !!resolveLocalFeature(fp)) ||
      (pp && !!(layerByKey.pending && (layerByKey.pending.features||[]).some(x=>x.pending && String(x.pending.id)===String(pp)))) ||
      (rp && ((layerByKey['experience']||{}).features||[]).some(x=>String(x.id)===String(rp)));
    if(_dlHit) widenForDeepLink();
    // deep-link: ?feature=<name> opens that item's drawer + zooms in (e.g. from
    // a profile page); coverage POIs stay linkable via one search-endpoint
    // lookup when the local index misses (coverage-provider.md §6)
    if(fp && !openFeatureByName(fp)) openCoverageFeatureByName(fp);
    if(pp) openPendingById(pp);
    // ?route=<id> opens a specific route selected (e.g. from the curator Routes desk)
    if(rp) openRouteById(rp);
  });

  // right-click anywhere → show + copy the coordinates (for defining start/end points, add-a-climb, etc.)
  // ONE reusable popup (review W11: a new Popup per contextmenu accumulated in
  // the DOM and repositioned on every map move for the whole session), and the
  // label only claims "copied" when the clipboard write actually resolved
  // (review W37: insecure context / denied permission / unfocused doc all fail).
  let _coordPopup=null;
  map.on('contextmenu', e=>{
    const c = `${e.lngLat.lat.toFixed(6)}, ${e.lngLat.lng.toFixed(6)}`;
    if(!_coordPopup) _coordPopup=new maplibregl.Popup({closeButton:true,className:'pop'});
    const label=ok=>_coordPopup.setHTML(`<div class="pop"><div class="pop-co">Coordinates${ok?' · copied':' — select to copy'}</div>${c}</div>`);
    label(false); _coordPopup.setLngLat(e.lngLat).addTo(map);
    if(navigator.clipboard) navigator.clipboard.writeText(c).then(()=>label(true)).catch(()=>{});
  });

  // curator keyboard: A approve / R reject when a pending drawer is open — plain
  // keys only (never on Ctrl/Cmd/Alt combos, e.g. Ctrl+R reload). Pressing a key
  // ARMS the decision and focuses the note (it no longer submits instantly —
  // the drawer used to close before a note could be typed); Enter inside the
  // note sends the armed decision (Shift+Enter keeps inserting a newline).
  // Mouse clicks on the buttons submit immediately, as before.
  document.addEventListener('keydown', e=>{
    if(e.ctrlKey||e.metaKey||e.altKey) return;
    const t=e.target;
    const box=document.querySelector('#drawer.open .cc-mod'); if(!box) return;
    if(t && (t.tagName==='INPUT'||t.tagName==='TEXTAREA'||t.tagName==='SELECT'||t.isContentEditable)){
      if(e.key==='Enter' && !e.shiftKey && t.classList && t.classList.contains('cc-mod-note')){
        const armed=box.querySelector('.cc-mod-btn.armed');
        if(armed){ e.preventDefault(); armed.click(); }
      }
      return;
    }
    const arm=cls=>{
      const b=box.querySelector('.cc-mod-btn.'+cls); if(!b) return;
      box.querySelectorAll('.cc-mod-btn').forEach(x=>x.classList.toggle('armed', x===b));
      const n=box.querySelector('.cc-mod-note'); if(n) n.focus();
    };
    if(e.key==='a'||e.key==='A'){ e.preventDefault(); arm('approve'); }
    if(e.key==='r'||e.key==='R'){ e.preventDefault(); arm('reject'); }
  });

  // Wikimedia Commons photo helper — builds sm/lg via Special:FilePath (stable, no hash needed)
  // from a verified File name (without the "File:" prefix). user = Commons username for the profile link.
  const wc = (file, credit, user, license) => {
    const enc = encodeURIComponent(file), page = file.replace(/ /g,'_');
    return { sm:`https://commons.wikimedia.org/wiki/Special:FilePath/${enc}?width=520`,
             lg:`https://commons.wikimedia.org/wiki/Special:FilePath/${enc}?width=1400`,
             credit, creditUrl: user ? `https://commons.wikimedia.org/wiki/User:${user.replace(/ /g,'_')}` : '',
             license, source:`https://commons.wikimedia.org/wiki/File:${page}` };
  };
  // One real, verified Ardennes example per catalog type (A–K). geom.ll = [lat,lng].
  // record[] rows render in the detail drawer; omit any attribute we cannot verify.
  const CATALOG = [
    { key:'surface', letter:'A', label:LAYER_L10N.surface||'Road surface', color:'#4E8C84', icon:'▰', kind:'surface', exp:true, features:[] }
    ,{ key:'climbs', letter:'B', label:LAYER_L10N.climbs||'Climbs', color:'#6A2C8F', icon:'⛰', kind:'point', exp:true, features:[] }
    ,{ key:'water', letter:'C', label:LAYER_L10N.water||'Water & food', color:'#8FB6A8', icon:'💧', kind:'point', exp:false, features:[] }
    ,{ key:'services', letter:'D', label:LAYER_L10N.services||'Bike services', color:'#6b6f5e', icon:'⚙', kind:'point', exp:false, features:[] }
    ,{ key:'stays', letter:'E', label:LAYER_L10N.stays||'Where to sleep', color:'#B5532E', icon:'⛺', kind:'point', exp:true, features:[] }
    // F · Hazards — features filled below from window.CC_HAZARDS (the served
    // payload), region-stamped like every letter (region-scoping-design.md §7
    // Task A). The hardcoded demo fixture ("Exposed crosswind · Hautes Fagnes")
    // was retired in the 07-20 review round (rid-less client fixtures have no
    // honest place in a scope-filtered map); it now returns as a real seeded,
    // region-stamped SeedManualCatalogCommand row served through this path.
    ,{ key:'hazards', letter:'F', label:LAYER_L10N.hazards||'Hazards & conditions', color:'#C8923A', icon:'⚠', kind:'point', exp:false, features:[]}
    ,{ key:'transit', letter:'G', label:LAYER_L10N.transit||'Getting there', color:'#3E7D8C', icon:'🚆', kind:'point', exp:false, features:[] }
    ,{ key:'shelter', letter:'H', label:LAYER_L10N.shelter||'Shelter', color:'#9A8FB6', icon:'⛑', kind:'point', exp:false, features:[] }
    ,{ key:'scenic', letter:'I', label:LAYER_L10N.scenic||'Scenic views', color:'#2C5440', icon:'📷', kind:'point', exp:true, features:[] }
    ,{ key:'history', letter:'J', label:LAYER_L10N.history||'History & culture', color:'#6E5849', icon:'🏛', kind:'point', exp:true, features:[] }
    ,{ key:'experience', letter:'K', label:LAYER_L10N.experience||'Recommended routes', color:'#FF5A1F', icon:'★', kind:'line', exp:false, features:[] }
  ];

  const active = new Set(CATALOG.map(l => l.key));   // all layers (incl. K · Recommended routes) on by default
  const layerByKey = Object.fromEntries(CATALOG.map(l => [l.key, l]));

  // Towns referenced by routes — each links to a place on the map + a city info card.
  // ll=[lat,lng]; info is a short blurb (in production auto-found from Wikidata/Wikipedia or user-added).
  const CITIES = {
    'Spa':{ll:[50.4920,5.8636], wiki:'https://en.wikipedia.org/wiki/Spa,_Belgium', info:'The thermal town that gave the word "spa" its name; start of these loops and gateway to Spa-Francorchamps.'},
    'Stavelot':{ll:[50.3957,5.9300], wiki:'https://en.wikipedia.org/wiki/Stavelot', info:'Abbey town grown around its Benedictine abbey (651), at the foot of the Côte de Stockeu.'},
    'Vielsalm':{ll:[50.2833,5.9167], wiki:'https://en.wikipedia.org/wiki/Vielsalm', info:'Ardennes town on the Salm river — gravel and cross-country country.'},
    'Sankt Vith':{ll:[50.2811,6.1267], wiki:'https://en.wikipedia.org/wiki/Sankt_Vith', info:'Hub of the eastern Ardennes, in the German-speaking Community of Belgium.'},
    'Francorchamps':{ll:[50.4350,5.9710], wiki:'https://en.wikipedia.org/wiki/Francorchamps', info:'Village beside the Spa-Francorchamps racing circuit, on the high road south of Spa.'},
    'Coo':{ll:[50.3892,5.8847], wiki:'https://en.wikipedia.org/wiki/Coo,_Belgium', info:'Hamlet of Stavelot known for the Cascade de Coo waterfall and Plopsa Coo park.'},
    'Sart':{ll:[50.5200,5.8800], wiki:'https://en.wikipedia.org/wiki/Jalhay', info:'Sart-lez-Spa, a village of Jalhay on the plateau north of Spa.'},
    'Jalhay':{ll:[50.5560,5.9700], wiki:'https://en.wikipedia.org/wiki/Jalhay', info:'Municipality on the edge of the Hautes Fagnes, by the Gileppe dam.'},
    'Stoumont':{ll:[50.4050,5.8000], wiki:'https://en.wikipedia.org/wiki/Stoumont', info:'Hilly Amblève-valley municipality of steep Ardennes lanes.'},
    'Chevron':{ll:[50.4200,5.7600], wiki:'https://en.wikipedia.org/wiki/Stoumont', info:'Village of Stoumont in the Amblève valley.'},
    'La Gleize':{ll:[50.4150,5.8500], wiki:'https://en.wikipedia.org/wiki/La_Gleize', info:'Amblève-valley village of Stoumont, known for its WWII history (a preserved King Tiger tank).'},
    'Trois-Ponts':{ll:[50.3700,5.8730], wiki:'https://en.wikipedia.org/wiki/Trois-Ponts', info:'"Three bridges" — confluence of the Amblève and Salm, on the LBL roads.'},
    'Tiège':{ll:[50.5300,5.8900], wiki:'https://en.wikipedia.org/wiki/Jalhay', info:'Hamlet of Sart/Jalhay on the plateau above Spa.'},
    // major Wallonia cities (t:'City') — searchable anchors to fly to; far-west ones have no Commons data nearby yet
    'Namur':{t:'City', ll:[50.4674,4.8720], wiki:'https://en.wikipedia.org/wiki/Namur', info:'Capital of Wallonia, where the Sambre meets the Meuse beneath its citadel.'},
    'Liège':{t:'City', ll:[50.6451,5.5736], wiki:'https://en.wikipedia.org/wiki/Li%C3%A8ge', info:'Largest city of eastern Wallonia, on the Meuse — start of Liège–Bastogne–Liège.'},
    'Charleroi':{t:'City', ll:[50.4109,4.4447], wiki:'https://en.wikipedia.org/wiki/Charleroi', info:'Former industrial hub on the Sambre, heart of the Pays Noir.'},
    'Mons':{t:'City', ll:[50.4542,3.9563], wiki:'https://en.wikipedia.org/wiki/Mons', info:'Capital of Hainaut, a UNESCO-listed belfry town.'},
    'Tournai':{t:'City', ll:[50.6071,3.3892], wiki:'https://en.wikipedia.org/wiki/Tournai', info:'Among the oldest cities in Belgium, on the Scheldt near the French border.'},
    'Arlon':{t:'City', ll:[49.6839,5.8113], wiki:'https://en.wikipedia.org/wiki/Arlon', info:'Capital of Luxembourg province, in the far south-east.'},
    'Bastogne':{t:'City', ll:[50.0028,5.7186], wiki:'https://en.wikipedia.org/wiki/Bastogne', info:'Ardennes town famed for the WWII Battle of the Bulge, on the LBL roads.'},
    'Dinant':{t:'City', ll:[50.2605,4.9118], wiki:'https://en.wikipedia.org/wiki/Dinant', info:'Meuse-valley town under a clifftop citadel; birthplace of Adolphe Sax.'},
    'Verviers':{t:'City', ll:[50.5911,5.8625], wiki:'https://en.wikipedia.org/wiki/Verviers', info:'Wool-trade town on the Vesdre, gateway to the Hautes Fagnes.'},
    'Huy':{t:'City', ll:[50.5186,5.2393], wiki:'https://en.wikipedia.org/wiki/Huy', info:'Meuse town below the Mur de Huy, the Flèche Wallonne finish.'},
    'Marche-en-Famenne':{t:'City', ll:[50.2275,5.3450], wiki:'https://en.wikipedia.org/wiki/Marche-en-Famenne', info:'Hub of the Famenne, between the Condroz and the Ardennes.'},
    'La Roche-en-Ardenne':{t:'City', ll:[50.1827,5.5765], wiki:'https://en.wikipedia.org/wiki/La_Roche-en-Ardenne', info:'Castle town in a bend of the Ourthe, deep in the Ardennes.'},
    'Wavre':{t:'City', ll:[50.7173,4.6122], wiki:'https://en.wikipedia.org/wiki/Wavre', info:'Capital of Walloon Brabant, on the Dyle.'},
    'Nivelles':{t:'City', ll:[50.5977,4.3270], wiki:'https://en.wikipedia.org/wiki/Nivelles', info:'Brabant town around its Romanesque collegiate church.'},
    'Malmedy':{t:'City', ll:[50.4259,6.0283], wiki:'https://en.wikipedia.org/wiki/Malmedy', info:'East-cantons town below the Hautes Fagnes, near the Stavelot roads.'}
  };
  // Defense in depth: the name is escaped even though today's callers only
  // pass RIDE_CITIES constants — if a payload value ever reaches this, it
  // must not break out of the attribute or element context.
  const cityLink = name => `<a class="cc-city" data-city="${escPend(name)}">${escPend(name)}</a>`;
  // distance (km) between [lat,lng] points; a feature's representative point for radius search
  function haversine(a,b){ const R=6371,d=Math.PI/180;
    const x=Math.sin((b[0]-a[0])*d/2)**2 + Math.cos(a[0]*d)*Math.cos(b[0]*d)*Math.sin((b[1]-a[1])*d/2)**2;
    return 2*R*Math.asin(Math.sqrt(x)); }
  function featurePoint(f){ return (f.geom&&f.geom.ll) || (f.route&&f.route[0]) || (f.geom&&f.geom.path&&f.geom.path[0]) || null; }
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
  function buildItemIndex(){
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
      push({name:f.name, unnamed:f.unnamed, key:slug(f.name+' '+(layer.label||'')), kind:layer.label||'', badge:layer.letter||'•',
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
      push({name:p.n, key:slug(p.n+' '+(p.town||'')+' '+layer.label), kind:layer.label, badge:layer.letter,
        color:layer.color, letter:layer.letter, ll:[+c[1],+c[0]], id:p.id,
        rid:p.rid,   // pivot stays carry rid via catalog-load's property merge (finding 3)
        verified:!!p.v,   // v = real state/confirmation signal from CatalogProvider
        hlOff: p.v ? [0,-16] : [0,0],   // pin offset keys on the real promotion signal (c is dead, see the re-key step)
        go:()=>openStayPivot(f)});
    });
    return out;
  }
  // Deliberately scope-EXEMPT (07-20 review finding 3, owner decision):
  // opening a town card is an explicit location choice, so its nearby list
  // shows what is physically there regardless of the active scope — unlike
  // text search, which filters to scope (runS). Keep this asymmetry.
  function nearbyItems(ll, km){
    const out=[];
    ITEM_INDEX.forEach(e=>{ if(!e.ll) return; const dist=haversine(ll, e.ll); if(dist<=km) out.push({e, dist}); });
    return out.sort((a,b)=>a.dist-b.dist);
  }
  // privacy: drop the first & last 350–750 m of a contributed ride (kills home/start fingerprints).
  // startM/endM in metres; haversine() returns km, so compare against m/1000.
  function trimEnds(loop, startM, endM){
    let i=0,d=0; while(i<loop.length-2 && d<startM/1000){ d+=haversine(loop[i],loop[i+1]); i++; }
    let j=loop.length-1,e=0; while(j>i+1 && e<endM/1000){ e+=haversine(loop[j],loop[j-1]); j--; }
    return loop.slice(i, j+1);
  }
  // populate K · Recommended routes with every uploaded sample route + its cyclist-experience attributes
  // C1-T4 (W6): CC_CLIMBS' 'source' field is the free-text citation ('OSM roads ·
  // geometry handmade', etc.); srcType is the real ItemSource value. A rider-
  // added/edited climb (user/manual) must not keep an OSM-flavoured citation —
  // swap the cc-d-src line to the plain rider-contributed label for those only.
  if(window.CC_CLIMBS){
    const climbSrc = CC_CLIMBS.map(c => (c.srcType==='user'||c.srcType==='manual')
      ? Object.assign({}, c, {source: sourceLabel(c.srcType)}) : c);
    layerByKey['climbs'].features = layerByKey['climbs'].features.concat(climbSrc);
  }
  if(window.CC_ROUTES){
    // towns each ride starts at / passes — lets riders search routes by start location (demo lookup)
    const RIDE_CITIES={
      'Spa · Sankt Vith':['Spa','Stavelot','Vielsalm','Sankt Vith'],
      'Spa · Coo · Francorchamps':['Spa','Francorchamps','Coo','Stavelot'],
      'Spa · Côte des Hézalles':['Spa','Sart','Jalhay'],
      'Rondje Spa–Chevron':['Spa','Stoumont','Chevron','La Gleize'],
      'Rondje Super Stockeu':['Spa','Stavelot','Coo','Trois-Ponts'],
      'Afternoon Ride':['Spa','Sart','Tiège']
    };
    layerByKey['experience'].features = CC_ROUTES.routes.map((r,i)=>{
      // Demo-era lookup keyed by ride name. NO fallback: fabricating
      // 'Starts at: Spa' for unknown routes (e.g. rider proposals) is wrong
      // data — reverse-geocoding real towns is a recorded route-domain
      // non-goal, so unknown routes simply omit the town rows.
      const cities = RIDE_CITIES[r.name];
      // Seeded from r.id (not the array index i): located corrections store
      // fractions relative to this trimmed path, so the trim must stay
      // deterministic per route even when the served route set changes
      // (e.g. another route rejected shifts indices) — an index-seeded trim
      // would re-trim the same route differently and drift stored fractions.
      const seed = Number(r.id)||0;
      const startM = 350 + (seed*137)%401, endM = 350 + (seed*211+90)%401;   // 350–750 m, varied but stable per ride
      // difficulty is always {score,label} now (P2-D1); typeof fallback is defensive only.
      const diffLabel = r.difficulty?.label ?? (typeof r.difficulty === 'string' ? r.difficulty : undefined);
      return {
      id:r.id, rid:r.rid, name:r.name, state:r.state, headline:`${r.km} km${diffLabel ? ' · ' + trVal(diffLabel) : ''}`, cur:false, edit:'ride',
      geom:{path:trimEnds(r.loop, startM, endM)}, elev:r.elev, gain:r.gain, difficulty:r.difficulty, uploader:r.uploader,
      cities: cities || [],                                // searchable start/through towns (empty when unknown)
      bikeTypes: Array.isArray(r.bikeTypes) ? r.bikeTypes : [],   // declared suitability (may be empty = undeclared)
      photo:r.photo||wc('Liège-Bastogne-Liège 2014 Echappée du jour Côte de Wanne.JPG','Les Meloures','Les Meloures','CC BY-SA 3.0'),
      // C1-T4 (W6): 'Contributed GPX' is an accurate detail for the pipeline's
      // usual auto-derived routes; a rider-added/edited one gets the plain label.
      source:(r.srcType==='user'||r.srcType==='manual') ? sourceLabel(r.srcType) : (D.contributedGpx||'Contributed GPX (GPS track only)'),
      // C2-T7 (spec §W2): every row below is a real QualityRides registry
      // attribute (CatalogFormRegistry::for(QualityRides), forwarded by
      // CatalogProvider::routes()) — the previous Quietness/Scenic
      // rating/Cycling-friendliness/Suitable bikes/Accessibility/Best direction
      // rows were index-derived formulas or literals identical for every ride
      // (the same class of bug C2-T6 fixed for climbs' "Bike type"/"Handbike"
      // filler) — deleted; only present when a rider (or import) actually set
      // the attribute.
      record:(()=>{
        const rec=[
          {label:D.distance||'Distance', value:r.km+' km'}
        ];
        // Phase-2 badge: a proposed route (unverified) rides "ride it to verify";
        // a verified route renders normally. state is served by CatalogProvider.
        if(r.state === 'unverified') rec.unshift({label:D.status||'Status', value:D.proposedVerify||'Proposed · ride it to verify', warn:true});
        if(cities){
          // html:true — builder-constructed markup from the constant
          // RIDE_CITIES table (cityLink escapes the name); NEVER set this
          // flag on payload-derived values.
          rec.push({label:D.startsAt||'Starts at', value:cityLink(cities[0]), html:true});
          rec.push({label:D.townsOnRoute||'Towns on route', value:cities.map(cityLink).join(' · '), html:true});
        }
        // Derived, not declared: measured against the A-layer mapped-road
        // segments at import/intake (SurfaceProfiler). The method note
        // discloses estimate + coverage — never present this as ground truth.
        // Kept hand-authored (it is not a registry field; K's declared field is
        // 'dominantSurface', rendered by the schema below).
        if(r.surfaces && Array.isArray(r.surfaces.parts) && r.surfaces.parts.length){
          rec.push({label:D.surfaces||'Surfaces', value:r.surfaces.parts.map(p=>`${trVal(p.surface)} ${p.pct}%`).join(' · '),
                    method:tpl(D.estimateMethod||'estimate · {pct}% of route mapped', {pct:Number(r.surfaces.covered)||0})});
        }
        // Registry-driven (CC_FIELD_SCHEMA[K]): season / dominantSurface /
        // quietness / scenic / friendliness / bikeTypes / gradientLimited /
        // bestDirection / note — value or "add" prompt. 'difficulty' is skipped
        // (rendered as the cc-diff badge); 'rideName' is display:false.
        rec.push(...schemaRows('K', r, r.id, {skip:['difficulty']}));
        return rec;
      })()
    };
    });
  }
  // populate A · Road surface from the hand-picked OSM segments
  if(window.CC_SURFACE){
    layerByKey['surface'].features = CC_SURFACE.segments.map(s=>{
      // Registry-driven rows (CC_FIELD_SCHEMA[A]) — value or "add" prompt per field.
      // Fields: surface / smoothness / width / traffic / note / lit /
      // segregated / seasonalClosure. Per-row OSM provenance now lives only
      // on the Source line.
      const rec = schemaRows('A', s, s.id);
      return {
        id:s.id, rid:s.rid, name:s.name, headline:`${trVal(s.surface)} · ${trVal(s.smoothness)}`, cur:(s.cls!=='paved'), edit:'road-surface',
        geom:{path:s.path}, surfaceClass:s.cls, width:s.width,
        photo: s.photoFile ? wc(s.photoFile, s.photoCredit, s.photoUser, s.photoLicense) : undefined,
        // C1-T4 (W6): a rider-added/edited surface segment isn't OSM.
        source:(s.srcType==='user'||s.srcType==='manual') ? sourceLabel(s.srcType) : 'OSM (surface=*)',
        record:rec
      };
    });
  }
  // F · Hazards & conditions — served items (region-scoping-design.md §7 Task A).
  // Hazards have no coverage tile layer and no OSM bulk pool, so they render as
  // CATALOG point features (like climbs), sourced from the served payload
  // (CatalogProvider 'F' key -> window.CC_HAZARDS). Region stamping is automatic
  // (item rows; recomputeMembership), so f.rid flows through featureVisible()'s
  // scope gate with zero extra work. The drawer's registry rows / confirm panel
  // / edit-bridge all key on f.id + schemaRows('F', …), same as every letter.
  if(window.CC_HAZARDS && Array.isArray(CC_HAZARDS.features)){
    layerByKey['hazards'].features = CC_HAZARDS.features.map(ft=>{
      const p=ft.properties||{}, c=(ft.geometry&&ft.geometry.coordinates)||[];
      // headline: localized hazard type + severity when the item carries them
      // (schema choices localize the stored English via trVal/VALUE_TR).
      const bits=[p.hazardType, p.severity].filter(Boolean).map(trVal);
      const named=!!p.n;
      // photo may arrive as a JSON string (importable attribute) — parse it with
      // the same guard osmDrawer uses, else a raw string flows unparsed into
      // photoList()/the <img> sink (finding 14).
      let photo=p.photo; if(typeof photo==='string'){ try{ photo=JSON.parse(photo); }catch(e){ photo=null; } }
      return {
        // Real name when the item carries one; otherwise the layer label for
        // DISPLAY only, flagged `unnamed` so the index neither name-dedupes
        // nameless hazards (two potholes 50 m apart → one dropped) nor routes
        // them by the shared label (last-match-wins onto the wrong pin) — finding 9.
        id:p.id, rid:p.rid, name:p.n||(LAYER_L10N.hazards||'Hazard'), unnamed:!named,
        // Only append the layer label as a headline when there's no type/severity
        // AND no real name would already carry it, so a bare hazard never reads
        // "Hazards & conditions · Hazards & conditions" (finding 9, cosmetic).
        headline:bits.join(' · ')||(named?(LAYER_L10N.hazards||'Hazards & conditions'):''),
        // A rider-confirmed hazard (v) earns the same verified tier as a C/D/G/H
        // confirmed twin, not a pixel-identical unconfirmed pin (finding 13).
        cur:!!p.v, geom:{ll:[c[1], c[0]]},
        // Registry-driven record (CC_FIELD_SCHEMA[F]) — filled rows + "add" prompts.
        record:schemaRows('F', p, p.id),
        photo:photo,
        // srcType drives the source line: an osm-sourced row keeps the OSM label
        // (its ODbL linkifier + attribution), user/manual reads rider-contributed,
        // and only a genuinely source-less row falls back to "Community report"
        // (finding 12 — the old ternary made osm unreachable AND would have
        // dropped OSM attribution by labelling it a community report).
        source:sourceLabel(p.srcType) || (D.communityReport||'Community report'),
        v:p.v
      };
    });
  }
  // Curator-only pending submissions (injected by MapController for ROLE_CURATOR only).
  // Off the public map by design — riders never receive window.CC_PENDING.
  if(window.CC_IS_CURATOR && Array.isArray(window.CC_PENDING)){
    const pf = window.CC_PENDING.map(s=>({
      name:s.title, headline:`${(I18N.pendingTypes||{})[s.type]||s.type} · ${s.who} · ${s.when}`,
      geom:{ll:[s.lat, s.lng]},
      record:[
        {label:D.submittedBy||'Submitted by', value:s.who},
        {label:D.age||'Age', value:s.when},
        {label:D.where||'Where', value:`${s.region||''} · ${s.country||''}`}
      ],
      source:'Pending submission · preview',
      pending:s
    }));
    const pendingLayer = { key:'pending', letter:'⚑', label:LAYER_L10N.pending||'Pending review', color:'#D92D20', icon:'⏳', kind:'point', exp:false, pendingLayer:true, features:pf };
    CATALOG.push(pendingLayer);
    layerByKey['pending'] = pendingLayer;
    active.add('pending');
  }
  let mode = 'curated';
  let markers = [];
  const dynamicIds=[];
  const boundLayerIds=new Set();   // delegated click/hover handlers are attached once per id
  function clearDynamic(){
    // remove casing layers first (they share the base source id), then base layer + source
    dynamicIds.forEach(id=>{ const c=id+'-case'; if(map.getLayer(c)) map.removeLayer(c); });
    dynamicIds.forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); if(map.getSource(id)) map.removeSource(id); });
    dynamicIds.length=0;
  }
  // draw a polyline with a light casing so it stays visible over the tinted basemap
  const gradColor=p=> p<5?'#D9A6F2':p<8?'#B25BE8':p<12?'#8A2BD0':p<16?'#5E18A0':'#3A0A66';   // purple, wide light→dark range
  // difficulty scale 1–5 — same light→dark purple ramp as the climb gradient, so it reads as "climb-coloured"
  const DIFF_LABELS=['','Easy','Moderate','Challenging','Hard','Very hard'].map(l=>l?trVal(l):l);
  const DIFF_PURPLE=['','#D9A6F2','#B25BE8','#8A2BD0','#5E18A0','#3A0A66'];
  // slug for edit-registry ids — must match the keys authored in edit-items.js (diacritics stripped)
  const slug = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
  // map a licence label to its canonical deed URL (the image source itself links separately)
  const ccUrl = lic => ({
    'CC0':'https://creativecommons.org/publicdomain/zero/1.0/',
    'Public domain':'https://en.wikipedia.org/wiki/Public_domain',
    'CC BY 4.0':'https://creativecommons.org/licenses/by/4.0/',
    'CC BY 3.0':'https://creativecommons.org/licenses/by/3.0/',
    'CC BY 2.0':'https://creativecommons.org/licenses/by/2.0/',
    'CC BY-SA 4.0':'https://creativecommons.org/licenses/by-sa/4.0/',
    'CC BY-SA 3.0':'https://creativecommons.org/licenses/by-sa/3.0/',
    'CC BY-SA 3.0 lu':'https://creativecommons.org/licenses/by-sa/3.0/lu/',
    'CC BY-SA 2.5':'https://creativecommons.org/licenses/by-sa/2.5/',
    'CC BY-SA 2.0':'https://creativecommons.org/licenses/by-sa/2.0/'
  }[lic] || 'https://commons.wikimedia.org/wiki/Commons:Licensing');
  // readable text colour on a coloured chip: white on dark backgrounds (e.g. purple), ink on light
  function txtOn(hex){
    const h=hex.replace('#',''); const r=parseInt(h.slice(0,2),16),g=parseInt(h.slice(2,4),16),b=parseInt(h.slice(4,6),16);
    return (0.299*r+0.587*g+0.114*b)/255 < 0.58 ? '#fff' : '#101E16';
  }
  // climb line coloured by gradient (line-gradient over the route)
  function drawClimbLine(id, latlngs, grad, layer, f){
    if(!map.getSource(id)) map.addSource(id,{type:'geojson',lineMetrics:true,data:{type:'Feature',properties:{},
      geometry:{type:'LineString',coordinates:latlngs.map(p=>[p[1],p[0]])}}});
    const caseW=['interpolate',['linear'],['zoom'],9,7,13,11,16,17];
    const lineW=['interpolate',['linear'],['zoom'],9,4.5,13,7,16,12];
    if(!map.getLayer(id+'-case')) map.addLayer({id:id+'-case',type:'line',source:id,
      layout:{'line-cap':'round','line-join':'round'},
      paint:{'line-color':'#FBF4E4','line-width':caseW,'line-opacity':.95}});
    const expr=['interpolate',['linear'],['line-progress']];
    const n=grad.length;
    for(let i=0;i<n;i++){ expr.push(i/(n-1)); expr.push(gradColor(grad[i])); }
    if(!map.getLayer(id)) map.addLayer({id,type:'line',source:id,
      layout:{'line-cap':'round','line-join':'round'},
      paint:{'line-width':lineW,'line-gradient':expr}});
    dynamicIds.push(id);
    if(!boundLayerIds.has(id)){
      map.on('click',id,()=>openDrawer(layer,f));
      map.on('mouseenter',id,()=>map.getCanvas().style.cursor='pointer');
      map.on('mouseleave',id,()=>map.getCanvas().style.cursor='');
      boundLayerIds.add(id);
    }
  }
  // K route selection styling: the selected route gets the full brand orange
  // and a slightly wider line; every sibling route dims so the selection is
  // unmistakable. Cleared when the drawer closes or a non-route feature opens.
  const ROUTE_BASE_COLOR='#FD986E';   // 60% #FF5A1F pre-blended over #FBF4E4
  const ROUTE_BASE_W=5, ROUTE_BASE_CASE_W=9;
  // A SELECTED route reads as a wide orange halo that the road-surface line
  // (3→8 px by zoom, over an 8 px cream case) sits ON TOP of — so you see the
  // highlight AND the surface on it. Kept comfortably wider than the surface's
  // 8 px case at every zoom so the orange shows on both sides of the surface line.
  const ROUTE_SEL_W=['interpolate',['linear'],['zoom'],9,8,13,13,16,17];
  const ROUTE_SEL_CASE_W=['interpolate',['linear'],['zoom'],9,12,13,18,16,23];
  let selectedRouteLayerId=null;
  const routeLineIds=()=>map.getStyle().layers.map(l=>l.id).filter(id=>/^experience-\d+$/.test(id));
  // Climb lines, road-surface indications and Mapillary always render ABOVE
  // route lines (the draw-time stacking rule) — re-applied after any
  // selection moveLayer so highlighting a route never buries the A-layer
  // surface colours under an opaque ride line.
  function liftInfoLayersAboveRoutes(){
    const liftGroup=id=>{ if(map.getLayer(id+'-case')) map.moveLayer(id+'-case'); if(map.getLayer(id)) map.moveLayer(id); };
    dynamicIds.filter(id=>id.startsWith('route-climbs-')).forEach(liftGroup);
    // consolidated A-layer (C3): one shared casing + one layer per surface class
    if(map.getLayer('surface-case')) map.moveLayer('surface-case');
    surfaceClsLayerIds().forEach(id=>{ if(map.getLayer(id)) map.moveLayer(id); });
    ['mly-cov','mly-img'].forEach(id=>{ if(map.getLayer(id)) map.moveLayer(id); });
  }
  function highlightRoute(selId){
    selectedRouteLayerId=selId;
    routeLineIds().forEach(id=>{
      const on=id===selId;
      map.setPaintProperty(id,'line-color',on?'#FF5A1F':ROUTE_BASE_COLOR);
      map.setPaintProperty(id,'line-opacity',on?1:0.4);
      map.setPaintProperty(id,'line-width',on?ROUTE_SEL_W:ROUTE_BASE_W);
      if(map.getLayer(id+'-case')){
        map.setPaintProperty(id+'-case','line-opacity',on?0.95:0.35);
        map.setPaintProperty(id+'-case','line-width',on?ROUTE_SEL_CASE_W:ROUTE_BASE_CASE_W);
      }
    });
    // Lift the SELECTED route (a wide orange halo) above its dimmed siblings,
    // then put the info layers (climbs / surface / Mapillary) back on top — the
    // surface line is narrower than the halo, so it sits ON the selected route
    // and you see the highlight AND the surfaces together.
    if(map.getLayer(selId+'-case')) map.moveLayer(selId+'-case');
    if(map.getLayer(selId)) map.moveLayer(selId);
    liftInfoLayersAboveRoutes();
  }
  function clearRouteHighlight(){
    if(selectedRouteLayerId===null) return;
    selectedRouteLayerId=null;
    routeLineIds().forEach(id=>{
      map.setPaintProperty(id,'line-color',ROUTE_BASE_COLOR);
      map.setPaintProperty(id,'line-opacity',1);
      map.setPaintProperty(id,'line-width',ROUTE_BASE_W);
      if(map.getLayer(id+'-case')){
        map.setPaintProperty(id+'-case','line-opacity',0.95);
        map.setPaintProperty(id+'-case','line-width',ROUTE_BASE_CASE_W);
      }
    });
  }
  // --- Located-correction geometry (spec §16 S4). Path is [lat,lng] points. ---
  function _cumLen(path){ // cumulative planar length per vertex + total (deg is fine at this scale)
    const cum=[0]; for(let i=1;i<path.length;i++){ const dx=path[i][1]-path[i-1][1], dy=path[i][0]-path[i-1][0]; cum.push(cum[i-1]+Math.hypot(dx,dy)); } return cum;
  }
  // nearest point on the polyline to click [lat,lng] → {frac, at:[lat,lng]}
  function nearestOnPath(path, click){
    const cum=_cumLen(path), total=cum[cum.length-1]||1; let best=null;
    for(let i=1;i<path.length;i++){
      const ax=path[i-1][1], ay=path[i-1][0], bx=path[i][1], by=path[i][0];
      const dx=bx-ax, dy=by-ay, len2=dx*dx+dy*dy||1e-12;
      let t=((click[1]-ax)*dx+(click[0]-ay)*dy)/len2; t=Math.max(0,Math.min(1,t));
      const px=ax+t*dx, py=ay+t*dy, d2=(click[1]-px)**2+(click[0]-py)**2;
      if(!best||d2<best.d2){ best={d2, at:[py,px], frac:(cum[i-1]+t*Math.hypot(dx,dy))/total}; }
    }
    return best;
  }
  function fracToLatLng(path, frac){
    const cum=_cumLen(path), total=cum[cum.length-1]||1, target=frac*total;
    for(let i=1;i<path.length;i++){ if(cum[i]>=target){ const seg=cum[i]-cum[i-1]||1e-12, t=(target-cum[i-1])/seg;
      return [path[i-1][0]+t*(path[i][0]-path[i-1][0]), path[i-1][1]+t*(path[i][1]-path[i-1][1])]; } }
    return path[path.length-1];
  }
  // slice the path between two fractions → [lat,lng] sub-path (for the highlighted stretch)
  function sliceByFrac(path, a, b){
    if(a>b){ const t=a; a=b; b=t; }
    const out=[fracToLatLng(path,a)]; const cum=_cumLen(path), total=cum[cum.length-1]||1;
    for(let i=0;i<path.length;i++){ const f=cum[i]/total; if(f>a && f<b) out.push(path[i]); }
    out.push(fracToLatLng(path,b)); return out;
  }
  function drawLine(id, latlngs, color, layer, f){
    if(!map.getSource(id)) map.addSource(id,{type:'geojson',data:{type:'Feature',properties:{},
      geometry:{type:'LineString',coordinates:latlngs.map(p=>[p[1],p[0]])}}});
    if(!map.getLayer(id+'-case')) map.addLayer({id:id+'-case',type:'line',source:id,
      layout:{'line-cap':'round','line-join':'round'},
      paint:{'line-color':'#FBF4E4','line-width':9,'line-opacity':.95}});
    if(!map.getLayer(id)) map.addLayer({id,type:'line',source:id,
      layout:{'line-cap':'round','line-join':'round'},
      // Routes render in a PRE-BLENDED lighter orange at full opacity (60%
      // brand #FF5A1F over the cream basemap) instead of a translucent line:
      // translucent lines stacked wherever routes share a road, making some
      // segments read darker orange than others. Selection styling (full
      // brand color + dimmed siblings) lives in highlightRoute().
      paint:{'line-color':layer.key==='experience'?ROUTE_BASE_COLOR:color,'line-width':5,'line-opacity':1}});
    dynamicIds.push(id);
    if(!boundLayerIds.has(id)){
      map.on('click',id,()=>openDrawer(layer,f));
      map.on('mouseenter',id,()=>map.getCanvas().style.cursor='pointer');
      map.on('mouseleave',id,()=>map.getCanvas().style.cursor='');
      boundLayerIds.add(id);
    }
  }
  // A · Road surface — colour + pattern by surface class (solid paved · dashed gravel · dotted pavé)
  const SURFACE_STYLE={
    cycleway:{color:'#3E9C8A'},                        // smooth RAVeL asphalt — solid teal
    paved:{color:'#4E6E66'},                           // asphalt/concrete — solid slate
    gravel:{color:'#C8923A',dash:[2,1.5],cap:'butt'},  // gravel/compacted — dashed ochre
    pave:{color:'#6E7B96',dash:[1,1.5],cap:'butt'},    // sett/cobbles (pavé) — square slate-grey dashes (matches the legend; distinct from brown ground)
    dirt:{color:'#6E5849',dash:[2,1.5],cap:'butt'},    // dirt — dashed brown
    rock:{color:'#5F5A54',dash:[1,2],cap:'butt'},      // rock — rough technical, dark grey dots
    unverified:{color:'#D92D20',dash:[2.5,2.5],cap:'butt'} // OSM has no surface tag — red dashes over the white casing ("needs a tag")
  };
  const surfaceStyle=cls=>SURFACE_STYLE[cls]||{color:'#4E8C84'};
  // Consolidated A-layer rendering (frontend review 2026-07-12 C3+C4): ONE
  // GeoJSON source for ALL segments + one shared casing layer + one line layer
  // per surface class (dash/cap can't vary per feature within a layer), instead
  // of a source and two layers PER SEGMENT (~350 sources / ~700 layers, each an
  // individual draw call) with four listeners each (~1400 hit-tests per pointer
  // move). Re-renders are a single setData; listeners bind once per class layer
  // and resolve the clicked feature via properties.idx.
  const SURFACE_CLS=Object.keys(SURFACE_STYLE).concat('other');   // 'other' = unknown class → default solid teal
  const surfaceClsLayerIds=()=>SURFACE_CLS.map(c=>'surface-cls-'+c);
  function renderSurfaceLayer(layer, visible){
    const feats=[];
    if(visible) layer.features.forEach((f,i)=>{
      if(!((mode==='all')||!layer.exp||f.cur)) return;   // same visibility rule as featureVisible()
      if(!inScope(f.rid)) return;                        // region scope gate (region-scoping-design.md §4)
      feats.push({type:'Feature',
        properties:{idx:i, cls:SURFACE_STYLE[f.surfaceClass]?f.surfaceClass:'other'},
        geometry:{type:'LineString',coordinates:f.geom.path.map(p=>[p[1],p[0]])}});
    });
    const data={type:'FeatureCollection',features:feats};
    if(map.getSource('surface-src')){ map.getSource('surface-src').setData(data); return feats.length; }
    map.addSource('surface-src',{type:'geojson',data});
    map.addLayer({id:'surface-case',type:'line',source:'surface-src',
      layout:{'line-cap':'round','line-join':'round'},
      paint:{'line-color':'#FBF4E4','line-width':8,'line-opacity':.9}});
    const w=['interpolate',['linear'],['zoom'],9,3,13,5,16,8];
    const featAt=e=>layer.features[e.features[0].properties.idx];
    SURFACE_CLS.forEach(cls=>{
      const st=surfaceStyle(cls), id='surface-cls-'+cls;
      const paint={'line-color':st.color,'line-width':w,'line-opacity':1};
      if(st.dash) paint['line-dasharray']=st.dash;
      map.addLayer({id,type:'line',source:'surface-src',
        filter:['==',['get','cls'],cls],
        layout:{'line-cap':st.cap||'round','line-join':'round'},paint});
      map.on('click',id,e=>{ const f=featAt(e); if(f) openDrawer(layer,f); });
      map.on('mouseenter',id,()=>map.getCanvas().style.cursor='pointer');
      map.on('mousemove',id,e=>{ const f=featAt(e); if(f) showTip(f.headline||f.name, e.lngLat); });   // surface type (e.g. "Asphalt · Excellent") on hover
      map.on('mouseleave',id,()=>{ map.getCanvas().style.cursor=''; hideTip(); });
    });
    return feats.length;
  }
  const chipSet=id=>{const s=new Set();document.querySelectorAll('#'+id+' .chip.on').forEach(c=>s.add(c.dataset.v));return s;};
  let activeSurface=chipSet('sqf'), activeTraffic=chipSet('trf');
  // C2-T8 (spec §W2, D2): filters for the new difficulty/suitability attributes —
  // climbs' 'effort' (CatalogFormRegistry Climbs.effort) and stays' 'accessibility'
  // (CatalogFormRegistry WhereToSleep.accessibility). Vocab lists mirror the registry.
  const ALL_EFFORT=new Set(['Steady','Challenging','Tough','Very steep']);
  const ALL_ACCESS=new Set(['Step-free access','Handbike-friendly','Wheelchair-accessible']);
  // "Narrowing" semantics, deliberately different from the pre-existing sq/tr chips above
  // (which always require a matching value, hiding any climb missing sq/tr regardless of
  // chip state): most existing items predate effort/accessibility, so with every chip on
  // (the default) nothing is filtered — including items with no value for the attribute.
  // As soon as a rider deselects at least one option, items with no value are hidden too,
  // since they can't be confirmed to match the narrowed selection.
  function attrMatch(value, activeSet, allSet){
    if(activeSet.size===allSet.size) return true;
    return value ? activeSet.has(value) : false;
  }
  let activeEffort=chipSet('effortf'), activeAccess=chipSet('accessf');
  // stays' coverage tile layer narrows on the accessibility filter above;
  // confirmed/clustered stays are filtered in updateConfMarkers().
  function applyStaysAccessFilter(){
    // Coverage stays' individual icons narrow on the flat `acc` tile prop
    // (coverage-provider.md §6) — the dedupe + region-scope arms (covIconFilter)
    // are the base and must survive every setFilter. The acc narrow composes
    // over the single coverage icon layer only (no cluster bubbles exist for
    // coverage, per 2026-07-24-coverage-no-cluster-design.md §2).
    const extra = activeAccess.size===ALL_ACCESS.size ? null
      : ['in', ['get','acc'], ['literal', Array.from(activeAccess)]];
    // stays split per country (Task 4): narrow every stays-<cc>-cov icon layer,
    // not just a single hardcoded id. cc===null is the pre-split 'stays-cov' id.
    COVERAGE_CCS.forEach(cc=>{
      const id = cc ? 'stays-'+cc+'-cov' : 'stays-cov';
      if(map.getLayer(id)) map.setFilter(id, covIconFilter(extra));
    });
  }

  // D · services confirmed/curated pins show the per-kind glyph (shop/station/pump) instead of
  // the layer's generic icon; every other layer (and services items with no/unknown serviceKind)
  // keeps layer.icon exactly as before. props is the feature/properties object carrying serviceKind
  // (GeoJSON properties for OSM-sourced points, the plain feature object for CATALOG-authored ones).
  function pinGlyph(layer, props){
    if(layer.key==='services' && props && props.serviceKind) return SERVICE_GLYPH[props.serviceKind] || layer.icon;
    return layer.icon;
  }
  function pinEl(layer,cur,props){
    const d=document.createElement('div');
    d.className='cc-pin'+(cur?' cur':'')+(layer.pendingLayer?' pending':''); d.style.setProperty('--c',layer.color);
    const white = txtOn(layer.color)==='#fff';   // dark pins (e.g. purple climbs) → white icon
    d.innerHTML=`<span${white?' style="filter:brightness(0) invert(1)"':''}>${pinGlyph(layer, props)}</span>`; return d;
  }
  // Preference prefilter (spec 2026-07-14): riders' saved bike types filter
  // the routes layer. Unknown ≠ unsuitable — a route with NO declared
  // bikeTypes stays visible; only a declared non-overlap hides it. Off for
  // anonymous visitors (PREFS.bikes empty) and toggleable via the rail chip
  // (#prefFilter), persisted in localStorage.
  let prefFilterOn = PREFS.bikes.length>0 && (localStorage.getItem('cc-pref-filter')||'on')==='on';
  function prefMatch(f){
    if(!prefFilterOn || !PREFS.bikes.length) return true;
    if(!f.bikeTypes || !f.bikeTypes.length) return true;          // undeclared → keep
    return f.bikeTypes.some(t=>PREFS.bikes.includes(t));
  }
  function featureVisible(layer, f){
    let show = layer.key==='experience' ? (mode==='all'||f.cur) : ((mode==='all') || !layer.exp || f.cur);       // experiential layers filter to curated; K honours cur in Curated (best-of), all in Everything
    if(show) show = inScope(f.rid);   // region scope gate (region-scoping-design.md §4)
    if(show && layer.key==='experience') show = prefMatch(f);
    if(show && layer.key==='climbs'){
      show = activeSurface.has(f.sq) && activeTraffic.has(f.tr);
      if(show) show = attrMatch(f.effort, activeEffort, ALL_EFFORT);
    }
    return show;
  }
  // Rail totals (coverage-provider.md §5):
  // per-letter coverage counts from /map/coverage/counts, re-fetched on each
  // scope change (Phase 3: scope-aware, region-scoping-design.md §7). This one
  // scoped count drives BOTH the 'shown' and 'total' sides for a coverage layer
  // (see covShownCount).
  let _covCounts=null, _covCountReq=0;
  function fetchCoverageCounts(){
    if(!COVERAGE_ON) return;
    ++_covCountReq;   // invalidate any earlier in-flight scope's response, fetched or not
    if(covScopeIsZero()){
      // myArea derived to zero regions (map-and-search.md §4.5): skip the
      // request entirely — an unscoped fetch would silently return GLOBAL
      // counts — and zero the rail instead.
      _covCounts=null; updateCounts(); return;
    }
    const q=covScopeQuery(), myReq=_covCountReq;
    fetch('/map/coverage/counts'+(q?('?'+q):''), {headers:{'Accept':'application/json'}})
      .then(r=>r.ok?r.json():null)
      .catch(()=>null)
      // Race-guard: a slow scoped-counts response must not overwrite a newer
      // scope's totals (rapid rail switching). Same _historyReq discipline. On a
      // FAILED fetch for the current scope, clear the counts rather than leave the
      // PREVIOUS scope's numbers on the rail while the dots already re-filtered to
      // the new scope (finding 16 — an offline mismatch that self-heals on the
      // next success); an honest blank beats a wrong-scope total.
      .then(d=>{ if(myReq===_covCountReq){ _covCounts=(d && d.counts) || null; updateCounts(); } });
  }
  // "Shown" for a coverage layer = its in-scope count (the scope-aware
  // /counts value), NOT the handful of tiles rendered in the current viewport.
  // Coverage is a dense vector-tile layer clustered at low zoom (and, before
  // clustering, point-thinned), so a viewport-render count read a confusing
  // near-zero at the region/country overview zooms the scope selector fits to
  // (All Belgium at ~z8 showed "16/2015", even "0/2015" on a slightly different
  // frame). Every in-scope POI IS on the map — revealed progressively as you
  // zoom — so the whole scope counts as shown, mirroring the served
  // layers (A shows 351/351). 0 when the layer is toggled off or mode-hidden
  // (experiential coverage E/I/J in Curated), which the visibility gate covers.
  function covShownCount(key){
    // _covCounts is a per-LETTER scope-aware aggregate (server-side), so it is
    // NOT summed per country — the whole letter's in-scope count is "shown" as
    // soon as any of its per-country layers is drawn. Visibility is uniform
    // across a letter's countries (syncCoverageLayers), so "any visible" gates it.
    if(!COVERAGE_ON) return 0;
    const drawn = COVERAGE_CCS.some(cc=>{
      const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
      return map.getLayer(id) && map.getLayoutProperty(id,'visibility')==='visible';
    });
    if(!drawn) return 0;
    return (_covCounts && _covCounts[KEY_LETTER[key]]) || 0;
  }
  // legend count = shown/total: in Curated only confirmed/curated count; in Everything everything does
  function layerCounts(layer){
    const covTotal=(_covCounts && _covCounts[layer.letter])||0;
    // BOTH tiers' totals are scope-aware: coverage covTotal is a server-side
    // per-scope count, and the curated features are gated to the active scope
    // (inScope) too — so an out-of-scope region reads 0/0, not 0/<global>. Without
    // this the curated total is the whole in-memory set: e.g. Wallonia's 351
    // surfaces / 15 climbs leaked into Gelderland's rail as "0/351", "0/15" even
    // though none are in Gelderland (owner-reported 2026-07-24). Everywhere still
    // shows the global total (inScope returns true for every rid there).
    const curated=layer.features.filter(f=>inScope(f.rid)).length;
    const shown=layer.features.filter(f=>featureVisible(layer,f)).length + covShownCount(layer.key);
    return {shown, total:curated+covTotal};
  }
  function updateCounts(){
    CATALOG.forEach(layer=>{
      const c=layerCounts(layer), el=document.querySelector(`#layers .layer[data-key="${layer.key}"] .ct`);
      if(el) el.textContent=`${c.shown}/${c.total}`;
    });
  }
  function flyToPin(lngLat){   // centre + slow zoom-in on click; offset left so the drawer doesn't cover it
    map.flyTo({center:lngLat, zoom:Math.max(map.getZoom(),14), offset:[-150,0], duration:1700, essential:true});
  }
  function render(){
    // Style-load race (surfaced by the consolidated A-source, C3): the initial
    // best-of fetch can resolve BEFORE map 'load', and addSource/addLayer throw
    // on a not-yet-loaded style. Skip early calls — the 'load' handler runs
    // render() itself, and it sees all state mutated so far (f.cur, mode, …).
    if(!_styleReady) return;
    markers.forEach(m=>m.remove()); markers=[];
    clearDynamic();
    // Community tier (07-15 decision B): utility coverage draws lightly in
    // Curated; experiential coverage only in Everything. See syncCoverageLayers.
    syncCoverageLayers();
    let n=0;
    CATALOG.forEach(layer=>{
      // The consolidated surface source is persistent (never torn down by
      // clearDynamic), so an inactive A layer must explicitly render empty.
      if(layer.kind==='surface'){
        n+=renderSurfaceLayer(layer, active.has(layer.key));
        return;
      }
      if(!active.has(layer.key)) return;
      if(layer.kind==='point'){
        layer.features.forEach((f,i)=>{
          if(!featureVisible(layer,f)) return;
          if(f.route){                                    // climbs: draw the gradient-coloured road line + steepest marker
            if(f.grad) drawClimbLine(`route-${layer.key}-${i}`, f.route, f.grad, layer, f);
            else drawLine(`route-${layer.key}-${i}`, f.route, layer.color, layer, f);
            if(f.steep){
              const sEl=document.createElement('div');
              sEl.className='cc-steep'; sEl.textContent=f.steep.pct;
              sEl.title=`${D.steepest||'Steepest pitch'} · ${f.steep.pct}`;
              // stopPropagation: without it this click bubbles to the map container and
              // ALSO fires the underlying route/line layer's own map.on('click', id, …)
              // handler (MapLibre hit-tests canvas-rendered layers under DOM markers
              // regardless of what DOM element the click actually landed on) — which can
              // open a second, unrelated feature's drawer right behind this one and win
              // the C1-T3 history race with an empty (wrong-item) response. See loadItemHistory.
              sEl.addEventListener('click',e=>{ e.stopPropagation(); openDrawer(layer,f); flyToPin([f.steep.at[1],f.steep.at[0]]); });
              const sm=new maplibregl.Marker({element:sEl,anchor:'center'})
                .setLngLat([f.steep.at[1],f.steep.at[0]]).addTo(map);
              markers.push(sm);
            }
          }
          const el = pinEl(layer,f.cur,f);
          el.style.cursor='pointer';
          el.tabIndex=0; el.setAttribute('role','button');
          el.setAttribute('aria-label', `${f.name} — ${f.headline}`);
          const start = f.route ? f.route[0] : f.geom.ll;   // climbs: pin sits at the start (foot)
          const lngLat=[start[1],start[0]];
          // stopPropagation: see the note above the steep-marker's click handler —
          // same click-bleed-through-to-the-map-canvas issue, same fix.
          el.addEventListener('click', e=>{ e.stopPropagation(); openDrawer(layer,f); flyToPin(lngLat); });
          el.addEventListener('mouseenter', ()=>showTip(`${f.name} · ${f.headline}`, lngLat));
          el.addEventListener('mouseleave', hideTip);
          el.addEventListener('focus', ()=>showTip(`${f.name} · ${f.headline}`, lngLat));
          el.addEventListener('blur', hideTip);
          el.addEventListener('keydown', e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); openDrawer(layer,f); flyToPin(lngLat); }});
          const m=new maplibregl.Marker({element:el,anchor:'bottom'})
            .setLngLat(lngLat)
            .addTo(map);
          markers.push(m); n++;
        });
        return;
      }
      if(layer.kind==='line'){
        layer.features.forEach((f,i)=>{
          // The FULL visibility rule, same as the legend counts with: mode/cur
          // (Route domain phase 4), the region scope gate, and the bike-pref
          // prefilter. Review 07-20 finding 1: this branch used to re-implement
          // only the mode/cur half, so route lines leaked into out-of-scope
          // views (map showed 11 while the legend said 0/11) — never inline a
          // subset of featureVisible() here.
          if(!featureVisible(layer,f)) return;
          drawLine(`${layer.key}-${i}`, f.geom.path, layer.color, layer, f);
          n++;
        });
        return;
      }
      // (the old 'area' render branch was dead — no CATALOG entry has that kind,
      // and it lacked the getSource guard its siblings have; removed, review W35)
    });
    // confirmed/validated points are clustered (count bubble → category icon pins); unverified stay as dots
    updateConfMarkers();
    // stacking, bottom → top: ride lines, climb lines, road-surface lines, then Mapillary on top
    const liftGroup=id=>{ if(map.getLayer(id+'-case')) map.moveLayer(id+'-case'); if(map.getLayer(id)) map.moveLayer(id); };
    dynamicIds.filter(id=>id.startsWith('experience-')).forEach(liftGroup);    // ride lines (bottom of the three)
    liftInfoLayersAboveRoutes();                                               // climbs, surface, Mapillary above them
    // render() rebuilds every dynamic layer from scratch, which drops the
    // selection styling + z-order — re-apply it so toggling a layer (surface),
    // switching best-of facets, or changing mode never loses the highlighted
    // route. Guarded: if the selection was filtered out (e.g. not in the current
    // best-of), highlightRoute's moveLayer/setPaint calls simply no-op.
    if(selectedRouteLayerId && map.getLayer(selectedRouteLayerId)) highlightRoute(selectedRouteLayerId);
    document.getElementById('count').textContent=n;
    updateCounts();   // legend shows shown/total, refreshed on mode + layer changes
  }

  function photoList(f){ return f.photos || (f.photo ? [f.photo] : []); }
  // p.photo is parsed straight from the importable photo attribute (review W1):
  // credit/license/source text goes through escPend, and creditUrl/source
  // through safeHref — same hardening r.links[].href already has.
  function photoCap(p){
    const credit = p.creditUrl ? `<a href="${safeHref(p.creditUrl)}" target="_blank" rel="noopener">${escPend(p.credit)}</a>` : escPend(p.credit);
    return `© ${credit} · <a href="${ccUrl(p.license)}" target="_blank" rel="noopener">${escPend(p.license)}</a> · <a href="${safeHref(p.source)}" target="_blank" rel="noopener">Wikimedia Commons ↗</a>`;
  }
  function buildRecord(layer, f){
    const cur = f.cur ? `<div class="cc-d-cur">▲ ${I18N.curated||'Curated best-of'}</div>` : '';
    const pl = photoList(f);
    // Same edit-bridge rule as the "Edit this item" link below (spec §6/§8):
    // the add-photo CTA only ever binds to the item's real DB id — no id, no
    // link (a name-slug guess is never a faithful target).
    const addPhoto = (f.id!=null && layer.key!=='experience') ? `<a class="cc-d-addphoto" href="/improve?item=${f.id}&name=${encodeURIComponent(f.name)}&type=${layer.letter}&add=photo" aria-label="Add a photo of ${escPend(f.name)}">
      <svg class="cc-ap-cam" viewBox="0 0 48 36" width="42" height="31" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="1.5" y="7.5" width="45" height="27" rx="4"/><path d="M16 7.5l3-4h10l3 4" stroke-linejoin="round"/><circle cx="24" cy="21.5" r="8"/><path d="M40.5 13h.01" stroke-width="3" stroke-linecap="round"/>
      </svg>
      <span class="cc-ap-t">${D.noPhoto||'No photo yet'}</span>
      <span class="cc-ap-b">＋ ${D.addPhoto||'Add the first photo'}</span>
    </a>` : '';
    const photo = pl.length ? `<figure class="cc-d-photo">
      <img src="${safeHref(pl[0].sm)}" alt="${escPend(f.name)}" data-i="0" />
      <figcaption id="cc-d-cap">${photoCap(pl[0])}</figcaption>
      ${pl.length>1 ? `<div class="cc-d-thumbs">${pl.map((p,i)=>`<img class="cc-d-thumb${i===0?' on':''}" src="${safeHref(p.sm)}" data-i="${i}" alt="${escPend(f.name)} — photo ${i+1}" />`).join('')}</div>` : ''}
    </figure>` : addPhoto;
    let recs = f.record || [];
    if(layer.key==='climbs'){
      // Registry-driven (CC_FIELD_SCHEMA[B]): every climb attribute — including
      // the additional options (water on climb / hairpins / shade) the old
      // hand-list dropped — renders here, filled or as an "add" prompt.
      // Attributes flow via CatalogProvider::climbs() (CC_CLIMBS spreads
      // item.attributes onto the feature) -> layerByKey['climbs'].features -> f.
      // A filled schema row REPLACES any stale pre-baked f.record row of the same
      // label (wallonia harvest + hand-authored CATALOG fixtures) so an approved
      // edit is never shadowed by a duplicate.
      const attrRows = schemaRows('B', f, f.id);
      const attrLabels = new Set(attrRows.filter(r=>!r.empty).map(r=>r.label));
      recs = recs.filter(r=>!attrLabels.has(r.label)).concat(attrRows);
    }
    const rows = recs.map(r => {
      const links = r.links ? ' ' + r.links.map(l=>`<a class="cc-d-link" href="${safeHref(l.href)}" target="_blank" rel="noopener">${escPend(l.label)} ↗</a>`).join('') : '';
      // r.html is the explicit trusted-markup channel (like r.links): honored
      // only for rows whose markup the builder constructs itself with EVERY
      // interpolation escPend-escaped (RIDE_CITIES city links, bike-type
      // chips). Raw payload values never take this path — they stay
      // escPend-escaped below (spec §13).
      return `<li class="${r.empty?'empty':''}"><span class="k">${escPend(r.label)}</span><span class="v${r.warn?' warn':''}">${r.html?r.value:escPend(r.value)}${r.method?`<span class="m">${r.method}</span>`:''}${links}</span></li>`;
    }).join('');
    const freshState = f.freshness ? ({fresh:D.freshFresh, ageing:D.freshAgeing, stale:D.freshStale}[f.freshness.state] || f.freshness.state) : '';
    const fresh = f.freshness
      ? `<div class="cc-d-fresh ${f.freshness.state}">${freshState} · ${D.lastConfirmed||'last confirmed'} ${f.freshness.lastConfirmed==='this season'?(D.thisSeason||'this season'):f.freshness.lastConfirmed}</div>` : '';
    // difficulty is always {score,label} now (P2-D1); typeof fallback is defensive only.
    const diffLabel = f.difficulty?.label ?? (typeof f.difficulty === 'string' ? f.difficulty : undefined);
    const diffScore = f.difficulty?.score ?? null;
    const diff = diffLabel
      ? `<div class="cc-diff" title="${D.difficulty||'Difficulty'} 1–5: ${DIFF_LABELS.slice(1).join(' · ')}">${D.difficulty||'Difficulty'}
          <div class="cc-diff-scale">${[1,2,3,4,5].map(n=>`<span class="cc-diff-dot${n===diffScore?' on':''}" style="--p:${DIFF_PURPLE[n]}" title="${n} · ${DIFF_LABELS[n]}">${n}</span>`).join('')}</div>
          <b class="cc-diff-lbl">${trVal(diffLabel)}</b></div>` : '';
    const elev = f.elev ? `<div class="cc-elev-cap">${D.elevation||'Elevation'} · ${Math.min(...f.elev)}–${Math.max(...f.elev)} m`
      + (f.gain?` · ${tpl(D.mClimbing||'{n} m climbing',{n:f.gain})}`:'') + ` <em>${D.fromGpx||'(from GPX)'}</em></div>` + elevSvg(f.elev) : '';
    const grad = f.grad ? gradStrip(f.grad) : '';
    const up = f.uploader
      ? (f.uploader.public
          ? `<div class="cc-up">${D.sharedBy||'Shared by'} <b>${escPend(f.uploader.name)}</b> · <a href="/profile?u=${slug(f.uploader.name)}">${D.viewProfile||'view profile'}</a></div>`
          : `<div class="cc-up">${D.sharedAnon||'Shared anonymously'}</div>`)
      : '';
    // The edit-bridge opens /improve bound to the item's real DB id, which
    // loads that exact item and prefills the form with its current values
    // (spec §6/§8) — no id, no edit link (a name-slug guess is never a
    // faithful target).
    const ell = f.geom && f.geom.ll;                    // [lat,lng] for point features
    let edit = '';
    if(f.id!=null){
      if(layer.key==='experience'){
        // Route domain v1 phase 3 (spec §7): the community panel. GPX download
        // stays; rode-it/vote/suggest render as a container filled async by
        // openDrawer's GET /routes/{id}/community (P3-D3) — riders never edit.
        edit = routeCommunityPanel(f.id, f.state);
      } else {
        const editQ = `item=${f.id}&name=${encodeURIComponent(f.name)}`
          + `&type=${layer.letter}`
          + (ell ? `&lat=${ell[0]}&lng=${ell[1]}` : '');
        edit = `<a class="cc-d-act edit" href="/improve?${editQ}">✎ ${D.editItem||'Edit this item'}</a>`;
        // Direct "this pin is wrong" path — only when we know where it is.
        // editQ already carries lat/lng; fix=location opens the editor expanded.
        if(ell){
          edit += `<a class="cc-d-act fixloc" href="/improve?${editQ}&fix=location">◎ ${D.fixLocation||'Fix location'}</a>`;
        }
      }
    }
    let moderate = '';
    if(layer.pendingLayer && f.pending){
      // §13: real submissions now flow here (not trusted fixtures) — HTML-escape
      // every interpolated submission field before it hits innerHTML (stored-XSS-
      // in-curator-session risk). s.title/s.who/s.when render via the shared
      // f.name/f.record path above (untouched — see MapController/Task 4).
      const s=f.pending;
      // Pending items carry their own catalog letter (A–K) + coords → a faithful edit link.
      // Every interpolation is encoded (review W33): the server serves lat/lng
      // numeric and letter as an enum, but this attribute context shouldn't
      // depend on that guarantee holding forever.
      // Edit-bridge binds to the TARGET catalog item (s.itemId), NOT the
      // submission id (s.id) — using s.id sent /improve a non-existent item id
      // and it fell through to the empty "pick a place" explainer. A brand-new
      // submission (no itemId yet — the place isn't in the catalog until it's
      // approved) has nothing to edit, so no link.
      edit = (s.itemId != null)
        ? `<a class="cc-d-act edit" href="/improve?type=${encodeURIComponent(s.letter)}&item=${encodeURIComponent(s.itemId)}&name=${encodeURIComponent(s.title)}&lat=${encodeURIComponent(s.lat)}&lng=${encodeURIComponent(s.lng)}">✎ ${D.editItem||'Edit this item'}</a>`
        : '';
      const body = s.body ? `<p class="cc-mod-body">${escPend(s.body)}</p>` : '';
      // "Proposed change" — what THIS submission wants to change, not the
      // item's history. Kept visually distinct from the history section below.
      const diff = (s.was && s.now)
        ? `<div class="cc-mod-diff"><div class="cc-mod-diff-h">${D.proposedChange||'Proposed change'}</div><div class="cc-mod-was">${escPend(s.was)}</div><div class="cc-mod-now">${escPend(s.now)}</div></div>`
        : '';
      // Moderation-UX (user request): "Everybody should always be able to see
      // the history of an item." A brand-new submission (type 'new') has no
      // prior item to have a history — say so plainly, no fetch needed. An
      // edit of an existing item (itemId set) fetches the same applied
      // change_history the normal item drawer shows (C1-T3), via openDrawer
      // below — reusing loadItemHistory/renderHistoryList so both views stay
      // in sync. escPend covers every interpolated value (see historyRow).
      const modHist = 'new' === s.type
        ? `<div class="cc-d-hist cc-d-hist-initial"><h4 class="cc-d-hist-h">${D.history||'History'}</h4><p class="cc-mod-initial">${D.initialEntry||'Initial entry — new item'}</p></div>`
        : (s.itemId != null ? `<div class="cc-d-hist" id="cc-d-hist-slot" data-item="${s.itemId}"></div>` : '');
      moderate = `<div class="cc-mod" data-id="${escPend(s.id)}">
        <div class="cc-mod-badge">⚑ ${I18N.pendingReview||'Pending review'}</div>${body}${diff}
        <textarea class="cc-mod-note" placeholder="${D.modNotePh||'Optional note — a reason, or context…'}"></textarea>
        <div class="cc-mod-acts">
          <button class="cc-mod-btn approve" data-decision="approve">✓ ${D.approve||'Approve'}</button>
          <button class="cc-mod-btn info" data-decision="needs_info">? ${D.needsInfo||'Needs info'}</button>
          <button class="cc-mod-btn reject" data-decision="reject">✕ ${D.reject||'Reject'}</button>
        </div>
        <div class="cc-mod-preview">${D.modKeys||'A · approve · R · reject — recorded, not yet persisted.'}</div>
        ${modHist}
      </div>`;
    }
    // Only votable point types get the vote CTA. Utilities are confirmed, not
    // voted — the erroneous vote link used to show on water/services/etc.
    const vote = (f.cur && CC_VOTABLE.has(layer.key)) ? `<a class="cc-d-act" href="/vote">▲ ${D.voteRound||'Vote in this round'}</a>` : '';
    // Non-votable utilities carry a community confirmation panel (water:
    // potable/not-potable, others: "still here?"), hydrated async on open.
    const confirmPanel = (CC_CONFIRMABLE.has(layer.key) && f.id!=null)
      ? `<div class="cc-cf" data-item="${f.id}"><div class="cc-cf-body" data-cf-body></div><div class="cc-cf-login" hidden>${D.loginConfirm||'Log in to confirm'} · <a href="/login">${I18N.login||'Log in'}</a></div></div>`
      : '';
    const act = edit + vote;
    const desc = f.desc ? `<p class="cc-d-desc">${escPend(f.desc)}${f.descTr?` <span class="cc-d-tr">· auto-translated</span>`:''}</p>` : '';
    // C1-T3 (spec W5): an empty placeholder for the async "Recent changes"
    // section — openDrawer() fetches GET /map/item/{id}/history after this
    // HTML lands and fills #cc-d-hist-slot (buildRecord itself stays sync/pure,
    // no network calls here). Same edit-bridge id contract as `edit`/`addPhoto`
    // above: only real DB items (f.id!=null) get one. The curator's pending-
    // submission records (below) never carry f.id, so they never render this —
    // see the note on the pending branch for why that view doesn't get one either.
    const histSlot = f.id!=null ? `<div class="cc-d-hist" id="cc-d-hist-slot" data-item="${f.id}"></div>` : '';
    // Point the "OpenStreetMap" source link at the object's location on OSM (its
    // feature-query view) rather than the OSM homepage. The served OSM features
    // carry no element (node/way) id, so a coordinate query is the closest we can
    // deep-link to the exact object the rider is looking at.
    const osmHref = (f.geom && f.geom.ll)
      ? `https://www.openstreetmap.org/query?lat=${f.geom.ll[0]}&lon=${f.geom.ll[1]}#map=18/${f.geom.ll[0]}/${f.geom.ll[1]}`
      : 'https://www.openstreetmap.org';
    return `<span class="cc-d-type" style="--c:${layer.color};color:${txtOn(layer.color)}">${layer.letter} · ${layer.label}</span>
      <div class="cc-d-name">${escPend(f.name)}</div>${cur}${photo}${desc}${diff}${elev}${grad}
      <ul class="cc-d-rec">${rows}</ul>${fresh}${up}
      <div class="cc-d-src">${D.source||'Source'} · ${escPend(f.source).replace(/^(OpenStreetMap|OSM)/, `<a href="${osmHref}" target="_blank" rel="noopener" style="color:var(--glacier);text-decoration:underline;text-underline-offset:2px">$1</a>`).replace(/(Géoportail de la Wallonie)/, '<a href="https://geoportail.wallonie.be/catalogue/91721175-5f01-410c-8c78-37c1d1893ba2.html" target="_blank" rel="noopener" style="color:var(--glacier);text-decoration:underline;text-underline-offset:2px">$1</a>')}</div>${confirmPanel}${act}${moderate}${histSlot}`;
  }
  // C1-T3: renders one change_history row. Every interpolated value is
  // user-contributed (old/new attribute values, and `who`/`when`/`changedAt`
  // are server-derived but still passed through escPend for defense in depth)
  // — same stored-XSS concern the §13 pending-submission fix addressed, so
  // ALL FIVE fields go through escPend before hitting innerHTML.
  function historyRow(h){
    const isEmpty = v => v===null || v===undefined || v==='';
    const ov = isEmpty(h.oldValue) ? '—' : escPend(h.oldValue);
    const nv = isEmpty(h.newValue) ? '—' : escPend(h.newValue);
    return `<li class="cc-h-row">
      <span class="cc-h-field">${escPend(h.field)}</span>
      <span class="cc-h-diff">${ov} → ${nv}</span>
      <span class="cc-h-meta">${escPend(h.who)} · <time datetime="${escPend(h.changedAt)}">${escPend(h.when)}</time></span>
    </li>`;
  }
  // Empty history (never-edited item) renders nothing — spec W5/C1-T3
  // acceptance: "no changes yet" is silence, not a section.
  function renderHistoryList(history){
    if(!Array.isArray(history) || !history.length) return '';
    return `<h4 class="cc-d-hist-h">${D.recentChanges||'Recent changes'}</h4><ul class="cc-d-hist-list">${history.map(historyRow).join('')}</ul>`;
  }
  // Fetches an item's change log (C1-T2's GET /map/item/{id}/history) and
  // fills the drawer's history slot. Lazy/async on purpose — never blocks
  // the drawer opening. Race-guarded: `myReq` is snapshotted from the shared
  // `_historyReq` counter, which openDrawer() bumps on every call; if a newer
  // drawer opened (or this one closed and another opened) before the response
  // lands, `myReq` no longer matches and the stale response is dropped. Fetch
  // failure is silent — history is an enhancement, not core drawer content.
  function loadItemHistory(itemId){
    const myReq = ++_historyReq;
    fetch('/map/item/' + itemId + '/history')
      .then(r => r.ok ? r.json() : null)
      .catch(()=>null)   // network/HTTP failure — enhancement only, stays silent (no exception to report)
      .then(data => {
        if(myReq !== _historyReq || !data) return;   // stale response — a newer drawer has since opened
        const slot = document.getElementById('cc-d-hist-slot');
        if(!slot) return;                              // drawer content changed/closed under us
        // A render bug here must not be indistinguishable from "no history yet" —
        // only the fetch/network stage above is allowed to fail silently.
        try {
          slot.innerHTML = renderHistoryList(data.history);
        } catch(e){
          console.error('Recent-changes history failed to render', e);
        }
      });
  }
  // Route community loop (spec §7). One authenticated fetch on drawer-open
  // carries counts + my-state + a stateless CSRF token; the three POSTs reuse it.
  const CC_BIKES=['Road','Gravel','MTB','E-bike','Handbike','Recumbent','Trike','Tandem'];
  const CC_SEASONS=['spring','summer','autumn','winter'];
  const CC_REASONS=[['broken-track',D.reasonBroken||'Wrong / broken track'],['trim-privacy',D.reasonPrivacy||'Trim a private start/end'],['duplicate',D.reasonDuplicate||'Duplicate of another route'],['not-rideable',D.reasonNotRideable||'Not actually rideable'],['other',D.reasonOther||'Something else']];
  const _rcTokens={};   // route id → CSRF token from the last snapshot

  // Votable point types (climbs/stays/scenic/history) carry the vote CTA;
  // routes (experience) have their own vote block. Non-votable UTILITIES are
  // confirmed, not voted: water carries a potable/not-potable judgement, the
  // rest a plain "still here?" confirmation. Keys match the CATALOG layer keys.
  const CC_VOTABLE=new Set(['climbs','stays','scenic','history']);
  const CC_CONFIRMABLE=new Set(['water','services','hazards','transit','shelter']);
  const _cfTokens={};   // item id → CSRF token from the last confirmations snapshot

  function routeCommunityPanel(id, state){
    const bikeL=I18N.bikes||{};
    const bikeOpts=CC_BIKES.map(b=>`<option value="${b}">${bikeL[b]||b}</option>`).join('');
    // Bike type has no safe default (it changes what a ride/vote means), so the
    // picker opens on a disabled placeholder — the rider must choose actively.
    const bikePickOpts=`<option value="" selected disabled>${D.bikeTypePh||'Bike type…'}</option>`+bikeOpts;
    const seasonOpts=CC_SEASONS.map(s=>`<option value="${s}">${CC_SEASON_LABEL[s]||s[0].toUpperCase()+s.slice(1)}</option>`).join('');
    const reasonOpts=CC_REASONS.map(([v,l])=>`<option value="${v}">${l}</option>`).join('');
    // Vote block only for verified routes (spec D7); rode-it for both.
    const voteBlock = state==='verified' ? `
      <div class="cc-rc-vote">
        <label class="cc-rc-l">${D.recommend||'Recommend it'} <span class="cc-rc-count" data-rc="votes"></span></label>
        <div class="cc-rc-row"><select class="cc-rc-season">${seasonOpts}</select><select class="cc-rc-vbike">${bikePickOpts}</select>
          <button class="cc-rc-btn" data-rc-act="vote">▲ ${D.vote||'Vote'}</button></div>
      </div>` : '';
    const rideProgress = state==='unverified' ? `<span class="cc-rc-count" data-rc="rides">…</span>` : '';
    return `<div class="cc-rc" data-route="${id}" data-state="${state||''}">
      <div class="cc-rc-ride">
        <label class="cc-rc-l">${D.rodeThis||'I rode this'} ${rideProgress}</label>
        <div class="cc-rc-row"><select class="cc-rc-rbike">${bikePickOpts}</select>
          <button class="cc-rc-btn" data-rc-act="rode-it">✓ ${D.rodeThis||'I rode this'}</button></div>
      </div>
      ${voteBlock}
      <details class="cc-rc-suggest"><summary>${D.suggestCorrection||'Suggest a correction'}</summary>
        <select class="cc-rc-reason">${reasonOpts}</select>
        <textarea class="cc-rc-note" placeholder="${D.optionalDetail||'Optional detail…'}"></textarea>
        <button type="button" class="cc-rc-mark" data-rc-mark="${id}">✎ ${D.markParts||'Mark the part(s) on the map'}</button>
        <span class="cc-rc-marks" data-rc-marks></span>
        <button class="cc-rc-btn" data-rc-act="suggest">${D.send||'Send'}</button>
      </details>
      <a class="cc-d-act edit" href="/routes/${id}.gpx">⤓ ${D.downloadGpx||'Download GPX'}</a>
      <div class="cc-rc-login" hidden>${D.loginRate||'Log in to rate this route'} · <a href="/login">${I18N.login||'Log in'}</a></div>
    </div>`;
  }

  // Called from openDrawer after the route drawer HTML lands.
  function hydrateRouteCommunity(id){
    const box=document.querySelector(`.cc-rc[data-route="${id}"]`); if(!box) return;
    // Restore the "N stretches marked" indicator if a picking session was already
    // committed for this route (e.g. drawer closed without Send, then reopened) —
    // otherwise the marks silently ride along on the next Send with no visible cue.
    const segs=_pickSegs[id];
    if(segs && segs.length){
      const m=box.querySelector('[data-rc-marks]');
      if(m) m.textContent = tpl((segs.length===1?D.marksOne:D.marksMany)||`· {n} stretch${segs.length===1?'':'es'} marked`, {n:segs.length});
    }
    fetch(`/routes/${id}/community`, {credentials:'same-origin', headers:{'Accept':'application/json'}})
      .then(r=>{ if(r.status===401||r.status===403){ box.querySelector('.cc-rc-login').hidden=false; box.classList.add('cc-rc-anon'); throw new Error('anon'); } if(!r.ok) throw new Error('community'); return r.json(); })
      .then(s=>{ _rcTokens[id]=s.token; paintRouteCommunity(box, s); })
      .catch(()=>{});
  }

  function paintRouteCommunity(box, s){
    const rides=box.querySelector('[data-rc="rides"]'); if(rides) rides.textContent=`· ${tpl(D.ridesProgress||'{n} of {m} to verify', {n:s.rideCount, m:s.threshold})}`;
    const votes=box.querySelector('[data-rc="votes"]'); if(votes) votes.textContent=s.voteCount?`· ${tpl((s.voteCount===1?D.voteOne:D.voteMany)||`{n} vote${s.voteCount>1?'s':''}`, {n:s.voteCount})}`:'';
    if(s.iRode){ const b=box.querySelector('[data-rc-act="rode-it"]'); if(b){ b.textContent=`✓ ${D.youRode||'You rode this'}`; b.disabled=true; } }
    if(s.iVotedThisSeason){ const b=box.querySelector('[data-rc-act="vote"]'); if(b){ b.textContent=`✓ ${D.votedSeason||'Voted this season'}`; b.disabled=true; } }
  }

  // --- Community confirmations for non-votable utilities (water potability /
  // "still here?"). Public counts, login to confirm — mirrors the route
  // community loop but simpler (one toggle-able stance per rider). ---
  const CC_CF_STANCES={
    potability:[['potable',`✓ ${D.potable||'Potable'}`],['not_potable',`✗ ${D.notPotable||'Not potable'}`]],
    existence:[['exists',`✓ ${D.confirmHere||'Confirm it’s here'}`]],
  };
  function hydrateItemConfirm(id){
    const box=document.querySelector(`.cc-cf[data-item="${id}"]`); if(!box) return;
    fetch(`/items/${id}/confirmations`, {credentials:'same-origin', headers:{'Accept':'application/json'}})
      .then(r=>{ if(!r.ok) throw new Error('confirm'); return r.json(); })
      .then(s=>{ if(s.token) _cfTokens[id]=s.token; paintItemConfirm(box, s); })
      .catch(()=>{});   // enhancement only — never blocks the drawer
  }
  function paintItemConfirm(box, s){
    const authed=!!s.token;
    const defs=CC_CF_STANCES[s.stanceKind]||CC_CF_STANCES.existence;
    const heading = s.stanceKind==='potability'
      ? (D.waterQ||'Is the water drinkable?') : (D.hereQ||'Is this still here?');
    const btns=defs.map(([v,l])=>{
      const n=(s.stances&&s.stances[v])||0;
      const mine=s.mine===v?' is-mine':'';
      return `<button class="cc-cf-btn${mine}" data-cf-act="${v}"${authed?'':' disabled'}>${l} <span class="cc-cf-n">${n}</span></button>`;
    }).join('');
    const total = s.total ? `<span class="cc-cf-total">· ${tpl((s.total===1?D.confirmedOne:D.confirmedMany)||`{n} rider${s.total===1?'':'s'} confirmed`, {n:s.total})}</span>` : '';
    box.querySelector('[data-cf-body]').innerHTML =
      `<div class="cc-cf-h">${heading} ${total}</div><div class="cc-cf-row">${btns}</div>`;
    box.querySelector('.cc-cf-login').hidden = authed;
  }
  // Delegated: clicking a stance button records/switches it, then repaints.
  document.addEventListener('click', e=>{
    const btn=e.target.closest('[data-cf-act]'); if(!btn) return;
    const box=btn.closest('.cc-cf'); if(!box) return;
    const id=box.getAttribute('data-item'), token=_cfTokens[id];
    if(!token){ mapToast(D.toastLoginConfirm||'Please log in to confirm.'); return; }
    const body=new URLSearchParams(); body.set('_token', token); body.set('stance', btn.getAttribute('data-cf-act'));
    box.querySelectorAll('.cc-cf-btn').forEach(b=>b.disabled=true);
    fetch(`/items/${id}/confirm`, {method:'POST', credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
      .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
      .then(s=>{ paintItemConfirm(box, s); mapToast(D.toastThanks||'Thanks — recorded.'); })
      .catch(()=>{ box.querySelectorAll('.cc-cf-btn').forEach(b=>b.disabled=false); mapToast(D.toastErr||'Could not record that — please try again.'); });
  });

  // Flag a picker the rider left on its placeholder: red border + focus + toast.
  function warnPick(sel, msg){ if(sel){ sel.classList.add('cc-rc-invalid'); sel.focus(); } mapToast(msg); }
  function rcPost(box, act){
    const id=box.dataset.route, token=_rcTokens[id];
    if(!token){ mapToast(D.toastLoginRate||'Please log in to rate routes.'); return; }
    const body=new URLSearchParams(); body.set('_token', token);
    // Bike type must be actively chosen (no default) — block + warn if empty.
    if(act==='rode-it'){
      const sel=box.querySelector('.cc-rc-rbike');
      if(!sel.value){ warnPick(sel, D.pickBikeRode||'Pick the bike type you rode it on first.'); return; }
      body.set('bike_type', sel.value);
    }
    if(act==='vote'){
      const sel=box.querySelector('.cc-rc-vbike');
      if(!sel.value){ warnPick(sel, D.pickBikeVote||'Pick a bike type to recommend it for first.'); return; }
      body.set('season', box.querySelector('.cc-rc-season').value); body.set('bike_type', sel.value);
    }
    if(act==='suggest'){
      body.set('reason', box.querySelector('.cc-rc-reason').value);
      body.set('note', box.querySelector('.cc-rc-note').value);
      const segs=_pickSegs[id]; if(segs && segs.length) body.set('segments', JSON.stringify(segs));
    }
    box.querySelectorAll('.cc-rc-btn').forEach(b=>b.disabled=true);
    fetch(`/routes/${id}/${act}`, {method:'POST', credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
      .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
      .then(s=>{
        box.querySelectorAll('.cc-rc-btn').forEach(b=>b.disabled=false);
        if(act==='suggest'){ delete _pickSegs[id]; const m=box.querySelector('[data-rc-marks]'); if(m) m.textContent=''; mapToast(D.toastCurator||'Thanks — a curator will review it.'); box.querySelector('.cc-rc-suggest').open=false; box.querySelector('.cc-rc-note').value=''; return; }
        paintRouteCommunity(box, s);
        if(act==='rode-it' && s.state==='verified' && box.dataset.state==='unverified'){ mapToast(D.toastVerified||'Verified — thanks for confirming this route!'); box.dataset.state='verified'; }
        else mapToast(D.toastRecorded||'Recorded — thanks!');
      })
      .catch(err=>{ box.querySelectorAll('.cc-rc-btn').forEach(b=>b.disabled=false); mapToast(err.message==='429'?(D.toastLimit||'Daily limit reached — try again tomorrow.'):(D.toastErr||'Could not record that — please try again.')); });
  }

  // --- Located-correction picking mode (spec §16 S1). Segments captured per
  // route id, kept until a successful "suggest" POST consumes and clears them. ---
  const _pickSegs={};   // route id → list<{start,end}> captured for the open suggest form
  let _pick=null;       // active picking session or null

  // Delegated: the "Mark on map" button starts picking for the drawer's route.
  document.addEventListener('click', e=>{
    const mb=e.target.closest('[data-rc-mark]'); if(!mb) return;
    startPicking(mb.getAttribute('data-rc-mark'));
  });

  function routePathById(id){
    const layer=layerByKey['experience']; if(!layer) return null;
    const f=layer.features.find(x=>String(x.id)===String(id));
    return f && f.geom && f.geom.path ? f.geom.path : null;
  }

  function startPicking(routeId){
    if(_pick) return;   // re-entrancy guard: don't orphan an in-progress session
    const path=routePathById(routeId); if(!path){ mapToast(D.toastOpenRoute||'Open the route first.'); return; }
    _pick={ routeId, path, points:[], markers:[], segLayers:[] };
    document.querySelector('.cc-drawer')?.classList.add('cc-drawer-min');   // minimise so the map is clickable
    map.getCanvas().style.cursor='crosshair';
    showPickBar();
    // click on the route line drops a snapped point
    map.on('click', pickClick);
  }
  function pickClick(e){
    if(!_pick) return;
    const snap=nearestOnPath(_pick.path, [e.lngLat.lat, e.lngLat.lng]); if(!snap) return;
    // ignore clicks far from the line (>~30 m in deg ≈ 3e-4)
    if(Math.sqrt(snap.d2) > 3e-4) return;
    _pick.points.push(snap.frac);
    const n=_pick.points.length;
    const el=document.createElement('div'); el.className='cc-pick-pin'; el.textContent=String(n);
    _pick.markers.push(new maplibregl.Marker({element:el,anchor:'center'}).setLngLat([snap.at[1],snap.at[0]]).addTo(map));
    redrawPickSegments();
    updatePickBar();
  }
  // Click-to-scope (2026-07-22-scope-selector-scale-design.md §C): a left-click
  // on EMPTY map scopes to the region under the point. Feature clicks (coverage
  // POIs/clusters, CATALOG route/climb/line layers, the A-layer surface
  // classes, curator correction previews, Mapillary) keep their own handlers —
  // this bails if any of THEIR layers has a feature under the point, or a
  // stretch-picking session is active.
  //
  // queryRenderedFeatures(e.point) with no layer filter would also match
  // basemap polygons — a click over land would then always look "non-empty"
  // and this would never fire — so the query is narrowed to the ids those
  // handlers actually bind. That list is DERIVED off the same live
  // registries the handlers themselves use (COVERAGE_KEYS/COVERAGE_CCS,
  // boundLayerIds, surfaceClsLayerIds(), _corrLayers) rather than a second,
  // hand-kept list that could silently drift from the real bindings — if a
  // layer were missing here, clicking that feature would ALSO re-scope the
  // map underneath it. cov-sel-icon/planroute(-case) have no click handler of
  // their own but are real rendered overlays, not "empty map" — included so a
  // click on them doesn't misread as empty either. Filtered to map.getLayer()
  // existence: queryRenderedFeatures throws on an id absent from the current
  // style, and boundLayerIds in particular can carry stale ids across a
  // render() that dropped a previously-bound feature.
  function selectableLayers(){
    const ids=['mly-img','mly-cov','cov-sel-icon','planroute','planroute-case'];
    COVERAGE_KEYS.forEach(([key])=>COVERAGE_CCS.forEach(cc=>{
      const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
      ids.push(id);
    }));
    boundLayerIds.forEach(id=>ids.push(id));         // drawLine/drawClimbLine: route/climb/line CATALOG layers
    surfaceClsLayerIds().forEach(id=>ids.push(id));  // A-layer surface classes
    _corrLayers.forEach(id=>ids.push(id));           // curator correction-segment previews
    return ids.filter(id=>map.getLayer(id));
  }
  map.on('click', async e=>{
    if(_pick) return;                                    // stretch-picking owns the click
    if(map.queryRenderedFeatures(e.point, {layers:selectableLayers()}).length) return;  // a feature layer will handle it
    if(!window.CCScope) return;
    // Precise resolution refines an ambiguous click (2+ overlapping region bboxes)
    // against the real polygon; deep inside one region it is synchronous-fast, no
    // fetch (2026-07-24-region-adjacency-and-click-refinement-design.md §3.2).
    const r=await window.CCScope.regionOfPointPrecise(e.lngLat.lng, e.lngLat.lat);
    if(!r) return;
    // Same-region click is a no-op (final review CRITICAL 1): bail before setRegion
    // so an empty-map click inside the ALREADY-active region doesn't re-fit the
    // camera + re-fetch coverage counts + re-render on every stray click.
    const s=curScope();
    if(s.kind==='region' && s.regionIds[0]===r.id) return;
    window.CCScope.setRegion(r.slug);   // cc:scopechange -> applyScope + renderScopeChips
  });
  function redrawPickSegments(){
    _pick.segLayers.forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); if(map.getSource(id)) map.removeSource(id); });
    _pick.segLayers=[];
    const pts=_pick.points;
    for(let i=0;i+1<pts.length;i+=2){
      const id=`pickseg-${i}`, coords=sliceByFrac(_pick.path, pts[i], pts[i+1]).map(p=>[p[1],p[0]]);
      map.addSource(id,{type:'geojson',data:{type:'Feature',geometry:{type:'LineString',coordinates:coords}}});
      map.addLayer({id,type:'line',source:id,layout:{'line-cap':'round','line-join':'round'},paint:{'line-color':'#FF5A1F','line-width':8,'line-opacity':.9}});
      _pick.segLayers.push(id);
    }
  }
  function pickSegments(){ // fold the ordered points into {start,end} pairs (drop a lone trailing point)
    // Normalize start ≤ end regardless of click order (mirrors sliceByFrac's swap) —
    // the server rejects start > end with 422 invalid_segments.
    const p=_pick.points, out=[]; for(let i=0;i+1<p.length;i+=2){ const a=p[i], b=p[i+1]; out.push({start:Math.min(a,b), end:Math.max(a,b)}); } return out;
  }
  function showPickBar(){
    let bar=document.getElementById('cc-pickbar');
    if(!bar){ bar=document.createElement('div'); bar.id='cc-pickbar'; bar.className='cc-pickbar'; document.body.appendChild(bar); }
    bar.innerHTML=`<span class="cc-pickbar-t"></span>
      <button data-pick="undo">↶ ${D.undo||'Undo'}</button><button data-pick="clear">${D.clear||'Clear'}</button><button data-pick="done" class="on">${D.done||'Done'}</button>`;
    bar.hidden=false; updatePickBar();
    bar.onclick=e=>{ const b=e.target.closest('[data-pick]'); if(!b) return; pickAction(b.dataset.pick); };
  }
  function updatePickBar(){
    const t=document.querySelector('#cc-pickbar .cc-pickbar-t'); if(!t) return;
    const done=Math.floor(_pick.points.length/2), pending=_pick.points.length%2;
    t.textContent = pending
      ? tpl(D.pointSet||'Point {n} set — click the end of this stretch', {n:_pick.points.length})
      : tpl((done===1?D.barOne:D.barMany)||`{n} stretch${done===1?'':'es'} marked — click to start another, or Done`, {n:done});
  }
  function pickAction(a){
    if(a==='undo'){ _pick.points.pop(); const m=_pick.markers.pop(); if(m) m.remove(); redrawPickSegments(); updatePickBar(); return; }
    if(a==='clear'){ _pick.points=[]; _pick.markers.forEach(m=>m.remove()); _pick.markers=[]; redrawPickSegments(); updatePickBar(); return; }
    if(a==='done'){ finishPicking(); }
  }
  function finishPicking(){
    if(!_pick) return;
    _pickSegs[_pick.routeId]=pickSegments();
    map.off('click', pickClick); map.getCanvas().style.cursor='';
    _pick.markers.forEach(m=>m.remove()); _pick.segLayers.forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); if(map.getSource(id)) map.removeSource(id); });
    const rid=_pick.routeId; _pick=null;
    document.getElementById('cc-pickbar').hidden=true;
    document.querySelector('.cc-drawer')?.classList.remove('cc-drawer-min');
    const marks=document.querySelector(`.cc-rc[data-route="${rid}"] [data-rc-marks]`);
    const n=(_pickSegs[rid]||[]).length; if(marks) marks.textContent = n ? tpl((n===1?D.marksOne:D.marksMany)||`· {n} stretch${n===1?'':'es'} marked`, {n}) : '';
  }
  // Tear down an in-progress picking session without committing it to
  // _pickSegs (used when the drawer itself closes mid-pick — see closeDrawer).
  function cancelPicking(){
    if(!_pick) return;
    map.off('click', pickClick); map.getCanvas().style.cursor='';
    _pick.markers.forEach(m=>m.remove()); _pick.segLayers.forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); if(map.getSource(id)) map.removeSource(id); });
    _pick=null;
    const bar=document.getElementById('cc-pickbar'); if(bar) bar.hidden=true;
    document.querySelector('.cc-drawer')?.classList.remove('cc-drawer-min');
  }

  // Delegated click handler for every community button (drawer is re-rendered often).
  document.addEventListener('click', e=>{
    const btn=e.target.closest('[data-rc-act]'); if(!btn) return;
    const box=btn.closest('.cc-rc'); if(!box) return;
    rcPost(box, btn.dataset.rcAct);
  });
  // Clear the "must pick a bike type" warning as soon as the rider chooses one.
  document.addEventListener('change', e=>{ const s=e.target.closest('.cc-rc-invalid'); if(s) s.classList.remove('cc-rc-invalid'); });
  function mapToast(msg){
    let t=document.getElementById('cc-toast');
    if(!t){ t=document.createElement('div'); t.id='cc-toast'; t.className='cc-toast'; document.body.appendChild(t); }
    t.textContent=msg; t.classList.add('show');
    clearTimeout(mapToast._t); mapToast._t=setTimeout(()=>t.classList.remove('show'),3200);
  }
  function hidePendingPin(id){
    const layer=layerByKey.pending; if(!layer) return;
    layer.features=layer.features.filter(f=>!(f.pending && String(f.pending.id)===String(id)));
    if(_searchDropPending) _searchDropPending(id);   // keep the search index in step (W36)
    render();
  }
  // set by the sidebar-search block below (it owns SEARCH_IDX); null until then
  let _searchDropPending=null;
  // Stateless same-origin CSRF: the decision form carries a _token placeholder tied
  // to the csrf-token cookie (HttpOnly → unreadable from JS). The map page renders no
  // such form, so fetch one token from /moderate and reuse it (stable for the session);
  // a failed decision clears it so the next attempt re-fetches a fresh one.
  let _modToken;
  function moderationToken(){
    // The decision CSRF token is emitted directly on the map page
    // (window.CC_MOD_TOKEN) — approval happens only here now, so there is no
    // /moderate decision form to scrape. Reject if it is somehow absent so the
    // caller's error state fires rather than a silent CSRF failure later.
    if(_modToken) return _modToken;
    _modToken = window.CC_MOD_TOKEN
      ? Promise.resolve(window.CC_MOD_TOKEN)
      : Promise.reject(new Error('moderation token not available'));
    return _modToken;
  }
  function submitModeration(btn){
    const box=btn.closest('.cc-mod'); if(!box) return;
    const id=box.dataset.id, decision=btn.dataset.decision;
    const note=(box.querySelector('.cc-mod-note')||{}).value||'';
    box.querySelectorAll('.cc-mod-btn').forEach(b=>b.disabled=true);
    moderationToken().then(token=>{
      const body=new URLSearchParams();
      body.set('moderation_decision[submission_id]', id);
      body.set('moderation_decision[decision]', decision);
      body.set('moderation_decision[note]', note);
      body.set('moderation_decision[_token]', token);
      return fetch('/moderate/decide', { method:'POST', credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'},
        body:body.toString() });
    })
      .then(r=>{ if(!r.ok) throw new Error('decide'); return r.json(); })
      .then(res=>{ hidePendingPin(id); closeDrawer(); mapToast(tpl(D.decisionRecorded||'Decision recorded ({d}) — preview, not yet persisted · {ref}', {d:decision.replace('_',' '), ref:res.reference})); })
      .catch(()=>{ _modToken=undefined; box.querySelectorAll('.cc-mod-btn').forEach(b=>b.disabled=false); mapToast(D.decisionErr||'Could not record the decision — please try again.'); });
  }
  function gradStrip(grad){
    const max=Math.max(...grad), avg=Math.round(grad.reduce((a,b)=>a+b,0)/grad.length);
    const bars=grad.map(p=>`<span class="cc-grad-bar" style="height:${Math.round(10+(p/Math.max(max,1))*30)}px;background:${gradColor(p)}" title="${p}%"></span>`).join('');
    return `<div class="cc-elev-cap">${tpl(D.gradProfile||'Gradient profile · avg ~{a}% · max {m}%', {a:avg, m:max})} <em>${D.illustrative||'(illustrative)'}</em></div>
      <div class="cc-grad">${bars}</div>`;
  }
  function elevSvg(elev){
    const w=300,h=64,pad=3,min=Math.min(...elev),max=Math.max(...elev),rng=Math.max(1,max-min);
    const xy=elev.map((e,i)=>[pad+i/(elev.length-1)*(w-2*pad), h-pad-((e-min)/rng)*(h-2*pad)]);
    const line=xy.map(p=>p[0].toFixed(1)+','+p[1].toFixed(1)).join(' ');
    return `<svg class="cc-elev" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" role="img" aria-label="${D.elevAria||'Elevation profile'}">
      <polygon points="${pad},${h-pad} ${line} ${w-pad},${h-pad}" fill="rgba(255,90,31,.16)"/>
      <polyline points="${line}" fill="none" stroke="#FF5A1F" stroke-width="1.6"/></svg>`;
  }
  // Content-only body render, shared by openDrawer and the coverage detail
  // repaint (openCoverageDrawer's enrich-later step). (Re)writes #drawerBody
  // + its content-scoped wiring/hydrations, and deliberately does NOT touch
  // the open state, selection halo, focus or the mobile sheet snap — so a
  // progressive-hydration repaint (the loadItemHistory/hydrateItemConfirm
  // convention) never yanks the bottom sheet back to half or re-steals focus.
  function renderDrawerBody(layer, f){
    document.getElementById('drawerBody').innerHTML = buildRecord(layer, f);
    if(layer.key==='experience' && f.id!=null) hydrateRouteCommunity(f.id);
    if(CC_CONFIRMABLE.has(layer.key) && f.id!=null) hydrateItemConfirm(f.id);
    // C1-T3: async "Recent changes" — see loadItemHistory for the race guard.
    // Pending (moderation) features carry no f.id; when they target a real
    // item (f.pending.itemId, i.e. an edit — never a brand-new submission,
    // which renders "Initial entry" synchronously above with no fetch) fetch
    // that item's history instead, into the same #cc-d-hist-slot rendered by
    // buildRecord's pending branch.
    if(f.pending){
      if('new' !== f.pending.type && f.pending.itemId!=null) loadItemHistory(f.pending.itemId);
    } else if(f.id!=null){
      loadItemHistory(f.id);
    }
    const pl = photoList(f);
    const mainImg = document.querySelector('#drawerBody .cc-d-photo > img');
    const cap = document.getElementById('cc-d-cap');
    let cur = 0;
    function show(i){ cur=i; if(mainImg) mainImg.src=pl[i].sm; if(cap) cap.innerHTML=photoCap(pl[i]);
      document.querySelectorAll('#drawerBody .cc-d-thumb').forEach((t,k)=>t.classList.toggle('on',k===i)); }
    if(mainImg && pl.length) mainImg.addEventListener('click', ()=>openLightbox(pl, cur, f.name));
    document.querySelectorAll('#drawerBody .cc-d-thumb').forEach(t=>t.addEventListener('click', ()=>show(+t.dataset.i)));
    document.querySelectorAll('#drawerBody .cc-city').forEach(a=>{
      a.addEventListener('click', e=>{ e.preventDefault(); openCity(a.dataset.city); });
      a.addEventListener('mouseenter', ()=>{ const c=CITIES[a.dataset.city]; if(c) highlightAt(c.ll); });
      a.addEventListener('mouseleave', clearHighlight);
    });
    document.querySelectorAll('#drawerBody .cc-mod-btn').forEach(btn=>{
      btn.addEventListener('click', ()=>submitModeration(btn));
    });
  }
  function openDrawer(layer, f){
    // Guard (spec §16 S1): while picking correction stretches, the route line
    // still carries its normal layer click handler (drawLine's map.on('click',
    // 'experience-'+i, ()=>openDrawer(...))) — a click meant to drop a picking
    // point would ALSO fire that handler and open/switch the drawer under the
    // rider's feet. Bail out here so picking clicks never re-open a drawer;
    // pickClick (bound separately) still gets the same click event and drops
    // the point normally.
    if(_pick) return;
    clearRevealPin();   // decision C: a new pick supersedes any reveal pin (call sites re-drop after)
    clearSelectedCoverageIcon();   // drop the previous coverage selection's icon overlay; openCoverageDrawer re-adds it right after this returns
    _covReq++; _placeReq++;   // invalidate any in-flight coverage POI detail + town-card nearby fetch — this render supersedes them
    // Route selection emphasis: covers both the click path and the ?feature=
    // deep-link (both funnel through here). Layer id convention: the K line
    // layers are `experience-<feature index>` (see the drawLine call site).
    if(layer.key==='experience'){
      const i=layer.features.indexOf(f);
      if(i>=0 && map.getLayer('experience-'+i)) highlightRoute('experience-'+i);
      clearHighlight();                    // routes read as the wide line halo, not a point halo
    } else {
      clearRouteHighlight();
      // Pulsing selection halo on the clicked point — curated AND OSM — so the
      // selected place stands out; persists while the drawer is open and is
      // cleared by closeDrawer()/the next open. highlightAt(null) no-ops.
      // Confirmed items render as bottom-anchored teardrop pins whose icon sits
      // ~16px above the ground point, so raise the halo to ring the icon; flat
      // WebGL dots (unverified OSM) are centred on the point → no offset.
      // PENDING (moderation) pins are bottom-anchored teardrops too → same lift.
      // Climbs place their pin at the route START (foot), not geom.ll — the
      // halo must ring the pin the rider actually sees, not the centroid.
      const hlAt = f.route ? f.route[0] : (f.geom && f.geom.ll);   // [lat,lng] arrays both
      highlightAt(hlAt, (f.cur || f.pending) ? [0,-16] : [0,0]);
    }
    renderDrawerBody(layer, f);
    const d=document.getElementById('drawer'); d.classList.add('open'); d.setAttribute('aria-hidden','false');
    d.focus({preventScroll:true});   // move focus into the panel (not the close X — avoids a focus ring on tap/click open)
    if(window.innerWidth<=820) sheet.reset();          // land at half; desktop untouched
  }
  // place info card: fly to the town/village, show its info (when known) +
  // everything in the Commons within 5 km, grouped by layer. Works for any
  // geocoded place (spec 2026-07-14 §3.3): CITIES entries keep their wiki/info
  // blurbs; Photon hits pass just {ll}.
  function openPlace(name, meta){
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
  // (_placeReq is declared with _covReq above — shared drawer-generation convention.)
  function renderPlaceCard(name, meta, near, covGroups){
    const all=near.slice();
    (covGroups||[]).forEach(g=>{
      const key=LETTER_KEY[g.letter], layer=key&&layerByKey[key]; if(!layer) return;
      (g.items||[]).forEach(it=>{
        if(!it || !Array.isArray(it.ll)) return;
        if(it.itemId!=null && IDX_IDS.has(g.letter+':'+it.itemId)) return;
        all.push({dist:haversine(meta.ll, it.ll), e:{name:it.n||layer.label, kind:layer.label,
          badge:g.letter, color:layer.color, letter:g.letter, ll:it.ll, hlOff:[0,0], community:!it.curated,
          go:()=>openCoverageByRef(it.ref, g.letter, it.ll, it.n)}});
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
    _covReq++;   // invalidate any in-flight coverage POI detail — this render supersedes it
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
  function openCity(name){
    const c = CITIES[name]; if(!c) return;
    openPlace(name, c);
  }
  // Resolve a name to a CATALOG feature or PIVOT stay WITHOUT side effects —
  // shared by openFeatureByName and the deep-link auto-widen gate (07-20
  // review finding 9), so "does this deep link resolve?" can be asked before
  // any scope change or drawer open, and the two lookups can never drift.
  function resolveLocalFeature(name){
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
  function openFeatureByName(name){
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
  function openLocalFeature(layer, f){
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
  function openRouteById(id){
    const layer=layerByKey['experience']; if(!layer) return false;
    const f=layer.features.find(x=>String(x.id)===String(id));
    if(!f) return false;
    openDrawer(layer,f);
    const p=featurePoint(f); if(p) flyToPin([p[1],p[0]]);
    if(!(mode==='all'||f.cur) && p) revealPinAt(layer, p);
    showRouteCorrections(id);
    return true;
  }
  // §16 S3/S5: curator-only pending-corrections overlay — one colour per
  // correction, numbered stretch endpoints, bottom-left side list. The
  // /corrections endpoint 403s for non-curators; that's treated as "no
  // corrections" (renderCorrections never runs, nothing leaks).
  const CC_CORR_COLORS=['#FF5A1F','#3E9C8A','#C8923A','#6E7B96','#B5532E','#8FB6A8','#5F5A54','#6E5849'];
  let _corrLayers=[], _corrMarkers=[];
  function clearCorrections(){
    _corrLayers.forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); if(map.getSource(id)) map.removeSource(id); });
    _corrMarkers.forEach(m=>m.remove()); _corrLayers=[]; _corrMarkers=[];
    document.getElementById('cc-corrpanel')?.remove();
  }
  function showRouteCorrections(routeId){
    const path=routePathById(routeId); if(!path) return;
    fetch(`/routes/${routeId}/corrections`, {credentials:'same-origin', headers:{'Accept':'application/json'}})
      .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
      .then(d=>renderCorrections(path, d.corrections||[]))
      .catch(()=>clearCorrections());   // 403 (not curator) / error → clear any stale overlay
  }
  function renderCorrections(path, corrections){
    clearCorrections();
    if(!corrections.length) return;
    let ptN=0;
    corrections.forEach((c,ci)=>{
      const color=CC_CORR_COLORS[ci%CC_CORR_COLORS.length];
      c._color=color; c._pins=[];
      (c.segments||[]).forEach((seg,si)=>{
        const id=`corr-${c.id}-${si}`, coords=sliceByFrac(path, seg.start, seg.end).map(p=>[p[1],p[0]]);
        map.addSource(id,{type:'geojson',data:{type:'Feature',geometry:{type:'LineString',coordinates:coords}}});
        map.addLayer({id,type:'line',source:id,layout:{'line-cap':'round','line-join':'round'},paint:{'line-color':color,'line-width':7,'line-opacity':.92}});
        _corrLayers.push(id);
        [seg.start, seg.end].forEach(fr=>{ ptN++; const at=fracToLatLng(path,fr);
          const el=document.createElement('div'); el.className='cc-corr-pin'; el.style.background=color; el.textContent=String(ptN);
          _corrMarkers.push(new maplibregl.Marker({element:el,anchor:'center'}).setLngLat([at[1],at[0]]).addTo(map));
          c._pins.push(ptN);
        });
      });
    });
    buildCorrPanel(corrections, path);
  }
  function buildCorrPanel(corrections, path){
    const p=document.createElement('div'); p.id='cc-corrpanel'; p.className='cc-corrpanel';
    p.innerHTML=`<h4>Pending corrections</h4>`+corrections.map(c=>`
      <button class="cc-corr-item" data-corr="${c.id}">
        <span class="cc-corr-sw" style="background:${c._color}"></span>
        <span class="cc-corr-body"><b>${c.reason.replace(/-/g,' ')}</b>${c.note?` — ${escPend(c.note)}`:''}
          <em>${(c.segments||[]).length} stretch${(c.segments||[]).length===1?'':'es'}${c._pins&&c._pins.length?` · ${c._pins.join('→')}`:''}</em></span>
      </button>`).join('');
    document.querySelector('.mapwrap, .app, body').appendChild(p);
    p.onclick=e=>{ const b=e.target.closest('[data-corr]'); if(!b) return;
      const c=corrections.find(x=>String(x.id)===b.dataset.corr); if(!c||!c.segments||!c.segments.length) return;
      // zoom to this correction's first stretch
      const seg=c.segments[0], a=fracToLatLng(path,seg.start), b2=fracToLatLng(path,seg.end);
      map.fitBounds([[Math.min(a[1],b2[1]),Math.min(a[0],b2[0])],[Math.max(a[1],b2[1]),Math.max(a[0],b2[0])]],{padding:120,maxZoom:15,duration:600});
    };
  }
  // ---- Ride-check (spec 2026-07-14 §4.3): riders-only "what's along my GPX?" ----
  // Track overlay uses its own source/layer ids (render()'s clearDynamic never
  // touches them); results render in the standard right-hand drawer, exactly
  // like the town card (same .cc-near list styling). Closing the drawer keeps
  // the track on the map — the rail status offers "results" (re-open) and
  // "clear"; Clear tears everything down. Read-only indication — the server
  // parses the GPX in memory and stores nothing (notice in the rail control).
  (function initRideCheck(){
    if(!window.CC_RIDECHECK) return;                       // anonymous: no control rendered
    const pick=document.getElementById('rcPick'), fileIn=document.getElementById('rcFile'),
          radiusSel=document.getElementById('rcRadius'), status=document.getElementById('rcStatus');
    if(!pick || !fileIn || !radiusSel || !status) return;
    let _file=null, _busy=false, _last=null;
    const say=msg=>{ status.hidden=!msg; status.textContent=msg||''; };
    function loadedStatus(d){
      status.hidden=false;
      status.innerHTML=`${d.distanceKm} km · <a class="cc-ride-lnk" data-act="show">${I18N.rcResults||'results'}</a> · <a class="cc-ride-lnk" data-act="clear">${I18N.rcClear||'clear'}</a>`;
    }
    status.addEventListener('click',e=>{ const a=e.target.closest('[data-act]'); if(!a) return;
      if(a.dataset.act==='show' && _last) renderRideDrawer(_last);
      else if(a.dataset.act==='clear') clearRideCheck();
    });
    function rideDrawerShowing(){ return !!document.getElementById('rcClearBtn'); }
    function clearOverlay(){
      ['ridecheck','ridecheck-case'].forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); });
      if(map.getSource('ridecheck')) map.removeSource('ridecheck');
      clearHighlight();
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
      renderRideDrawer(d);
    }
    function renderRideDrawer(d){
      const asc=d.ascentM!=null?` · ↑ ${d.ascentM} m`:'';
      const radius=d.radiusM<1000?`${d.radiusM} m`:'1 km';
      const kColor=(layerByKey.experience||{}).color||'#FF5A1F';
      let html=`<span class="cc-d-type" style="--c:#3A3A33;color:#fff">➜ ${D.rideCheck||'Ride check'}</span>
       <div class="cc-d-name">${D.alongRide||'Along your ride'}</div>
       <div class="cc-city-info">${d.distanceKm} km${asc} · ${tpl(D.rideMeta||'within {r} of the track — indication only, nothing stored.', {r:radius})}</div>
       <div class="cc-ride-actions"><button class="cc-ride-btn" id="rcClearBtn" type="button">✕ ${D.clearRide||'Clear ride'}</button></div>`;
      if(d.routes.length){
        html+=`<h4 class="cc-near-h">${D.rideFollows||'Your ride follows'}</h4><ul class="cc-near-list">`
          +d.routes.map(r=>`<li><button class="cc-near" data-rc-route="${r.id}"><span class="cc-near-k" style="background:${kColor};color:${txtOn(kColor)}">K</span><span class="cc-near-nm">${escPend(r.name)}</span><em>${tpl(D.kmShared||'{n} km shared', {n:r.sharedKm})}</em></button></li>`).join('')
          +`</ul>`;
      }
      html+=`<h4 class="cc-near-h">${D.alongTrackH||'In the Commons along the track'}</h4>`;
      if(d.groups.length){
        html+=`<ul class="cc-near-list">`;
        d.groups.forEach(g=>{
          const meta=CATALOG.find(l=>l.letter===g.letter)||{color:'#6b6f5e',label:g.letter};
          html+=`<li class="cc-near-grp"><span class="cc-near-k" style="background:${meta.color};color:${txtOn(meta.color)}">${g.letter}</span>${escPend(meta.label)} · ${g.items.length}${g.truncated?` ${D.capped||'(capped)'}`:''}</li>`;
          html+=g.items.map((it,i)=>`<li><button class="cc-near" data-rc-g="${g.letter}" data-rc-i="${i}"><span class="cc-near-nm">${escPend(it.name||meta.label)}</span><em>${tpl(D.kmOff||'km {a} · {b} m off', {a:it.alongKm, b:it.distM})}</em></button></li>`).join('');
        });
        html+=`</ul>`;
      } else {
        html+=`<div class="cc-near-empty">${tpl(D.nothingWithin||'Nothing in the Commons within {r} of this ride yet.', {r:radius})}</div>`;
      }
      const body=document.getElementById('drawerBody');
      _covReq++; _placeReq++;   // invalidate any in-flight coverage POI detail + town-card nearby fetch — this render supersedes them
      body.innerHTML=html;
      document.getElementById('rcClearBtn').onclick=clearRideCheck;
      const groupsByLetter=Object.fromEntries(d.groups.map(g=>[g.letter,g]));
      body.querySelectorAll('[data-rc-route]').forEach(b=>{ b.onclick=()=>openRouteById(b.dataset.rcRoute); });
      body.querySelectorAll('[data-rc-g]').forEach(b=>{
        const it=(groupsByLetter[b.dataset.rcG]||{items:[]}).items[+b.dataset.rcI]; if(!it) return;
        const entry=ITEM_INDEX.find(x=>x.letter===b.dataset.rcG && x.id===it.id);
        b.onclick=()=>{ if(entry) entry.go(); else { flyToPin([it.ll[1],it.ll[0]]); highlightAt(it.ll); } };
        b.onmouseenter=()=>highlightAt(it.ll, entry && entry.hlOff);
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
  })();

  // open a pending submission by id (deep-link from the /moderate queue's "View on map")
  function openPendingById(id){
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
  function openStayPivot(f){
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
  // pulsing highlight marker — show where a hovered list item / town sits on the map
  let hlMarker=null;
  function highlightAt(ll, offset){
    if(!ll){ return clearHighlight(); }
    if(!hlMarker){ const el=document.createElement('div'); el.className='cc-highlight'; hlMarker=new maplibregl.Marker({element:el,anchor:'center'}); }
    hlMarker.setOffset(offset||[0,0]).setLngLat([ll[1],ll[0]]).addTo(map);
  }
  function clearHighlight(){ if(hlMarker) hlMarker.remove(); }
  // Selected coverage-POI icon overlay (fix 2026-07-22): the exact tile icon id
  // for a clicked POI, so the cov-sel overlay redraws it and it survives the
  // tile clustering that hides the individual icon on zoom-out. Mirrors the
  // icon-image match expressions of the <key>-<cc>-cov layers (addCoverage).
  function coverageIconId(key, tp){
    if(key==='water') return ['yes','true','1'].includes(String(tp.potable)) ? 'water-drop' : 'water-drop-unk';
    if(key==='services'){
      if(tp.kind==='station') return miniIcon('services', SERVICE_GLYPH.station, 'station');
      if(tp.kind==='pump') return miniIcon('services', SERVICE_GLYPH.pump, 'pump');
      return miniIcon('services');
    }
    return miniIcon(key);
  }
  function showSelectedCoverageIcon(key, tp, ll){
    const src=map.getSource('cov-sel'); if(!src||!ll) return;
    // Per-key icon-size ramps, identical to the <key>-<cc>-cov tile layers, as
    // data-driven stop outputs for the overlay's single zoom interpolate.
    const w=key==='water';
    src.setData({type:'FeatureCollection',features:[{type:'Feature',
      geometry:{type:'Point',coordinates:[ll.lng,ll.lat]},
      properties:{_icon:coverageIconId(key,tp),
        _s8:w?0.55:0.42, _s13:w?0.9:0.7, _s18:w?1.3:0.95}}]});
  }
  function clearSelectedCoverageIcon(){ const src=map.getSource('cov-sel'); if(src) src.setData({type:'FeatureCollection',features:[]}); }
  // Reveal pin (07-15 decision C): picking a NON-DRAWN feature from search in
  // Curated mode drops one temporary marker (community look + selection pulse)
  // instead of force-switching the whole map to Everything. Cleared on the
  // next pick (openDrawer clears it up front), on drawer close, and on a mode
  // change.
  let _revealMarker=null;
  function clearRevealPin(){ if(_revealMarker){ _revealMarker.remove(); _revealMarker=null; } }
  function revealPinAt(layer, ll){
    clearRevealPin();
    const el=pinEl(layer, false);
    el.classList.add('community','reveal');
    _revealMarker=new maplibregl.Marker({element:el, anchor:'bottom'}).setLngLat([ll[1],ll[0]]).addTo(map);
  }
  function closeDrawer(){
    // If the rider closes the drawer (X / scrim / Escape) mid-pick, tear the
    // picking session down too — an orphaned map click handler + toolbar with
    // no drawer to return to would be a dead-end. Uncommitted points (this
    // session hasn't hit Done) are simply dropped; any previously-Done
    // stretches already live in _pickSegs and are untouched.
    if(_pick) cancelPicking();
    // Task 10 race convention: an explicit close invalidates any in-flight
    // coverage POI detail AND any in-flight town-card nearby fetch — without
    // this, openCoverageByRef (a FIRST opener, so the its-drawer-still-open
    // guard can't apply) would reopen a drawer the rider just dismissed when
    // the /map/coverage/poi/{ref} response lands, and a stale
    // /map/coverage/nearby response would resurrect a dismissed town card
    // (_placeReq bumps everywhere _covReq does — shared drawer-generation convention).
    _covReq++; _placeReq++;
    const d=document.getElementById('drawer'); d.classList.remove('open'); d.setAttribute('aria-hidden','true');
    clearHighlight();
    clearSelectedCoverageIcon();                        // remove the selected coverage POI's persistent icon overlay
    clearRevealPin();
    clearRouteHighlight();
    clearCorrections();
    sheet.clear();                                     // drop snap classes + inline transform for the next open
  }
  // lightbox doubles as a slideshow over a feature's photo gallery
  let _lb={photos:[],i:0,name:''};
  function openLightbox(photos, i, name){
    _lb.photos = Array.isArray(photos) ? photos : [{lg:photos, credit:'', license:'', source:''}];
    _lb.i = i||0; _lb.name = name||'';
    renderLightbox();
    const lb=document.getElementById('lightbox'); lb.classList.add('open'); lb.setAttribute('aria-hidden','false');
  }
  function renderLightbox(){
    const lb=document.getElementById('lightbox'), p=_lb.photos[_lb.i], multi=_lb.photos.length>1;
    lb.querySelector('img').src=p.lg;
    lb.querySelector('.cc-lb-cap').innerHTML =
      (_lb.name?`<b>${escPend(_lb.name)}</b> · `:'') + (p.source?photoCap(p):'') + (multi?` · ${_lb.i+1} / ${_lb.photos.length}`:'');
    lb.querySelector('.cc-lb-prev').hidden=!multi; lb.querySelector('.cc-lb-next').hidden=!multi;
  }
  function lbStep(d){ const n=_lb.photos.length; if(!n) return; _lb.i=(_lb.i+d+n)%n; renderLightbox(); }
  function closeLightbox(){
    const lb=document.getElementById('lightbox'); lb.classList.remove('open');
    lb.setAttribute('aria-hidden','true'); lb.querySelector('img').src='';
  }
  document.getElementById('drawerClose').onclick=closeDrawer;
  document.getElementById('drawerScrim').onclick=closeDrawer;   // tap the dimmed area above the bottom sheet to close
  // Mobile snap sheet (spec 2026-07-14): peek / half / full resting states.
  // Content scrolls only at full; below full any vertical drag moves the
  // sheet; at full, a downward drag while scrollTop===0 grabs the sheet back
  // (Google-Maps-style hand-off). Desktop (>820px) never enters this code.
  const sheet=(function initSnapSheet(){
    const d=document.getElementById('drawer'), grab=document.getElementById('drawerGrab');
    if(!d||!grab) return {reset(){}};
    const mobile=()=>window.innerWidth<=820;
    const rem=()=>parseFloat(getComputedStyle(document.documentElement).fontSize)||16;
    let snap='half', justDragged=false;
    const setSnap=s=>{ snap=s; d.classList.toggle('s-full', s==='full'); d.classList.toggle('s-peek', s==='peek'); d.style.transform=''; if(s==='full') {} else d.scrollTop=0; };
    // translateY offsets (px from fully-open) for each resting state
    function offsets(){
      const h=d.getBoundingClientRect().height;
      return {full:0, half:Math.max(0, h-window.innerHeight*0.5), peek:Math.max(0, h-7.5*rem())};
    }
    let active=false, dragging=false, viaGrab=false, startY=0, startT=0, dy=0, baseOff=0;
    d.addEventListener('touchstart', e=>{
      if(!mobile() || e.touches.length!==1 || !d.classList.contains('open')) return;
      viaGrab=grab.contains(e.target);
      if(!viaGrab && snap==='full' && d.scrollTop>0) return;   // mid-scroll at full → content's gesture
      active=true; dragging=false; startY=e.touches[0].clientY; startT=e.timeStamp; dy=0;
      // baseOff from the sheet's ACTUAL rendered transform, not the offsets()
      // table: the CSS half rule rests at 50svh while offsets() uses
      // innerHeight — with a retracted URL bar those bases differ and a
      // table-derived baseOff would jump the sheet on the first touchmove.
      // offsets() is still fine for the release-snap targets below.
      const tr=getComputedStyle(d).transform;
      baseOff = (tr && tr!=='none') ? new DOMMatrixReadOnly(tr).m42 : 0;
    }, {passive:true});
    d.addEventListener('touchmove', e=>{
      if(!active) return;
      dy=e.touches[0].clientY-startY;
      if(!dragging){
        // At full, content owns upward drags (scroll); the sheet owns downward
        // ones from scrollTop 0. Below full the sheet owns both directions.
        if(!viaGrab && snap==='full' && dy<-4){ active=false; return; }
        if(Math.abs(dy)<=4) return;                    // wait for a clear direction
        dragging=true;
      }
      e.preventDefault();                              // own the gesture (passive:false)
      d.classList.add('dragging');
      const off=offsets();
      d.style.transform=`translateY(${Math.min(Math.max(0, baseOff+dy), off.peek+40)}px)`;   // clamp: never above full; slight give past peek
    }, {passive:false});
    d.addEventListener('touchend', ()=>{
      if(!dragging){ active=false; return; }
      active=false; dragging=false; justDragged=true; setTimeout(()=>{ justDragged=false; }, 450);
      d.classList.remove('dragging');
      const off=offsets(), pos=baseOff+dy, vel=dy/Math.max(1, performance.now()-startT);   // px/ms, + = down
      // fast flick: skip straight to the neighbouring state in the flick's direction
      if(vel>0.5){ if(snap==='peek' || pos>off.peek+20){ dismiss(); return; } setSnap(snap==='full'?'half':'peek'); return; }
      if(vel<-0.5){ setSnap(snap==='peek'?'half':'full'); return; }
      if(pos>off.peek+60){ dismiss(); return; }        // dragged well past peek → close
      // otherwise: snap to nearest resting state
      let best='full', bestD=Infinity;
      ['full','half','peek'].forEach(s=>{ const dd=Math.abs(pos-off[s]); if(dd<bestD){ bestD=dd; best=s; } });
      setSnap(best);
    });
    d.addEventListener('touchcancel', ()=>{
      if(!active && !dragging) return;
      active=false; dragging=false;
      d.classList.remove('dragging');
      setSnap(snap);                    // re-settle on the last resting state
    });
    function dismiss(){
      d.style.transform='translateY(102%)';
      let closed=false;
      const fin=ev=>{ if(closed||(ev&&ev.propertyName!=='transform')) return; closed=true;
        d.removeEventListener('transitionend',fin); closeDrawer(); };
      d.addEventListener('transitionend', fin);
      setTimeout(fin, 320);                            // fallback if transitionend doesn't fire
    }
    grab.addEventListener('click', ()=>{ if(!mobile()||justDragged) return; setSnap(snap==='full'?'half':'full'); });   // Enter/Space included (button)
    return {
      reset(){ setSnap('half'); },                     // openDrawer lands every sheet at half
      clear(){ d.classList.remove('s-full','s-peek'); d.style.transform=''; snap='half'; },
    };
  })();
  document.querySelector('.cc-lb-prev').onclick=e=>{ e.stopPropagation(); lbStep(-1); };
  document.querySelector('.cc-lb-next').onclick=e=>{ e.stopPropagation(); lbStep(1); };
  document.getElementById('lightbox').addEventListener('click',e=>{ if(e.target.id==='lightbox'||e.target.classList.contains('cc-lb-x')) closeLightbox(); });
  document.addEventListener('keydown',e=>{
    const lbOpen=document.getElementById('lightbox').classList.contains('open');
    if(e.key==='Escape'){ lbOpen ? closeLightbox() : closeDrawer(); }
    else if(lbOpen && e.key==='ArrowLeft') lbStep(-1);
    else if(lbOpen && e.key==='ArrowRight') lbStep(1);
  });

  const tipEl=document.getElementById('tip');
  function showTip(text, lngLat){
    const p=map.project(lngLat);
    tipEl.textContent=text; tipEl.style.left=p.x+'px'; tipEl.style.top=p.y+'px'; tipEl.hidden=false;
  }
  function hideTip(){ tipEl.hidden=true; }
  map.on('move', ()=>{ if(!tipEl.hidden) hideTip(); });

  // catalog in canonical A–K order for the rail + legend (display only; render keeps CATALOG order)
  const CATALOG_AZ=[...CATALOG].sort((a,b)=>a.letter<b.letter?-1:1);

  // build layer toggles
  const lc=document.getElementById('layers');
  CATALOG_AZ.forEach(layer=>{
    const el=document.createElement('div');
    el.className='layer'; el.style.setProperty('--c',layer.color); el.style.setProperty('--ic',txtOn(layer.color));
    el.style.setProperty('--ig', txtOn(layer.color)==='#fff' ? 'brightness(0) invert(1)' : 'brightness(0)'); el.dataset.key=layer.key;
    if(!active.has(layer.key)) el.classList.add('off');
    const _lc=layerCounts(layer);
    const ct=`${_lc.shown}/${_lc.total}`;
    el.innerHTML=`<span class="sw"><i class="sw-g">${layer.icon}</i></span><span class="nm">${layer.letter} · ${layer.label}</span><span class="ct">${ct}</span>`;
    el.onclick=()=>{ if(active.has(layer.key)){active.delete(layer.key);el.classList.add('off')} else {active.add(layer.key);el.classList.remove('off')} syncLayersAll(); render(); };
    lc.appendChild(el);
  });
  // (de)select-all toggle for the data layers
  const layersAll=document.getElementById('layersAll');
  function syncLayersAll(){ layersAll.textContent = CATALOG.every(l=>active.has(l.key)) ? (I18N.deselectAll||'deselect all') : (I18N.selectAll||'select all'); }
  layersAll.onclick=()=>{
    const allOn=CATALOG.every(l=>active.has(l.key));
    CATALOG.forEach(l=>{ if(allOn) active.delete(l.key); else active.add(l.key); });
    document.querySelectorAll('#layers .layer').forEach(el=>el.classList.toggle('off', !active.has(el.dataset.key)));
    syncLayersAll(); render();
  };
  syncLayersAll();

  // on-map base/overlay control (top-right) — Map ↔ Satellite + Street-level overlay
  document.querySelectorAll('#baseSeg button').forEach(b=>b.onclick=()=>{
    document.querySelectorAll('#baseSeg button').forEach(x=>x.classList.remove('on'));
    b.classList.add('on');
    const sat=b.dataset.b==='satellite';
    if(map.getLayer('satellite')) map.setLayoutProperty('satellite','visibility', sat?'visible':'none');
    document.querySelector('.map-wrap').classList.toggle('sat', sat);
  });
  const ovStreet=document.getElementById('ovStreet');
  if(MLY_ENABLED){
    ovStreet.onclick=function(){
      const on=!this.classList.contains('on'); this.classList.toggle('on',on);
      mlyOn=on;
      ['mly-cov','mly-img'].forEach(id=>{ if(map.getLayer(id)) map.setLayoutProperty(id,'visibility', on?'visible':'none'); });
      if(!on) mlyClose();
    };
  } else {
    // no Mapillary token → coverage tiles can't load; show the control as unavailable instead of a dead toggle
    ovStreet.classList.add('mc-off');
    ovStreet.title='Street-level needs a Mapillary token — set MAPILLARY_TOKEN in /map';
  }
  // mobile: the control collapses to a small layers icon — tap to expand, and
  // collapse again after a choice is made
  const mcToggle=document.getElementById('mcToggle');
  const mapCtrl=document.querySelector('.map-ctrl');
  if(mcToggle && mapCtrl){
    mcToggle.onclick=()=>{
      const open=mapCtrl.classList.toggle('open');
      mcToggle.setAttribute('aria-expanded', open?'true':'false');
    };
    mapCtrl.querySelectorAll('#baseSeg button, #ovStreet').forEach(b=>b.addEventListener('click',()=>{
      if(window.innerWidth<=760){ mapCtrl.classList.remove('open'); mcToggle.setAttribute('aria-expanded','false'); }
    }));
  }

  // mobile: filters bottom-sheet — the rail-foot peek toggles it
  const app=document.querySelector('.app');
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
    ITEM_INDEX = buildItemIndex();
    IDX_IDS = new Set(ITEM_INDEX.filter(e=>e.id!=null).map(e=>e.letter+':'+e.id));
    ITEM_INDEX.forEach(e=>{ if(!e.unnamed) SEARCH_IDX.push(e); });   // nameless POIs list in place cards, not in text search
    // pending entries carry their submission id so hidePendingPin can drop
    // them from the index after a moderation decision (review W36) — the
    // feature disappears from the map, and a search hit that "does nothing"
    // must disappear with it. Both lists share the entry objects.
    _searchDropPending=id=>{
      for(let i=SEARCH_IDX.length-1;i>=0;i--){ if(SEARCH_IDX[i].pend===String(id)) SEARCH_IDX.splice(i,1); }
      for(let i=ITEM_INDEX.length-1;i>=0;i--){ if(ITEM_INDEX[i].pend===String(id)) ITEM_INDEX.splice(i,1); }
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
    // (region-scoping-design.md §6), so the wider rung's rows only appear once
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
    // Scopes section (2026-07-22-scope-selector-scale-design.md §A/§B): a
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
      // Scope the search to the active region (Phase 3, region-scoping-design.md
      // §6): the server returns only in-scope rows, so the coverage results
      // mirror the scope-filtered tiles. Everywhere adds no params.
      const sq=covScopeQuery();
      fetch('/map/coverage/search?q='+encodeURIComponent(q)+(sq?('&'+sq):''), {signal:ctl.signal, headers:{'Accept':'application/json'}})
        .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(d=>{
          if(ctl.signal.aborted) return;
          _covHits=(d.results||[])
            .filter(h=>h && h.n && LETTER_KEY[h.letter] && Array.isArray(h.ll))
            .filter(h=>!(h.itemId!=null && IDX_IDS.has(h.letter+':'+h.itemId)))   // curated twin already indexed locally
            .map(h=>{ const layer=layerByKey[LETTER_KEY[h.letter]];
              return {name:h.n, key:slug(h.n), kind:layer.label, badge:h.letter, color:layer.color,
                letter:h.letter, ll:h.ll, cov:1, community:!h.curated,
                go:()=>openCoverageByRef(h.ref, h.letter, h.ll, h.n)}; });
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
        // Scope-first search (region-scoping-design.md §4, 07-20 review
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
      // region-scoping-design.md §6/§7): runCoverageSearch sends the active
      // scope's rids/cc, so _covHits already mirror the scope-filtered tiles —
      // the widen chip re-runs the fetch rung by rung. (The served local rows
      // above are gated client-side by inScope() since they aren't re-fetched.)
      if(_covSQ===q) _covHits.forEach(m=>{ (byLetter[m.letter]=byLetter[m.letter]||[]).push(m); });
      // decision A ordering: verified/curated rows first inside each letter
      // group, community after. Array.prototype.sort is stable (ES2019), so the
      // prefix-before-substring ranking survives within each tier.
      Object.keys(byLetter).forEach(L=>byLetter[L].sort((a,b)=>(commRow(a)?1:0)-(commRow(b)?1:0)));
      // Scopes group (task-6 brief; 2026-07-22-scope-selector-scale-design.md
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
      // Widen chip (region-scoping-design.md §4, the Craigslist lesson): a
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
      sRes.innerHTML = (realCount ? html : `<li class="search-empty">${D.noMatch||'No match in the Wallonia demo yet.'}</li>`) + widenHtml;
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

  const sheetHandle=document.querySelector('.rail-foot .res');
  const railFoot=document.querySelector('.rail-foot');
  if(app && sheetHandle){
    let _sheetSwiped=false;
    sheetHandle.addEventListener('click',()=>{ if(_sheetSwiped) return; if(window.innerWidth<=820) app.classList.toggle('sheet-open'); });
    const exp=document.querySelector('.rail-foot .export');
    if(exp) exp.addEventListener('click',e=>e.stopPropagation());   // export ≠ sheet toggle
    map.on('dragstart',()=>app.classList.remove('sheet-open'));      // collapse when panning
    // mobile: swipe the filters handle down to close it (or up to open it) — the sheet has no ✕, only this bar
    let fy=0, fActive=false, fMoved=0, fOpen=false;
    railFoot.addEventListener('touchstart', e=>{
      if(window.innerWidth>820 || e.touches.length!==1 || e.target.closest('.export')) return;
      fActive=true; fy=e.touches[0].clientY; fMoved=0; fOpen=app.classList.contains('sheet-open');
    }, {passive:true});
    railFoot.addEventListener('touchmove', e=>{
      if(!fActive) return; fMoved=e.touches[0].clientY-fy;
      if((fOpen && fMoved>0) || (!fOpen && fMoved<0)) e.preventDefault();   // own a meaningful vertical swipe
    }, {passive:false});
    railFoot.addEventListener('touchend', ()=>{
      if(!fActive) return; fActive=false;
      if(fOpen && fMoved>45) app.classList.remove('sheet-open');            // drag down → close
      else if(!fOpen && fMoved<-45) app.classList.add('sheet-open');        // drag up → open
      else return;
      _sheetSwiped=true; setTimeout(()=>{ _sheetSwiped=false; }, 450);      // suppress the swipe's synthesized click
    });
  }

  // mobile: road-surface legend collapses to an icon (mirrors #mcToggle)
  const lgToggle=document.getElementById('lgToggle'), legend=document.querySelector('.legend');
  if(lgToggle && legend) lgToggle.onclick=()=>{ const o=legend.classList.toggle('open'); lgToggle.setAttribute('aria-expanded',o?'true':'false'); };

  // mobile: top-bar nav hamburger -> dropdown
  const railHead=document.querySelector('.rail-head'), railBurger=document.getElementById('railBurger');
  if(railHead && railBurger){
    railBurger.onclick=e=>{ e.stopPropagation(); const o=railHead.classList.toggle('nav-open'); railBurger.setAttribute('aria-expanded',o?'true':'false'); };
    document.addEventListener('click',e=>{ if(railHead.classList.contains('nav-open') && !railHead.contains(e.target)){ railHead.classList.remove('nav-open'); railBurger.setAttribute('aria-expanded','false'); } });
    document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ railHead.classList.remove('nav-open'); railBurger.setAttribute('aria-expanded','false'); } });
  }

  // the #map box only really changes size at the 820px layout flip → resize then
  const _mq=window.matchMedia('(max-width:820px)');
  const _onBP=()=>requestAnimationFrame(()=>map.resize());
  _mq.addEventListener ? _mq.addEventListener('change',_onBP) : _mq.addListener(_onBP);

  // Route domain phase 4 (spec §8): Curated mode = best-of for a (season, bike)
  // facet, fetched from /map/best-of; the returned ids get cur:true and Curated
  // filters K routes to them. A named-region scope now sends &region= too
  // (region-scoping-design.md §6 / §7 Phase 2).
  const CC_SEASON_LABEL=Object.assign({spring:'Spring',summer:'Summer',autumn:'Autumn',winter:'Winter'}, I18N.seasons||{});
  const CC_BIKE_LABEL=I18N.bikes||{};
  function currentSeason(){ const m=new Date().getMonth()+1; return m>=3&&m<=5?'spring':m>=6&&m<=8?'summer':m>=9&&m<=11?'autumn':'winter'; }
  let boSeason=currentSeason(), boBike='all';

  function updateSubtitle(){
    const sub=document.querySelector('.map-top .sub'); if(!sub) return;
    if(mode==='all'){ sub.textContent=I18N.subEverything||'Everything · full backlog'; return; }
    const bike=boBike==='all' ? (I18N.allBikes||'All bikes') : (CC_BIKE_LABEL[boBike]||boBike);
    sub.textContent=`${I18N.curated||'Curated best-of'} · ${CC_SEASON_LABEL[boSeason]} · ${bike}`;
  }

  function applyBestOf(ids){
    // Membership only: the endpoint returns ids in rank order (vote count, then
    // recency), but the map surfaces best-of routes as unordered lines — Curated
    // shows the set, Everything shows all. The server ORDER BY is latent until a
    // ranked-list UI consumes it; don't assume order is honoured client-side.
    const set=new Set((ids||[]).map(Number));
    const feats=(layerByKey['experience']||{}).features||[];
    feats.forEach(f=>{ f.cur = set.has(Number(f.id)); });
    render();
  }

  // Race-guard token, same pattern as the drawer's _historyReq (review W5):
  // switching Season/Bike quickly must never let a slower earlier response
  // overwrite the newer facet's membership under a subtitle that says otherwise.
  let _bestOfReq=0;
  function refreshBestOf(){
    const req=++_bestOfReq;
    if(mode!=='curated'){ render(); return; }
    // A named-region scope sends &region=<id>; My area sends its derived rid SET
    // as a CSV (&region=1,24) so best-of ranks across the whole home-base area
    // (MapController parses the CSV; region-scoping-design.md §6 / §9.1 Phase 4).
    // Country/Everywhere send no region — the guarded unbounded aggregate. A
    // single named region keeps the identical single-id request as before.
    const regionIds = window.CCScope && window.CCScope.bestOfRegionIds();
    const regionQ = (regionIds && regionIds.length) ? `&region=${encodeURIComponent(regionIds.join(','))}` : '';
    fetch(`/map/best-of?season=${encodeURIComponent(boSeason)}&bike=${encodeURIComponent(boBike)}${regionQ}`,
      {credentials:'same-origin', headers:{'Accept':'application/json'}})
      .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
      .then(d=>{ if(req===_bestOfReq) applyBestOf(d.ids); })
      .catch(()=>{ if(req===_bestOfReq) applyBestOf([]); });   // on failure, Curated shows no picks rather than a stale set
  }

  // Facet pickers (Curated only).
  const boSeasonEl=document.getElementById('boSeason'), boBikeEl=document.getElementById('boBike');
  if(boSeasonEl){ boSeasonEl.value=boSeason; boSeasonEl.onchange=()=>{ boSeason=boSeasonEl.value; updateSubtitle(); refreshBestOf(); }; }
  if(boBikeEl){ boBikeEl.onchange=()=>{ boBike=boBikeEl.value; updateSubtitle(); refreshBestOf(); }; }

  // mode toggle
  document.querySelectorAll('#mode button').forEach(b=>b.onclick=()=>{
    document.querySelectorAll('#mode button').forEach(x=>x.classList.remove('on'));
    b.classList.add('on'); mode=b.dataset.m;
    clearRevealPin();   // decision C: mode change clears any reveal pin
    const bf=document.getElementById('bestFacets'); if(bf) bf.hidden = (mode!=='curated');
    updateSubtitle();
    refreshBestOf();          // Curated → fetch + filter; Everything → plain render()
  });

  // Region scope selector (region-scoping-design.md §4 / §7 Phase 2): buttons
  // call window.CCScope; its cc:scopechange event drives the single visual
  // update path (applyScope), which also persists to localStorage + URL.
  // Narrowed to the two STATIC buttons (myarea/everywhere) — the region/country
  // chips are JS-rendered now (2026-07-22-scope-selector-scale-design.md §B) and
  // bind themselves inside renderScopeChips(); a wildcard #regionScope selector
  // here would double-bind them.
  document.querySelectorAll('#regionScope > button').forEach(b=>b.onclick=()=>{
    const tok = b.dataset.scope||'';
    if(!window.CCScope) return;
    if(tok==='everywhere') window.CCScope.setEverywhere();
    else if(tok==='myarea') window.CCScope.setMyArea();   // Phase 4: resolves from the base-location source
    else if(tok.startsWith('country:')) window.CCScope.setCountry(tok.slice(8));
    else if(tok.startsWith('region:')) window.CCScope.setRegion(tok.slice(7));
  });
  // renderScopeChips()/applyScope() order (2026-07-23 flash fix): no longer
  // coupled — see the load-handler comment above. Kept in this order anyway to
  // avoid unrelated churn.
  window.addEventListener('cc:scopechange', e=>{ renderScopeChips(); applyScope(e.detail, {fit:true}); });

  // Pan-away widen nudge (region-scoping-design.md §4 "Deep links & far panning"):
  // when a My-area scope is active and the map centre drifts past 1.5× the circle
  // radius, surface a one-tap widen prompt — NEVER auto-widen, the rider taps. It
  // hides again once the centre comes back inside; once dismissed or acted on it
  // stays gone for the rest of the page load (no per-moveend nagging).
  (function initPanAwayNudge(){
    if(!window.CCScope) return;
    let nudge=null, dismissed=false;
    const build=()=>{
      nudge=document.createElement('div'); nudge.id='cc-area-nudge'; nudge.className='cc-area-nudge'; nudge.hidden=true;
      const msg=document.createElement('span'); msg.className='cc-nudge-msg';
      const go=document.createElement('button'); go.type='button'; go.className='cc-nudge-go';
      const x=document.createElement('button'); x.type='button'; x.className='cc-nudge-x'; x.textContent='✕';
      x.setAttribute('aria-label', I18N.areaDismiss||'Dismiss');
      nudge.append(msg,go,x);
      (document.querySelector('.map-wrap')||document.body).appendChild(nudge);
      go.onclick=()=>{ dismissed=true; nudge.hidden=true; window.CCScope.widen(); };   // the rider chose to widen
      x.onclick=()=>{ dismissed=true; nudge.hidden=true; };
    };
    const hide=()=>{ if(nudge) nudge.hidden=true; };
    const show=()=>{
      if(!nudge) build();
      nudge.querySelector('.cc-nudge-msg').textContent = I18N.outsideArea||'Outside your area';
      const nw=window.CCScope.nextWider();
      // Reuse the search-widen label so the nudge names the target rung; Everywhere
      // has no rail label to interpolate, so it reads "Search everywhere".
      nudge.querySelector('.cc-nudge-go').textContent = (nw && nw.kind==='everywhere')
        ? (I18N.searchEverywhere||'Search everywhere')
        : tpl(I18N.searchWiden||'Search in {area} instead', {area:scopeLabel(nw)});
      nudge.hidden=false;
    };
    map.on('moveend',()=>{
      const s=curScope();
      if(!s||s.kind!=='myArea'||!s.myArea){ hide(); return; }
      const c=map.getCenter(), ctr=s.myArea.center;   // ctr = [lat, lng]
      // Equirectangular ground distance (km) from the map centre to the home
      // circle centre — plenty accurate at riding scale.
      const dLat=(c.lat-ctr[0])*111.32;
      const dLng=(c.lng-ctr[1])*111.32*Math.cos(ctr[0]*Math.PI/180);
      const dist=Math.sqrt(dLat*dLat+dLng*dLng);
      if(dist > 1.5*s.myArea.radiusKm){ if(!dismissed) show(); }
      else hide();
    });
  })();

  // Exactly one saved bike → preselect the Curated facet (single-valued
  // select; multi-bike riders keep the neutral 'all').
  if(PREFS.bikes.length===1 && boBikeEl && [...boBikeEl.options].some(o=>o.value===PREFS.bikes[0])){
    boBikeEl.value=PREFS.bikes[0]; boBike=PREFS.bikes[0];
  }

  // Initial best-of for the default facet so Curated isn't empty on load.
  updateSubtitle();
  { const bf=document.getElementById('bestFacets'); if(bf) bf.hidden = (mode!=='curated'); }
  refreshBestOf();
  // discipline + freshness chips (visual)
  document.querySelectorAll('#disc .chip, .grp .chips .chip').forEach(c=>c.onclick=()=>c.classList.toggle('on'));
  // Saved riding styles preselect the (visual-only) discipline chips.
  if(PREFS.styles.length){
    document.querySelectorAll('#disc .chip').forEach(c=>c.classList.toggle('on', PREFS.styles.includes(c.dataset.style)));
  }
  // Preference prefilter chip: later onclick assignment overrides the generic
  // toggle-only binder above (same pattern as the climb-filter chips below).
  (function initPrefChip(){
    const chip=document.getElementById('prefFilter'), grp=document.getElementById('prefGrp');
    if(!chip||!grp||!PREFS.bikes.length) return;      // anonymous / no prefs → group stays hidden
    grp.hidden=false;
    const sync=()=>{ chip.classList.toggle('on', prefFilterOn); chip.setAttribute('aria-pressed', prefFilterOn?'true':'false'); };
    const flip=()=>{ prefFilterOn=!prefFilterOn; try{ localStorage.setItem('cc-pref-filter', prefFilterOn?'on':'off'); }catch(e){} sync(); render(); updateCounts(); };
    chip.onclick=flip;
    chip.onkeydown=e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); flip(); } };
    sync();
  })();
  // "Set my area" cold-start prompt (region-scoping-design.md §4 / §9.1 Phase 4):
  // the pref-chip pattern — a hidden rail chip revealed only when the rider has
  // NO My-area source yet (no base location, no anon circle) AND hasn't dismissed
  // it. Tapping derives the base from the map centre (Komoot's suggest-home
  // pattern): a logged-in POST to the base-location endpoint, or an anonymous
  // localStorage-only circle. Coordinates are rounded to 2 decimals BEFORE they
  // leave the page (request precision == stored precision — no raw point in the
  // request or in localStorage; owner privacy invariant). MUST run after the
  // generic '.grp .chips .chip' toggle binder above (same as initPrefChip), or
  // that assignment clobbers this chip's onclick with a toggle-only handler.
  (function initAreaPrompt(){
    const row=document.getElementById('areaPromptRow');
    const setChip=document.getElementById('areaPromptSet');
    const xBtn=document.getElementById('areaPromptX');
    if(!row||!setChip||!xBtn||!window.CCScope) return;
    const DISMISS_KEY='cc-area-prompt-dismissed';
    let dismissed=false; try{ dismissed=!!localStorage.getItem(DISMISS_KEY); }catch(e){}
    // A My-area already exists (base location or a saved anon circle) → the prompt
    // is moot; leave it hidden.
    if(window.CCScope.myAreaAvailable()||dismissed) return;
    row.hidden=false;
    const hide=()=>{ row.hidden=true; };
    const revealMyAreaBtn=()=>{ const mb=document.getElementById('myAreaBtn'); if(mb) mb.hidden=false; };
    function setMyAreaFromCentre(){
      const c=map.getCenter();
      const lat=Math.round(c.lat*100)/100, lng=Math.round(c.lng*100)/100;   // round client-side
      if(window.CC_MY_AREA && CC_MY_AREA.url){
        // Logged-in: persist the coarse point server-side (the server rounds again
        // as the invariant holder) and merge back the derived region/country set.
        fetch(CC_MY_AREA.url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CC_MY_AREA.token},body:JSON.stringify({lat,lng})})
          .then(r=>r.ok?r.json():null)
          .then(a=>{
            if(!a) return;                                        // silent degradation — keep the chip
            window.CC_MY_AREA=Object.assign({},window.CC_MY_AREA,a);
            revealMyAreaBtn(); window.CCScope.setMyArea(); hide();
            mapToast(I18N.myAreaSet||'My area saved');
          })
          .catch(()=>{ /* network/CSRF failure — leave the chip so the rider can retry */ });
      } else {
        // Anonymous: a localStorage-only circle (setAnonCircle rounds to 2dp on
        // write); NOTHING server-side, no device-location prompt (owner decision).
        window.CCScope.setAnonCircle(c.lat,c.lng,40);
        revealMyAreaBtn(); hide();
        mapToast(I18N.myAreaSetAnon||'My area set on this device');
      }
    }
    setChip.onclick=setMyAreaFromCentre;
    setChip.onkeydown=e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); setMyAreaFromCentre(); } };
    xBtn.onclick=()=>{ try{ localStorage.setItem(DISMISS_KEY,'1'); }catch(e){} hide(); };
  })();
  // climb surface + traffic chips actually filter the climbs layer; C2-T8 adds
  // climb effort + stay accessibility to the same wiring (this assignment runs
  // after the generic '.grp .chips .chip' toggle-only handler above, so it wins).
  document.querySelectorAll('#sqf .chip, #trf .chip, #effortf .chip, #accessf .chip').forEach(c=>c.onclick=()=>{
    c.classList.toggle('on');
    activeSurface=chipSet('sqf'); activeTraffic=chipSet('trf');
    activeEffort=chipSet('effortf'); activeAccess=chipSet('accessf');
    applyStaysAccessFilter();
    render();
  });

  // ride-heatmap toggle + season filter (source built on first On — W43)
  document.querySelectorAll('#heattoggle button').forEach(b=>b.onclick=()=>{
    document.querySelectorAll('#heattoggle button').forEach(x=>x.classList.remove('on')); b.classList.add('on');
    // addHeatmap ends with updateHeatFilter(), which honours a season chip
    // selected before the layer existed AND the active region scope.
    if(b.dataset.h==='on' && !map.getLayer('rideheat')) addHeatmap();
    if(map.getLayer('rideheat')) map.setLayoutProperty('rideheat','visibility', b.dataset.h==='on'?'visible':'none');
  });
  document.querySelectorAll('#season .chip').forEach(c=>c.onclick=()=>{
    document.querySelectorAll('#season .chip').forEach(x=>x.classList.remove('on')); c.classList.add('on');
    updateHeatFilter();
  });

  // street-level imagery (Mapillary) dock controls — the on/off toggle lives in the data-layers list
  document.getElementById('mlyClose').onclick=mlyClose;
  // User-resizable dock height: drag the top-edge grip (pointer events cover
  // mouse + touch), ↑/↓ when the grip is focused, double-click resets to the
  // CSS default. Persisted per browser. The Mapillary canvas measures itself
  // once at mount, so every height change needs an explicit viewer.resize().
  const MLY_H_KEY='cc-mly-dock-h';
  const mlyDockEl=document.getElementById('mlyDock'), mlyGrip=document.getElementById('mlyGrip');
  function mlyRemeasure(){ requestAnimationFrame(()=>{ if(mlyViewer){ try{ mlyViewer.resize(); }catch(_){} } }); }
  function setDockH(px, persist){
    const h=Math.max(160, Math.min(Math.round(window.innerHeight*0.85), Math.round(px)));
    mlyDockEl.style.height=h+'px';
    if(persist){ try{ localStorage.setItem(MLY_H_KEY, String(h)); }catch(_){} }
    mlyRemeasure();
  }
  if(mlyGrip){
    try{ const saved=+localStorage.getItem(MLY_H_KEY); if(saved) setDockH(saved, false); }catch(_){}
    let _rs=null;   // {y: drag-start clientY, h: drag-start dock height}
    mlyGrip.addEventListener('pointerdown',e=>{
      if(mlyDockEl.classList.contains('full')) return;
      e.preventDefault();
      _rs={y:e.clientY, h:mlyDockEl.getBoundingClientRect().height};
      try{ mlyGrip.setPointerCapture(e.pointerId); }catch(_){}   // capture is an optimisation, not a requirement
    });
    mlyGrip.addEventListener('pointermove',e=>{ if(_rs) setDockH(_rs.h+(_rs.y-e.clientY), false); });
    mlyGrip.addEventListener('pointerup',e=>{ if(!_rs) return; setDockH(_rs.h+(_rs.y-e.clientY), true); _rs=null; });
    mlyGrip.addEventListener('pointercancel',()=>{ _rs=null; });
    mlyGrip.addEventListener('dblclick',()=>{
      mlyDockEl.style.height='';
      try{ localStorage.removeItem(MLY_H_KEY); }catch(_){}
      mlyRemeasure();
    });
    mlyGrip.addEventListener('keydown',e=>{
      if(e.key!=='ArrowUp' && e.key!=='ArrowDown') return;
      e.preventDefault();
      setDockH(mlyDockEl.getBoundingClientRect().height + (e.key==='ArrowUp'?24:-24), true);
    });
  }
  // Fullscreen relies on the class's height:auto, which an inline height would
  // override — stash the custom height while full, restore it on the way back.
  document.getElementById('mlyFull').onclick=()=>{
    const entering=!mlyDockEl.classList.contains('full');
    if(entering){ mlyDockEl.dataset.h=mlyDockEl.style.height||''; mlyDockEl.style.height=''; }
    else if(mlyDockEl.dataset.h){ mlyDockEl.style.height=mlyDockEl.dataset.h; }
    mlyDockEl.classList.toggle('full');
    mlyRemeasure();
  };

  // "plan from Spa" — pick the sample loop nearest the chosen distance (faked for now)
  let planMarker=null;
  function clearPlan(){
    ['planroute','planroute-case'].forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); });
    if(map.getSource('planroute')) map.removeSource('planroute');
    if(planMarker){ planMarker.remove(); planMarker=null; }
  }
  function planFromSpa(km){
    // Empty-catalog + optional-attribute guards (review W6): reduce() with no
    // initial value throws on [], and CatalogProvider only emits `start` when
    // the attribute exists — fall back to the loop's first vertex.
    if(!window.CC_ROUTES || !CC_ROUTES.routes.length) return;
    const r=CC_ROUTES.routes.reduce((b,x)=>Math.abs(x.km-km)<Math.abs(b.km-km)?x:b);
    const start=r.start||r.loop[0];
    clearPlan();
    map.addSource('planroute',{type:'geojson',data:{type:'Feature',geometry:{type:'LineString',coordinates:r.loop.map(p=>[p[1],p[0]])}}});
    map.addLayer({id:'planroute-case',type:'line',source:'planroute',layout:{'line-cap':'round','line-join':'round'},paint:{'line-color':'#FBF4E4','line-width':9,'line-opacity':.95}});
    map.addLayer({id:'planroute',type:'line',source:'planroute',layout:{'line-cap':'round','line-join':'round'},paint:{'line-color':'#FF5A1F','line-width':5,'line-opacity':1}});
    const el=document.createElement('div'); el.className='cc-pin cur'; el.style.setProperty('--c','#FF5A1F'); el.innerHTML='<span>◎</span>';
    planMarker=new maplibregl.Marker({element:el,anchor:'bottom'}).setLngLat([start[1],start[0]]).addTo(map);
    let mnx=180,mny=90,mxx=-180,mxy=-90;
    r.loop.forEach(p=>{mny=Math.min(mny,p[0]);mxy=Math.max(mxy,p[0]);mnx=Math.min(mnx,p[1]);mxx=Math.max(mxx,p[1]);});
    map.fitBounds([[mnx,mny],[mxx,mxy]],{padding:60,duration:600});
    openDrawer({color:'#FF5A1F',letter:'R',label:D.suggestedRoute||'Suggested route'},{
      name:r.name, cur:false, source:D.fakedSrc||'Illustrative — faked from sample rides',
      elev:r.elev, gain:r.gain, difficulty:r.difficulty, uploader:r.uploader,
      record:[
        {label:D.start||'Start', value:'Spa'},
        {label:D.distance||'Distance', value:r.km+' km'},
        {label:D.shape||'Shape', value:D.roundtrip||'Roundtrip'},
        {label:D.season||'Season', value:CC_SEASON_LABEL[r.season]||trVal(r.season)},
        {label:D.why||'Why', value:D.popularSeason||'Popular this season'},
        {label:D.note||'Note', value:D.fakedNote||'⚠ Faked — the real planner stitches from the heatmap', warn:true}
      ]
    });
  }
  document.querySelectorAll('#planner .chip').forEach(c=>c.onclick=()=>{
    const wasOn=c.classList.contains('on');
    document.querySelectorAll('#planner .chip').forEach(x=>x.classList.remove('on'));
    if(wasOn){ clearPlan(); closeDrawer(); return; }   // click the active one again to clear the route
    c.classList.add('on');
    planFromSpa(+c.dataset.km);
  });

  // initial render runs from map.on('load') above (sources need the style loaded)
