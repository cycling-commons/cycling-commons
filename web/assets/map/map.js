// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
  // §13: shared HTML-escaper for real (user-authored) pending-submission text —
  // stored-XSS-in-curator-session risk now that submissions come from real users.
  const escPend = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
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
      .then(r=>r.json()).then(d=>{
        if(!d[0]||!d[0].geojson||!map.getStyle()||map.getSource('region')) return;
        const g=d[0].geojson;
        const polys = g.type==='MultiPolygon' ? g.coordinates : [g.coordinates];
        const world=[[-180,-85],[180,-85],[180,85],[-180,85],[-180,-85]];
        const mask={type:'Feature',geometry:{type:'Polygon',coordinates:[world,...polys.map(p=>p[0])]}};
        map.addSource('region-mask',{type:'geojson',data:mask});
        map.addSource('region',{type:'geojson',data:{type:'Feature',geometry:g}});
        map.addLayer({id:'region-mask',type:'fill',source:'region-mask',paint:{'fill-color':'#101E16','fill-opacity':0.22}});
        map.addLayer({id:'region-line',type:'line',source:'region',paint:{'line-color':'#C8923A','line-width':2.5,'line-dasharray':[2,1.4],'line-opacity':0.95}});
      }).catch(()=>{});
  }
  // optional satellite base — Esri World Imagery (added below the data layers, hidden by default)
  function addSatellite(){
    if(map.getSource('satellite')) return;
    map.addSource('satellite',{type:'raster',tileSize:256,
      tiles:['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'],
      maxzoom:19, attribution:'Imagery © Esri, Maxar, Earthstar Geographics'});
    map.addLayer({id:'satellite',type:'raster',source:'satellite',layout:{visibility:'none'}});
  }
  // seasonal ride-heatmap (illustrative — built from sample GPX rides, served as catalog.json's L layer)
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
  function mlyPopup(lngLat,msg){
    new maplibregl.Popup({closeButton:false,className:'pop'}).setLngLat(lngLat)
      .setHTML(`<div class="pop"><div class="pop-co">Mapillary</div>${msg}</div>`).addTo(map);
  }

  // inject mapillary-js (JS + CSS) once, on first open; resolves when ready
  function loadMapillaryJs(){
    if(window.mapillary) return Promise.resolve();
    if(mlyLoading) return mlyLoading;
    mlyLoading=new Promise((res,rej)=>{
      const css=document.createElement('link'); css.rel='stylesheet';
      css.href='https://unpkg.com/mapillary-js@4.1.2/dist/mapillary.css'; document.head.appendChild(css);
      const js=document.createElement('script');
      js.src='https://unpkg.com/mapillary-js@4.1.2/dist/mapillary.js';
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
    map.on('click','water-osm',e=>{ const p=e.features[0].properties; openDrawer(layerByKey['water'], waterDrawer(p, e.lngLat)); });
    map.on('mouseenter','water-osm',()=>map.getCanvas().style.cursor='pointer');
    map.on('mousemove','water-osm',e=>{ const p=e.features[0].properties; showTip(p.t||'Drinking water', e.lngLat); });
    map.on('mouseleave','water-osm',()=>{ map.getCanvas().style.cursor=''; hideTip(); });
  }

  // registry of bulk-OSM dot layers so render() can promote confirmed (simulated) points to icon pins
  const osmLayers = {};
  // drawer card for a generic bulk-OSM point — shared by the dot click handler and the confirmed pin
  function osmDrawer(layer, p, ll, src){
    const lbl=(layer||{}).label||'Place';
    const pivot=p.src==='pivot';   // official Tourisme Wallonie accommodation (CC-BY), not OSM
    const rec=[{label:'Type', value:p.t||lbl, method: pivot?'Tourisme Wallonie':'OSM'}];
    if(p.town) rec.push({label:'Town', value:p.town});
    rec.push({label:'Province', value:p.prov||'Wallonia'});
    if(pivot) rec.push({label:'Listed', value:'Official Tourisme Wallonie registry', method:'official'});
    if(p.sim){ rec.push({label:'Status', value:(p.c||'Confirmed')+' · simulated', method:'demo'});
      if(p.r) rec.push({label:'Rating', value:'★ '+p.r+' · simulated', method:'demo'}); }
    if(p.web) rec.push({label:'Website', value:p.web.replace(/^https?:\/\//,'').replace(/\/$/,''), links:[{label:'Visit site',href:p.web}]});
    // source is shown once, in the bottom cc-d-src line (linkified there) — like every other drawer
    const d={name:p.n||p.t||lbl, headline:(p.t||lbl)+' · '+(pivot?'Tourisme Wallonie':'OSM'), cur:!!p.c, geom:{ll:[ll.lat,ll.lng]}, record:rec,
      source: pivot?'Tourisme Wallonie (TW) — CC-BY 4.0 · PIVOT / Géoportail de la Wallonie':src};
    if(p.id!=null) d.id=p.id;          // real DB item id — the edit-bridge's `?item=` target
    if(p.desc) d.desc=p.desc;
    if(p.descTr) d.descTr=1;
    let photo=p.photo; if(typeof photo==='string'){ try{ photo=JSON.parse(photo); }catch(e){ photo=null; } }
    if(photo) d.photo=photo;
    return d;
  }
  // drawer card for a water point — shared by the droplet click handler and the confirmed pin
  function waterDrawer(p, ll){
    const potable = p.c
      ? {label:'Potable', value:p.c+' · simulated demo flag (not utility-verified)', method:'demo'}
      : {label:'Potable', value:'Tagged drinkable in OSM — not utility-verified; confirm on the spot', method:'unverified'};
    const d={name:p.n||p.t||'Drinking water', headline:'drinking water · OSM', cur:!!p.c, geom:{ll:[ll.lat,ll.lng]},
      record:[{label:'Type', value:p.t||'Drinking water', method:'OSM'}, potable,
        {label:'Verify', value:'Cross-check tap-water quality with the regional utility / fountain directory', links:[{label:'SWDE · Wallonia',href:'https://www.swde.be'},{label:'eaupotable.info',href:'https://eaupotable.info/nl/be-belgie'}]}],
      source:'OpenStreetMap (amenity=drinking_water / drinking_water=yes)'};
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
    map.on('click',id,e=>{ const p=e.features[0].properties; openDrawer(layerByKey[key], osmDrawer(layerByKey[key], p, e.lngLat, srcDesc)); });
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
        let m=on[key];
        if(!m){
          if(p.cluster){
            const el=clusterEl(st.layer, p.point_count_abbreviated); el.style.cursor='pointer';
            el.addEventListener('click', ()=>{ map.getSource(srcId).getClusterExpansionZoom(p.cluster_id,(err,z)=>{ if(!err) map.easeTo({center:co, zoom:z+0.2}); }); });
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
  map.on('load',()=>{ addSatellite(); addHeatmap(); addMapillary(); addWaterOsm();
    addOsmDots('services', window.CC_SERVICES_OSM, 'OpenStreetMap (shop=bicycle / amenity=bicycle_repair_station / compressed_air)');
    addOsmDots('scenic', window.CC_SCENIC_OSM, 'OpenStreetMap (tourism=viewpoint / natural=peak / waterway=waterfall)');
    addOsmDots('history', window.CC_HISTORY_OSM, 'OpenStreetMap (historic=castle/fort/ruins/monument/memorial/…)');
    addOsmDots('stays', window.CC_STAYS_OSM, 'OpenStreetMap (tourism=camp_site/hostel/guest_house/chalet/hotel/…)');
    addOsmDots('shelter', window.CC_SHELTER_OSM, 'OpenStreetMap (amenity=shelter / emergency=phone/defibrillator)');
    addOsmDots('transit', window.CC_TRANSIT_OSM, 'OpenStreetMap (railway=station / railway=halt)');
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
  });

  // right-click anywhere → show + copy the coordinates (for defining start/end points, add-a-climb, etc.)
  map.on('contextmenu', e=>{
    const c = `${e.lngLat.lat.toFixed(6)}, ${e.lngLat.lng.toFixed(6)}`;
    if(navigator.clipboard) navigator.clipboard.writeText(c).catch(()=>{});
    new maplibregl.Popup({closeButton:true,className:'pop'}).setLngLat(e.lngLat)
      .setHTML(`<div class="pop"><div class="pop-co">Coordinates · copied</div>${c}</div>`).addTo(map);
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
    ,{ key:'climbs', letter:'B', label:'Climbs', color:'#6A2C8F', icon:'⛰', kind:'point', exp:true, features:[
      { name:'Côte de la Redoute', headline:'2.0 km · 8.4% avg', cur:true, sq:'Smooth', tr:'Quiet',
        geom:{ll:[50.49222,5.69924]},
        route:[[50.48321,5.70391],[50.48327,5.70383],[50.4837,5.70348],[50.48396,5.70319],[50.48421,5.70315],[50.48453,5.70322],[50.48522,5.70341],[50.48535,5.70344],[50.48554,5.7034],[50.48585,5.70317],[50.48603,5.70308],[50.48613,5.70317],[50.48626,5.70361],[50.48636,5.70418],[50.48655,5.70479],[50.48708,5.70577],[50.48807,5.70728],[50.48833,5.70767],[50.48853,5.70774],[50.48887,5.70757],[50.48912,5.70742],[50.48971,5.70703],[50.4903,5.70637],[50.49063,5.70593],[50.49077,5.70583],[50.49094,5.70564],[50.49101,5.70534],[50.49097,5.70485],[50.49091,5.70383],[50.49097,5.70337],[50.49118,5.7022],[50.4913,5.7019],[50.49149,5.70163],[50.49181,5.70131],[50.49206,5.70094],[50.49219,5.70057],[50.49226,5.69965],[50.49217,5.6991],[50.49203,5.69881],[50.49167,5.6984],[50.49126,5.6978],[50.49098,5.69734],[50.49045,5.69635],[50.48999,5.69579],[50.48989,5.69571]],
        steep:{at:[50.49077,5.70583],pct:'~20%'},
        grad:[4,6,8,11,14,18,20,16,12,9,7,8],
        photo:{sm:'/media/redoute-sm.jpg',lg:'/media/redoute.jpg',credit:'DimiTalen',creditUrl:'https://commons.wikimedia.org/wiki/User:DimiTalen',license:'CC0',source:'https://commons.wikimedia.org/wiki/File:Phil_Phil_Phil_on_C%C3%B4te_de_la_Redoute,_Aywaille,_2011.jpg'},
        record:[
          {label:'Length', value:'2.0 km'},
          {label:'Average gradient', value:'8.4%'},
          {label:'Max gradient', value:'~20% (mid-climb ramp)'},
          {label:'Surface', value:'Asphalt', method:'OSM'},
          {label:'Approach', value:'From Sougné-Remouchamps (Aywaille)'},
          {label:'Famous for', value:'Liège–Bastogne–Liège — the decisive climb', links:[{label:'Official',href:'https://www.liege-bastogne-liege.be/en/'},{label:'Wikipedia',href:'https://en.wikipedia.org/wiki/Li%C3%A8ge%E2%80%93Bastogne%E2%80%93Li%C3%A8ge'}]},
          {label:'Water on climb', value:'None'},
          {label:'Traffic', value:'Quiet'}
        ],
        source:'OSM roads · geometry handmade' },
      { name:'Mur de Huy', headline:'1.3 km · 9.3% avg', cur:true, sq:'Good', tr:'Busy',
        geom:{ll:[50.51426,5.24874]},
        route:[[50.51656,5.24049],[50.51655,5.24056],[50.51653,5.24097],[50.51654,5.2414],[50.51665,5.24191],[50.51686,5.2423],[50.51707,5.24266],[50.51729,5.243],[50.51749,5.24333],[50.51793,5.24398],[50.51836,5.2446],[50.51865,5.24501],[50.51877,5.24521],[50.51886,5.24586],[50.51887,5.24652],[50.51879,5.24692],[50.51867,5.2471],[50.51765,5.24788],[50.51751,5.24792],[50.51692,5.24757],[50.51687,5.24722],[50.51684,5.24707],[50.51623,5.24608],[50.51611,5.24609],[50.516,5.2465],[50.51547,5.24665],[50.51519,5.24678],[50.51461,5.24726],[50.51442,5.24753],[50.51433,5.24777],[50.51426,5.24831],[50.51425,5.24875],[50.5142,5.24926],[50.51411,5.24997],[50.51403,5.25064]],
        steep:{at:[50.51765,5.24788],pct:'26%'},
        grad:[6,9,13,17,21,26,23,16,11,9,8],
        photos:[wc('2019 Mur de Huy 3.jpg','Hoebele','Hoebele','CC BY-SA 4.0'),
          {sm:'/media/mur-de-huy-sm.jpg',lg:'/media/mur-de-huy.jpg',credit:'Rz98',creditUrl:'https://commons.wikimedia.org/wiki/User:Rz98',license:'CC BY-SA 4.0',source:'https://commons.wikimedia.org/wiki/File:Mur_de_Huy_001.jpg'}],
        record:[
          {label:'Length', value:'1.3 km'},
          {label:'Average gradient', value:'9.3%'},
          {label:'Max gradient', value:'~26% (Chapelle hairpin)'},
          {label:'Surface', value:'Asphalt', method:'OSM'},
          {label:'Famous for', value:'La Flèche Wallonne summit finish', links:[{label:'Official',href:'https://www.la-fleche-wallonne.be/en/'},{label:'Wikipedia',href:'https://en.wikipedia.org/wiki/La_Fl%C3%A8che_Wallonne'}]},
          {label:'Tops out at', value:'Chapelle Notre-Dame de la Sarte'},
          {label:'Traffic', value:'Busy (town climb)'}
        ],
        source:'OSM roads · geometry handmade' },
      { name:'Côte de Stockeu', headline:'~1.0 km · 9%+ avg', cur:true, sq:'Worn', tr:'Quiet',
        geom:{ll:[50.39148,5.93247]},
        route:[[50.39147,5.93248],[50.39142,5.93255],[50.39137,5.93262],[50.39126,5.93276],[50.39102,5.93308],[50.39083,5.9333],[50.39073,5.9334],[50.39062,5.93349],[50.39038,5.93363],[50.38988,5.93391],[50.38955,5.93408],[50.38949,5.93408],[50.38918,5.93395],[50.389,5.93386],[50.38886,5.93382],[50.38868,5.93378],[50.38839,5.93377],[50.38773,5.93402],[50.38737,5.93415],[50.38716,5.9342],[50.38698,5.93426],[50.3869,5.93432],[50.38682,5.93439],[50.3866,5.93464],[50.38638,5.93488],[50.38626,5.93499],[50.38601,5.93513],[50.38553,5.93545],[50.38445,5.93607],[50.38419,5.9362],[50.38409,5.93626],[50.38404,5.9363],[50.38395,5.93638],[50.38386,5.93649],[50.38369,5.93674],[50.38345,5.93706],[50.3833,5.93726],[50.38327,5.93732]],
        steep:{at:[50.38773,5.93402],pct:'~20%'},
        grad:[7,10,14,18,20,17,13,10,8,9],
        photos:[{sm:'/media/stockeu-sm.jpg',lg:'/media/stockeu.jpg',credit:'Hoebele',creditUrl:'https://commons.wikimedia.org/wiki/User:Hoebele',license:'CC BY-SA 4.0',source:'https://commons.wikimedia.org/wiki/File:Stavelot_Stockeu_Eddy_Merckx_monument.jpg'},
          wc('Monument Eddy Merckx Stockeu.jpg','Les Meloures','Les Meloures','CC BY-SA 4.0')],
        record:[
          {label:'Length', value:'~1.0 km'},
          {label:'Average gradient', value:'9%+'},
          {label:'Surface', value:'Asphalt, worn', method:'OSM'},
          {label:'Famous for', value:'Liège–Bastogne–Liège — Eddy Merckx stele', links:[{label:'Official',href:'https://www.liege-bastogne-liege.be/en/'},{label:'Wikipedia',href:'https://en.wikipedia.org/wiki/Li%C3%A8ge%E2%80%93Bastogne%E2%80%93Li%C3%A8ge'}]},
          {label:'Near', value:'Stavelot'},
          {label:'Traffic', value:'Quiet'}
        ],
        source:'OSM roads · geometry handmade' },
      { name:'Côte de la Roche-aux-Faucons', headline:'1.5 km · 9% avg', cur:false, sq:'Rough', tr:'Moderate',
        geom:{ll:[50.55573,5.54831]},
        route:[[50.55573,5.54831],[50.5541,5.54805],[50.55337,5.54818],[50.55306,5.54839],[50.55279,5.54894],[50.55244,5.54986],[50.55245,5.55005],[50.55264,5.55053],[50.55279,5.55157],[50.55294,5.55284],[50.55319,5.55429],[50.55336,5.55532],[50.55357,5.55657],[50.55373,5.55748],[50.55372,5.55812],[50.55358,5.55866],[50.55364,5.55949],[50.55354,5.56097],[50.55329,5.56186],[50.55323,5.56241],[50.55348,5.56466],[50.55345,5.56557],[50.55323,5.56668],[50.55318,5.56787],[50.55316,5.56809]],
        steep:{at:[50.55358,5.55866],pct:'~11%'},
        grad:[6,8,9,10,11,10,9,8,9,7],
        photos:[wc('Philippe Gilbert LBL 2009 Roche aux faucons.jpg','Les Meloures','Les Meloures','CC BY-SA 2.5'),
          wc('Cote de la Roche-aux-faucons01.jpg','Bel Adone','','Public domain')],
        record:[
          {label:'Length', value:'1.5 km'},
          {label:'Average gradient', value:'9%'},
          {label:'Surface', value:'Asphalt', method:'OSM'},
          {label:'Famous for', value:'Late selective climb in Liège–Bastogne–Liège', links:[{label:'Official',href:'https://www.liege-bastogne-liege.be/en/'},{label:'Wikipedia',href:'https://en.wikipedia.org/wiki/Li%C3%A8ge%E2%80%93Bastogne%E2%80%93Li%C3%A8ge'}]},
          {label:'Traffic', value:'Moderate'}
        ],
        source:'OSM roads · geometry handmade' }
    ,
      { name:'Hockai · via RAVeL L44a', headline:'17 km · Wallonia\'s longest climb', cur:true, sq:'Smooth', tr:'Traffic-free',
        geom:{ll:[50.37607,5.87635]},
        route:[[50.37607,5.87635],[50.37562,5.87507],[50.37581,5.87496],[50.37649,5.87599],[50.37772,5.88222],[50.37909,5.889],[50.37956,5.89538],[50.38177,5.89828],[50.38377,5.89968],[50.38502,5.90168],[50.38572,5.90459],[50.38555,5.91477],[50.38594,5.91773],[50.38679,5.91984],[50.38873,5.92195],[50.39616,5.92631],[50.39779,5.92894],[50.39929,5.93248],[50.39966,5.93345],[50.40096,5.93566],[50.40342,5.93773],[50.40489,5.93981],[50.40639,5.9435],[50.40778,5.94564],[50.40987,5.94666],[50.41622,5.94621],[50.42327,5.94568],[50.42495,5.94602],[50.42748,5.94905],[50.43026,5.95383],[50.43335,5.95802],[50.43566,5.96229],[50.43621,5.96331],[50.43772,5.96491],[50.43955,5.96542],[50.44138,5.96473],[50.44558,5.96159],[50.44863,5.95825],[50.45217,5.95711],[50.45307,5.95661],[50.4533,5.9569],[50.45397,5.95693],[50.45705,5.95663],[50.4593,5.95658],[50.46281,5.95834],[50.46482,5.96077],[50.46627,5.96352],[50.46856,5.96804],[50.46879,5.96843],[50.47117,5.97136],[50.47425,5.97323],[50.47445,5.97327],[50.47476,5.97508],[50.47542,5.97394],[50.47745,5.97543],[50.47883,5.97707],[50.48107,5.98187],[50.48237,5.98524],[50.48307,5.98707]],
        grad:[2,2,1,1,2,2,2,2,1,1,3],
        record:[
          {label:'Length', value:'17.2 km — the longest climb in Wallonia'},
          {label:'Average gradient', value:'~1.7% — +296 m over 17.2 km, a long gentle drag'},
          {label:'Surface', value:'Asphalt · car-free RAVeL L45 + L44a (ex-railway)', method:'OSM'},
          {label:'Route', value:'Coo / Trois-Ponts up the RAVeL greenway to the Hockai plateau'},
          {label:'Traffic', value:'Traffic-free (RAVeL greenway)'}
        ],
        source:'OSM RAVeL L45+L44a · BRouter (follows the greenway)' }
    ]}
    ,{ key:'water', letter:'C', label:'Water & food', color:'#8FB6A8', icon:'💧', kind:'point', exp:false, features:[
      { name:'Public fountain · Stavelot', headline:'drinking water · verified potable', cur:false,
        edit:'water-fountain',                       // both fountains share one edit item (see edit-items.js)
        geom:{ll:[50.3957,5.9300]},
        record:[
          {label:'Type', value:'Public tap fountain', method:'OSM'},
          {label:'Location', value:'Stavelot town centre'},
          {label:'Potable', value:'Yes — verified tap point on the SWDE network', method:'SWDE'},
          {label:'Why verified', value:'OSM water points aren\'t all drinkable — the Commons confirms potability with the regional utility before showing a point as drinking water', links:[{label:'SWDE · Wallonia',href:'https://www.swde.be'},{label:'De Watergroep · Flanders',href:'https://www.dewatergroep.be/nl-be/drinkwater/extra-services/drinkwatertappunten'}]},
          {label:'Seasonal', value:'Year-round'}
        ],
        source:'OSM + SWDE (potability)' },
      { name:'Pouhon La Sauvenière · Spa', headline:'mineral spring · refill', cur:false,
        geom:{ll:[50.4851,5.8983]},
        photo:wc('Spa-Source de la Sauvenière (1).jpg','Romaine','Romaine','CC0'),
        record:[
          {label:'Type', value:'Mineral spring (pouhon)', method:'OSM'},
          {label:'Location', value:'Spa — the town that gave its name to every "spa"'},
          {label:'Story', value:'Spa\'s oldest spring — its iron-rich "pouhon" water drew European nobility from the 1600s', links:[{label:'Spa springs',href:'https://en.wikipedia.org/wiki/Spa,_Belgium'}]},
          {label:'Potable', value:'Yes — natural mineral spring (Ville de Spa), not SWDE tap water'},
          {label:'Seasonal', value:'Year-round'}
        ],
        source:'OSM' },
      { name:'Source Barisart · Spa', headline:'mineral spring · refill', cur:false,
        geom:{ll:[50.4745,5.8627]},
        photo:wc('Spa-Source de Barisart (3).jpg','Romaine','Romaine','CC0'),
        record:[
          {label:'Type', value:'Mineral spring (source)', method:'OSM'},
          {label:'Location', value:'Barisart, in the woods south of Spa'},
          {label:'Story', value:'A forest spring on Spa\'s historic spring-walk circuit — naturally sparkling, iron-rich water'},
          {label:'Potable', value:'Yes — natural mineral spring (Ville de Spa), not SWDE tap water'},
          {label:'Seasonal', value:'Year-round'}
        ],
        source:'OSM' },
      { name:'Fontaine Nicolay · Stavelot', headline:'street fountain · not yet verified', cur:false,
        geom:{ll:[50.39249,5.92637]},
        record:[
          {label:'Type', value:'Street fountain', method:'OSM'},
          {label:'Location', value:'Rue Neuve, Stavelot old town (on the pavé)'},
          {label:'Potable', value:'Unconfirmed — OSM maps the fountain but sets no drinking_water tag', method:'unverified'},
          {label:'Why flagged', value:'A mapped fountain isn\'t proof of drinking water — the Commons holds it back until the utility confirms', links:[{label:'SWDE',href:'https://www.swde.be'}]}
        ],
        source:'OSM (amenity=fountain · drinking_water unset)' },
      { name:'Fountain · Stavelot centre', headline:'fountain · not yet verified', cur:false,
        geom:{ll:[50.39484,5.92994]},
        record:[
          {label:'Type', value:'Fountain', method:'OSM'},
          {label:'Location', value:'Near the abbey, Stavelot'},
          {label:'Potable', value:'Unconfirmed — no drinking_water tag in OSM', method:'unverified'},
          {label:'Status', value:'Candidate pending a potability check with SWDE', links:[{label:'SWDE',href:'https://www.swde.be'}]}
        ],
        source:'OSM (amenity=fountain · drinking_water unset)' }
    ]}
    ,{ key:'services', letter:'D', label:'Bike services', color:'#6b6f5e', icon:'⚙', kind:'point', exp:false, features:[
      { name:'Repair station · Malmedy', headline:'pump · tools', cur:false,
        geom:{ll:[50.4260,6.0270]},
        photo:wc('Fahrradreparaturstation Neustadt Hambach.jpg','Emilius123','Emilius123','CC BY 4.0'),
        record:[
          {label:'Type', value:'Public repair station', method:'OSM'},
          {label:'Pump', value:'Yes — Presta + Schrader'},
          {label:'Tools', value:'Tethered multi-tool set'},
          {label:'Location', value:'Malmedy'}
        ],
        source:'OSM' },
      { name:'North Bike · Stavelot', headline:'bike shop · sales & repair', cur:false,
        geom:{ll:[50.3965,5.9361]},
        record:[
          {label:'Type', value:'Bike shop', method:'OSM'},
          {label:'Services', value:'Sales, parts & repairs'},
          {label:'Location', value:'Stavelot town'}
        ],
        source:'OSM' },
      { name:'Ardennes Bike · Spa', headline:'bike shop · e-bike', cur:false,
        geom:{ll:[50.4896,5.8424]},
        record:[
          {label:'Type', value:'Bike shop', method:'OSM'},
          {label:'Services', value:'Sales & repairs'},
          {label:'Location', value:'Spa'}
        ],
        source:'OSM' },
      { name:'E-bike charging · Botrange', headline:'e-bike charge point', cur:false,
        geom:{ll:[50.5019,6.0930]},
        record:[
          {label:'Type', value:'E-bike charging station', method:'OSM'},
          {label:'Location', value:'Signal de Botrange plateau'},
          {label:'Use', value:'Top up before the long exposed plateau'}
        ],
        source:'OSM' }
    ]}
    ,{ key:'stays', letter:'E', label:'Where to sleep', color:'#B5532E', icon:'⛺', kind:'point', exp:true, features:[
      { name:'Cyclist-friendly gîte · Amblève valley', headline:'secure bike storage', cur:true,
        geom:{ll:[50.4500,5.6200]},
        photo:wc('Gîte rural de Puyolle.JPG','Darreenvt','Darreenvt','CC BY-SA 4.0'),
        record:[
          {label:'Type', value:'Gîte / guesthouse'},
          {label:'Secure bike storage', value:'Yes'},
          {label:'Area', value:'Amblève valley, near Aywaille'}
        ],
        source:'Community edit' }
    ]}
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
    ,{ key:'transit', letter:'G', label:'Getting there', color:'#3E7D8C', icon:'🚆', kind:'point', exp:false, features:[
      { name:'Aywaille station', headline:'bikes on board (line 42)', cur:false,
        geom:{ll:[50.4730,5.6770]},
        photo:wc('Gare Aywaille.jpg','Les Meloures','Les Meloures','CC BY-SA 3.0 lu'),
        record:[
          {label:'Type', value:'Railway station', method:'OSM'},
          {label:'Line', value:'L42 · Liège – Luxembourg'},
          {label:'Bikes on train', value:'Allowed with a bike supplement (SNCB)'},
          {label:'Use', value:'Gateway to the Amblève climbs'}
        ],
        source:'OSM + SNCB' }
    ]}
    ,{ key:'shelter', letter:'H', label:'Shelter & emergency', color:'#9A8FB6', icon:'⛑', kind:'point', exp:false, features:[
      { name:'Shelter · Baraque Michel', headline:'refuge on the plateau', cur:false,
        geom:{ll:[50.5020,6.0500]},
        photo:wc('0 Xhoffraix - Baraque Michel - Chapelle Fischbach (1).JPG','Jean-Pol GRANDMONT','Jean-Pol GRANDMONT','CC BY 3.0'),
        record:[
          {label:'Type', value:'Refuge / chapel shelter', method:'OSM'},
          {label:'Where', value:'Baraque Michel, Hautes Fagnes'},
          {label:'Use', value:'Wind/rain refuge on exposed moorland'}
        ],
        source:'OSM' },
      { name:'Abri Jean Poumay', headline:'trail shelter', cur:false,
        geom:{ll:[50.5074,5.8521]},
        record:[
          {label:'Type', value:'Shelter / abri', method:'OSM'},
          {label:'Where', value:'Forest above Spa'},
          {label:'Use', value:'Wind & rain refuge off the trail'}
        ],
        source:'OSM' },
      { name:'Belvédère de la Hoëgne', headline:'shelter · viewpoint', cur:false,
        geom:{ll:[50.4969,5.9857]},
        record:[
          {label:'Type', value:'Belvedere shelter', method:'OSM'},
          {label:'Where', value:'Hoëgne valley, Hautes Fagnes'},
          {label:'Setting', value:'Above the tumbling Hoëgne — one of the prettiest streams in the Fagnes'},
          {label:'Use', value:'Covered rest stop & wind/rain refuge'}
        ],
        source:'OSM' },
      { name:'Picnic shelter · Pont de Baileu', headline:'covered picnic stop', cur:false,
        geom:{ll:[50.4996,6.0551]},
        record:[
          {label:'Type', value:'Picnic shelter', method:'OSM'},
          {label:'Where', value:'Hautes Fagnes, near Mont Rigi'},
          {label:'Use', value:'Wait out a shower on the plateau'}
        ],
        source:'OSM' }
    ]}
    ,{ key:'scenic', letter:'I', label:'Scenic views', color:'#2C5440', icon:'📷', kind:'point', exp:true, features:[
      { name:'Signal de Botrange', headline:'694 m · highest point of Belgium', cur:true,
        edit:'signal-de-botrange',
        geom:{ll:[50.5010,6.0940]},
        photos:[{sm:'/media/botrange-sm.jpg',lg:'/media/botrange.jpg',credit:'Trougnouf (Benoit Brummer)',creditUrl:'https://commons.wikimedia.org/wiki/User:Trougnouf',license:'CC BY 4.0',source:'https://commons.wikimedia.org/wiki/File:Signal_de_Botrange_(DSCF6640).jpg'},
          wc('1031346 Botrange 700m.jpg','Wikoli','Wikoli','CC BY-SA 3.0'),
          wc('SignalDeBotrange6mTower.jpg','David Edgar','David Edgar','CC BY-SA 3.0')],
        record:[
          {label:'Type', value:'Viewpoint / high point', method:'OSM'},
          {label:'Elevation', value:'694 m — highest point in Belgium', links:[{label:'Wikipedia',href:'https://en.wikipedia.org/wiki/Signal_de_Botrange'}]},
          {label:'The 700 m step', value:'A 6 m stone stair (Butte Baltia, 1923) was built beside it so visitors reach exactly 700 m'},
          {label:'Tower', value:'Stone Baltia tower (1934) — climb it for the full panorama'},
          {label:'Setting', value:'Hautes Fagnes nature reserve · Waimes, Liège', links:[{label:'Hautes Fagnes',href:'https://en.wikipedia.org/wiki/High_Fens'}]},
          {label:'What you see', value:'Hautes Fagnes moorland — Belgium\'s largest nature reserve'},
          {label:'Climate', value:'The coldest, wettest place in Belgium — record −25.6 °C, ~1,450 mm rain and 35+ snow days a year', links:[{label:'Wikipedia',href:'https://en.wikipedia.org/wiki/Signal_de_Botrange'}]},
          {label:'Watershed', value:'On the Ardennes river watershed and the Romance–Germanic language border'},
          {label:'For cyclists', value:'A long, gentle plateau drag rather than a steep climb — but bleak and exposed; it can be cold, windy and foggy even in summer', warn:true}
        ],
        source:'OSM' },
      { name:'Cascade de Coo', headline:'waterfall · ~15 m on the Amblève', cur:true,
        geom:{ll:[50.39359,5.87664]},
        photo:wc('Coo waterfall.JPG','Pierotreruote','Pierotreruote','CC BY-SA 3.0'),
        record:[
          {label:'Type', value:'Waterfall / viewpoint', method:'OSM'},
          {label:'Where', value:'Coo, on the Amblève — municipality of Stavelot, Liège province'},
          {label:'Story', value:'A small natural cascade existed here by the 1400s; in the 17th century the monks of Stavelot enlarged it — cutting through the Amblève\'s meander on the prince-abbot\'s orders to stop Petit-Coo flooding', links:[{label:'Wikipedia (NL)',href:'https://nl.wikipedia.org/wiki/Watervallen_van_Coo'}]},
          {label:'Height', value:'~15 m — long called Belgium\'s tallest fall, though Reinhardstein (60 m) actually holds that title'},
          {label:'For cyclists', value:'A natural photo stop on the Amblève-valley run below the Côte de Stockeu'}
        ],
        source:'OSM' }
    ]}
    ,{ key:'history', letter:'J', label:'History & culture', color:'#6E5849', icon:'🏛', kind:'point', exp:true, features:[
      { name:'Stavelot Abbey', headline:'heritage · foot of the Stockeu', cur:true,
        edit:'stavelot-abbey',
        geom:{ll:[50.3950,5.9290]},
        photos:[{sm:'/media/stavelot-abbey-sm.jpg',lg:'/media/stavelot-abbey.jpg',credit:'Nenea hartia',creditUrl:'https://commons.wikimedia.org/wiki/User:Nenea_hartia',license:'CC BY-SA 4.0',source:'https://commons.wikimedia.org/wiki/File:Abbaye_de_Stavelot.01.jpg'},
          wc('Abbaye de Stavelot 03.jpg','FrDr','FrDr','CC BY-SA 4.0')],
        record:[
          {label:'Type', value:'Abbey / heritage site', method:'OSM', links:[{label:'Official',href:'https://www.abbayedestavelot.be/'},{label:'Wikipedia',href:'https://en.wikipedia.org/wiki/Stavelot_Abbey'}]},
          {label:'Story', value:'Benedictine abbey founded 651; town museums today'},
          {label:'Cycling link', value:'At the foot of the Côte de Stockeu (LBL)'}
        ],
        source:'OSM' }
    ]}
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
  const cityLink = name => `<a class="cc-city" data-city="${name}">${name}</a>`;
  // distance (km) between [lat,lng] points; a feature's representative point for radius search
  function haversine(a,b){ const R=6371,d=Math.PI/180;
    const x=Math.sin((b[0]-a[0])*d/2)**2 + Math.cos(a[0]*d)*Math.cos(b[0]*d)*Math.sin((b[1]-a[1])*d/2)**2;
    return 2*R*Math.asin(Math.sqrt(x)); }
  function featurePoint(f){ return (f.geom&&f.geom.ll) || (f.route&&f.route[0]) || (f.geom&&f.geom.path&&f.geom.path[0]) || null; }
  function nearbyItems(ll, km){
    const out=[];
    CATALOG.forEach(layer=>layer.features.forEach(f=>{ const p=featurePoint(f); if(!p) return;
      const dist=haversine(ll,p); if(dist<=km) out.push({layer,f,dist}); }));
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
  if(window.CC_CLIMBS){ layerByKey['climbs'].features = layerByKey['climbs'].features.concat(CC_CLIMBS); }
  if(window.CC_ROUTES){
    const stars=n=>'★★★★★'.slice(0,n)+'☆☆☆☆☆'.slice(0,5-n);
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
      const cities = RIDE_CITIES[r.name] || ['Spa'];
      const startM = 350 + (i*137)%401, endM = 350 + (i*211+90)%401;   // 350–750 m, varied but stable per ride
      return {
      id:r.id, name:r.name, headline:`${r.km} km · ${r.difficulty.label}`, cur:false, edit:'ride',
      geom:{path:trimEnds(r.loop, startM, endM)}, elev:r.elev, gain:r.gain, difficulty:r.difficulty, uploader:r.uploader,
      cities,                                              // searchable start/through towns
      photo:r.photo||wc('Liège-Bastogne-Liège 2014 Echappée du jour Côte de Wanne.JPG','Les Meloures','Les Meloures','CC BY-SA 3.0'),
      source:'Contributed GPX (GPS track only)',
      record:[
        {label:'Distance', value:r.km+' km'},
        {label:'Starts at', value:cityLink(cities[0])},
        {label:'Towns on route', value:cities.map(cityLink).join(' · ')},
        {label:'Season', value:r.season},
        {label:'Quietness', value:i%2?'Mixed — some main road':'Quiet — low traffic', method:'auto · tap'},
        {label:'Scenic rating', value:stars(4+(i%2)), method:'community tap'},
        {label:'Cycling-friendliness', value:stars(3+(i%3?1:0)), method:'community tap'},
        {label:'Suitable bikes', value:r.km>90?'Road · e-bike':'Road · gravel · e-bike', method:'community edit'},
        {label:'Accessibility', value:'Handbike-friendly on the valley sections', method:'community edit'},
        {label:'Best direction', value:'Clockwise — climbs early', method:'community edit'}
      ]
    };
    });
  }
  // populate A · Road surface from the hand-picked OSM segments
  if(window.CC_SURFACE){
    layerByKey['surface'].features = CC_SURFACE.segments.map(s=>({
      id:s.id, name:s.name, headline:`${s.surface} · ${s.smoothness}`, cur:(s.cls!=='paved'), edit:'road-surface',
      geom:{path:s.path}, surfaceClass:s.cls, width:s.width,
      photo: s.photoFile ? wc(s.photoFile, s.photoCredit, s.photoUser, s.photoLicense) : undefined,
      source:'OSM (surface=*)',
      record:[
        {label:'Surface', value:s.surface, method:'OSM'},
        {label:'Smoothness', value:s.smoothness, method:'OSM'},
        {label:'Width', value:s.width},
        {label:'Traffic', value:s.traffic}
      ]
    }));
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
  function drawLine(id, latlngs, color, layer, f){
    if(!map.getSource(id)) map.addSource(id,{type:'geojson',data:{type:'Feature',properties:{},
      geometry:{type:'LineString',coordinates:latlngs.map(p=>[p[1],p[0]])}}});
    if(!map.getLayer(id+'-case')) map.addLayer({id:id+'-case',type:'line',source:id,
      layout:{'line-cap':'round','line-join':'round'},
      paint:{'line-color':'#FBF4E4','line-width':9,'line-opacity':.95}});
    if(!map.getLayer(id)) map.addLayer({id,type:'line',source:id,
      layout:{'line-cap':'round','line-join':'round'},
      paint:{'line-color':color,'line-width':5,'line-opacity':layer.key==='experience'?0.6:1}});
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
    ground:{color:'#6E5849',dash:[2,1.5],cap:'butt'},  // ground/dirt — dashed brown
    unverified:{color:'#D92D20',dash:[2.5,2.5],cap:'butt'} // OSM has no surface tag — red dashes over the white casing ("needs a tag")
  };
  const surfaceStyle=cls=>SURFACE_STYLE[cls]||{color:'#4E8C84'};
  function drawSurfaceLine(id, latlngs, cls, layer, f){
    const st=surfaceStyle(cls), cap=st.cap||'round';
    if(!map.getSource(id)) map.addSource(id,{type:'geojson',data:{type:'Feature',properties:{},
      geometry:{type:'LineString',coordinates:latlngs.map(p=>[p[1],p[0]])}}});
    if(!map.getLayer(id+'-case')) map.addLayer({id:id+'-case',type:'line',source:id,
      layout:{'line-cap':'round','line-join':'round'},
      paint:{'line-color':'#FBF4E4','line-width':8,'line-opacity':.9}});
    const w=['interpolate',['linear'],['zoom'],9,3,13,5,16,8];
    const paint={'line-color':st.color,'line-width':w,'line-opacity':1};
    if(st.dash) paint['line-dasharray']=st.dash;
    if(!map.getLayer(id)) map.addLayer({id,type:'line',source:id,
      layout:{'line-cap':cap,'line-join':'round'},paint});
    dynamicIds.push(id);
    if(!boundLayerIds.has(id)){
      map.on('click',id,()=>openDrawer(layer,f));
      map.on('mouseenter',id,()=>map.getCanvas().style.cursor='pointer');
      map.on('mousemove',id,e=>showTip(f.headline||f.name, e.lngLat));   // surface type (e.g. "Asphalt · Excellent") on hover
      map.on('mouseleave',id,()=>{ map.getCanvas().style.cursor=''; hideTip(); });
      boundLayerIds.add(id);
    }
  }
  const chipSet=id=>{const s=new Set();document.querySelectorAll('#'+id+' .chip.on').forEach(c=>s.add(c.dataset.v));return s;};
  let activeSurface=chipSet('sqf'), activeTraffic=chipSet('trf');

  function pinEl(layer,cur){
    const d=document.createElement('div');
    d.className='cc-pin'+(cur?' cur':'')+(layer.pendingLayer?' pending':''); d.style.setProperty('--c',layer.color);
    const white = txtOn(layer.color)==='#fff';   // dark pins (e.g. purple climbs) → white icon
    d.innerHTML=`<span${white?' style="filter:brightness(0) invert(1)"':''}>${layer.icon}</span>`; return d;
  }
  function featureVisible(layer, f){
    let show = (mode==='all') || !layer.exp || f.cur;       // experiential layers filter to curated
    if(show && layer.key==='climbs') show = activeSurface.has(f.sq) && activeTraffic.has(f.tr);
    return show;
  }
  // legend count = shown/total: in Curated only confirmed/curated count; in Everything everything does
  function layerCounts(layer){
    const osmFx=window['CC_'+layer.key.toUpperCase()+'_OSM'];
    const osmTotal=osmFx?osmFx.features.length:0;
    const osmConf=osmFx?osmFx.features.filter(f=>f.properties&&f.properties.c).length:0;
    const shown=layer.features.filter(f=>featureVisible(layer,f)).length + (mode==='all'?osmTotal:osmConf);
    return {shown, total:layer.features.length+osmTotal};
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
    markers.forEach(m=>m.remove()); markers=[];
    clearDynamic();
    ['water','services','scenic','history','stays','shelter','transit'].forEach(k=>{
      // unverified dots show only in Everything mode; Curated best-of keeps just the confirmed pins
      const id=k+'-osm'; if(map.getLayer(id)) map.setLayoutProperty(id,'visibility', (active.has(k) && mode==='all')?'visible':'none');
    });
    let n=0;
    CATALOG.forEach(layer=>{
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
              sEl.addEventListener('click',()=>{ openDrawer(layer,f); flyToPin([f.steep.at[1],f.steep.at[0]]); });
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
          el.addEventListener('click', ()=>{ openDrawer(layer,f); flyToPin(lngLat); });
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
          if(!((mode==='all')||!layer.exp||f.cur)) return;
          drawLine(`${layer.key}-${i}`, f.geom.path, layer.color, layer, f);
          n++;
        });
        return;
      }
      if(layer.kind==='surface'){
        layer.features.forEach((f,i)=>{
          if(!((mode==='all')||!layer.exp||f.cur)) return;
          drawSurfaceLine(`${layer.key}-${i}`, f.geom.path, f.surfaceClass, layer, f);
          n++;
        });
        return;
      }
      if(layer.kind==='area'){
        layer.features.forEach((f,i)=>{
          const id=`${layer.key}-${i}`;
          map.addSource(id,{type:'geojson',data:{type:'Feature',properties:{},
            geometry:{type:'Polygon',coordinates:[f.geom.polygon.map(p=>[p[1],p[0]])]}}});
          map.addLayer({id,type:'fill',source:id,
            paint:{'fill-color':layer.color,'fill-opacity':.16,'fill-outline-color':layer.color}});
          dynamicIds.push(id);
          if(!boundLayerIds.has(id)){
            map.on('click',id,()=>openDrawer(layer,f));
            map.on('mouseenter',id,()=>map.getCanvas().style.cursor='pointer');
            map.on('mouseleave',id,()=>map.getCanvas().style.cursor='');
            boundLayerIds.add(id);
          }
          n++;
        });
        return;
      }
    });
    // confirmed/validated points are clustered (count bubble → category icon pins); unverified stay as dots
    updateConfMarkers();
    // stacking, bottom → top: ride lines, climb lines, road-surface lines, then Mapillary on top
    const liftGroup=id=>{ if(map.getLayer(id+'-case')) map.moveLayer(id+'-case'); if(map.getLayer(id)) map.moveLayer(id); };
    dynamicIds.filter(id=>id.startsWith('experience-')).forEach(liftGroup);    // ride lines (bottom of the three)
    dynamicIds.filter(id=>id.startsWith('route-climbs-')).forEach(liftGroup);  // climb lines, above ride
    dynamicIds.filter(id=>id.startsWith('surface-')).forEach(liftGroup);       // road-surface lines, above routes
    ['mly-cov','mly-img'].forEach(id=>{ if(map.getLayer(id)) map.moveLayer(id); }); // Mapillary line + dots on the very top
    document.getElementById('count').textContent=n;
    updateCounts();   // legend shows shown/total, refreshed on mode + layer changes
  }

  function photoList(f){ return f.photos || (f.photo ? [f.photo] : []); }
  function photoCap(p){
    const credit = p.creditUrl ? `<a href="${p.creditUrl}" target="_blank" rel="noopener">${p.credit}</a>` : p.credit;
    return `© ${credit} · <a href="${ccUrl(p.license)}" target="_blank" rel="noopener">${p.license}</a> · <a href="${p.source}" target="_blank" rel="noopener">Wikimedia Commons ↗</a>`;
  }
  function buildRecord(layer, f){
    const cur = f.cur ? `<div class="cc-d-cur">▲ Curated best-of</div>` : '';
    const pl = photoList(f);
    // Same edit-bridge rule as the "Edit this item" link below (spec §6/§8):
    // the add-photo CTA only ever binds to the item's real DB id — no id, no
    // link (a name-slug guess is never a faithful target).
    const addPhoto = f.id!=null ? `<a class="cc-d-addphoto" href="/improve?item=${f.id}&name=${encodeURIComponent(f.name)}&type=${layer.letter}&add=photo" aria-label="Add a photo of ${escPend(f.name)}">
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
    let recs = f.record;
    if(layer.key==='climbs'){                          // climbs share suitability rows
      recs = recs.concat([
        {label:'Road quality', value:f.sq||'—'},
        {label:'Bike type', value:'Every type'},
        {label:'Handbike', value:'⚠ Steep — challenging for handbikes', warn:true}
      ]);
    }
    const rows = recs.map(r => {
      const links = r.links ? ' ' + r.links.map(l=>`<a class="cc-d-link" href="${l.href}" target="_blank" rel="noopener">${l.label} ↗</a>`).join('') : '';
      return `<li><span class="k">${escPend(r.label)}</span><span class="v${r.warn?' warn':''}">${escPend(r.value)}${r.method?`<span class="m">${r.method}</span>`:''}${links}</span></li>`;
    }).join('');
    const fresh = f.freshness
      ? `<div class="cc-d-fresh ${f.freshness.state}">${f.freshness.state} · last confirmed ${f.freshness.lastConfirmed}</div>` : '';
    const diff = f.difficulty
      ? `<div class="cc-diff" title="Difficulty 1–5: Easy · Moderate · Challenging · Hard · Very hard">Difficulty
          <div class="cc-diff-scale">${[1,2,3,4,5].map(n=>`<span class="cc-diff-dot${n===f.difficulty.score?' on':''}" style="--p:${DIFF_PURPLE[n]}" title="${n} · ${DIFF_LABELS[n]}">${n}</span>`).join('')}</div>
          <b class="cc-diff-lbl">${f.difficulty.label}</b></div>` : '';
    const elev = f.elev ? `<div class="cc-elev-cap">Elevation · ${Math.min(...f.elev)}–${Math.max(...f.elev)} m`
      + (f.gain?` · ${f.gain} m climbing`:'') + ` <em>(from GPX)</em></div>` + elevSvg(f.elev) : '';
    const grad = f.grad ? gradStrip(f.grad) : '';
    const up = f.uploader
      ? (f.uploader.public
          ? `<div class="cc-up">Shared by <b>${f.uploader.name}</b> · <a href="/profile?u=${slug(f.uploader.name)}">view profile</a></div>`
          : `<div class="cc-up">Shared anonymously</div>`)
      : '';
    // The edit-bridge opens /improve bound to the item's real DB id, which
    // loads that exact item and prefills the form with its current values
    // (spec §6/§8) — no id, no edit link (a name-slug guess is never a
    // faithful target).
    const editLbl = layer.key==='experience' ? '✎ Edit this ride' : '✎ Edit this item';
    const ell = f.geom && f.geom.ll;                    // [lat,lng] for point features
    let edit = '';
    if(f.id!=null){
      const editQ = `item=${f.id}&name=${encodeURIComponent(f.name)}`
        + `&type=${layer.letter}`
        + (ell ? `&lat=${ell[0]}&lng=${ell[1]}` : '');
      edit = `<a class="cc-d-act edit" href="/improve?${editQ}">${editLbl}</a>`;
    }
    let moderate = '';
    if(layer.pendingLayer && f.pending){
      // §13: real submissions now flow here (not trusted fixtures) — HTML-escape
      // every interpolated submission field before it hits innerHTML (stored-XSS-
      // in-curator-session risk). s.title/s.who/s.when render via the shared
      // f.name/f.record path above (untouched — see MapController/Task 4).
      const s=f.pending;
      // Pending items carry their own catalog letter (A–K) + coords → a faithful edit link.
      // (encodeURIComponent already makes this URL-safe; s.title isn't otherwise
      // HTML-interpolated here, so it's left alone — see edit-bridge note above.)
      edit = `<a class="cc-d-act edit" href="/improve?type=${s.letter}&item=${encodeURIComponent(s.id)}&name=${encodeURIComponent(s.title)}&lat=${s.lat}&lng=${s.lng}">✎ Edit this item</a>`;
      const body = s.body ? `<p class="cc-mod-body">${escPend(s.body)}</p>` : '';
      const diff = (s.was && s.now) ? `<div class="cc-mod-diff"><div class="cc-mod-was">${escPend(s.was)}</div><div class="cc-mod-now">${escPend(s.now)}</div></div>` : '';
      moderate = `<div class="cc-mod" data-id="${s.id}">
        <div class="cc-mod-badge">⚑ Pending review</div>${body}${diff}
        <textarea class="cc-mod-note" placeholder="Optional note — a reason, or context…"></textarea>
        <div class="cc-mod-acts">
          <button class="cc-mod-btn approve" data-decision="approve">✓ Approve</button>
          <button class="cc-mod-btn info" data-decision="needs_info">? Needs info</button>
          <button class="cc-mod-btn reject" data-decision="reject">✕ Reject</button>
        </div>
        <div class="cc-mod-preview">A · approve · R · reject — recorded, not yet persisted.</div>
      </div>`;
    }
    const vote = f.cur ? `<a class="cc-d-act" href="/vote">▲ Vote in this round</a>` : '';
    const act = edit + vote;
    const desc = f.desc ? `<p class="cc-d-desc">${f.desc}${f.descTr?` <span class="cc-d-tr">· auto-translated</span>`:''}</p>` : '';
    // C1-T3 (spec W5): an empty placeholder for the async "Recent changes"
    // section — openDrawer() fetches GET /map/item/{id}/history after this
    // HTML lands and fills #cc-d-hist-slot (buildRecord itself stays sync/pure,
    // no network calls here). Same edit-bridge id contract as `edit`/`addPhoto`
    // above: only real DB items (f.id!=null) get one. The curator's pending-
    // submission records (below) never carry f.id, so they never render this —
    // see the note on the pending branch for why that view doesn't get one either.
    const histSlot = f.id!=null ? `<div class="cc-d-hist" id="cc-d-hist-slot" data-item="${f.id}"></div>` : '';
    return `<span class="cc-d-type" style="--c:${layer.color};color:${txtOn(layer.color)}">${layer.letter} · ${layer.label}</span>
      <div class="cc-d-name">${escPend(f.name)}</div>${cur}${photo}${desc}${diff}${elev}${grad}
      <ul class="cc-d-rec">${rows}</ul>${fresh}${up}
      <div class="cc-d-src">Source · ${String(f.source).replace(/^(OpenStreetMap|OSM)/, '<a href="https://www.openstreetmap.org" target="_blank" rel="noopener" style="color:var(--glacier);text-decoration:underline;text-underline-offset:2px">$1</a>').replace(/(Géoportail de la Wallonie)/, '<a href="https://geoportail.wallonie.be/catalogue/91721175-5f01-410c-8c78-37c1d1893ba2.html" target="_blank" rel="noopener" style="color:var(--glacier);text-decoration:underline;text-underline-offset:2px">$1</a>')}</div>${act}${moderate}${histSlot}`;
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
      .then(data => {
        if(myReq !== _historyReq || !data) return;   // stale response — a newer drawer has since opened
        const slot = document.getElementById('cc-d-hist-slot');
        if(!slot) return;                              // drawer content changed/closed under us
        slot.innerHTML = renderHistoryList(data.history);
      })
      .catch(()=>{});   // enhancement only — silent on failure
  }
  function mapToast(msg){
    let t=document.getElementById('cc-toast');
    if(!t){ t=document.createElement('div'); t.id='cc-toast'; t.className='cc-toast'; document.body.appendChild(t); }
    t.textContent=msg; t.classList.add('show');
    clearTimeout(mapToast._t); mapToast._t=setTimeout(()=>t.classList.remove('show'),3200);
  }
  function hidePendingPin(id){
    const layer=layerByKey.pending; if(!layer) return;
    layer.features=layer.features.filter(f=>!(f.pending && String(f.pending.id)===String(id)));
    render();
  }
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
    document.getElementById('drawerBody').innerHTML = buildRecord(layer, f);
    if(f.id!=null) loadItemHistory(f.id);   // C1-T3: async "Recent changes" — see loadItemHistory for the race guard
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
  // city info card: fly to the town, show its info + everything in the Commons within 5 km
  function openCity(name){
    const c = CITIES[name]; if(!c) return;
    const near = nearbyItems(c.ll, 5);
    const list = near.length
      ? near.map((n,i)=>`<li><button class="cc-near" data-i="${i}"><span class="cc-near-k" style="background:${n.layer.color};color:${txtOn(n.layer.color)}">${n.layer.letter}</span><span class="cc-near-nm">${escPend(n.f.name)}</span><em>${n.dist<1?Math.round(n.dist*1000)+' m':n.dist.toFixed(1)+' km'}</em></button></li>`).join('')
      : '<li class="cc-near-empty">Nothing mapped here yet — be the first to add something.</li>';
    document.getElementById('drawerBody').innerHTML =
      `<span class="cc-d-type" style="--c:#3E7D8C;color:#fff">◎ City</span>
       <div class="cc-d-name">${name}</div>
       <div class="cc-city-info">${c.info}</div>
       <div class="cc-city-links"><a href="${c.wiki}" target="_blank" rel="noopener">Wikipedia ↗</a> · <span class="cc-city-ua">community notes — none yet</span></div>
       <h4 class="cc-near-h">In the Commons nearby · ≤ 5 km</h4>
       <ul class="cc-near-list">${list}</ul>`;
    document.querySelectorAll('#drawerBody .cc-near').forEach(b=>{
      const n=near[+b.dataset.i], p=featurePoint(n.f);
      b.onclick=()=>{ openDrawer(n.layer,n.f); if(p) flyToPin([p[1],p[0]]); };
      b.onmouseenter=()=>highlightAt(p); b.onmouseleave=clearHighlight;
    });
    const d=document.getElementById('drawer'); d.classList.add('open'); d.setAttribute('aria-hidden','false');
    flyToPin([c.ll[1],c.ll[0]]);
    d.focus({preventScroll:true});   // move focus into the panel (not the close X — avoids a focus ring on tap/click open)
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
  function highlightAt(ll){
    if(!ll){ return clearHighlight(); }
    if(!hlMarker){ const el=document.createElement('div'); el.className='cc-highlight'; hlMarker=new maplibregl.Marker({element:el,anchor:'center'}); }
    hlMarker.setLngLat([ll[1],ll[0]]).addTo(map);
  }
  function clearHighlight(){ if(hlMarker) hlMarker.remove(); }
  function closeDrawer(){
    const d=document.getElementById('drawer'); d.classList.remove('open'); d.setAttribute('aria-hidden','true');
    clearHighlight();
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
    // built once — CATALOG is fully populated (climbs + rides folded in) by this point
    const SEARCH_IDX=[];
    Object.keys(CITIES).forEach(name=>{ const big=CITIES[name].t==='City';   // big cities stand apart from hamlets: ochre ◉ "City" vs teal ◎ "Town"
      SEARCH_IDX.push({name, key:slug(name), kind: big?'City':'Town', badge: big?'◉':'◎', color: big?'#C8923A':'#3E7D8C', go:()=>openCity(name)}); });
    CATALOG.forEach(layer=>(layer.features||[]).forEach(f=>{ if(!f.name) return;
      SEARCH_IDX.push({name:f.name, key:slug(f.name+' '+(layer.label||'')), kind:layer.label||'', badge:layer.letter||'•', color:layer.color||'#6b6f5e', go:()=>openFeatureByName(f.name)}); }));
    // PIVOT accommodation (Tourisme Wallonie, CC-BY) — bulk stays, not CATALOG features → index explicitly
    (window.CC_STAYS_PIVOT && window.CC_STAYS_PIVOT.features || []).forEach(f=>{ const p=f.properties; if(!p || !p.n) return;
      const layer=layerByKey.stays; if(!layer) return;
      SEARCH_IDX.push({name:p.n, key:slug(p.n+' '+(p.town||'')+' '+(layer.label||'')), kind:layer.label||'', badge:layer.letter||'•', color:layer.color||'#6b6f5e', go:()=>openStayPivot(f)}); });
    let sMatches=[], sHL=-1;
    const closeS=()=>{ sRes.hidden=true; sRes.innerHTML=''; sMatches=[]; sHL=-1; sBox.setAttribute('aria-expanded','false'); };
    const hlS=()=>sRes.querySelectorAll('button').forEach((b,i)=>b.classList.toggle('hl',i===sHL));
    function pickS(i){ const m=sMatches[i]; if(!m) return; sBox.value=m.name; closeS();
      if(window.innerWidth<=820){ const ap=document.querySelector('.app'); if(ap) ap.classList.remove('sheet-open'); }  // clear the filter sheet on mobile
      m.go(); }
    function runS(){
      const q=slug(sBox.value.trim());
      if(!q){ closeS(); return; }
      const starts=[], has=[];                                  // prefix matches rank above substring matches
      for(const it of SEARCH_IDX){ const i=it.key.indexOf(q); if(i===0) starts.push(it); else if(i>0) has.push(it); }
      sMatches=starts.concat(has).slice(0,8); sHL=-1;
      sRes.hidden=false; sBox.setAttribute('aria-expanded','true');
      sRes.innerHTML = sMatches.length
        ? sMatches.map((m,i)=>`<li role="option"><button data-i="${i}"><span class="sw" style="background:${m.color};color:${txtOn(m.color)}">${m.badge}</span><span class="snm">${escH(m.name)}</span><span class="sub">${escH(m.kind)}</span></button></li>`).join('')
        : '<li class="search-empty">No match in the Wallonia demo yet.</li>';
      sRes.querySelectorAll('button').forEach(b=>b.onclick=()=>pickS(+b.dataset.i));
    }
    sBox.addEventListener('input', runS);
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

  // mode toggle
  document.querySelectorAll('#mode button').forEach(b=>b.onclick=()=>{
    document.querySelectorAll('#mode button').forEach(x=>x.classList.remove('on'));
    b.classList.add('on'); mode=b.dataset.m;
    document.querySelector('.map-top .sub').textContent = (mode==='curated'?'Curated best-of · Summer 2026':'Everything · full backlog');
    render();
  });
  // discipline + freshness chips (visual)
  document.querySelectorAll('#disc .chip, .grp .chips .chip').forEach(c=>c.onclick=()=>c.classList.toggle('on'));
  // climb surface + traffic chips actually filter the climbs layer
  document.querySelectorAll('#sqf .chip, #trf .chip').forEach(c=>c.onclick=()=>{
    c.classList.toggle('on');
    activeSurface=chipSet('sqf'); activeTraffic=chipSet('trf');
    render();
  });

  // ride-heatmap toggle + season filter
  document.querySelectorAll('#heattoggle button').forEach(b=>b.onclick=()=>{
    document.querySelectorAll('#heattoggle button').forEach(x=>x.classList.remove('on')); b.classList.add('on');
    if(map.getLayer('rideheat')) map.setLayoutProperty('rideheat','visibility', b.dataset.h==='on'?'visible':'none');
  });
  document.querySelectorAll('#season .chip').forEach(c=>c.onclick=()=>{
    document.querySelectorAll('#season .chip').forEach(x=>x.classList.remove('on')); c.classList.add('on');
    const s=c.dataset.s;
    if(map.getLayer('rideheat')) map.setFilter('rideheat', s==='all'?null:['==',['get','season'],s]);
  });

  // street-level imagery (Mapillary) dock controls — the on/off toggle lives in the data-layers list
  document.getElementById('mlyClose').onclick=mlyClose;
  document.getElementById('mlyFull').onclick=()=>document.getElementById('mlyDock').classList.toggle('full');

  // "plan from Spa" — pick the sample loop nearest the chosen distance (faked for now)
  let planMarker=null;
  function clearPlan(){
    ['planroute','planroute-case'].forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); });
    if(map.getSource('planroute')) map.removeSource('planroute');
    if(planMarker){ planMarker.remove(); planMarker=null; }
  }
  function planFromSpa(km){
    if(!window.CC_ROUTES) return;
    const r=CC_ROUTES.routes.reduce((b,x)=>Math.abs(x.km-km)<Math.abs(b.km-km)?x:b);
    clearPlan();
    map.addSource('planroute',{type:'geojson',data:{type:'Feature',geometry:{type:'LineString',coordinates:r.loop.map(p=>[p[1],p[0]])}}});
    map.addLayer({id:'planroute-case',type:'line',source:'planroute',layout:{'line-cap':'round','line-join':'round'},paint:{'line-color':'#FBF4E4','line-width':9,'line-opacity':.95}});
    map.addLayer({id:'planroute',type:'line',source:'planroute',layout:{'line-cap':'round','line-join':'round'},paint:{'line-color':'#FF5A1F','line-width':5,'line-opacity':1}});
    const el=document.createElement('div'); el.className='cc-pin cur'; el.style.setProperty('--c','#FF5A1F'); el.innerHTML='<span>◎</span>';
    planMarker=new maplibregl.Marker({element:el,anchor:'bottom'}).setLngLat([r.start[1],r.start[0]]).addTo(map);
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
