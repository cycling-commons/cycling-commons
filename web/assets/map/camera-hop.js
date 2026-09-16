// SPDX-License-Identifier: AGPL-3.0-only
/* Animate the move, or cut to it (docs/specs/map-and-search.md §8).
   A leaf: no DOM, no map, no storage - the decision alone, so it can be tested.

   A map animates over the tiles it has. It only ever fetched the ones for
   viewports it has actually drawn, so a hop to ground the rider has not been
   looking at has nothing under it: the camera slides for a second over the
   style's background colour and lands on a view that then assembles itself.
   Owner-reported 2026-09-16, first as "page goes blank and rebuild on the now
   focussed route" and then, with the overlays out of the way, as "now it
   scrolls to the location while show a grey screen".

   No arc shape fixes that. A flatter arc keeps the camera at higher zooms,
   where a 270 km corridor needs MORE tiles, and none of those are cached
   either; zooming further out needs low-zoom tiles the session never fetched.
   Prefetching the whole corridor first buys the animation at the price of
   seconds of nothing happening after a click. So the honest rule is to animate
   when there IS map under the move, and to cut when there is not. */

/** Ground metres one screen pixel covers at this zoom and latitude (Web Mercator). */
export function metresPerPixel(zoom, lat){
  return 156543.03392 * Math.cos(lat * Math.PI / 180) / Math.pow(2, zoom);
}

/** Great-circle-ish metres between two [lng, lat] points. Equirectangular: at
 *  the distances this judges (tens to hundreds of km) it is within a percent,
 *  and the threshold it feeds is a judgement call, not a measurement. */
export function groundDistanceM(a, b){
  const R = 6371008.8, rad = Math.PI / 180;
  const lat = ((a[1] + b[1]) / 2) * rad;
  const dx = (b[0] - a[0]) * rad * Math.cos(lat);
  const dy = (b[1] - a[1]) * rad;
  return Math.sqrt(dx * dx + dy * dy) * R;
}

/**
 * Whether the ground a move crosses is ground the rider is already looking at,
 * which is the same question as "are those tiles already fetched".
 *
 * `from` is {center:[lng,lat], zoom, width, height} (the container in CSS
 * pixels); `to` is {center:[lng,lat], zoom}. Two tests, both about tiles:
 *
 * - the camera moves no further than the width of what is on screen. A target
 *   further than that sits beyond every tile the session has, whatever zoom the
 *   arc passes through.
 * - the target does not zoom out to more than `spread` times the ground now on
 *   screen. Zooming out uncovers area around the current view, and that area
 *   has no tiles either.
 *
 * Measured on the owner's own hops: Friesland to the Liege route is 269 km
 * against a 234 km viewport, so it cuts; Friesland to Groningen is 62 km, and a
 * pin in the town being read is under a kilometre, so both animate. Those are
 * exactly the moves they called right and wrong.
 */
export function hopIsNear(from, to, opts){
  if(!from || !to || !Array.isArray(from.center) || !Array.isArray(to.center)) return true;
  const o = opts || {};
  const reach = o.reach == null ? 1 : o.reach;          // viewport widths the camera may cross
  const spread = o.spread == null ? 4 : o.spread;        // times the ground now on screen (two zoom levels out)
  const width = from.width || 0;
  if(!width || !isFinite(from.zoom) || !isFinite(to.zoom)) return true;   // nothing to judge on: animate
  const groundNow = metresPerPixel(from.zoom, from.center[1]) * width;
  const groundThen = metresPerPixel(to.zoom, to.center[1]) * width;
  if(groundDistanceM(from.center, to.center) > reach * groundNow) return false;
  return groundThen <= spread * groundNow;
}
