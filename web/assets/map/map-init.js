// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The MapLibre instance and everything that belongs to the map object itself:
   controls, the satellite base, the style-ready flag, the fly-to-pin camera
   move and the right-click coordinate popup
   (2026-07-26-map-js-module-split-design.md §4).

   `map` is constructed at module scope — one of the two exceptions §4.2 allows.
   Every other module needs it before any init() runs, it is assigned once, and
   the catalog-fetch gate already guarantees the container exists. Everything
   with a side effect beyond that is an exported init*() the entry calls. */

const _scopeBb = window.CCScope ? window.CCScope.bbox() : null;
export const map = new maplibregl.Map({
  container:'map', style:'https://tiles.openfreemap.org/styles/liberty',
  // Initial viewport = the active scope's bbox (Wallonia by default; a saved
  // Flanders/Brussels scope reopens there). Everywhere has NO bbox (null), so
  // it — like a missing registry — opens on the old hardcoded Wallonia
  // literal: a deliberate anchor view, not a scope (review 07-20 info c).
  bounds: _scopeBb ? [[_scopeBb[0],_scopeBb[1]],[_scopeBb[2],_scopeBb[3]]] : [[2.84,49.45],[6.41,50.85]],
  fitBoundsOptions:{padding:24}, attributionControl:false
});
// Non-prod test handle. web/tests/browser/map-smoke.js (the checkpoint sweep of
// 2026-07-26-map-js-module-split-design.md §6) runs as a page script and has no
// other way to reach the MapLibre instance, so it cannot assert on layers,
// sources or filters — the exact things a module split can silently break.
// Gated on CC_DEBUG, which templates/map/index.html.twig emits only when
// app.environment is not 'prod', so production ships no handle at all.
if(window.CC_DEBUG) window.__ccMap = map;

/** Attribution, navigation and the live zoom readout. */
export function initMapControls(){
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
}

// optional satellite base — Esri World Imagery (added below the data layers, hidden by default)
export function addSatellite(){
  if(map.getSource('satellite')) return;
  map.addSource('satellite',{type:'raster',tileSize:256,
    tiles:['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'],
    maxzoom:19, attribution:'Imagery © Esri, Maxar, Earthstar Geographics'});
  map.addLayer({id:'satellite',type:'raster',source:'satellite',layout:{visibility:'none'}});
}

export function flyToPin(lngLat){   // centre + slow zoom-in on click; offset left so the drawer doesn't cover it
  map.flyTo({center:lngLat, zoom:Math.max(map.getZoom(),14), offset:[-150,0], duration:1700, essential:true});
}

/* Style-load race (C3): sources cannot be added, and render() must not paint,
   until MapLibre has the style. The entry's map.on('load') handler flips this. */
let _styleReady=false;
export const styleReady = () => _styleReady;
export function markStyleReady(){ _styleReady=true; }

/** Right-click → show + copy coordinates. */
export function initCoordPopup(){
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
}
