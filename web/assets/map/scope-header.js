// SPDX-License-Identifier: AGPL-3.0-only
//
// Header/kicker/search-title for the active map scope
// (docs/specs/map-and-search.md §4.5). Loads as a blocking <script> after
// scope.js and before catalog-load.js, so paint() runs before first paint.
(() => {
  'use strict';

  // `strings` is the subset of window.CC_I18N this needs; every key optional
  // with an English fallback.
  // `opts.worldwide`: the search reach is on (search-ui.js), so the search
  // title says "Search everywhere" while the scope heading stays the scope's.
  function paint(strings, opts) {
    if (!window.CCScope) return;
    const I18N = strings || {};
    const worldwide = !!(opts && opts.worldwide);
    const tpl = (s, vars) => String(s).replace(/\{(\w+)\}/g, (m, k) => (vars[k] != null ? vars[k] : m));
    const km = (v) => (window.ccKm ? window.ccKm(v, 0) : Math.round(Number(v)) + ' km');
    const s = window.CCScope.get();
    // My area (docs/specs/map-and-search.md §4.5): header names place + radius,
    // never coordinates.
    const isMy = !!(s && s.kind === 'myArea' && s.myArea);
    // Everywhere owns I18N.everywhereLabel — CCScope.label() returns null for it.
    const lbl = (s && s.kind === 'everywhere') ? (I18N.everywhereLabel || 'Everywhere') : (window.CCScope.label(s) || '');
    // Region/country need a resolvable label; don't wipe the server fallback with blanks.
    if (!isMy && !lbl) return;
    const myLine = isMy
      ? (s.myArea.place ? tpl(I18N.myAreaLine || 'Near {place} · {d}', { place: s.myArea.place, d: km(s.myArea.radiusKm) })
                         : tpl(I18N.myAreaLinePlain || 'My area · {d}', { d: km(s.myArea.radiusKm) }))
      : null;
    const rl = document.getElementById('regionLine'); if (rl) rl.textContent = myLine || lbl;
    // Brand kicker: scope label + bbox-centre coords, or just the label for
    // Everywhere. My area shows its line without a coordinate suffix.
    const co = document.getElementById('regionCoords');
    if (co) {
      if (isMy) { co.textContent = `◎ ${myLine}`; }
      else { const b = window.CCScope.viewBbox(); co.textContent = b ? `◎ ${lbl} · ${((b[1] + b[3]) / 2).toFixed(2)}°N ${((b[0] + b[2]) / 2).toFixed(2)}°E` : `◎ ${lbl}`; }
    }
    const stt = document.getElementById('searchTitle');
    if (stt) stt.textContent = (worldwide || (s && s.kind === 'everywhere')) ? (I18N.searchEverywhere || 'Search everywhere')
      : (isMy ? myLine : tpl(I18N.searchIn || 'Search in {area}', { area: lbl }));
  }
  window.CCScopeHeader = { paint };

  // Resolve + paint before catalog-load.js's fetch starts. Same fallback as
  // map.js: My area first, else the 'wallonia' region, else Everywhere
  // (docs/specs/map-and-search.md §4.5).
  if (window.CCScope) {
    const regions = window.CC_REGIONS || [];
    const defaultScope = window.CCScope.myAreaAvailable()
      ? { kind: 'myArea', regionIds: [], countryCode: null }
      : (() => {
          const w = regions.find((r) => r.slug === 'wallonia') || regions[0];
          return w ? { kind: 'region', regionIds: [w.id], countryCode: w.countryCode } : { kind: 'everywhere', regionIds: [], countryCode: null };
        })();
    window.CCScope.init(regions, defaultScope);
    paint(window.CC_I18N || {});
  }
})();
