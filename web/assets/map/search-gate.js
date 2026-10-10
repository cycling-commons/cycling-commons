// SPDX-License-Identifier: AGPL-3.0-only
/* Where the search box looks (docs/specs/map-and-search.md §4.5, §7). With the
   reach off, every source of rows keeps to the scope; with the reach on
   (Everywhere), none does. Every gate the list obeys lives here, so one test
   holds them all: tests/js/search-gate.test.mjs. `scope` is CCScope. */
export function searchGate(scope, worldwide){
  const reach = !!worldwide;
  const cur = scope ? scope.get() : null;
  const open = reach || !cur || cur.kind === 'everywhere';
  const photon = (scope && !reach) ? scope.photonParams() : {bbox:null, countrycode:null};
  const ccs = cur && cur.kind === 'myArea' && cur.myArea ? cur.myArea.countryCodes
    : (cur && cur.countryCode ? [cur.countryCode] : []);
  return {
    reach,
    /** A row of the map's own item index, by its region id. */
    item: rid => open || cur.regionIds.indexOf(rid) !== -1,
    /** Photon hints: the scope's bbox (the coarse pre-filter) and country. */
    photon,
    /** A Photon feature: its countrycode is the precise gate, a bbox spills borders. */
    photonHit: f => !photon.countrycode
      || String((f && f.properties && f.properties.countrycode) || '').toLowerCase() === photon.countrycode,
    /** The coverage search sends the scope; with the reach on it sends none. */
    coverageScoped: !reach,
    /** The server's item search (/v1/search) is asked only with the reach on. */
    askServer: reach,
    /** A region or country row: inside the country on the map. */
    scopeHit: h => reach || (!!h.cc && ccs.indexOf(h.cc) !== -1),
  };
}
