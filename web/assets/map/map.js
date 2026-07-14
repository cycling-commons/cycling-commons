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
  const SOURCE_LABELS = {
    osm:'OpenStreetMap', pivot:'Tourisme Wallonie (CC-BY)', wikidata:'Wikidata',
    auto:'Derived by the pipeline', user:'Rider-contributed', manual:'Rider-contributed'
  };
  const sourceLabel = raw => SOURCE_LABELS[raw] || null;
  // C1-T3: race-guard token for the drawer's async "Recent changes" fetch —
  // bumped on every openDrawer() call so a slow response from a since-replaced
  // drawer never paints stale history over whatever is open now.
  let _historyReq = 0;
  const map = new maplibregl.Map({
    container:'map', style:'https://tiles.openfreemap.org/styles/liberty',
    bounds:[[2.84,49.45],[6.41,50.85]], fitBoundsOptions:{padding:24}, attributionControl:false  // all of Wallonia visible on load
  });
  map.addControl(new maplibregl.AttributionControl({customAttribution:'© OpenStreetMap contributors · ODbL'}),'bottom-right');
  map.addControl(new maplibregl.NavigationControl({showCompass:false}),'bottom-left');

  // region boundary — dim everything OUTSIDE the region (spotlight) + a clear
  // dashed outline, so the region you're filtering inside reads at a glance.
  function addRegionBoundary(name){
    fetch(`https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(name)}&format=jsonv2&polygon_geojson=1&limit=1`)
      .then(r=>{ if(!r.ok) throw new Error('nominatim HTTP '+r.status); return r.json(); }).then(d=>{
        if(!d[0]||!d[0].geojson||!map.getStyle()||map.getSource('region')) return;
        const g=d[0].geojson;
        const polys = g.type==='MultiPolygon' ? g.coordinates : [g.coordinates];
        const world=[[-180,-85],[180,-85],[180,85],[-180,85],[-180,-85]];
        const mask={type:'Feature',geometry:{type:'Polygon',coordinates:[world,...polys.map(p=>p[0])]}};
        map.addSource('region-mask',{type:'geojson',data:mask});
        map.addSource('region',{type:'geojson',data:{type:'Feature',geometry:g}});
        map.addLayer({id:'region-mask',type:'fill',source:'region-mask',paint:{'fill-color':'#101E16','fill-opacity':0.22}});
        map.addLayer({id:'region-line',type:'line',source:'region',paint:{'line-color':'#C8923A','line-width':2.5,'line-dasharray':[2,1.4],'line-opacity':0.95}});
      // decorative only — the map works without the boundary, but log why it's missing (W34)
      }).catch(e=>console.warn('Region boundary unavailable:', e));
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
    const feats=CC_ROUTES.heat.map(h=>({type:'Feature',properties:{season:h[2]},
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
    const ld=document.getElementById('mlyLoad'); if(ld) ld.innerHTML='<span class="mly-spin"></span>Loading street-level…';
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
    else mlyDockMessage('Zoom in and click a green dot to open street-level here.');
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
      if(!img){ mlyDockMessage('No street-level imagery here.'); return; }
      openMapillaryImage(img.id);
    }catch(_){ mlyDockMessage('No street-level imagery here.'); }
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

  // all Wallonia drinking-water points (OSM) as an efficient dot layer, toggled with the Water layer
  function addWaterOsm(){
    if(!window.CC_WATER_OSM || map.getSource('water-osm')) return;
    osmLayers['water']={data:CC_WATER_OSM, water:true};
    // build a small water-droplet icon (just the drop, no pin/marker around it) once
    if(!map.hasImage('water-drop')){
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
    }
    map.addSource('water-osm',{type:'geojson',data:CC_WATER_OSM});
    map.addLayer({id:'water-osm',type:'symbol',source:'water-osm',
      filter:['!',['has','c']],
      layout:{visibility:'none','icon-image':'water-drop','icon-allow-overlap':true,
        'icon-size':['interpolate',['linear'],['zoom'],8,0.55,13,0.9,18,1.3]}});
    map.on('click','water-osm',e=>{ const f0=e.features[0], p=f0.properties, c=f0.geometry.coordinates, ll={lng:c[0],lat:c[1]}; openDrawer(layerByKey['water'], waterDrawer(p, ll)); flyToPin([c[0],c[1]]); });   // use the feature's exact coords, not the click point, so the halo/centre land on the marker
    map.on('mouseenter','water-osm',()=>map.getCanvas().style.cursor='pointer');
    map.on('mousemove','water-osm',e=>{ const p=e.features[0].properties; showTip(p.t||'Drinking water', e.lngLat); });
    map.on('mouseleave','water-osm',()=>{ map.getCanvas().style.cursor=''; hideTip(); });
  }

  // registry of bulk-OSM dot layers so render() can promote confirmed (simulated) points to icon pins
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
    const rows = [];
    schema.forEach(f=>{
      if(skip.indexOf(f.key) >= 0) return;
      const v = src[f.key];
      const has = Array.isArray(v) ? v.length > 0 : (v != null && v !== '');
      if(has){
        if(f.kind === 'multiselect'){
          const list = Array.isArray(v) ? v : [v];
          rows.push({label:f.label, html:true, value:list.map(t=>`<span class="cc-chip">${escPend(t)}</span>`).join('')});
        } else if(f.kind === 'rating'){
          rows.push({label:f.label, value: /^[1-5]$/.test(String(v)) ? stars(Number(v)) : v});
        } else if(f.kind === 'url'){
          rows.push({label:f.label, value:String(v).replace(/^https?:\/\//,'').replace(/\/$/,''), links:[{label:'Visit site', href:v}]});
        } else {
          rows.push({label:f.label, value:v});
        }
      } else if(id != null){
        const href = `/improve?item=${encodeURIComponent(id)}&type=${encodeURIComponent(letter)}&field=${encodeURIComponent(f.key)}`;
        rows.push({label:f.label, html:true, empty:true, value:`<a class="cc-d-add" href="${href}">＋ add</a>`});
      }
    });
    return rows;
  }
  // drawer card for a generic bulk-OSM point — shared by the dot click handler and the confirmed pin
  function osmDrawer(layer, p, ll, src){
    const lbl=(layer||{}).label||'Place';
    const pivot=p.src==='pivot';   // official Tourisme Wallonie accommodation (CC-BY), not OSM
    // C1-T4 (W6): p.srcType is the item's real ItemSource value from CatalogProvider.
    // A rider-added/edited item (user/manual) must read as rider-contributed even
    // when served through a bulk-OSM layer — the per-fact "Type"/"Listed" method
    // tags below are unchanged (Phase C2 scope), only the headline + source line
    // are corrected here.
    const community = p.srcType==='user' || p.srcType==='manual';
    const originLbl = pivot?'Tourisme Wallonie':(community?sourceLabel(p.srcType):'OSM');
    let rec=[{label:'Type', value:p.t||lbl, method: pivot?'Tourisme Wallonie':'OSM'}];
    if(p.town && layer.letter!=='E') rec.push({label:'Town', value:p.town});  // stays' 'town' comes from the schema (labelled "Town / commune")
    rec.push({label:'Province', value:p.prov||'Wallonia'});
    if(pivot) rec.push({label:'Listed', value:'Official Tourisme Wallonie registry', method:'official'});
    if(p.sim){ rec.push({label:'Status', value:(p.c||'Confirmed')+' · simulated', method:'demo'});
      if(p.r) rec.push({label:'Rating', value:'★ '+p.r+' · simulated', method:'demo'}); }
    if(p.web) rec.push({label:'Website', value:p.web.replace(/^https?:\/\//,'').replace(/\/$/,''), links:[{label:'Visit site',href:p.web}]});
    // Registry-driven attribute rows (single source of truth = CatalogFormRegistry,
    // served as CC_FIELD_SCHEMA). Filled rows replace any structural row of the
    // same label (e.g. a curated 'Type' overriding the raw OSM one); unset fields
    // become "add" prompts. 'web' dedupes by the shared "Website" label below.
    const attrRows = schemaRows((layer||{}).letter, p, p.id);
    const attrLabels = new Set(attrRows.filter(r=>!r.empty).map(r=>r.label));
    rec = rec.filter(r=>!attrLabels.has(r.label)).concat(attrRows);
    // source is shown once, in the bottom cc-d-src line (linkified there) — like every other drawer
    const d={name:p.n||p.t||lbl, headline:(p.t||lbl)+' · '+originLbl, cur:!!p.c, geom:{ll:[ll.lat,ll.lng]}, record:rec,
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
    const potable = p.potable
      ? {label:'Potable', value:p.potable}
      : p.c
        ? {label:'Potable', value:p.c+' · simulated demo flag (not utility-verified)', method:'demo'}
        : {label:'Potable', value:'Tagged drinkable in OSM — not utility-verified; confirm on the spot', method:'unverified'};
    // C1-T4 (W6): see osmDrawer — a rider-added/edited water point isn't OSM.
    const community = p.srcType==='user' || p.srcType==='manual';
    const rec=[{label:'Type', value:p.type||p.t||'Drinking water', method: p.type?undefined:'OSM'}, potable,
      {label:'Verify', value:'Cross-check tap-water quality with the regional utility / fountain directory', links:[{label:'SWDE · Wallonia',href:'https://www.swde.be'},{label:'eaupotable.info',href:'https://eaupotable.info/nl/be-belgie'}]}];
    // Registry-driven rows for the remaining WaterFood fields (seasonal/note/
    // bottleFill/cost). 'type' and 'potable' are rendered structurally above.
    rec.push(...schemaRows('C', p, p.id, {skip:['type','potable']}));
    const d={name:p.n||p.t||'Drinking water', headline:'drinking water · '+(community?sourceLabel(p.srcType):'OSM'), cur:!!p.c, geom:{ll:[ll.lat,ll.lng]},
      record:rec,
      source: community?sourceLabel(p.srcType):'OpenStreetMap (amenity=drinking_water / drinking_water=yes)'};
    if(p.id!=null) d.id=p.id;          // real DB item id — the edit-bridge's `?item=` target
    return d;
  }
  // small recognisable marker for UNVERIFIED items: paper disc + category-colour ring + the category glyph
  function miniIcon(key){
    const id='mini-'+key;
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
    gx.fillText((layer||{}).icon||'•', R, R+1*S);
    const gd=gx.getImageData(0,0,D,D), gp=gd.data;
    for(let i=0;i<gp.length;i+=4){ if(gp[i+3]>25){ gp[i]=dark?255:20; gp[i+1]=dark?255:22; gp[i+2]=dark?255:14; gp[i+3]=255; } }
    gx.putImageData(gd,0,0); x.drawImage(gc,0,0);
    map.addImage(id,{width:D,height:D,data:new Uint8Array(x.getImageData(0,0,D,D).data.buffer)},{pixelRatio:S});
    return id;
  }
  // generic bulk-OSM layer: UNVERIFIED items get the small category marker; confirmed become large pins (clustered)
  function addOsmDots(key, data, srcDesc){
    const id=key+'-osm';
    if(!data || map.getSource(id)) return;
    osmLayers[key]={data, src:srcDesc};
    map.addSource(id,{type:'geojson',data});
    map.addLayer({id,type:'symbol',source:id,
      filter:['!',['has','c']],
      layout:{visibility:'none','icon-image':miniIcon(key),'icon-allow-overlap':true,
        'icon-size':['interpolate',['linear'],['zoom'],8,0.42,13,0.7,18,0.95]}});
    map.on('click',id,e=>{ const f0=e.features[0], p=f0.properties, c=f0.geometry.coordinates, ll={lng:c[0],lat:c[1]}; openDrawer(layerByKey[key], osmDrawer(layerByKey[key], p, ll, srcDesc)); flyToPin([c[0],c[1]]); });   // exact feature coords, not the click point, so the halo sits on the marker
    map.on('mouseenter',id,()=>map.getCanvas().style.cursor='pointer');
    map.on('mousemove',id,e=>{ const p=e.features[0].properties; showTip(p.t||(layerByKey[key]||{}).label||'Item', e.lngLat); });
    map.on('mouseleave',id,()=>{ map.getCanvas().style.cursor=''; hideTip(); });
  }
  // --- clustering for confirmed (validated/simulated) points: count bubble at low zoom → icon pins when spread ---
  const confState = {};   // srcId -> {key, layer, info, onScreen:{}}
  function setupConfClusters(){
    Object.keys(osmLayers).forEach(key=>{
      const info=osmLayers[key]; if(!info || !info.data) return;
      const confirmed = info.data.features.filter(f=>f.properties.c);
      const srcId=key+'-conf';
      if(!confirmed.length || map.getSource(srcId)) return;
      map.addSource(srcId,{type:'geojson', cluster:true, clusterRadius:48, clusterMaxZoom:13,
        data:{type:'FeatureCollection', features:confirmed}});
      // invisible layer so the clustered source loads tiles (querySourceFeatures needs rendered tiles)
      map.addLayer({id:srcId+'-hit', type:'circle', source:srcId, paint:{'circle-radius':0,'circle-opacity':0}});
      confState[srcId]={key, layer:layerByKey[key], info, onScreen:{}};
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
    const el=pinEl(st.layer, true);
    el.style.cursor='pointer'; el.tabIndex=0; el.setAttribute('role','button');
    el.setAttribute('aria-label', drawerF.name+' — '+drawerF.headline);
    el.addEventListener('click', ()=>{ openDrawer(st.layer, drawerF); flyToPin(lngLat); });
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
            el.addEventListener('click', ()=>{ map.getSource(srcId).getClusterExpansionZoom(p.cluster_id).then(z=>map.easeTo({center:co, zoom:z+0.2})).catch(()=>{}); });
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
  // The bulk OSM POI layers (layer key, data collection, OSM source note),
  // declared once so the map-load renderer AND the sidebar-search index below
  // stay in lockstep — previously the search skipped all of these, so every OSM
  // stay/shop/viewpoint/station was unfindable ("Les Louveteaux and other things
  // not searchable"). Read straight from the inlined CC_*_OSM globals (available
  // synchronously), since osmLayers is only populated later on map 'load'.
  const OSM_BULK = [
    ['services', window.CC_SERVICES_OSM, 'OpenStreetMap (shop=bicycle / amenity=bicycle_repair_station / compressed_air)'],
    ['scenic',   window.CC_SCENIC_OSM,   'OpenStreetMap (tourism=viewpoint / natural=peak / waterway=waterfall)'],
    ['history',  window.CC_HISTORY_OSM,  'OpenStreetMap (historic=castle/fort/ruins/monument/memorial/…)'],
    ['stays',    window.CC_STAYS_OSM,    'OpenStreetMap (tourism=camp_site/hostel/guest_house/chalet/hotel/…)'],
    ['shelter',  window.CC_SHELTER_OSM,  'OpenStreetMap (amenity=shelter / emergency=phone/defibrillator)'],
    ['transit',  window.CC_TRANSIT_OSM,  'OpenStreetMap (railway=station / railway=halt)']
  ];
  let _styleReady=false;   // flipped in the 'load' handler below; render() no-ops until then
  map.on('load',()=>{ _styleReady=true; addSatellite(); addMapillary(); addWaterOsm();   // heatmap is lazy (W43)
    OSM_BULK.forEach(([key, data, src])=>addOsmDots(key, data, src));
    addRegionBoundary('Wallonia'); setupConfClusters();
    // Reconcile cluster/leaf markers only when the map SETTLES, never on every render frame:
    // querySourceFeatures() + DOM marker diffing across all clustered layers, run per-frame during a
    // flyTo, is what made zooming/flying stutter. MapLibre repositions the existing markers smoothly on
    // its own mid-animation; we only need to add/remove on moveend (motion stops) and idle (tiles loaded).
    let _confRAF=null;
    const scheduleConfMarkers=()=>{ if(_confRAF) return; _confRAF=requestAnimationFrame(()=>{ _confRAF=null; updateConfMarkers(); }); };
    map.on('moveend', scheduleConfMarkers); map.on('idle', scheduleConfMarkers);
    render();
    // deep-link: ?feature=<name> opens that item's drawer + zooms in (e.g. from a profile page)
    const fp = new URLSearchParams(location.search).get('feature');
    if(fp) openFeatureByName(fp);
    const pp = new URLSearchParams(location.search).get('pending');
    if(pp) openPendingById(pp);
    // ?route=<id> opens a specific route selected (e.g. from the curator Routes desk)
    const rp = new URLSearchParams(location.search).get('route');
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

  // curator keyboard: A approve / R reject when a pending drawer is open — plain keys only
  // (never on Ctrl/Cmd/Alt combos, e.g. Ctrl+R reload; never while typing in any input)
  document.addEventListener('keydown', e=>{
    if(e.ctrlKey||e.metaKey||e.altKey) return;
    const t=e.target;
    if(t && (t.tagName==='INPUT'||t.tagName==='TEXTAREA'||t.tagName==='SELECT'||t.isContentEditable)) return;
    const box=document.querySelector('#drawer.open .cc-mod'); if(!box) return;
    if(e.key==='a'||e.key==='A'){ const b=box.querySelector('.cc-mod-btn.approve'); if(b){ e.preventDefault(); b.click(); } }
    if(e.key==='r'||e.key==='R'){ const b=box.querySelector('.cc-mod-btn.reject'); if(b){ e.preventDefault(); b.click(); } }
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
    { key:'surface', letter:'A', label:'Road surface', color:'#4E8C84', icon:'▰', kind:'surface', exp:true, features:[] }
    ,{ key:'climbs', letter:'B', label:'Climbs', color:'#6A2C8F', icon:'⛰', kind:'point', exp:true, features:[] }
    ,{ key:'water', letter:'C', label:'Water & food', color:'#8FB6A8', icon:'💧', kind:'point', exp:false, features:[] }
    ,{ key:'services', letter:'D', label:'Bike services', color:'#6b6f5e', icon:'⚙', kind:'point', exp:false, features:[] }
    ,{ key:'stays', letter:'E', label:'Where to sleep', color:'#B5532E', icon:'⛺', kind:'point', exp:true, features:[] }
    ,{ key:'hazards', letter:'F', label:'Hazards & conditions', color:'#C8923A', icon:'⚠', kind:'point', exp:false, features:[
      { name:'Exposed crosswind · Hautes Fagnes', headline:'wind & fog · plateau', cur:false,
        geom:{ll:[50.5160,6.0700]},
        photo:wc('Hohes Venn Winter 4.jpg','Geolina163','Geolina163','CC BY-SA 3.0'),
        record:[
          {label:'Type', value:'Notorious crosswind / fog', method:'safety'},
          {label:'Where', value:'Hautes Fagnes plateau (Baraque Michel)'},
          {label:'Severity', value:'Moderate — exposed open moorland'},
          {label:'Seasonal', value:'Worst in autumn/winter; ice & fog possible'}
        ],
        freshness:{state:'fresh', lastConfirmed:'this season'},
        source:'Community report' }
    ]}
    ,{ key:'transit', letter:'G', label:'Getting there', color:'#3E7D8C', icon:'🚆', kind:'point', exp:false, features:[] }
    ,{ key:'shelter', letter:'H', label:'Shelter & emergency', color:'#9A8FB6', icon:'⛑', kind:'point', exp:false, features:[] }
    ,{ key:'scenic', letter:'I', label:'Scenic views', color:'#2C5440', icon:'📷', kind:'point', exp:true, features:[] }
    ,{ key:'history', letter:'J', label:'History & culture', color:'#6E5849', icon:'🏛', kind:'point', exp:true, features:[] }
    ,{ key:'experience', letter:'K', label:'Recommended routes', color:'#FF5A1F', icon:'★', kind:'line', exp:false, features:[] }
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
  // Every catalog item exactly once, across ALL pools: CATALOG features
  // (curated: climbs, hazards, routes, curator-pending) first, then PIVOT
  // stays, then the bulk-OSM dot pools (incl. water, which loads its dot layer
  // separately from OSM_BULK). Search and nearbyItems() both consume this —
  // previously the town card scanned only CATALOG[].features (7 of 10 item
  // types silently missing) and the search index pushed PIVOT stays twice
  // (explicitly AND via the deliberate pivot→OSM concat in catalog-load.js).
  // Dedup: DB-backed entries on letter+id; cross-source physical doubles
  // (same letter + normalized name within 100 m) keep the earlier entry —
  // build order makes that curated/pivot over a raw OSM import.
  let ITEM_INDEX = [];
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
      push({name:f.name, key:slug(f.name+' '+(layer.label||'')), kind:layer.label||'', badge:layer.letter||'•',
        color:layer.color||'#6b6f5e', letter:layer.letter||'•', ll:featurePoint(f), id:f.id,
        hlOff: layer.kind==='point' ? [0,-16] : [0,0],
        pend:f.pending?String(f.pending.id):undefined,
        go:()=>openFeatureByName(f.name)});
    }));
    (window.CC_STAYS_PIVOT && CC_STAYS_PIVOT.features || []).forEach(f=>{ const p=f.properties;
      if(!p || !p.n) return; const layer=layerByKey.stays; if(!layer) return;
      const c=f.geometry && f.geometry.coordinates; if(!c || c.length<2) return;
      push({name:p.n, key:slug(p.n+' '+(p.town||'')+' '+layer.label), kind:layer.label, badge:layer.letter,
        color:layer.color, letter:layer.letter, ll:[+c[1],+c[0]], id:p.id,
        hlOff: p.c ? [0,-16] : [0,0],
        go:()=>openStayPivot(f)});
    });
    // Bulk OSM pools: the shared OSM_BULK table + water (its droplet layer is
    // registered separately in addWaterOsm(), so it never appears in OSM_BULK —
    // indexed here or every drinking-water point stays unsearchable/unlisted).
    const pools=OSM_BULK.concat([['water', window.CC_WATER_OSM, 'OpenStreetMap (amenity=drinking_water / drinking_water=yes)']]);
    pools.forEach(([key, data, src])=>{ const layer=layerByKey[key]; if(!layer || !data || !data.features) return;
      data.features.forEach(f=>{ const p=f.properties||{};
        const c=f.geometry && f.geometry.coordinates; if(!c || c.length<2) return;
        const lng=+c[0], lat=+c[1]; if(!isFinite(lng)||!isFinite(lat)) return;
        // Unnamed POIs (most drinking-water taps, many shelters/stations) are
        // real catalog items the town card must list — index them under their
        // type label, flagged `unnamed` so the search dropdown skips them
        // (nothing to text-match) and the name-proximity dedup ignores them
        // (two genuine taps 80 m apart share the fallback label).
        const nm=p.n||p.t||layer.label; if(!nm) return;
        push({name:nm, key:slug(nm+' '+(p.town||'')+' '+layer.label), kind:layer.label, badge:layer.letter,
          color:layer.color, letter:layer.letter, ll:[lat,lng], id:p.id, unnamed:!p.n,
          hlOff: p.c ? [0,-16] : [0,0],
          go:()=>{ openDrawer(layer, key==='water' ? waterDrawer(p, {lng, lat}) : osmDrawer(layer, p, {lng, lat}, src)); flyToPin([lng,lat]); }});
      });
    });
    return out;
  }
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
      id:r.id, name:r.name, state:r.state, headline:`${r.km} km${diffLabel ? ' · ' + diffLabel : ''}`, cur:false, edit:'ride',
      geom:{path:trimEnds(r.loop, startM, endM)}, elev:r.elev, gain:r.gain, difficulty:r.difficulty, uploader:r.uploader,
      cities: cities || [],                                // searchable start/through towns (empty when unknown)
      photo:r.photo||wc('Liège-Bastogne-Liège 2014 Echappée du jour Côte de Wanne.JPG','Les Meloures','Les Meloures','CC BY-SA 3.0'),
      // C1-T4 (W6): 'Contributed GPX' is an accurate detail for the pipeline's
      // usual auto-derived routes; a rider-added/edited one gets the plain label.
      source:(r.srcType==='user'||r.srcType==='manual') ? sourceLabel(r.srcType) : 'Contributed GPX (GPS track only)',
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
          {label:'Distance', value:r.km+' km'}
        ];
        // Phase-2 badge: a proposed route (unverified) rides "ride it to verify";
        // a verified route renders normally. state is served by CatalogProvider.
        if(r.state === 'unverified') rec.unshift({label:'Status', value:'Proposed · ride it to verify', warn:true});
        if(cities){
          // html:true — builder-constructed markup from the constant
          // RIDE_CITIES table (cityLink escapes the name); NEVER set this
          // flag on payload-derived values.
          rec.push({label:'Starts at', value:cityLink(cities[0]), html:true});
          rec.push({label:'Towns on route', value:cities.map(cityLink).join(' · '), html:true});
        }
        // Derived, not declared: measured against the A-layer mapped-road
        // segments at import/intake (SurfaceProfiler). The method note
        // discloses estimate + coverage — never present this as ground truth.
        // Kept hand-authored (it is not a registry field; K's declared field is
        // 'dominantSurface', rendered by the schema below).
        if(r.surfaces && Array.isArray(r.surfaces.parts) && r.surfaces.parts.length){
          rec.push({label:'Surfaces', value:r.surfaces.parts.map(p=>`${p.surface} ${p.pct}%`).join(' · '),
                    method:`estimate · ${Number(r.surfaces.covered)||0}% of route mapped`});
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
        id:s.id, name:s.name, headline:`${s.surface} · ${s.smoothness}`, cur:(s.cls!=='paved'), edit:'road-surface',
        geom:{path:s.path}, surfaceClass:s.cls, width:s.width,
        photo: s.photoFile ? wc(s.photoFile, s.photoCredit, s.photoUser, s.photoLicense) : undefined,
        // C1-T4 (W6): a rider-added/edited surface segment isn't OSM.
        source:(s.srcType==='user'||s.srcType==='manual') ? sourceLabel(s.srcType) : 'OSM (surface=*)',
        record:rec
      };
    });
  }
  // Curator-only pending submissions (injected by MapController for ROLE_CURATOR only).
  // Off the public map by design — riders never receive window.CC_PENDING.
  if(window.CC_IS_CURATOR && Array.isArray(window.CC_PENDING)){
    const pf = window.CC_PENDING.map(s=>({
      name:s.title, headline:`${s.type} · ${s.who} · ${s.when}`,
      geom:{ll:[s.lat, s.lng]},
      record:[
        {label:'Submitted by', value:s.who},
        {label:'Age', value:s.when},
        {label:'Where', value:`${s.region||''} · ${s.country||''}`}
      ],
      source:'Pending submission · preview',
      pending:s
    }));
    const pendingLayer = { key:'pending', letter:'⚑', label:'Pending review', color:'#D92D20', icon:'⏳', kind:'point', exp:false, pendingLayer:true, features:pf };
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
  const DIFF_LABELS=['','Easy','Moderate','Challenging','Hard','Very hard'];
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
  // stays' bulk-OSM dot layer filter combines the base "unconfirmed only" clause with the
  // accessibility narrowing above; confirmed/clustered stays are filtered in updateConfMarkers().
  function applyStaysAccessFilter(){
    if(!map.getLayer('stays-osm')) return;
    const base=['!',['has','c']];
    map.setFilter('stays-osm', activeAccess.size===ALL_ACCESS.size ? base
      : ['all', base, ['in', ['get','accessibility'], ['literal', Array.from(activeAccess)]]]);
  }

  function pinEl(layer,cur){
    const d=document.createElement('div');
    d.className='cc-pin'+(cur?' cur':'')+(layer.pendingLayer?' pending':''); d.style.setProperty('--c',layer.color);
    const white = txtOn(layer.color)==='#fff';   // dark pins (e.g. purple climbs) → white icon
    d.innerHTML=`<span${white?' style="filter:brightness(0) invert(1)"':''}>${layer.icon}</span>`; return d;
  }
  function featureVisible(layer, f){
    let show = layer.key==='experience' ? (mode==='all'||f.cur) : ((mode==='all') || !layer.exp || f.cur);       // experiential layers filter to curated; K uses the render loop's own carve-out
    if(show && layer.key==='climbs'){
      show = activeSurface.has(f.sq) && activeTraffic.has(f.tr);
      if(show) show = attrMatch(f.effort, activeEffort, ALL_EFFORT);
    }
    return show;
  }
  // legend count = shown/total: in Curated only confirmed/curated count; in Everything everything does
  function layerCounts(layer){
    const osmFx=window['CC_'+layer.key.toUpperCase()+'_OSM'];
    const rawOsm=osmFx?osmFx.features:[];
    // C2-T8: stays' shown/total narrows with the accessibility filter, same as climbs above
    // ('total' stays the true unfiltered count, matching how sq/tr never shrink climbs' total).
    const osmVisible = layer.key==='stays'
      ? rawOsm.filter(f=>attrMatch((f.properties||{}).accessibility, activeAccess, ALL_ACCESS))
      : rawOsm;
    const osmTotal=osmVisible.length;
    const osmConf=osmVisible.filter(f=>f.properties&&f.properties.c).length;
    const shown=layer.features.filter(f=>featureVisible(layer,f)).length + (mode==='all'?osmTotal:osmConf);
    return {shown, total:layer.features.length+rawOsm.length};
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
    ['water','services','scenic','history','stays','shelter','transit'].forEach(k=>{
      // unverified dots show only in Everything mode; Curated best-of keeps just the confirmed pins
      const id=k+'-osm'; if(map.getLayer(id)) map.setLayoutProperty(id,'visibility', (active.has(k) && mode==='all')?'visible':'none');
    });
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
              sEl.title=`Steepest pitch · ${f.steep.pct}`;
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
          const el = pinEl(layer,f.cur);
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
          // Route domain phase 4: K routes honour cur in Curated (best-of), all in Everything.
          if(layer.key==='experience'){ if(!(mode==='all'||f.cur)) return; }
          else if(!((mode==='all')||!layer.exp||f.cur)) return;
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
    const cur = f.cur ? `<div class="cc-d-cur">▲ Curated best-of</div>` : '';
    const pl = photoList(f);
    // Same edit-bridge rule as the "Edit this item" link below (spec §6/§8):
    // the add-photo CTA only ever binds to the item's real DB id — no id, no
    // link (a name-slug guess is never a faithful target).
    const addPhoto = (f.id!=null && layer.key!=='experience') ? `<a class="cc-d-addphoto" href="/improve?item=${f.id}&name=${encodeURIComponent(f.name)}&type=${layer.letter}&add=photo" aria-label="Add a photo of ${escPend(f.name)}">
      <svg class="cc-ap-cam" viewBox="0 0 48 36" width="42" height="31" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="1.5" y="7.5" width="45" height="27" rx="4"/><path d="M16 7.5l3-4h10l3 4" stroke-linejoin="round"/><circle cx="24" cy="21.5" r="8"/><path d="M40.5 13h.01" stroke-width="3" stroke-linecap="round"/>
      </svg>
      <span class="cc-ap-t">No photo yet</span>
      <span class="cc-ap-b">＋ Add the first photo</span>
    </a>` : '';
    const photo = pl.length ? `<figure class="cc-d-photo">
      <img src="${pl[0].sm}" alt="${escPend(f.name)}" data-i="0" />
      <figcaption id="cc-d-cap">${photoCap(pl[0])}</figcaption>
      ${pl.length>1 ? `<div class="cc-d-thumbs">${pl.map((p,i)=>`<img class="cc-d-thumb${i===0?' on':''}" src="${p.sm}" data-i="${i}" alt="${escPend(f.name)} — photo ${i+1}" />`).join('')}</div>` : ''}
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
    const fresh = f.freshness
      ? `<div class="cc-d-fresh ${f.freshness.state}">${f.freshness.state} · last confirmed ${f.freshness.lastConfirmed}</div>` : '';
    // difficulty is always {score,label} now (P2-D1); typeof fallback is defensive only.
    const diffLabel = f.difficulty?.label ?? (typeof f.difficulty === 'string' ? f.difficulty : undefined);
    const diffScore = f.difficulty?.score ?? null;
    const diff = diffLabel
      ? `<div class="cc-diff" title="Difficulty 1–5: Easy · Moderate · Challenging · Hard · Very hard">Difficulty
          <div class="cc-diff-scale">${[1,2,3,4,5].map(n=>`<span class="cc-diff-dot${n===diffScore?' on':''}" style="--p:${DIFF_PURPLE[n]}" title="${n} · ${DIFF_LABELS[n]}">${n}</span>`).join('')}</div>
          <b class="cc-diff-lbl">${diffLabel}</b></div>` : '';
    const elev = f.elev ? `<div class="cc-elev-cap">Elevation · ${Math.min(...f.elev)}–${Math.max(...f.elev)} m`
      + (f.gain?` · ${f.gain} m climbing`:'') + ` <em>(from GPX)</em></div>` + elevSvg(f.elev) : '';
    const grad = f.grad ? gradStrip(f.grad) : '';
    const up = f.uploader
      ? (f.uploader.public
          ? `<div class="cc-up">Shared by <b>${escPend(f.uploader.name)}</b> · <a href="/profile?u=${slug(f.uploader.name)}">view profile</a></div>`
          : `<div class="cc-up">Shared anonymously</div>`)
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
        edit = `<a class="cc-d-act edit" href="/improve?${editQ}">✎ Edit this item</a>`;
        // Direct "this pin is wrong" path — only when we know where it is.
        // editQ already carries lat/lng; fix=location opens the editor expanded.
        if(ell){
          edit += `<a class="cc-d-act fixloc" href="/improve?${editQ}&fix=location">◎ Fix location</a>`;
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
      edit = `<a class="cc-d-act edit" href="/improve?type=${encodeURIComponent(s.letter)}&item=${encodeURIComponent(s.id)}&name=${encodeURIComponent(s.title)}&lat=${encodeURIComponent(s.lat)}&lng=${encodeURIComponent(s.lng)}">✎ Edit this item</a>`;
      const body = s.body ? `<p class="cc-mod-body">${escPend(s.body)}</p>` : '';
      // "Proposed change" — what THIS submission wants to change, not the
      // item's history. Kept visually distinct from the history section below.
      const diff = (s.was && s.now)
        ? `<div class="cc-mod-diff"><div class="cc-mod-diff-h">Proposed change</div><div class="cc-mod-was">${escPend(s.was)}</div><div class="cc-mod-now">${escPend(s.now)}</div></div>`
        : '';
      // Moderation-UX (user request): "Everybody should always be able to see
      // the history of an item." A brand-new submission (type 'new') has no
      // prior item to have a history — say so plainly, no fetch needed. An
      // edit of an existing item (itemId set) fetches the same applied
      // change_history the normal item drawer shows (C1-T3), via openDrawer
      // below — reusing loadItemHistory/renderHistoryList so both views stay
      // in sync. escPend covers every interpolated value (see historyRow).
      const modHist = 'new' === s.type
        ? `<div class="cc-d-hist cc-d-hist-initial"><h4 class="cc-d-hist-h">History</h4><p class="cc-mod-initial">Initial entry — new item</p></div>`
        : (s.itemId != null ? `<div class="cc-d-hist" id="cc-d-hist-slot" data-item="${s.itemId}"></div>` : '');
      moderate = `<div class="cc-mod" data-id="${escPend(s.id)}">
        <div class="cc-mod-badge">⚑ Pending review</div>${body}${diff}
        <textarea class="cc-mod-note" placeholder="Optional note — a reason, or context…"></textarea>
        <div class="cc-mod-acts">
          <button class="cc-mod-btn approve" data-decision="approve">✓ Approve</button>
          <button class="cc-mod-btn info" data-decision="needs_info">? Needs info</button>
          <button class="cc-mod-btn reject" data-decision="reject">✕ Reject</button>
        </div>
        <div class="cc-mod-preview">A · approve · R · reject — recorded, not yet persisted.</div>
        ${modHist}
      </div>`;
    }
    // Only votable point types get the vote CTA. Utilities are confirmed, not
    // voted — the erroneous vote link used to show on water/services/etc.
    const vote = (f.cur && CC_VOTABLE.has(layer.key)) ? `<a class="cc-d-act" href="/vote">▲ Vote in this round</a>` : '';
    // Non-votable utilities carry a community confirmation panel (water:
    // potable/not-potable, others: "still here?"), hydrated async on open.
    const confirmPanel = (CC_CONFIRMABLE.has(layer.key) && f.id!=null)
      ? `<div class="cc-cf" data-item="${f.id}"><div class="cc-cf-body" data-cf-body></div><div class="cc-cf-login" hidden>Log in to confirm · <a href="/login">Log in</a></div></div>`
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
      <div class="cc-d-src">Source · ${escPend(f.source).replace(/^(OpenStreetMap|OSM)/, `<a href="${osmHref}" target="_blank" rel="noopener" style="color:var(--glacier);text-decoration:underline;text-underline-offset:2px">$1</a>`).replace(/(Géoportail de la Wallonie)/, '<a href="https://geoportail.wallonie.be/catalogue/91721175-5f01-410c-8c78-37c1d1893ba2.html" target="_blank" rel="noopener" style="color:var(--glacier);text-decoration:underline;text-underline-offset:2px">$1</a>')}</div>${confirmPanel}${act}${moderate}${histSlot}`;
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
    return `<h4 class="cc-d-hist-h">Recent changes</h4><ul class="cc-d-hist-list">${history.map(historyRow).join('')}</ul>`;
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
  const CC_REASONS=[['broken-track','Wrong / broken track'],['trim-privacy','Trim a private start/end'],['duplicate','Duplicate of another route'],['not-rideable','Not actually rideable'],['other','Something else']];
  const _rcTokens={};   // route id → CSRF token from the last snapshot

  // Votable point types (climbs/stays/scenic/history) carry the vote CTA;
  // routes (experience) have their own vote block. Non-votable UTILITIES are
  // confirmed, not voted: water carries a potable/not-potable judgement, the
  // rest a plain "still here?" confirmation. Keys match the CATALOG layer keys.
  const CC_VOTABLE=new Set(['climbs','stays','scenic','history']);
  const CC_CONFIRMABLE=new Set(['water','services','hazards','transit','shelter']);
  const _cfTokens={};   // item id → CSRF token from the last confirmations snapshot

  function routeCommunityPanel(id, state){
    const bikeOpts=CC_BIKES.map(b=>`<option value="${b}">${b}</option>`).join('');
    // Bike type has no safe default (it changes what a ride/vote means), so the
    // picker opens on a disabled placeholder — the rider must choose actively.
    const bikePickOpts=`<option value="" selected disabled>Bike type…</option>`+bikeOpts;
    const seasonOpts=CC_SEASONS.map(s=>`<option value="${s}">${s[0].toUpperCase()+s.slice(1)}</option>`).join('');
    const reasonOpts=CC_REASONS.map(([v,l])=>`<option value="${v}">${l}</option>`).join('');
    // Vote block only for verified routes (spec D7); rode-it for both.
    const voteBlock = state==='verified' ? `
      <div class="cc-rc-vote">
        <label class="cc-rc-l">Recommend it <span class="cc-rc-count" data-rc="votes"></span></label>
        <div class="cc-rc-row"><select class="cc-rc-season">${seasonOpts}</select><select class="cc-rc-vbike">${bikePickOpts}</select>
          <button class="cc-rc-btn" data-rc-act="vote">▲ Vote</button></div>
      </div>` : '';
    const rideProgress = state==='unverified' ? `<span class="cc-rc-count" data-rc="rides">…</span>` : '';
    return `<div class="cc-rc" data-route="${id}" data-state="${state||''}">
      <div class="cc-rc-ride">
        <label class="cc-rc-l">I rode this ${rideProgress}</label>
        <div class="cc-rc-row"><select class="cc-rc-rbike">${bikePickOpts}</select>
          <button class="cc-rc-btn" data-rc-act="rode-it">✓ I rode this</button></div>
      </div>
      ${voteBlock}
      <details class="cc-rc-suggest"><summary>Suggest a correction</summary>
        <select class="cc-rc-reason">${reasonOpts}</select>
        <textarea class="cc-rc-note" placeholder="Optional detail…"></textarea>
        <button type="button" class="cc-rc-mark" data-rc-mark="${id}">✎ Mark the part(s) on the map</button>
        <span class="cc-rc-marks" data-rc-marks></span>
        <button class="cc-rc-btn" data-rc-act="suggest">Send</button>
      </details>
      <a class="cc-d-act edit" href="/routes/${id}.gpx">⤓ Download GPX</a>
      <div class="cc-rc-login" hidden>Log in to rate this route · <a href="/login">Log in</a></div>
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
      if(m) m.textContent = `· ${segs.length} stretch${segs.length===1?'':'es'} marked`;
    }
    fetch(`/routes/${id}/community`, {credentials:'same-origin', headers:{'Accept':'application/json'}})
      .then(r=>{ if(r.status===401||r.status===403){ box.querySelector('.cc-rc-login').hidden=false; box.classList.add('cc-rc-anon'); throw new Error('anon'); } if(!r.ok) throw new Error('community'); return r.json(); })
      .then(s=>{ _rcTokens[id]=s.token; paintRouteCommunity(box, s); })
      .catch(()=>{});
  }

  function paintRouteCommunity(box, s){
    const rides=box.querySelector('[data-rc="rides"]'); if(rides) rides.textContent=`· ${s.rideCount} of ${s.threshold} to verify`;
    const votes=box.querySelector('[data-rc="votes"]'); if(votes) votes.textContent=s.voteCount?`· ${s.voteCount} vote${s.voteCount>1?'s':''}`:'';
    if(s.iRode){ const b=box.querySelector('[data-rc-act="rode-it"]'); if(b){ b.textContent='✓ You rode this'; b.disabled=true; } }
    if(s.iVotedThisSeason){ const b=box.querySelector('[data-rc-act="vote"]'); if(b){ b.textContent='✓ Voted this season'; b.disabled=true; } }
  }

  // --- Community confirmations for non-votable utilities (water potability /
  // "still here?"). Public counts, login to confirm — mirrors the route
  // community loop but simpler (one toggle-able stance per rider). ---
  const CC_CF_STANCES={
    potability:[['potable','✓ Potable'],['not_potable','✗ Not potable']],
    existence:[['exists','✓ Confirm it’s here']],
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
      ? `Is the water drinkable?` : `Is this still here?`;
    const btns=defs.map(([v,l])=>{
      const n=(s.stances&&s.stances[v])||0;
      const mine=s.mine===v?' is-mine':'';
      return `<button class="cc-cf-btn${mine}" data-cf-act="${v}"${authed?'':' disabled'}>${l} <span class="cc-cf-n">${n}</span></button>`;
    }).join('');
    const total = s.total ? `<span class="cc-cf-total">· ${s.total} rider${s.total===1?'':'s'} confirmed</span>` : '';
    box.querySelector('[data-cf-body]').innerHTML =
      `<div class="cc-cf-h">${heading} ${total}</div><div class="cc-cf-row">${btns}</div>`;
    box.querySelector('.cc-cf-login').hidden = authed;
  }
  // Delegated: clicking a stance button records/switches it, then repaints.
  document.addEventListener('click', e=>{
    const btn=e.target.closest('[data-cf-act]'); if(!btn) return;
    const box=btn.closest('.cc-cf'); if(!box) return;
    const id=box.getAttribute('data-item'), token=_cfTokens[id];
    if(!token){ mapToast('Please log in to confirm.'); return; }
    const body=new URLSearchParams(); body.set('_token', token); body.set('stance', btn.getAttribute('data-cf-act'));
    box.querySelectorAll('.cc-cf-btn').forEach(b=>b.disabled=true);
    fetch(`/items/${id}/confirm`, {method:'POST', credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
      .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
      .then(s=>{ paintItemConfirm(box, s); mapToast('Thanks — recorded.'); })
      .catch(()=>{ box.querySelectorAll('.cc-cf-btn').forEach(b=>b.disabled=false); mapToast('Could not record that — please try again.'); });
  });

  // Flag a picker the rider left on its placeholder: red border + focus + toast.
  function warnPick(sel, msg){ if(sel){ sel.classList.add('cc-rc-invalid'); sel.focus(); } mapToast(msg); }
  function rcPost(box, act){
    const id=box.dataset.route, token=_rcTokens[id];
    if(!token){ mapToast('Please log in to rate routes.'); return; }
    const body=new URLSearchParams(); body.set('_token', token);
    // Bike type must be actively chosen (no default) — block + warn if empty.
    if(act==='rode-it'){
      const sel=box.querySelector('.cc-rc-rbike');
      if(!sel.value){ warnPick(sel, 'Pick the bike type you rode it on first.'); return; }
      body.set('bike_type', sel.value);
    }
    if(act==='vote'){
      const sel=box.querySelector('.cc-rc-vbike');
      if(!sel.value){ warnPick(sel, 'Pick a bike type to recommend it for first.'); return; }
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
        if(act==='suggest'){ delete _pickSegs[id]; const m=box.querySelector('[data-rc-marks]'); if(m) m.textContent=''; mapToast('Thanks — a curator will review it.'); box.querySelector('.cc-rc-suggest').open=false; box.querySelector('.cc-rc-note').value=''; return; }
        paintRouteCommunity(box, s);
        if(act==='rode-it' && s.state==='verified' && box.dataset.state==='unverified'){ mapToast('Verified — thanks for confirming this route!'); box.dataset.state='verified'; }
        else mapToast('Recorded — thanks!');
      })
      .catch(err=>{ box.querySelectorAll('.cc-rc-btn').forEach(b=>b.disabled=false); mapToast(err.message==='429'?'Daily limit reached — try again tomorrow.':'Could not record that — please try again.'); });
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
    const path=routePathById(routeId); if(!path){ mapToast('Open the route first.'); return; }
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
      <button data-pick="undo">↶ Undo</button><button data-pick="clear">Clear</button><button data-pick="done" class="on">Done</button>`;
    bar.hidden=false; updatePickBar();
    bar.onclick=e=>{ const b=e.target.closest('[data-pick]'); if(!b) return; pickAction(b.dataset.pick); };
  }
  function updatePickBar(){
    const t=document.querySelector('#cc-pickbar .cc-pickbar-t'); if(!t) return;
    const done=Math.floor(_pick.points.length/2), pending=_pick.points.length%2;
    t.textContent = pending ? `Point ${_pick.points.length} set — click the end of this stretch` : `${done} stretch${done===1?'':'es'} marked — click to start another, or Done`;
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
    const n=(_pickSegs[rid]||[]).length; if(marks) marks.textContent = n ? `· ${n} stretch${n===1?'':'es'} marked` : '';
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
    if(_modToken) return _modToken;
    _modToken = fetch('/moderate', { credentials:'same-origin', headers:{'Accept':'text/html'} })
      .then(r=>r.text())
      .then(html=>{
        const el=new DOMParser().parseFromString(html,'text/html').querySelector('input[name="moderation_decision[_token]"]');
        // §13: a selector miss must not silently cache an empty token (which
        // would just fail CSRF later) — reject so the caller's error state fires.
        if(!el) return Promise.reject(new Error('moderation form not found'));
        return el.value;
      })
      .catch(err=>{ _modToken=undefined; throw err; });
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
      .then(res=>{ hidePendingPin(id); closeDrawer(); mapToast(`Decision recorded (${decision.replace('_',' ')}) — preview, not yet persisted · ${res.reference}`); })
      .catch(()=>{ _modToken=undefined; box.querySelectorAll('.cc-mod-btn').forEach(b=>b.disabled=false); mapToast('Could not record the decision — please try again.'); });
  }
  function gradStrip(grad){
    const max=Math.max(...grad), avg=Math.round(grad.reduce((a,b)=>a+b,0)/grad.length);
    const bars=grad.map(p=>`<span class="cc-grad-bar" style="height:${Math.round(10+(p/Math.max(max,1))*30)}px;background:${gradColor(p)}" title="${p}%"></span>`).join('');
    return `<div class="cc-elev-cap">Gradient profile · avg ~${avg}% · max ${max}% <em>(illustrative)</em></div>
      <div class="cc-grad">${bars}</div>`;
  }
  function elevSvg(elev){
    const w=300,h=64,pad=3,min=Math.min(...elev),max=Math.max(...elev),rng=Math.max(1,max-min);
    const xy=elev.map((e,i)=>[pad+i/(elev.length-1)*(w-2*pad), h-pad-((e-min)/rng)*(h-2*pad)]);
    const line=xy.map(p=>p[0].toFixed(1)+','+p[1].toFixed(1)).join(' ');
    return `<svg class="cc-elev" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" role="img" aria-label="Elevation profile">
      <polygon points="${pad},${h-pad} ${line} ${w-pad},${h-pad}" fill="rgba(255,90,31,.16)"/>
      <polyline points="${line}" fill="none" stroke="#FF5A1F" stroke-width="1.6"/></svg>`;
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
      highlightAt(f.geom && f.geom.ll, f.cur ? [0,-16] : [0,0]);
    }
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
    const d=document.getElementById('drawer'); d.classList.add('open'); d.setAttribute('aria-hidden','false');
    d.focus({preventScroll:true});   // move focus into the panel (not the close X — avoids a focus ring on tap/click open)
  }
  // place info card: fly to the town/village, show its info (when known) +
  // everything in the Commons within 5 km, grouped by layer. Works for any
  // geocoded place (spec 2026-07-14 §3.3): CITIES entries keep their wiki/info
  // blurbs; Photon hits pass just {ll}.
  function openPlace(name, meta){
    // A · Road surface segments are corridor data, not places — near any mapped
    // town they'd flood the card (Spa: 58 rows). Text search still finds them.
    const near = nearbyItems(meta.ll, 5).filter(n=>n.e.letter!=='A');
    // group rows by letter, keeping the global nearest-first order inside each group
    const byLetter={};
    near.forEach((n,i)=>{ n._i=i; (byLetter[n.e.letter]=byLetter[n.e.letter]||[]).push(n); });
    const letters=Object.keys(byLetter).sort();
    const list = near.length
      ? letters.map(L=>{ const rows=byLetter[L], e0=rows[0].e;
          return `<li class="cc-near-grp"><span class="cc-near-k" style="background:${e0.color};color:${txtOn(e0.color)}">${e0.badge}</span>${escPend(e0.kind)} · ${rows.length}</li>`
            + rows.map(n=>`<li><button class="cc-near" data-i="${n._i}"><span class="cc-near-nm">${escPend(n.e.name)}</span><em>${n.dist<1?Math.round(n.dist*1000)+' m':n.dist.toFixed(1)+' km'}</em></button></li>`).join('');
        }).join('')
      : '<li class="cc-near-empty">Nothing mapped here yet — be the first to add something.</li>';
    document.getElementById('drawerBody').innerHTML =
      `<span class="cc-d-type" style="--c:#3E7D8C;color:#fff">◎ ${meta.t==='City'?'City':'Town'}</span>
       <div class="cc-d-name">${escPend(name)}</div>
       ${meta.info?`<div class="cc-city-info">${meta.info}</div>`:''}
       <div class="cc-city-links">${meta.wiki?`<a href="${meta.wiki}" target="_blank" rel="noopener">Wikipedia ↗</a> · `:''}<span class="cc-city-ua">community notes — none yet</span></div>
       <h4 class="cc-near-h">In the Commons nearby · ≤ 5 km</h4>
       <ul class="cc-near-list">${list}</ul>`;
    document.querySelectorAll('#drawerBody .cc-near').forEach(b=>{
      const n=near[+b.dataset.i];
      b.onclick=()=>n.e.go();
      b.onmouseenter=()=>highlightAt(n.e.ll, n.e.hlOff); b.onmouseleave=clearHighlight;
    });
    const d=document.getElementById('drawer'); d.classList.add('open'); d.setAttribute('aria-hidden','false');
    // Frame the whole ≤5 km neighbourhood instead of flyToPin's zoom-14 dive —
    // hovering the list must pulse items that are actually on screen. The
    // drawer covers the right edge on desktop (bottom sheet on mobile), hence
    // the asymmetric padding.
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
    d.focus({preventScroll:true});   // move focus into the panel (not the close X — avoids a focus ring on tap/click open)
  }
  // city info card — thin CITIES-lookup wrapper kept for existing callers (drawer .cc-city links, search)
  function openCity(name){
    const c = CITIES[name]; if(!c) return;
    openPlace(name, c);
  }
  // open a specific feature by name (deep-link from e.g. a profile page): activate its layer, draw, zoom in
  function openFeatureByName(name){
    let found=null;
    CATALOG.forEach(layer=>layer.features.forEach(f=>{ if(f.name===name) found={layer,f}; }));
    if(!found) return false;
    const {layer,f}=found;
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
  // Curated mode only draws best-of routes, so switch to Everything first — else
  // an un-voted route wouldn't render and couldn't be highlighted.
  function openRouteById(id){
    const layer=layerByKey['experience']; if(!layer) return false;
    const f=layer.features.find(x=>String(x.id)===String(id));
    if(!f) return false;
    const allBtn=document.querySelector('#mode button[data-m="all"]');
    if(mode!=='all' && allBtn) allBtn.click();   // draw every route so this one is visible/selectable
    openDrawer(layer,f);
    const p=featurePoint(f); if(p) flyToPin([p[1],p[0]]);
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
      status.innerHTML=`${d.distanceKm} km · <a class="cc-ride-lnk" data-act="show">results</a> · <a class="cc-ride-lnk" data-act="clear">clear</a>`;
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
      _busy=true; pick.disabled=true; say('Checking…');
      const body=new FormData();
      body.append('gpx', _file); body.append('radius', radiusSel.value); body.append('_token', CC_RIDECHECK.token);
      fetch(CC_RIDECHECK.url, {method:'POST', credentials:'same-origin', headers:{'Accept':'application/json'}, body})
        .then(r=>r.json().then(d=>({ok:r.ok, d})))
        .then(({ok, d})=>{ if(!ok) throw new Error(d.error||'ride-check failed'); loadedStatus(d); renderRideCheck(d); })
        .catch(err=>{ clearRideCheck(); say(err.message||'Could not check this ride — please try again.'); })
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
      let html=`<span class="cc-d-type" style="--c:#3A3A33;color:#fff">➜ Ride check</span>
       <div class="cc-d-name">Along your ride</div>
       <div class="cc-city-info">${d.distanceKm} km${asc} · within ${radius} of the track — indication only, nothing stored.</div>
       <div class="cc-ride-actions"><button class="cc-ride-btn" id="rcClearBtn" type="button">✕ Clear ride</button></div>`;
      if(d.routes.length){
        html+=`<h4 class="cc-near-h">Your ride follows</h4><ul class="cc-near-list">`
          +d.routes.map(r=>`<li><button class="cc-near" data-rc-route="${r.id}"><span class="cc-near-k" style="background:${kColor};color:${txtOn(kColor)}">K</span><span class="cc-near-nm">${escPend(r.name)}</span><em>${r.sharedKm} km shared</em></button></li>`).join('')
          +`</ul>`;
      }
      html+=`<h4 class="cc-near-h">In the Commons along the track</h4>`;
      if(d.groups.length){
        html+=`<ul class="cc-near-list">`;
        d.groups.forEach(g=>{
          const meta=CATALOG.find(l=>l.letter===g.letter)||{color:'#6b6f5e',label:g.letter};
          html+=`<li class="cc-near-grp"><span class="cc-near-k" style="background:${meta.color};color:${txtOn(meta.color)}">${g.letter}</span>${escPend(meta.label)} · ${g.items.length}${g.truncated?' (capped)':''}</li>`;
          html+=g.items.map((it,i)=>`<li><button class="cc-near" data-rc-g="${g.letter}" data-rc-i="${i}"><span class="cc-near-nm">${escPend(it.name||meta.label)}</span><em>km ${it.alongKm} · ${it.distM} m off</em></button></li>`).join('');
        });
        html+=`</ul>`;
      } else {
        html+=`<div class="cc-near-empty">Nothing in the Commons within ${radius} of this ride yet.</div>`;
      }
      const body=document.getElementById('drawerBody');
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
  function closeDrawer(){
    // If the rider closes the drawer (X / scrim / Escape) mid-pick, tear the
    // picking session down too — an orphaned map click handler + toolbar with
    // no drawer to return to would be a dead-end. Uncommitted points (this
    // session hasn't hit Done) are simply dropped; any previously-Done
    // stretches already live in _pickSegs and are untouched.
    if(_pick) cancelPicking();
    const d=document.getElementById('drawer'); d.classList.remove('open'); d.setAttribute('aria-hidden','true');
    clearHighlight();
    clearRouteHighlight();
    clearCorrections();
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
  // mobile: drag the detail sheet down (from the top of the sheet) to dismiss it
  (function initDrawerDrag(){
    const d=document.getElementById('drawer'); if(!d) return;
    let active=false, dragging=false, startY=0, startT=0, dy=0;
    d.addEventListener('touchstart', e=>{
      if(window.innerWidth>820 || e.touches.length!==1) return;
      if(d.scrollTop>0) return;   // mid-scroll → leave it to the content
      active=true; dragging=false; startY=e.touches[0].clientY; startT=e.timeStamp; dy=0;
    }, {passive:true});
    d.addEventListener('touchmove', e=>{
      if(!active) return;
      dy=e.touches[0].clientY-startY;
      if(!dragging){
        if(dy<-4){ active=false; return; }   // moved up first → it's a scroll; bail without hijacking
        if(dy<=4) return;                     // wait for a clear downward direction
        dragging=true;
      }
      e.preventDefault();                     // own the downward drag (listener is passive:false)
      d.classList.add('dragging');
      d.style.transform=`translateY(${Math.max(0,dy)}px)`;
    }, {passive:false});
    d.addEventListener('touchend', ()=>{
      if(!dragging){ active=false; return; }
      active=false; dragging=false;
      d.classList.remove('dragging');         // restore the transition for the release animation
      const vel=dy/Math.max(1, performance.now()-startT);   // px/ms
      if(dy>90 || (dy>30 && vel>0.5)){        // far enough, or a quick flick → dismiss
        d.style.transform='translateY(100%)';
        let closed=false;
        const fin=ev=>{ if(closed||(ev&&ev.propertyName!=='transform')) return; closed=true;
          d.removeEventListener('transitionend',fin); closeDrawer(); d.style.transform=''; };
        d.addEventListener('transitionend', fin);
        setTimeout(fin, 320);                 // fallback if transitionend doesn't fire
      } else {
        d.style.transform='';                 // snap back up (CSS .cc-drawer.open → transform:none)
      }
    });
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
  function syncLayersAll(){ layersAll.textContent = CATALOG.every(l=>active.has(l.key)) ? 'deselect all' : 'select all'; }
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
      SEARCH_IDX.push({name, key:slug(name), kind: big?'City':'Town', badge: big?'◉':'◎', color: big?'#C8923A':'#3E7D8C', town:true, go:()=>openCity(name)}); });
    ITEM_INDEX = buildItemIndex();
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
    const closeS=()=>{ sRes.hidden=true; sRes.innerHTML=''; sMatches=[]; sHL=-1; sBox.setAttribute('aria-expanded','false'); };
    const hlS=()=>sRes.querySelectorAll('button').forEach((b,i)=>b.classList.toggle('hl',i===sHL));
    function pickS(i){ const m=sMatches[i]; if(!m) return; sBox.value=m.name; closeS();
      if(window.innerWidth<=820){ const ap=document.querySelector('.app'); if(ap) ap.classList.remove('sheet-open'); }  // clear the filter sheet on mobile
      m.go(); }
    const sRow=(m,i)=>`<li role="option"><button data-i="${i}"><span class="sw" style="background:${m.color};color:${txtOn(m.color)}">${m.badge}</span><span class="snm">${escH(m.name)}</span><span class="sub">${escH(m.kind)}</span></button></li>`;
    // Any-town live place search via Photon (spec 2026-07-14 §3.2) — Photon,
    // not Nominatim: Nominatim's usage policy forbids type-ahead. Wallonia
    // bbox, place types only, ≥3 chars, 350 ms debounce, one in-flight request
    // (stale ones aborted). On error/timeout search silently degrades to the
    // local index + hardcoded quick-picks — no toast. Host already in CSP.
    let _phAbort=null, _phHits=[], _phQ='';
    const PH_URL='https://photon.komoot.io/api/?limit=6&bbox=2.75,49.45,6.55,50.90'
      +'&osm_tag=place:city&osm_tag=place:town&osm_tag=place:village&osm_tag=place:hamlet&osm_tag=place:municipality';
    function runPhoton(qRaw){
      const q=qRaw.trim();
      if(q.length<3){ _phHits=[]; _phQ=''; return; }
      if(_phAbort) _phAbort.abort();
      const ctl=new AbortController(); _phAbort=ctl;
      fetch(PH_URL+'&q='+encodeURIComponent(q), {signal:ctl.signal})
        .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(d=>{
          if(ctl.signal.aborted) return;
          const seen=new Set(Object.keys(CITIES).map(n=>slug(n)));   // quick-picks win over their Photon twin
          _phHits=(d.features||[])
            .filter(f=>f && f.properties && f.properties.name && f.geometry && Array.isArray(f.geometry.coordinates))
            .filter(f=>{ const k=slug(f.properties.name); if(!k || seen.has(k)) return false; seen.add(k); return true; })
            .map(f=>{ const c=f.geometry.coordinates, name=f.properties.name;
              return {name, key:slug(name), kind:'Town', badge:'◎', color:'#3E7D8C', town:true, ph:1,
                go:()=>openPlace(name, {ll:[+c[1],+c[0]]})}; });
          _phQ=slug(q);
          if(!sRes.hidden) runS();   // merge into the open dropdown
        })
        .catch(()=>{});   // abort / network / quota — degrade silently
    }
    function runS(){
      const q=slug(sBox.value.trim());
      if(!q){ closeS(); return; }
      const starts=[], has=[];                                  // prefix matches rank above substring matches
      for(const it of SEARCH_IDX){ const i=it.key.indexOf(q); if(i===0) starts.push(it); else if(i>0) has.push(it); }
      const ranked=starts.concat(has);
      // Grouped display (spec 2026-07-14 §3.3): places first, then items by
      // letter A–K, 30 rows total (the old flat slice(0,8) hid most matches
      // around a populous town). sMatches stays flat in display order so the
      // existing keyboard navigation is untouched; group headers aren't options.
      const towns=ranked.filter(m=>m.town), items=ranked.filter(m=>!m.town);
      if(_phQ===q) towns.push(..._phHits.slice(0, Math.max(0, 6-towns.length)));   // geocoded towns behind local ones
      const byLetter={};
      items.forEach(m=>{ (byLetter[m.letter]=byLetter[m.letter]||[]).push(m); });
      const groups=towns.length?[{label:'Places', rows:towns}]:[];
      Object.keys(byLetter).sort().forEach(L=>groups.push({label:`${L} · ${byLetter[L][0].kind}`, rows:byLetter[L]}));
      const CAP=30;
      sMatches=[]; sHL=-1;
      let html='';
      for(const g of groups){
        if(sMatches.length>=CAP) break;
        const rows=g.rows.slice(0, CAP-sMatches.length);
        html+=`<li class="sgrp" role="presentation">${escH(g.label)}</li>`;
        rows.forEach(m=>{ html+=sRow(m, sMatches.length); sMatches.push(m); });
      }
      sRes.hidden=false; sBox.setAttribute('aria-expanded','true');
      sRes.innerHTML = sMatches.length ? html : '<li class="search-empty">No match in the Wallonia demo yet.</li>';
    }
    // one delegated listener + a short debounce (review W41): the per-keystroke
    // cost was a full index scan, an innerHTML rebuild AND fresh per-result
    // listeners — the pattern that degrades linearly as the catalog grows.
    sRes.addEventListener('click', e=>{ const b=e.target.closest('button[data-i]'); if(b) pickS(+b.dataset.i); });
    let _sDeb=null, _phDeb=null;
    sBox.addEventListener('input', ()=>{ clearTimeout(_sDeb); _sDeb=setTimeout(runS,150);
      clearTimeout(_phDeb); _phDeb=setTimeout(()=>runPhoton(sBox.value),350); });
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
  // filters K routes to them. Region is single (Wallonia) — omitted for v1.
  const CC_SEASON_LABEL={spring:'Spring',summer:'Summer',autumn:'Autumn',winter:'Winter'};
  function currentSeason(){ const m=new Date().getMonth()+1; return m>=3&&m<=5?'spring':m>=6&&m<=8?'summer':m>=9&&m<=11?'autumn':'winter'; }
  let boSeason=currentSeason(), boBike='all';

  function updateSubtitle(){
    const sub=document.querySelector('.map-top .sub'); if(!sub) return;
    if(mode==='all'){ sub.textContent='Everything · full backlog'; return; }
    const bike=boBike==='all'?'All bikes':boBike;
    sub.textContent=`Curated best-of · ${CC_SEASON_LABEL[boSeason]} · ${bike}`;
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
    fetch(`/map/best-of?season=${encodeURIComponent(boSeason)}&bike=${encodeURIComponent(boBike)}`,
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
    const bf=document.getElementById('bestFacets'); if(bf) bf.hidden = (mode!=='curated');
    updateSubtitle();
    refreshBestOf();          // Curated → fetch + filter; Everything → plain render()
  });

  // Initial best-of for the default facet so Curated isn't empty on load.
  updateSubtitle();
  { const bf=document.getElementById('bestFacets'); if(bf) bf.hidden = (mode!=='curated'); }
  refreshBestOf();
  // discipline + freshness chips (visual)
  document.querySelectorAll('#disc .chip, .grp .chips .chip').forEach(c=>c.onclick=()=>c.classList.toggle('on'));
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
    if(b.dataset.h==='on' && !map.getLayer('rideheat')){
      addHeatmap();
      // honour a season chip selected before the layer existed
      const sc=document.querySelector('#season .chip.on');
      if(sc && sc.dataset.s!=='all' && map.getLayer('rideheat')) map.setFilter('rideheat',['==',['get','season'],sc.dataset.s]);
    }
    if(map.getLayer('rideheat')) map.setLayoutProperty('rideheat','visibility', b.dataset.h==='on'?'visible':'none');
  });
  document.querySelectorAll('#season .chip').forEach(c=>c.onclick=()=>{
    document.querySelectorAll('#season .chip').forEach(x=>x.classList.remove('on')); c.classList.add('on');
    const s=c.dataset.s;
    if(map.getLayer('rideheat')) map.setFilter('rideheat', s==='all'?null:['==',['get','season'],s]);
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
    openDrawer({color:'#FF5A1F',letter:'R',label:'Suggested route'},{
      name:r.name, cur:false, source:'Illustrative — faked from sample rides',
      elev:r.elev, gain:r.gain, difficulty:r.difficulty, uploader:r.uploader,
      record:[
        {label:'Start', value:'Spa'},
        {label:'Distance', value:r.km+' km'},
        {label:'Shape', value:'Roundtrip'},
        {label:'Season', value:r.season},
        {label:'Why', value:'Popular this season'},
        {label:'Note', value:'⚠ Faked — the real planner stitches from the heatmap', warn:true}
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
