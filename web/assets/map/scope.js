// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// Map scope model (docs/specs/2026-07-19-region-scoping-design.md §4 / §7
// Phase 2): the single source of truth for "what area am I looking at" —
// a named region, a whole country, or everywhere. Persisted in localStorage
// + the URL (?scope=), and broadcast as a `cc:scopechange` window event that
// map.js listens for to refilter, respotlight, and relabel.
//
// This is a focused module extracted from map.js (a 3000-line classic script);
// map.js consumes window.CCScope. The scope uses `kind`, NOT `mode` — map.js
// already owns `mode` for the Curated/Everything view toggle (verified name
// collision, region-scoping-design.md §4). A future `myArea` kind (Phase 4)
// slots in without touching this contract.
(() => {
  'use strict';

  const LS_KEY = 'cc-scope';
  const URL_PARAM = 'scope';
  const EVENT = 'cc:scopechange';

  // Region registry injected by the map page from the DB (CCScope.init):
  //   { id:int, slug:str, countryCode:str, bbox:[west, south, east, north] }
  let regions = [];
  let byId = new Map();
  let bySlug = new Map();
  let byCountry = new Map();

  // {kind:'region'|'country'|'everywhere', regionIds:int[], countryCode:str|null}
  let scope = null;
  let fallback = { kind: 'everywhere', regionIds: [], countryCode: null };

  const reindex = (list) => {
    regions = Array.isArray(list) ? list.slice() : [];
    byId = new Map();
    bySlug = new Map();
    byCountry = new Map();
    for (const r of regions) {
      byId.set(r.id, r);
      if (r.slug) bySlug.set(r.slug, r);
      if (r.countryCode) {
        if (!byCountry.has(r.countryCode)) byCountry.set(r.countryCode, []);
        byCountry.get(r.countryCode).push(r);
      }
    }
  };

  const clone = (s) => ({ kind: s.kind, regionIds: s.regionIds.slice(), countryCode: s.countryCode });

  const regionScope = (r) => ({ kind: 'region', regionIds: [r.id], countryCode: r.countryCode || null });
  const countryScope = (cc) => ({ kind: 'country', regionIds: (byCountry.get(cc) || []).map((r) => r.id), countryCode: cc });
  const everywhereScope = () => ({ kind: 'everywhere', regionIds: [], countryCode: null });

  // --- serialization for URL/storage: slug-based so links survive re-imports ---
  const serialize = (s) => {
    if (!s || s.kind === 'everywhere') return 'everywhere';
    if (s.kind === 'country') return `country:${s.countryCode}`;
    const slugs = s.regionIds.map((id) => { const r = byId.get(id); return r && r.slug ? r.slug : String(id); });
    return `region:${slugs.join(',')}`;
  };

  const deserialize = (str) => {
    if (!str) return null;
    if (str === 'everywhere') return everywhereScope();
    const i = str.indexOf(':');
    if (i < 0) return null;
    const kind = str.slice(0, i);
    const rest = str.slice(i + 1);
    if (kind === 'country') return byCountry.has(rest) ? countryScope(rest) : null;
    if (kind === 'region') {
      const ids = [];
      let cc = null;
      for (const tok of rest.split(',')) {
        const r = bySlug.get(tok) || byId.get(Number(tok));
        if (r) { ids.push(r.id); cc = r.countryCode || cc; }
      }
      return ids.length ? { kind: 'region', regionIds: ids, countryCode: cc } : null;
    }
    return null;
  };

  // Drop ids that no longer exist in the registry (deleted/re-imported regions);
  // returns null when nothing valid remains so the caller can fall back.
  const sanitize = (s) => {
    if (!s || !s.kind) return null;
    if (s.kind === 'everywhere') return everywhereScope();
    const ids = (s.regionIds || []).filter((id) => byId.has(id));
    if (!ids.length) return null;
    const cc = s.countryCode || byId.get(ids[0]).countryCode || null;
    return { kind: s.kind === 'country' ? 'country' : 'region', regionIds: ids, countryCode: cc };
  };

  const persist = (s) => {
    try { localStorage.setItem(LS_KEY, serialize(s)); } catch (e) { /* private mode */ }
    try {
      const url = new URL(location.href);
      url.searchParams.set(URL_PARAM, serialize(s));
      history.replaceState(null, '', url);
    } catch (e) { /* non-browser / sandboxed */ }
  };

  const emit = () => {
    try { window.dispatchEvent(new CustomEvent(EVENT, { detail: clone(scope) })); } catch (e) { /* no window */ }
  };

  const API = {
    EVENT,

    /** Load the region registry + optional default scope, then resolve the
     *  active scope from (URL param > localStorage > default > everywhere). */
    init(regionList, defaultScope) {
      reindex(regionList);
      if (defaultScope) { const d = sanitize(defaultScope); if (d) fallback = d; }
      let fromUrl = null;
      try { fromUrl = deserialize(new URLSearchParams(location.search).get(URL_PARAM)); } catch (e) { /* noop */ }
      let fromLs = null;
      try { fromLs = deserialize(localStorage.getItem(LS_KEY)); } catch (e) { /* noop */ }
      scope = fromUrl || fromLs || clone(fallback);
      return this.get();
    },

    /** The active scope (a copy — mutating it never changes internal state). */
    get() { return clone(scope || fallback); },

    /** Replace the scope; persists + emits unless {persist:false}. */
    set(next, opts) {
      const clean = sanitize(next);
      if (!clean) return this.get();
      scope = clean;
      if (!opts || opts.persist !== false) persist(scope);
      emit();
      return this.get();
    },

    setRegion(slugOrId) {
      const r = bySlug.get(slugOrId) || byId.get(slugOrId);
      return r ? this.set(regionScope(r)) : this.get();
    },
    setCountry(cc) { return byCountry.has(cc) ? this.set(countryScope(cc)) : this.get(); },
    setEverywhere() { return this.set(everywhereScope()); },

    /** One rung wider: region -> its country -> everywhere. */
    widen() {
      if (!scope || scope.kind === 'everywhere') return this.get();
      if (scope.kind === 'country') return this.setEverywhere();
      return (scope.countryCode && byCountry.has(scope.countryCode)) ? this.setCountry(scope.countryCode) : this.setEverywhere();
    },
    canWiden() { return !!scope && scope.kind !== 'everywhere'; },

    /** The next-wider scope WITHOUT applying it (for labelling the widen chip). */
    nextWider() {
      if (!scope || scope.kind === 'everywhere') return null;
      if (scope.kind === 'country') return everywhereScope();
      return (scope.countryCode && byCountry.has(scope.countryCode)) ? countryScope(scope.countryCode) : everywhereScope();
    },

    /** Region registry objects currently in scope (for spotlight + labels). */
    regions() { return (scope ? scope.regionIds : []).map((id) => byId.get(id)).filter(Boolean); },

    /** Union bbox [west, south, east, north] of the scope's regions; null for everywhere. */
    bbox() {
      const rs = this.regions();
      if (!rs.length) return null;
      let w = Infinity; let s = Infinity; let e = -Infinity; let n = -Infinity;
      for (const r of rs) {
        if (!r.bbox) continue;
        w = Math.min(w, r.bbox[0]); s = Math.min(s, r.bbox[1]);
        e = Math.max(e, r.bbox[2]); n = Math.max(n, r.bbox[3]);
      }
      return Number.isFinite(w) ? [w, s, e, n] : null;
    },

    /** Single region id for best-of &region= (only a single named region qualifies). */
    bestOfRegionParam() {
      return (scope && scope.kind === 'region' && scope.regionIds.length === 1) ? scope.regionIds[0] : null;
    },

    /** Photon geocode hints derived from scope (Task 8): scoped bbox + country gate. */
    photonParams() {
      if (!scope || scope.kind === 'everywhere') return { bbox: null, countrycode: null };
      return { bbox: this.bbox(), countrycode: scope.countryCode ? scope.countryCode.toLowerCase() : null };
    },
  };

  if (typeof window !== 'undefined') window.CCScope = API;
  // Node smoke tests set globalThis.window; also expose via module.exports when present.
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
