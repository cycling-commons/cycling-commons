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

/* The Bikes on board answer and where it came from. A dock's own `bicycle` tag
   always wins (`routes: 0`). Without one, the harvest may have given it the
   answer of the ferry routes that end at it, under its own `cc:` keys
   (docs/specs/coverage-provider.md §3); `routes` is how many routes gave it. */
export function bikeAccess(tags){
  const t = tags || {};
  if(String(t.bicycle ?? '').trim()){
    const own = bikesOnBoard(t);
    return own ? {answer:own, routes:0} : null;
  }
  const answer = bikesOnBoard({bicycle:t['cc:bicycle_from_route'], 'bicycle:fee':t['cc:bicycle:fee_from_route']});
  if(!answer) return null;
  const routes = String(t['cc:ferry_route'] ?? '').split(';').filter(r => OSM_REF.test(r.trim())).length;
  return {answer, routes:Math.max(routes, 1)};
}

/* Where an inherited answer came from, in words: "from the ferry {name}" for
   one named route, else "via its ferry route(s)". Null for the dock's own tag.
   `words` = {one, route, routes} from the drawer strings. */
export function bikeSourceText(access, routeName, words){
  if(!access || !access.routes) return null;
  if(access.routes > 1) return words.routes;
  const name = String(routeName ?? '').trim();
  return name ? words.one.replace('{name}', name) : words.route;
}

/* OSM `duration` as whole minutes (rounded up, so a crossing is never "0 min"):
   "HH:MM", "HH:MM:SS" or ISO 8601 "PT1H25M". A bare number is refused, because
   nothing says whether it counts minutes or hours. */
export function osmDuration(raw){
  const v = String(raw ?? '').trim();
  let seconds = null;
  let m = v.match(/^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?$/);
  if(m) seconds = Number(m[1]) * 3600 + Number(m[2]) * 60 + Number(m[3] || 0);
  m = v.match(/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i);
  if(m && (m[1] || m[2] || m[3])) seconds = Number(m[1] || 0) * 3600 + Number(m[2] || 0) * 60 + Number(m[3] || 0);
  return seconds ? Math.ceil(seconds / 60) : null;
}

/* Minutes as "1 h 25 min". `words` = {h, min, hMin} with {h} and {m} slots. */
export function durationText(minutes, words){
  const h = Math.floor(minutes / 60), mm = minutes % 60;
  const fill = s => s.replace('{h}', String(h)).replace('{m}', String(mm));
  return fill(h && mm ? words.hMin : h ? words.h : words.min);
}

/* What a `route=ferry` object says beyond bikes (docs/specs/coverage-provider.md §5):
   crossing time, season (`seasonal` yes/no as a word, free text verbatim),
   service hours, fare (`toll` or `fee`, yes wins) and website. Null when the
   object is not a ferry route or says none of it. */
export function ferryFacts(tags){
  const t = tags || {};
  if(t.route !== 'ferry') return null;
  const word = k => String(t[k] ?? '').trim();
  const seasonal = word('seasonal');
  const low = seasonal.toLowerCase();
  const fares = [word('toll').toLowerCase(), word('fee').toLowerCase()];
  const facts = {
    minutes: osmDuration(t.duration),
    season: low === 'yes' ? 'seasonal' : low === 'no' ? 'allYear' : null,
    seasonText: seasonal && low !== 'yes' && low !== 'no' ? seasonal : null,
    hours: word('opening_hours') || null,
    fare: fares.includes('yes') ? 'paid' : fares.includes('no') ? 'free' : null,
    web: word('website') || word('contact:website') || word('url') || null,
  };
  return Object.values(facts).some(v => v != null) ? facts : null;
}

/** The drawer string (D in i18n.js) for each ferryFacts token. */
export const FERRY_FACT_LABEL = {
  seasonal: 'ferrySeasonal',
  allYear: 'ferryAllYear',
  paid: 'ferryPaid',
  free: 'ferryFree',
};
