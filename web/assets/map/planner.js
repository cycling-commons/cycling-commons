// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Illustrative "plan from Spa" chips (docs/specs/map-and-search.md §11): pick
   the sample loop nearest the chosen distance. Explicitly faked — the drawer
   says so. Source/layer ids are not touched by render()'s clearDynamic. */
import { map } from './map-init.js';
import { D, trVal, CC_SEASON_LABEL } from './i18n.js';
import { uKm } from './units.js';
import { openDrawer, closeDrawer } from './drawer.js';

export let planMarker=null;
export function clearPlan(){
  ['planroute','planroute-case'].forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); });
  if(map.getSource('planroute')) map.removeSource('planroute');
  if(planMarker){ planMarker.remove(); planMarker=null; }
}
export function planFromSpa(km){
  // reduce() throws on []; CatalogProvider only emits `start` when it exists.
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
      {label:D.distance||'Distance', value:uKm(r.km)},
      {label:D.shape||'Shape', value:D.roundtrip||'Roundtrip'},
      {label:D.season||'Season', value:CC_SEASON_LABEL[r.season]||trVal(r.season)},
      {label:D.why||'Why', value:D.popularSeason||'Popular this season'},
      {label:D.note||'Note', value:D.fakedNote||'⚠ Faked — the real planner stitches from the heatmap', warn:true}
    ]
  });
}

// Distance chips (docs/specs/map-and-search.md §11): second click on the active chip clears.
export function initPlanner(){
  document.querySelectorAll('#planner .chip').forEach(c=>c.onclick=()=>{
    const wasOn=c.classList.contains('on');
    document.querySelectorAll('#planner .chip').forEach(x=>x.classList.remove('on'));
    if(wasOn){ clearPlan(); closeDrawer(); return; }   // click the active one again to clear the route
    c.classList.add('on');
    planFromSpa(+c.dataset.km);
  });
}
