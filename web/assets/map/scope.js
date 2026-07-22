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
// collision, region-scoping-design.md §4). The `myArea` kind (Phase 4, §9.1)
// derives from the logged-in home base (window.CC_MY_AREA, Task 6) or an
// anonymous circle (localStorage 'cc-my-area'), never from the URL/'cc-scope'
// token — those only ever carry the bare literal 'myarea', no coordinates.
(() => {
  'use strict';

  const LS_KEY = 'cc-scope';
  const LS_AREA_KEY = 'cc-my-area';
  const URL_PARAM = 'scope';
  const EVENT = 'cc:scopechange';

  // Region registry injected by the map page from the DB (CCScope.init):
  //   { id:int, slug:str, countryCode:str, bbox:[west, south, east, north] }
  let regions = [];
  let byId = new Map();
  let bySlug = new Map();
  let byCountry = new Map();

  // IANA timezone -> ISO country, for the anonymous cold-start home hint
  // (2026-07-22-scope-selector-scale-design.md §D). Starts with the onboarded
  // countries' common zones; extend per onboarding. Compute-only — nothing is
  // ever stored (2026-07-22-scope-selector-scale-design.md §F rule 1).
  const TZ_COUNTRY = {
    'Europe/Brussels': 'BE',
    'Europe/Amsterdam': 'NL',
    'Europe/Berlin': 'DE', 'Europe/Busingen': 'DE',
  };
  const currentTimezone = () => {
    if (typeof globalThis !== 'undefined' && globalThis.__ccTz) return globalThis.__ccTz; // test hook
    try { return Intl.DateTimeFormat().resolvedOptions().timeZone || null; } catch (e) { return null; }
  };

  // {kind:'region'|'country'|'everywhere'|'myArea', regionIds:int[], countryCode:str|null}
  // — myArea additionally carries `myArea: {center:[lat,lng], radiusKm, place|null,
  // countryCodes:[], anon:bool}`; countryCode stays null (rid-only, never a cc arm).
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

  const clone = (s) => {
    const c = { kind: s.kind, regionIds: s.regionIds.slice(), countryCode: s.countryCode };
    if (s.myArea) {
      c.myArea = {
        center: s.myArea.center.slice(),
        radiusKm: s.myArea.radiusKm,
        place: s.myArea.place,
        countryCodes: s.myArea.countryCodes.slice(),
        anon: s.myArea.anon,
      };
    }
    return c;
  };

  const regionScope = (r) => ({ kind: 'region', regionIds: [r.id], countryCode: r.countryCode || null });
  const countryScope = (cc) => ({ kind: 'country', regionIds: (byCountry.get(cc) || []).map((r) => r.id), countryCode: cc });
  const everywhereScope = () => ({ kind: 'everywhere', regionIds: [], countryCode: null });

  // --- myArea (Phase 4, region-scoping-design.md §9.1): "what's near my home
  // base" for logged-in users (window.CC_MY_AREA, Task 6) or an anonymous
  // circle (localStorage). The circle itself never leaves this module — the
  // URL/'cc-scope' token is the bare literal 'myarea', and the derived scope
  // (rid set only) is what every other API surface sees. ----------------------

  // [west, south, east, north] bbox around a lat/lng circle of radius rkm.
  const circleBbox = (center, rkm) => {
    const dLat = rkm / 111.32;
    // Pole safety: cos(lat) -> 0 near +/-90 would blow dLng up to Infinity/NaN.
    const cosLat = Math.max(0.01, Math.cos(center[0] * Math.PI / 180));
    const dLng = rkm / (111.32 * cosLat);
    return [center[1] - dLng, center[0] - dLat, center[1] + dLng, center[0] + dLat];
  };

  // The source of truth for myArea: the server-injected payload wins; an
  // anonymous circle (set via setAnonCircle) is the fallback for logged-out
  // users. Returns null when neither is present.
  const myAreaSource = () => {
    const a = (typeof window !== 'undefined' && window.CC_MY_AREA) || null;
    if (a && a.lat != null && a.lng != null) {
      return {
        center: [a.lat, a.lng], radiusKm: a.radiusKm || 40, place: a.place || null,
        regionIds: (a.regionIds || []).slice(), countryCodes: (a.countryCodes || []).slice(), anon: false,
      };
    }
    try {
      const raw = localStorage.getItem(LS_AREA_KEY);
      if (raw) {
        const c = JSON.parse(raw);
        if (typeof c.lat === 'number' && typeof c.lng === 'number') {
          return { center: [c.lat, c.lng], radiusKm: c.radiusKm || 40, place: null, regionIds: null, countryCodes: null, anon: true };
        }
      }
    } catch (e) { /* ignore */ }
    return null;
  };

  // Anonymous circles carry no server-derived region ids: approximate with a
  // registry-bbox intersection, nearest-centre-first, capped at 8. Over-inclusive
  // is fine — the filter widens, never narrows wrongly.
  const deriveFromBboxes = (center, rkm) => {
    const cb = circleBbox(center, rkm);
    const hits = [];
    regions.forEach((r) => {
      const b = r.bbox;
      if (b && b[0] <= cb[2] && cb[0] <= b[2] && b[1] <= cb[3] && cb[1] <= b[3]) {
        const cx = (b[0] + b[2]) / 2; const cy = (b[1] + b[3]) / 2;
        hits.push({ id: r.id, cc: r.countryCode, d: (cx - center[1]) ** 2 + (cy - center[0]) ** 2 });
      }
    });
    hits.sort((p, q) => p.d - q.d);
    return hits.slice(0, 8);
  };

  // Rebuild the myArea scope fresh from the source of truth (never from a
  // passed-in object) — this is what makes sanitize()/set() drop stale ids.
  // Returns null when there is no source (caller falls through to fallback).
  const myAreaScope = () => {
    const src = myAreaSource();
    if (!src) return null;
    let ids; let ccs;
    if (src.anon) {
      const hits = deriveFromBboxes(src.center, src.radiusKm);
      ids = hits.map((h) => h.id);
      ccs = [];
      hits.forEach((h) => { if (h.cc && ccs.indexOf(h.cc) === -1) ccs.push(h.cc); });
    } else {
      ids = src.regionIds.filter((id) => byId.has(id)); // drop stale ids
      ccs = src.countryCodes;
    }
    return {
      kind: 'myArea', regionIds: ids, countryCode: null,
      myArea: { center: src.center, radiusKm: src.radiusKm, place: src.place, countryCodes: ccs, anon: src.anon },
    };
  };

  // The single registry-known country among myArea's derived countryCodes, or
  // null when zero or more than one qualify (widen()/nextWider()'s "exactly
  // one" rule — an ambiguous myArea widens straight to Everywhere).
  const singleMyAreaCountry = () => {
    if (!scope || scope.kind !== 'myArea' || !scope.myArea) return null;
    const present = scope.myArea.countryCodes.filter((cc) => byCountry.has(cc));
    return present.length === 1 ? present[0] : null;
  };

  // --- serialization for URL/storage: slug-based so links survive re-imports ---
  const serialize = (s) => {
    if (!s || s.kind === 'everywhere') return 'everywhere';
    // Bare literal — NEVER coordinates in the URL or the 'cc-scope' LS entry.
    if (s.kind === 'myArea') return 'myarea';
    if (s.kind === 'country') return `country:${s.countryCode}`;
    const slugs = s.regionIds.map((id) => { const r = byId.get(id); return r && r.slug ? r.slug : String(id); });
    return `region:${slugs.join(',')}`;
  };

  const deserialize = (str) => {
    if (!str) return null;
    if (str === 'everywhere') return everywhereScope();
    // May be null (no CC_MY_AREA payload and no anon circle) — caller falls
    // through to localStorage/default, same as any other unresolvable token.
    if (str === 'myarea') return myAreaScope();
    const i = str.indexOf(':');
    if (i < 0) return null;
    const kind = str.slice(0, i);
    const rest = str.slice(i + 1);
    if (kind === 'country') return byCountry.has(rest) ? countryScope(rest) : null;
    if (kind === 'region') {
      // A multi-region token collapses to its FIRST resolvable region (07-20
      // review finding 10): every label/spotlight/best-of surface renders a
      // single named region today, so honouring the extra ids would filter on
      // regions the UI cannot show. Phase 4's derived sets (myArea) build
      // multi-region scopes internally, not from URL tokens — revisit then.
      for (const tok of rest.split(',')) {
        const r = bySlug.get(tok) || byId.get(Number(tok));
        if (r) return regionScope(r);
      }
      return null;
    }
    return null;
  };

  // Drop ids that no longer exist in the registry (deleted/re-imported regions);
  // returns null when nothing valid remains so the caller can fall back.
  const sanitize = (s) => {
    if (!s || !s.kind) return null;
    // Source of truth is the payload/circle, never the passed object — this
    // also handles stale ids (myAreaScope drops them against the registry).
    if (s.kind === 'myArea') return myAreaScope();
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
      // Phase 4 (region-scoping-design.md §9.1, owner decision): My area wins
      // whenever a base location is set — EXCEPT an explicit URL scope, which
      // always wins (a shared deep link must reproduce what was shared, not
      // silently swap in "my area").
      if (!fromUrl && fallback.kind === 'myArea') {
        scope = clone(fallback);
      } else {
        scope = fromUrl || fromLs || clone(fallback);
      }
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

    /** One rung wider: region -> its country -> everywhere; myArea -> its single
     *  registry-known country (if exactly one) -> everywhere. */
    widen() {
      if (!scope || scope.kind === 'everywhere') return this.get();
      if (scope.kind === 'country') return this.setEverywhere();
      if (scope.kind === 'myArea') {
        const cc = singleMyAreaCountry();
        return cc ? this.setCountry(cc) : this.setEverywhere();
      }
      return (scope.countryCode && byCountry.has(scope.countryCode)) ? this.setCountry(scope.countryCode) : this.setEverywhere();
    },
    canWiden() { return !!scope && scope.kind !== 'everywhere'; },

    /** The next-wider scope WITHOUT applying it (for labelling the widen chip). */
    nextWider() {
      if (!scope || scope.kind === 'everywhere') return null;
      if (scope.kind === 'country') return everywhereScope();
      if (scope.kind === 'myArea') {
        const cc = singleMyAreaCountry();
        return cc ? countryScope(cc) : everywhereScope();
      }
      return (scope.countryCode && byCountry.has(scope.countryCode)) ? countryScope(scope.countryCode) : everywhereScope();
    },

    /** Region registry objects currently in scope (for spotlight + labels). */
    regions() { return (scope ? scope.regionIds : []).map((id) => byId.get(id)).filter(Boolean); },

    /** Union bbox [west, south, east, north] of the scope's regions; the circle
     *  bbox for myArea; null for everywhere. */
    bbox() {
      if (scope && scope.kind === 'myArea' && scope.myArea) return circleBbox(scope.myArea.center, scope.myArea.radiusKm);
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

    /** Single region id for best-of &region= (only a single named region qualifies).
     *  Kept for compatibility — bestOfRegionIds() supersedes it for callers that
     *  can send a set (Phase 4). */
    bestOfRegionParam() {
      return (scope && scope.kind === 'region' && scope.regionIds.length === 1) ? scope.regionIds[0] : null;
    },

    /** Region id set for best-of: [id] for a single named region, the myArea
     *  derived set (sorted ascending to match the server-side sort), else null. */
    bestOfRegionIds() {
      if (!scope) return null;
      if (scope.kind === 'region' && scope.regionIds.length === 1) return scope.regionIds.slice();
      if (scope.kind === 'myArea' && scope.regionIds.length) return scope.regionIds.slice().sort((a, b) => a - b);
      return null;
    },

    /** Whether a myArea source (CC_MY_AREA payload or anon circle) is available. */
    myAreaAvailable() { return myAreaScope() !== null; },

    /** The onboarded home country code, from (in order) the My-area payload,
     *  the anon circle, then the client timezone; null when none is onboarded.
     *  Compute-only — writes nothing (2026-07-22-scope-selector-scale-design.md
     *  §D / §F rule 1: no localStorage, no cookie, no network call, no mutation
     *  of module state). */
    inferHomeCountry() {
      const onboarded = (cc) => (cc && byCountry.has(cc) ? cc : null);
      const src = myAreaSource();
      if (src) {
        if (src.anon) {
          for (const h of deriveFromBboxes(src.center, src.radiusKm)) {
            const cc = onboarded(h.cc);
            if (cc) return cc;
          }
        } else {
          for (const cc of src.countryCodes) {
            const hit = onboarded(cc);
            if (hit) return hit;
          }
        }
      }
      return onboarded(TZ_COUNTRY[currentTimezone()]);
    },

    /** Resolve + apply the myArea scope from its source of truth; no-op if unavailable. */
    setMyArea() {
      const s = myAreaScope();
      return s ? this.set(s) : this.get();
    },

    /** Store an anonymous circle (rounded to 2 decimals — same coarseness as
     *  the server) for logged-out users, then apply it as the myArea scope.
     *  radiusKm is clamped to [10,150] and rounded — the server (User::
     *  BASE_RADIUS_MIN/MAX, default 40) applies the same clamp, so this is
     *  the client-side mirror the docblock already claimed. */
    setAnonCircle(lat, lng, radiusKm) {
      try {
        const rkm = Math.round(Math.max(10, Math.min(150, radiusKm)));
        localStorage.setItem(LS_AREA_KEY, JSON.stringify({
          lat: Math.round(lat * 100) / 100,
          lng: Math.round(lng * 100) / 100,
          radiusKm: rkm,
        }));
      } catch (e) { /* private mode */ }
      return this.setMyArea();
    },

    /** Forget the anonymous circle (does not touch an in-progress CC_MY_AREA scope). */
    clearAnonCircle() {
      try { localStorage.removeItem(LS_AREA_KEY); } catch (e) { /* private mode */ }
    },

    /** Photon geocode hints derived from scope (Task 8): scoped bbox + country gate. */
    photonParams() {
      if (!scope || scope.kind === 'everywhere') return { bbox: null, countrycode: null };
      return { bbox: this.bbox(), countrycode: scope.countryCode ? scope.countryCode.toLowerCase() : null };
    },

    /** Coverage endpoint params {rids, cc} for the active scope (Phase 3,
     *  region-scoping-design.md §6). A region sends its ids only; a country
     *  sends its ids AND cc (the server ORs them, so an unsplit country row
     *  region_id NULL still matches on cc); Everywhere sends neither. rids are
     *  sorted so the shared HTTP-cache key is order-independent (§8 risk 10).
     *  A myArea scope whose derived region set is empty is NOT "no scope" —
     *  it returns rids: [] (an empty array, distinct from Everywhere's null)
     *  as an explicit "in scope: nothing" sentinel, so callers never fall
     *  through to an unscoped/GLOBAL request for a rider whose area matched
     *  no region (map-and-search.md §4.5's leak-safe-hide rule, extended to
     *  this client-side seam). */
    coverageParams() {
      if (!scope || scope.kind === 'everywhere') return { rids: null, cc: null };
      const rids = scope.regionIds.slice().sort((a, b) => a - b);
      if (scope.kind === 'myArea' && !rids.length) return { rids: [], cc: null };
      return { rids: rids.length ? rids : null, cc: scope.kind === 'country' ? scope.countryCode : null };
    },

    /** MapLibre filter expression for the coverage TILE layers (Phase 3,
     *  region-scoping-design.md §6/§7), or null for Everywhere (no filter). The
     *  scope keys are pipe-delimited membership TOKENS: ridtok = "|<region_id>|"
     *  (empty when unstamped), cctok = "|<cc>|", UNIONed across a cluster's
     *  members by tippecanoe (--accumulate-attribute=concat). Testing
     *  `'|id|' in ridtok` answers "does ANY member fall in this region?" for a
     *  bubble and works identically on an individual icon, so ONE filter serves
     *  both sublayers. Prop-less (both tokens empty) RENDERS — the artifact lags
     *  the DB by up to a weekly rebuild, so hiding-all would blank the map (§8
     *  risk 2); a cc-bearing rid-less row (cctok non-empty) is NOT prop-less, so
     *  it hides under a region scope (matching /counts) and shows under its
     *  country scope. coalesce keeps the test safe against a stale pre-token tile. */
    coverageTileFilter() {
      if (!scope || scope.kind === 'everywhere') return null;
      const ridtok = ['coalesce', ['get', 'ridtok'], ''];
      const cctok = ['coalesce', ['get', 'cctok'], ''];
      const arms = [['all', ['==', ridtok, ''], ['==', cctok, '']]];
      scope.regionIds.forEach((id) => arms.push(['in', '|' + id + '|', ridtok]));
      if (scope.kind === 'country' && scope.countryCode) arms.push(['in', '|' + scope.countryCode + '|', cctok]);
      return ['any'].concat(arms);
    },
  };

  if (typeof window !== 'undefined') window.CCScope = API;
  // Node smoke tests set globalThis.window; also expose via module.exports when present.
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
