// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Located-correction picking (route-domain spec §16 S1): the rider marks the
   stretches of a route a correction applies to, by clicking the line twice per
   stretch. Captured fractions are held per route id in _pickSegs until a
   successful "suggest" POST consumes them (community.js).
   Extracted from map.js by the module split.

   The session itself (`_pick`) stays private: an importer would get a read-only
   binding of a value that is reassigned on every start/finish, so it is read
   through isPicking() — the owner-module rule (§9). _pickSegs is a mutable
   container, not a rebindable variable, so it exports directly.

   What reads isPicking(), and why each one has to: openDrawer bails so a picking
   click cannot switch the drawer under the rider (§16 S1), closeDrawer tears the
   session down so a mid-pick close leaves no orphaned map handler, coverage.js
   skips both its paint and its detail fetch, and the entry's click-to-scope
   handler yields the click entirely. */
import { map } from './map-init.js';
import { D, tpl } from './i18n.js';
import { routePathById } from './catalog.js';
import { nearestOnPath, sliceByFrac } from './render.js';
import { mapToast } from './drawer.js';

// --- Located-correction picking mode (spec §16 S1). Segments captured per
// route id, kept until a successful "suggest" POST consumes and clears them. ---
export const _pickSegs={};   // route id → list<{start,end}> captured for the open suggest form
let _pick=null;       // active picking session or null
export const isPicking = () => !!_pick;


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
export function cancelPicking(){
  if(!_pick) return;
  map.off('click', pickClick); map.getCanvas().style.cursor='';
  _pick.markers.forEach(m=>m.remove()); _pick.segLayers.forEach(id=>{ if(map.getLayer(id)) map.removeLayer(id); if(map.getSource(id)) map.removeSource(id); });
  _pick=null;
  const bar=document.getElementById('cc-pickbar'); if(bar) bar.hidden=true;
  document.querySelector('.cc-drawer')?.classList.remove('cc-drawer-min');
}
// The "Mark on map" button lives inside the route drawer, which is re-rendered
// on every open, so the handler is delegated from the document (§4.2).
export function initPicking(){
  document.addEventListener('click', e=>{
    const mb=e.target.closest('[data-rc-mark]'); if(!mb) return;
    startPicking(mb.getAttribute('data-rc-mark'));
  });
}
