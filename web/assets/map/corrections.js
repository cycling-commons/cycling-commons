// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Curator-only pending-corrections overlay (docs/specs/route-domain.md §7):
   one colour per correction, numbered stretch endpoints, side list.
   GET /routes/{id}/corrections 403s for non-curators — treated as no
   corrections, so nothing leaks.
   `_corrLayers` is reassigned by clearCorrections(); importers read it through
   corrLayerIds() so click-to-scope does not treat a preview as empty map. */
import { D } from './i18n.js';
import { escPend } from './util.js';
import { map } from './map-init.js';
import { routePathById } from './catalog.js';
import { fracToLatLng, sliceByFrac } from './render.js';

export const corrLayerIds = () => _corrLayers;

const CC_CORR_COLORS=['#FF5A1F','#3E9C8A','#C8923A','#6E7B96','#B5532E','#8FB6A8','#5F5A54','#6E5849'];
let _corrLayers=[], _corrMarkers=[];
export function clearCorrections(){
  _corrLayers.forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); if(map.getSource(id)) map.removeSource(id); });
  _corrMarkers.forEach(m=>m.remove()); _corrLayers=[]; _corrMarkers=[];
  document.getElementById('cc-corrpanel')?.remove();
}
export function showRouteCorrections(routeId){
  const path=routePathById(routeId); if(!path) return;
  // Skip the fetch (and its 403 console line) when the session is not curator.
  if(!window.CC_IS_CURATOR){ clearCorrections(); return; }
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
      <span class="cc-corr-body"><b>${escPend(String(c.reason||'').replace(/-/g,' '))}</b>${c.note?` — ${escPend(c.note)}`:''}
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
