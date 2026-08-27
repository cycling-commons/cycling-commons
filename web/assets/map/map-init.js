// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* MapLibre instance, controls, satellite base, style-ready flag, fly-to-pin,
   right-click coordinates (docs/specs/map-and-search.md §2).
   `map` is adopted from catalog-load.js (built before the catalog fetch). */

export const map = (function(){
  if(window.__ccMapInstance) return window.__ccMapInstance;
  if(window.__ccMapOpts) return new maplibregl.Map(window.__ccMapOpts);
  throw new Error('map-init: no MapLibre instance and no options — map.js must be booted by catalog-load.js');
})();
// Non-prod test handle (docs/specs/map-and-search.md §2). Gated on CC_DEBUG.
if(window.CC_DEBUG) window.__ccMap = map;

/* Basemap place names follow the site language (docs/specs/map-and-search.md §4.2b).
   Liberty hardcodes name_en; the tiles already carry name:de / name:fr / name:nl.
   Detection is "text-field mentions name_en" so road-shield `ref` layers stay. */
const BASEMAP_LOCALE = (document.documentElement.lang || 'en').slice(0, 2);
export function localiseBasemapLabels(){
  const style = map.getStyle();
  if(!style || !style.layers) return 0;
  const pref = ['coalesce', ['get','name:'+BASEMAP_LOCALE], ['get','name_'+BASEMAP_LOCALE]];
  let n = 0;
  for(const layer of style.layers){
    if(layer.type !== 'symbol') continue;
    const tf = layer.layout && layer.layout['text-field'];
    if(!tf) continue;
    const json = JSON.stringify(tf);
    if(!json.includes('name_en')) continue;
    const sep = json.includes('"\\n"') ? '\n' : ' ';
    map.setLayoutProperty(layer.id, 'text-field', ['case',
      ['has','name:nonlatin'],
      ['concat', [...pref, ['get','name:latin']], sep, ['get','name:nonlatin']],
      [...pref, ['get','name'], ['get','name:latin']],
    ]);
    n++;
  }
  return n;
}

/** Attribution, navigation and the live zoom readout. */
export function initMapControls(){
  /* The Copernicus programme requires its notice on products derived from the
     DEM, and the climb gradients are exactly that (credits.html.twig carries
     the full wording). It was on /credits and missing here, which is the one
     place the derived product is actually looked at. Short form plus a link,
     because an attribution bar is not where anyone reads three sentences. */
  const creditsUrl = document.getElementById('map')?.dataset.credits || '/credits';
  map.addControl(new maplibregl.AttributionControl({customAttribution:
    '© OpenStreetMap contributors · ODbL · Elevation: Copernicus WorldDEM-30 '
    + '© DLR e.V. 2010-2014 · © Airbus Defence and Space GmbH 2014-2018 '
    + `(<a href="${creditsUrl}">credits</a>)`
  }),'bottom-right');
  map.addControl(new maplibregl.NavigationControl({showCompass:false}),'bottom-left');
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

/* Satellite base — Esri World Imagery (docs/specs/map-and-search.md §2).
   No key means no source (the layer is optional; a 401ing source is worse). */
export const ESRI_KEY = window.CC_ESRI_KEY || '';
export const esriTileUrl = key =>
  'https://ibasemaps-api.arcgis.com/arcgis/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}?token='
  + encodeURIComponent(key);

/* Ask this, never `map.getLayer('satellite')`: the layer is added on 'load',
   the chrome is built before that, so the layer question always answered no. */
export const satelliteConfigured = () => !!ESRI_KEY;

export function addSatellite(){
  if(map.getSource('satellite') || !ESRI_KEY) return;
  map.addSource('satellite',{type:'raster',tileSize:256,
    tiles:[esriTileUrl(ESRI_KEY)],
    maxzoom:19, attribution:'Imagery © Esri, Maxar, Earthstar Geographics'});
  map.addLayer({id:'satellite',type:'raster',source:'satellite',layout:{visibility:'none'}});
}

export function flyToPin(lngLat){   // centre + slow zoom-in on click; offset left so the drawer doesn't cover it
  map.flyTo({center:lngLat, zoom:Math.max(map.getZoom(),14), offset:[-150,0], duration:1700, essential:true});
}

/* Style-load race: sources cannot be added, and render() must not paint, until MapLibre has the style. */
let _styleReady=false;
export const styleReady = () => _styleReady;
export function markStyleReady(){ _styleReady=true; }

/** Right-click → show + copy coordinates. */
export function initCoordPopup(){
  let _coordPopup=null;
  map.on('contextmenu', e=>{
    const c = `${e.lngLat.lat.toFixed(6)}, ${e.lngLat.lng.toFixed(6)}`;
    if(!_coordPopup) _coordPopup=new maplibregl.Popup({closeButton:true,className:'pop'});
    const label=ok=>_coordPopup.setHTML(`<div class="pop"><div class="pop-co">Coordinates${ok?' · copied':' — select to copy'}</div>${c}</div>`);
    label(false); _coordPopup.setLngLat(e.lngLat).addTo(map);
    if(navigator.clipboard) navigator.clipboard.writeText(c).then(()=>label(true)).catch(()=>{});
  });
}
