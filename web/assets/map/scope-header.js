// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// Header/kicker/search-title paint for the active map scope (2026-07-23 flash
// fix). A tiny, standalone view-helper — deliberately NOT folded into
// scope.js (that module is a pure "what area am I looking at" model: it never
// touches `document` anywhere, by design — see its own top-of-file docstring)
// and NOT left inside map.js, because map.js's own execution is gated behind
// the /map/catalog.json fetch (catalog-load.js: "map.js EXECUTION is gated on
// the catalog fetch, its globals must exist") — a real network round trip, so
// even code at the very top of map.js's own script only runs once that fetch
// resolves. Browser-verified on the dev stack: that gap let the server-
// rendered "Wallonia, Belgium" fallback paint for ~130-220ms before a
// registry-based relabel inside map.js ever got a chance to run — an
// improvement over the old map.on('load') timing, but still a visible flash,
// not a fix.
//
// This file loads as a plain blocking <script src>
// (templates/map/index.html.twig), right after scope.js and BEFORE
// catalog-load.js, so paint() runs synchronously in the very same parse pass
// as the header markup above it (#regionLine / #regionCoords / #searchTitle)
// — there is no fetch, no defer, nothing for the browser to paint in between
// the server fallback text and the real one. map.js's own writeScopeHeader()
// (applyScope()'s per-scope-change repaint, once the map/catalog exist) calls
// this exact same window.CCScopeHeader.paint() — one implementation, two
// call sites (the early one below + map.js), not two copies of the logic.
(() => {
  'use strict';

  // `strings` is the subset of window.CC_I18N (MapController::mapI18n) this
  // needs: everywhereLabel, myAreaLine, myAreaLinePlain, searchIn,
  // searchEverywhere — every key optional, each with its own English
  // fallback, same contract map.js's other I18N reads already follow.
  function paint(strings) {
    if (!window.CCScope) return;
    const I18N = strings || {};
    const tpl = (s, vars) => String(s).replace(/\{(\w+)\}/g, (m, k) => (vars[k] != null ? vars[k] : m));
    // A plain blocking script, so the rider's unit comes off the window global
    // that cc-units.js sets (base.html.twig) rather than through an import.
    const km = (v) => (window.ccKm ? window.ccKm(v, 0) : Math.round(Number(v)) + ' km');
    const s = window.CCScope.get();
    // My area (map-and-search.md §4.5 Phase 4): the header + search
    // line name the base place + radius, NEVER coordinates — the deliberately
    // vague "Near X · N km" wording is the anti-border message in text form.
    const isMy = !!(s && s.kind === 'myArea' && s.myArea);
    // Everywhere owns its own string (I18N.everywhereLabel) rather than asking
    // the registry — CCScope.label() deliberately returns null for it
    // (scope.js). Region/country route through CCScope.label() (registry-based).
    const lbl = (s && s.kind === 'everywhere') ? (I18N.everywhereLabel || 'Everywhere') : (window.CCScope.label(s) || '');
    // Guard (07-20 review finding 7, preserved): region/country need a
    // resolvable label — an empty or stale registry must not wipe the
    // server-rendered fallback text with blanks. everywhere/myArea always have
    // one (their own owned string), independent of the registry.
    if (!isMy && !lbl) return;
    const myLine = isMy
      ? (s.myArea.place ? tpl(I18N.myAreaLine || 'Near {place} · {d}', { place: s.myArea.place, d: km(s.myArea.radiusKm) })
                         : tpl(I18N.myAreaLinePlain || 'My area · {d}', { d: km(s.myArea.radiusKm) }))
      : null;
    const rl = document.getElementById('regionLine'); if (rl) rl.textContent = myLine || lbl;
    // Brand kicker follows the scope (retires the hardcoded "Wallonia · 50.32°N"):
    // scope label + bbox-centre coords, or just the label for Everywhere. My
    // area shows its line WITHOUT the coordinate suffix (no precise point leak).
    const co = document.getElementById('regionCoords');
    if (co) {
      if (isMy) { co.textContent = `◎ ${myLine}`; }
      else { const b = window.CCScope.viewBbox(); co.textContent = b ? `◎ ${lbl} · ${((b[1] + b[3]) / 2).toFixed(2)}°N ${((b[0] + b[2]) / 2).toFixed(2)}°E` : `◎ ${lbl}`; }
    }
    const stt = document.getElementById('searchTitle');
    if (stt) stt.textContent = isMy ? myLine : ((s && s.kind === 'everywhere') ? (I18N.searchEverywhere || 'Search everywhere') : tpl(I18N.searchIn || 'Search in {area}', { area: lbl }));
  }
  window.CCScopeHeader = { paint };

  // Early bootstrap: resolve the active scope, then paint immediately — before
  // catalog-load.js's fetch even starts. Mirrors map.js's own _defaultScope
  // fallback (My area first, else the hardcoded 'wallonia' region, else
  // Everywhere; map-and-search.md §4.5 Phase 4 owner decision).
  // map.js recomputes the same fallback and re-calls CCScope.init() itself too
  // — harmless, since init() purely re-resolves the same URL/localStorage/
  // registry inputs — as a safety net for the (currently hypothetical) case
  // this script doesn't run.
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
