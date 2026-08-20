// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The MapLibre instance and everything that belongs to the map object itself:
   controls, the satellite base, the style-ready flag, the fly-to-pin camera
   move and the right-click coordinate popup
.

   `map` is bound at module scope — one of the two exceptions §4.2 allows.
   Every other module needs it before any init() runs, it is assigned once, and
   the catalog-fetch gate already guarantees the container exists. Everything
   with a side effect beyond that is an exported init*() the entry calls.

   It is ADOPTED, not constructed, since 2026-08-09. This module lives inside
   the graph that only executes once the ~1 MB catalog has arrived, so
   constructing here meant the basemap style request — and the first tile a
   visitor sees — queued behind a payload describing layers drawn much later.
   catalog-load.js builds the instance before it starts that fetch, and the
   bounds reasoning moved there with it. Nothing else about the gate changed:
   every module below still runs exactly when it used to.

   The fallback constructs from the same options object catalog-load.js
   published, so there is one home for them either way. Reaching the throw
   means map.js was loaded by something other than catalog-load.js, in which
   case none of the CC_* globals exist either and a named error here beats
   twenty confusing ones below. */

export const map = (function(){
  if(window.__ccMapInstance) return window.__ccMapInstance;
  if(window.__ccMapOpts) return new maplibregl.Map(window.__ccMapOpts);
  throw new Error('map-init: no MapLibre instance and no options — map.js must be booted by catalog-load.js');
})();
// Non-prod test handle. web/tests/browser/map-smoke.js (the checkpoint sweep of
// map-and-search.md §2) runs as a page script and has no
// other way to reach the MapLibre instance, so it cannot assert on layers,
// sources or filters — the exact things a module split can silently break.
// Gated on CC_DEBUG, which templates/map/index.html.twig emits only when
// app.environment is not 'prod', so production ships no handle at all.
if(window.CC_DEBUG) window.__ccMap = map;

/* Basemap place names follow the site language (reported 2026-07-27: "Lower
   Saxony does not become Niedersachsen"). This is the BASEMAP's own labels —
   the city/state/street names baked into the vector tiles — not our chips or
   header, which were translating all along.

   OpenFreeMap's `liberty` style hardcodes English on every name layer:
   `["coalesce", ["get","name_en"], ["get","name"]]`. The tiles themselves carry
   the whole set — measured on the live source, `name:de` on 396 of 400 place
   features, `name:fr` 387, `name:nl` 380 — so nothing needs to be fetched
   differently; the style just never asks. Lower Saxony ships as
   name:de=Niedersachsen, name:nl=Nedersaksen, name:fr=Basse-Saxe.

   Detection is "does this text-field mention name_en", not a match on the exact
   expression: the three road-shield layers read `ref` and must keep it, and a
   future style tweak to the name expression should still be caught. Each
   layer's own separator is preserved — the point-label group joins the two
   scripts with a newline and the line-label group with a space. */
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
      // Non-latin script: keep the style's dual-script rendering, but prefer
      // the reader's language over the generic latin transliteration.
      ['has','name:nonlatin'],
      ['concat', [...pref, ['get','name:latin']], sep, ['get','name:nonlatin']],
      // Otherwise: the reader's language, then the LOCAL name — falling back to
      // English here would put a German reader back where they started.
      [...pref, ['get','name'], ['get','name:latin']],
    ]);
    n++;
  }
  return n;
}

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

/* The satellite base — Esri World Imagery, keyed.

   The URL used to be the keyless `server.arcgisonline.com` REST path. It
   answered without a token, which is not the same as being licensed to: Esri's
   billed basemap endpoints are key-authenticated and their terms require a
   subscription, and nothing published grants keyless third-party access
   (docs/specs/Dated/2026-08-09-esri-imagery-terms.md). We hold an ArcGIS
   Location Platform key now — public-application credential, static-basemap-
   tiles privilege only, referrer-restricted — so the same imagery is served on
   terms that permit it.

   No key means NO satellite source at all, rather than a source that 401s
   every tile: the layer has always been optional, and the toggle simply has
   nothing to show. Which is also how a lapsed key behaves, so check
   window.CC_ESRI_KEY before assuming a rendering bug. */
export const ESRI_KEY = window.CC_ESRI_KEY || '';
export const esriTileUrl = key =>
  'https://ibasemaps-api.arcgis.com/arcgis/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}?token='
  + encodeURIComponent(key);

/* Is a satellite base available AT ALL? Ask this, never `map.getLayer(
   'satellite')`, when deciding whether to offer the base picker: the layer is
   added inside map.on('load'), and the chrome is built synchronously before
   that fires, so the layer question always answered "no" and the picker hid
   itself on every load - including for instances that have a perfectly good
   Esri key (owner-reported 2026-08-20). The key is the real condition and it
   is here from the first line. Same shape as surfaceTilesConfigured() /
   routesTilesConfigured(). */
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
