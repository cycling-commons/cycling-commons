// SPDX-License-Identifier: AGPL-3.0-only
/* OSM tag values that are facts rather than prose: a peak's altitude, a
   waterfall's drop, the way a viewpoint faces. Read straight from the harvest
   whitelist (docs/specs/coverage-provider.md §5).
   A leaf: imports nothing, so the node tests can import it directly. */

const COMPASS = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];

/** A metres-valued tag ("484", "484 m") as a number; anything else null. */
export function osmMetres(raw){
  const m = String(raw).trim().match(/^(-?\d+(?:\.\d+)?)\s*m?$/i);
  return m ? Number(m[1]) : null;
}

/** `direction`: a bearing becomes "SW · 225°"; a compass point stays itself. */
export function viewDirection(raw){
  const v = String(raw).trim();
  if(/^-?\d+(?:\.\d+)?$/.test(v)){
    const deg = ((Number(v) % 360) + 360) % 360;
    return COMPASS[Math.round(deg / 22.5) % 16] + ' · ' + Math.round(deg) + '°';
  }
  return /^[NSEW]{1,3}$/i.test(v) ? v.toUpperCase() : v;
}

/* A coverage POI's OSM ref (`node/462149319`). Only the two shapes the harvest
   stores and `/map/coverage/poi/{osmType}/{osmId}` accepts, so a ref that makes
   a share link always makes one that opens again. */
export const OSM_REF = /^(?:node|way)\/\d+$/;

/** That ref as an openstreetmap.org URL; null when it is not a ref we serve. */
export function osmRefUrl(ref){
  return OSM_REF.test(String(ref ?? '')) ? 'https://www.openstreetmap.org/' + ref : null;
}
