// SPDX-License-Identifier: AGPL-3.0-only
/* Whether the "not covered yet" banner shows (docs/specs/map-and-search.md
   §4.5b). A leaf: no DOM, no map, no CCScope import - the decision alone, so
   it can be tested. The caller (scope-ui.js) resolves onboarded-ness against
   the region registry and passes the answers in. */

// Panning names no country (there is no geocoder for an arbitrary point), so
// it never fires below this zoom - a rider zoomed out over a whole continent
// is not "at" any one place yet. Exported so scope-ui.js resets its dismissal
// memory at the same threshold the show/hide decision uses.
export const PAN_MIN_ZOOM = 7;

const HIDDEN = { show: false, key: null, countryName: null, countryCode: null };

/**
 * @param {object} p
 * @param {number} [p.zoom] - current map zoom (ignored when `searchHit` is set)
 * @param {{lat:number,lng:number}} [p.centre] - current map centre; carried
 *   through for callers that want it in the returned key one day, not read here
 * @param {boolean} p.onboardedAt - true when the place in question (the
 *   search hit's country, or the point under the map centre) is onboarded
 * @param {boolean} [p.nearOnboarded] - true when the pan centre sits within
 *   the coastal tolerance of an onboarded region (only consulted while
 *   panning; a search hit is a named country, not a coastline)
 * @param {{countryCode:?string, countryName:?string}} [p.searchHit] - set once,
 *   right after an explicit search selection has flown to its target; absent
 *   for a plain pan
 * @param {?string} p.dismissedKey - the key the rider last closed the banner on
 * @returns {{show:boolean, key:?string, countryName:?string, countryCode:?string}}
 */
export function noticeFor(p) {
  const { zoom, onboardedAt, nearOnboarded, searchHit, dismissedKey } = p || {};

  if (searchHit) {
    const cc = searchHit.countryCode || null;
    if (!cc) return HIDDEN;               // no country code: nothing to name, nothing to check
    if (onboardedAt) return HIDDEN;
    const key = 'country:' + cc.toUpperCase();
    if (key === dismissedKey) return HIDDEN;
    return { show: true, key, countryName: searchHit.countryName || null, countryCode: cc.toUpperCase() };
  }

  if (zoom == null || zoom < PAN_MIN_ZOOM) return HIDDEN;
  if (onboardedAt || nearOnboarded) return HIDDEN;
  const key = 'area';
  if (key === dismissedKey) return HIDDEN;
  return { show: true, key, countryName: null, countryCode: null };
}
