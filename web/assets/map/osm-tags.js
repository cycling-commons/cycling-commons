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

/* The coverage tile's own type label (`t`, docs/specs/coverage-provider.md §4)
   for one ref, from features read out of the tiles. A click on an icon reads
   `t` straight off the feature; a point opened by ref (?ref=, search, a ride
   row) reads it here, so both name the type the same way. Null when no tile
   feature with that ref carries a label. */
export function tileTypeLabel(features, ref){
  for(const f of (Array.isArray(features) ? features : [])){
    const p = f && f.properties;
    if(!p || p.ref !== ref) continue;
    const t = typeof p.t === 'string' ? p.t.trim() : '';
    if(t) return t;
  }
  return null;
}

/* The tile source-layers a point of `letter` can sit in (`<letter>_<cc>`, the
   unstamped `zz`, or the plain `<letter>` of tiles from before the per-country
   split). When the point's country is one of the tile countries, only that
   layer and `zz`; otherwise every one. `ccs` is coverage.js COVERAGE_CCS. */
export function coverageSourceLayers(letter, ccs, countryCode){
  const list = Array.isArray(ccs) && ccs.length ? ccs : [null];
  if(list.length === 1 && list[0] == null) return [letter];
  const cc = String(countryCode || '').toLowerCase();
  const pick = cc && cc !== 'zz' && list.includes(cc) ? [cc, 'zz'].filter(c => list.includes(c)) : list;
  return pick.map(c => letter + '_' + c);
}

/* Letter F's Bikes on board row (docs/specs/coverage-provider.md §5): the one
   place that reads OSM `bicycle` and `bicycle:fee` for the drawer. The answer
   is a token; BIKES_ON_BOARD_LABEL names the drawer string for each, so the
   OSM vocabulary and the words a rider reads stay in step here. */
const BIKE_ACCESS = {yes:'allowed', designated:'allowed', permissive:'allowed', no:'no', dismount:'dismount'};

/** 'allowed' | 'allowed_fee' | 'dismount' | 'dismount_fee' | 'no', or null when OSM says nothing we can put in words. */
export function bikesOnBoard(tags){
  const access = BIKE_ACCESS[String((tags || {}).bicycle ?? '').trim().toLowerCase()];
  if(!access) return null;
  const fee = String(tags['bicycle:fee'] ?? '').trim().toLowerCase() === 'yes';
  return fee && access !== 'no' ? access + '_fee' : access;
}

/** The drawer string (D in i18n.js) for each bikesOnBoard answer. */
export const BIKES_ON_BOARD_LABEL = {
  allowed: 'bikesAllowed',
  allowed_fee: 'bikesAllowedFee',
  dismount: 'bikesDismount',
  dismount_fee: 'bikesDismountFee',
  no: 'bikesNotAllowed',
};
