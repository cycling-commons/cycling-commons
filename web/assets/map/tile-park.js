// SPDX-License-Identifier: AGPL-3.0-only
/* Park the tiled overlays for the length of one camera move
   (docs/specs/map-and-search.md §8).

   The coverage archive and the surface archive together carry most of the
   style's layers (320 and 152 of 637 on a full catalogue). Flying into an area
   whose tiles nobody has asked for yet makes the map fetch, parse and place
   every one of them for the new viewport, on the main thread, while the ease is
   running: measured on the dev map as a single 1432 ms block in the middle of
   a 900 ms flight, during which the map rendered NOTHING. The rider sees the
   old view, then a frozen half-drawn frame, then the destination assembling in
   place. Owner-reported 2026-09-16: "page goes blank and rebuild on the now
   focussed route", and "back to Liege it also works", because the second visit
   has that data already.

   A source with no visible layer is a source MapLibre asks for nothing, so
   parking these two for the flight leaves the basemap and the catalogue lines
   (plain GeoJSON, already in memory) to carry the animation, and the overlays
   come back when the camera lands. Visibility is the whole mechanism: 472
   `setLayoutProperty` calls measured under 1 ms, unlike `setFilter`, which
   revalidates the whole style each time (coverage.js NO_VALIDATE). */
import { map } from './map-init.js';

// The two PMTiles archives. A source is named here, never a layer: the
// coverage grid adds a layer per letter per country and the list would rot.
const PARKED_SOURCES = ['coverage', 'surface-tiles'];
const NO_VALIDATE = {validate: false};

let _parked = null;

/** Hide every drawn layer of the tiled overlays. Idempotent: a second call
 *  while parked keeps the first call's list, so nothing the rider turned off
 *  in between comes back on. */
export function parkTiledOverlays(){
  if(_parked || !map.getStyle()) return;
  _parked = [];
  map.getStyle().layers.forEach(l => {
    if(PARKED_SOURCES.indexOf(l.source) === -1) return;
    // Already off (layer switched off, mode hides it): not ours to turn back on.
    if(map.getLayoutProperty(l.id, 'visibility') === 'none') return;
    _parked.push(l.id);
    map.setLayoutProperty(l.id, 'visibility', 'none', NO_VALIDATE);
  });
}

/** Put back exactly what parkTiledOverlays() hid. A no-op when nothing is
 *  parked, so a caller may always pair it. `syncCoverageLayers()` (render.js)
 *  has the last word on the coverage half a moment later. */
export function unparkTiledOverlays(){
  const ids = _parked;
  _parked = null;
  if(!ids) return;
  ids.forEach(id => { if(map.getLayer(id)) map.setLayoutProperty(id, 'visibility', 'visible', NO_VALIDATE); });
}

/** Whether the overlays are parked right now (for tests and for a caller that
 *  must not park twice over one another). */
export function tiledOverlaysParked(){ return _parked !== null; }
