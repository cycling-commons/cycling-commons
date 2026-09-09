// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// Map scope (docs/specs/map-and-search.md §4.5): region, country, or everywhere.
// Persisted in localStorage + ?scope=; broadcasts `cc:scopechange`.
// `kind` not `mode` (map.js owns view mode). URL/storage store 'myarea', never coordinates.
(() => {
  'use strict';

  const LS_KEY = 'cc-scope';
  const LS_AREA_KEY = 'cc-my-area';
  const URL_PARAM = 'scope';
  const EVENT = 'cc:scopechange';

  // Region registry from the map page (CCScope.init):
  //   { id, slug, countryCode, bbox:[w,s,e,n], adj:int[] }
  // adj = border-neighbour ids (docs/specs/map-and-search.md §4.5)
  let regions = [];
  let byId = new Map();
  let bySlug = new Map();
  let byCountry = new Map();

  // Session cache of fetched region boundary polygons (slug -> Polygon[] rings
  // list), so a second ambiguous click in the same overlap zone fetches nothing.
  const boundaryCache = new Map();

  // IANA timezone → ISO country, anonymous cold-start hint
  // (docs/specs/map-and-search.md §4.5). Compute-only — nothing is stored.
  const TZ_COUNTRY = {
    'Europe/Brussels': 'BE',
    'Europe/Amsterdam': 'NL',
    'Europe/Berlin': 'DE', 'Europe/Busingen': 'DE',
    'Europe/Luxembourg': 'LU',
    'Europe/Paris': 'FR',
    'Europe/Zurich': 'CH',
    'Europe/London': 'GB', 'Europe/Belfast': 'GB',
    'Europe/Rome': 'IT',
      // Spain: mainland + Balearics (Europe/Madrid), Canaries, Ceuta/Melilla.
    'Europe/Madrid': 'ES', 'Atlantic/Canary': 'ES', 'Africa/Ceuta': 'ES',
    'Asia/Tokyo': 'JP',
    'Australia/Sydney': 'AU', 'Australia/Melbourne': 'AU',
    'Australia/Brisbane': 'AU', 'Australia/Perth': 'AU',
    'Australia/Adelaide': 'AU', 'Australia/Hobart': 'AU',
    'Australia/Darwin': 'AU', 'Australia/Canberra': 'AU',
    'Australia/Broken_Hill': 'AU', 'Australia/Lindeman': 'AU',
    'Australia/Lord_Howe': 'AU', 'Australia/Eucla': 'AU',
    // Only onboarded US states; America/New_York is a country we have nothing for.
    'America/Los_Angeles': 'US', 'America/Denver': 'US',
    'Europe/Ljubljana': 'SI',
    'Africa/Kigali': 'RW',
    'Africa/Johannesburg': 'ZA',
    'America/Bogota': 'CO',
    // Chile: mainland America/Santiago; Easter Island Pacific/Easter.
    'America/Santiago': 'CL', 'Pacific/Easter': 'CL',
    // NZ: Chatham Islands are their own region and zone.
    'Pacific/Auckland': 'NZ', 'Pacific/Chatham': 'NZ',
    // Only onboarded CA provinces; America/Montreal is a link some browsers still resolve.
    'America/Vancouver': 'CA', 'America/Toronto': 'CA', 'America/Montreal': 'CA',
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

  // True only when init() fell through to the default (no URL ?scope=, no
  // stored 'cc-scope') and no setter has run. renderScopeChips() uses this
  // to substitute the inferred home country for chip choice; the active
  // scope itself is never touched.
  let isDefaultScope = false;

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
  // False when the region scope already holds every region its country has
  // (single-region country: Luxembourg) — the country rung adds no area.
  const countryRungWidens = (s) => {
    if (!s || !s.countryCode || !byCountry.has(s.countryCode)) return false;
    const held = s.regionIds || [];
    return (byCountry.get(s.countryCode) || []).some((r) => held.indexOf(r.id) < 0);
  };
  const countryScope = (cc) => ({ kind: 'country', regionIds: (byCountry.get(cc) || []).map((r) => r.id), countryCode: cc });
  const everywhereScope = () => ({ kind: 'everywhere', regionIds: [], countryCode: null });

  // myArea (docs/specs/map-and-search.md §4.5): logged-in home base or an
  // anonymous circle. The circle never leaves this module — URL/'cc-scope'
  // is the bare literal 'myarea'.

  // [west, south, east, north] bbox around a lat/lng circle of radius rkm.
  /* ---- boxes that may cross the antimeridian --------------------------------
     A region box whose WEST value is greater than its EAST crosses ±180 and is
     read the long way round. That is the GeoJSON convention (RFC 7946 §5.2) and
     RegionRegistryProvider emits it; two countries we carry need it, the United
     States through the Aleutians and New Zealand through the Chathams.

     Every longitude question goes through these. Reading a box as
     `b[0] <= lng && lng <= b[2]` was the bug: for those two it is false almost
     everywhere the region actually is, and PostGIS's -180..180 answer made it
     true everywhere else, so the United States contained the whole planet and
     won every "nearest region" contest on Earth. Latitude never wraps, so it is
     left alone. */
  const bboxWraps = (b) => !!b && b[0] > b[2];
  /** The box's longitude span in degrees, the short way round. */
  const bboxWidth = (b) => (bboxWraps(b) ? (b[2] + 360) - b[0] : b[2] - b[0]);
  /** Centre longitude, normalised back into -180..180. */
  const bboxCentreLng = (b) => {
    const cx = b[0] + bboxWidth(b) / 2;
    return cx > 180 ? cx - 360 : cx;
  };
  const bboxHasLng = (b, lng) => (bboxWraps(b) ? (lng >= b[0] || lng <= b[2]) : (lng >= b[0] && lng <= b[2]));
  const bboxHasPoint = (b, lng, lat) => !!b && lat >= b[1] && lat <= b[3] && bboxHasLng(b, lng);
  /** Do two boxes overlap? A wrapping box is two spans, so it is tested as two. */
  const bboxesOverlap = (a, b) => {
    if (!a || !b) return false;
    if (a[3] < b[1] || b[3] < a[1]) return false;              // latitude first: no wrap there
    const spans = (x) => (bboxWraps(x) ? [[x[0], 180], [-180, x[2]]] : [[x[0], x[2]]]);
    for (const p of spans(a)) for (const q of spans(b)) {
      if (p[0] <= q[1] && q[0] <= p[1]) return true;
    }
    return false;
  };
  /** Union of two boxes, keeping a crossing crossing rather than exploding it. */
  const bboxUnion = (a, b) => {
    if (!a) return b ? b.slice() : null;
    if (!b) return a.slice();
    const lat = [Math.min(a[1], b[1]), Math.max(a[3], b[3])];
    if (!bboxWraps(a) && !bboxWraps(b)) {
      const plain = [Math.min(a[0], b[0]), lat[0], Math.max(a[2], b[2]), lat[1]];
      /* Two ordinary boxes far apart either side of the seam union to a box
         spanning the world, and going round the back is shorter. The guard is
         the whole of it: this alternative only exists when the two really do
         sit apart, `west > east`. Without it, two neighbouring regions produced
         the gap BETWEEN them (Belgium's own scope came out 0.24 degrees wide
         instead of 3.87), because the candidate was then their intersection
         wearing a union's name. */
      const gw = Math.max(a[0], b[0]); const ge = Math.min(a[2], b[2]);
      if (gw > ge) {
        const wrapped = [gw, lat[0], ge, lat[1]];
        if (bboxWidth(wrapped) < bboxWidth(plain)) return wrapped;
      }
      return plain;
    }
    // With a crossing box involved, widen it in whichever direction costs least.
    const w = bboxHasLng(a, b[0]) ? a[0] : b[0];
    const e = bboxHasLng(a, b[2]) ? a[2] : b[2];
    return [w, lat[0], e, lat[1]];
  };

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
      if (bboxesOverlap(b, cb)) {
        const cx = bboxCentreLng(b); const cy = (b[1] + b[3]) / 2;
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
    // 'everywhere' is no longer a browsing scope (owner, 2026-09-06: drawing
    // every item on Earth makes the browser sluggish; Everywhere is a search
    // reach, not a place to look at). An old link or stored token falls
    // through to the default like any other token that no longer resolves.
    if (str === 'everywhere') return null;
    // May be null (no CC_MY_AREA payload and no anon circle) — caller falls
    // through to localStorage/default, same as any other unresolvable token.
    if (str === 'myarea') return myAreaScope();
    const i = str.indexOf(':');
    if (i < 0) return null;
    const kind = str.slice(0, i);
    const rest = str.slice(i + 1);
    if (kind === 'country') return byCountry.has(rest) ? countryScope(rest) : null;
    if (kind === 'region') {
      // A multi-region token collapses to its first resolvable region — UI
      // surfaces a single named region. myArea builds multi-region internally.
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

  // Ground distance: nearest point ON the region's outline (docs/specs/map-and-search.md §4.5).
  // Adjacency still gates eligibility; edge distance only orders that pool.

  // Squared distance from the origin to segment (ax,ay)→(bx,by), all already
  // translated so the anchor is at 0,0 and longitudes scaled by cos(lat).
  const segDist2 = (ax, ay, bx, by) => {
    const dx = bx - ax; const dy = by - ay;
    const l2 = dx * dx + dy * dy;
    let t = l2 > 0 ? -(ax * dx + ay * dy) / l2 : 0;
    if (t < 0) t = 0; else if (t > 1) t = 1;
    const px = ax + t * dx; const py = ay + t * dy;
    return px * px + py * py;
  };

  // Ray-casting on a flat [lng,lat,…] ring. Exterior only; enclave-as-inside is fine for ranking.
  const pointInFlatRing = (x, y, f) => {
    let inside = false;
    const n = f.length / 2;
    for (let i = 0, j = n - 1; i < n; j = i++) {
      const xi = f[i * 2]; const yi = f[i * 2 + 1];
      const xj = f[j * 2]; const yj = f[j * 2 + 1];
      if (((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi)) inside = !inside;
    }
    return inside;
  };

  // Squared corrected-degree distance from [lng,lat] to region `r`.
  function groundDistance2(r, lng, lat, kx) {
    const rings = r && r.outline;
    const usable = Array.isArray(rings) && rings.some((f) => Array.isArray(f) && f.length >= 6);
    if (!usable) {
      // No outline (a region imported before the column existed, or a test
      // fixture): fall back to the bbox centre, in the same units.
      const b = r && r.bbox;
      const cx = b ? bboxCentreLng(b) : lng; const cy = b ? (b[1] + b[3]) / 2 : lat;
      return ((cx - lng) * kx) ** 2 + (cy - lat) ** 2;
    }
    let best = Infinity;
    for (let k = 0; k < rings.length; k++) {
      const f = rings[k];
      if (!Array.isArray(f) || f.length < 6) continue;   // < 3 points is not a ring
      if (pointInFlatRing(lng, lat, f)) return 0;
      const n = f.length / 2;
      let ax = (f[(n - 1) * 2] - lng) * kx; let ay = f[(n - 1) * 2 + 1] - lat;
      for (let i = 0; i < n; i++) {
        const bx = (f[i * 2] - lng) * kx; const by = f[i * 2 + 1] - lat;
        const d = segDist2(ax, ay, bx, by);
        if (d < best) best = d;
        ax = bx; ay = by;
      }
    }
    return best;
  }

  // Nearest-first by ground distance, capped. cos(lat) correction shared with regionsNear.
  function rankByGroundDistance(list, near, cap) {
    const lng = near[0]; const lat = near[1];
    const kx = Math.cos(lat * Math.PI / 180);
    return list
      .map((r) => ({ r, d: groundDistance2(r, lng, lat, kx) }))
      .sort((a, b) => a.d - b.d)
      .slice(0, cap)
      .map((x) => x.r);
  }

  // Ray-casting point-in-polygon over a GeoJSON Polygon ring array (rings[0]
  // outer, rings[1..] holes); a point in a hole is outside. Pure; no mutation.
  const pointInRing = (x, y, ring) => {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const xi = ring[i][0]; const yi = ring[i][1];
      const xj = ring[j][0]; const yj = ring[j][1];
      const intersect = ((yi > y) !== (yj > y))
        && (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
      if (intersect) inside = !inside;
    }
    return inside;
  };
  function pointInPolygon(point, rings) {
    if (!point || !Array.isArray(rings) || !rings.length || !rings[0]) return false;
    const x = point[0]; const y = point[1];
    if (!pointInRing(x, y, rings[0])) return false;   // outside the outer ring
    for (let k = 1; k < rings.length; k++) {
      if (rings[k] && pointInRing(x, y, rings[k])) return false;   // inside a hole
    }
    return true;
  }

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
      // My area wins whenever a base location is set, except an explicit URL
      // scope (docs/specs/map-and-search.md §4.5).
      if (!fromUrl && fallback.kind === 'myArea') {
        scope = clone(fallback);
        isDefaultScope = true;
      } else {
        // Cold start: inferred home country, not the hardcoded default
        // (docs/specs/map-and-search.md §4.5). Below url/localStorage; writes nothing.
        let home = null;
        if (!fromUrl && !fromLs) {
          const cc = this.inferHomeCountry();
          if (cc) home = sanitize(countryScope(cc));
        }
        scope = fromUrl || fromLs || home || clone(fallback);
        isDefaultScope = !fromUrl && !fromLs;
      }
      return this.get();
    },

    /** The active scope (a copy — mutating it never changes internal state). */
    get() { return clone(scope || fallback); },

    /** Whether the active scope was never explicitly chosen (no URL, no stored
     *  'cc-scope', no setter since init). */
    isDefault() { return isDefaultScope; },

    /** Replace the scope; persists + emits unless {persist:false}. Every setter
     *  funnels through here, so isDefault() retires the moment a scope is picked. */
    set(next, opts) {
      const clean = sanitize(next);
      if (!clean) return this.get();
      scope = clean;
      isDefaultScope = false;
      if (!opts || opts.persist !== false) persist(scope);
      emit();
      return this.get();
    },

    setRegion(slugOrId, opts) {
      const r = bySlug.get(slugOrId) || byId.get(slugOrId);
      return r ? this.set(regionScope(r), opts) : this.get();
    },
    setCountry(cc, opts) { return byCountry.has(cc) ? this.set(countryScope(cc), opts) : this.get(); },
    /** Search-only (docs/specs/map-and-search.md §4.5): no rail button and no
     *  widen rung lead here; the search box uses it to look worldwide. */
    setEverywhere() { return this.set(everywhereScope()); },

    /** One rung wider: region → its country, and myArea → its single
     *  registry-known country. A country is the widest place to look at:
     *  wider than that is a search, not a scope (owner, 2026-09-06). A region
     *  that already covers every region its country has stays put. */
    widen() {
      const next = this.nextWider();
      return next ? this.set(next) : this.get();
    },
    canWiden() { return this.nextWider() !== null; },

    /** The next-wider scope WITHOUT applying it (for labelling the widen chip);
     *  null when the scope is already as wide as the map goes. */
    nextWider() {
      if (!scope || scope.kind === 'everywhere' || scope.kind === 'country') return null;
      if (scope.kind === 'myArea') {
        const cc = singleMyAreaCountry();
        return cc ? countryScope(cc) : null;
      }
      return countryRungWidens(scope) ? countryScope(scope.countryCode) : null;
    },

    /** The onboarded country under a point, from the region registry; null
     *  outside every region. What a deep link and the pan-away nudge jump to
     *  now that Everywhere is not a place to look at. */
    countryAt(lat, lng) {
      const r = this.regionOfPoint(lng, lat);
      return r && r.countryCode && byCountry.has(r.countryCode) ? r.countryCode : null;
    },

    /** Region registry objects currently in scope (for spotlight + labels). */
    regions() { return (scope ? scope.regionIds : []).map((id) => byId.get(id)).filter(Boolean); },

    /** Display label from the registry, never the DOM. `region`/`country` from
     *  the row; `everywhere`/`myArea` → null (caller owns those strings). */
    label(s) {
      if (!s || !s.kind) return null;
      if (s.kind === 'region') {
        const ids = s.regionIds || [];
        const r = ids.length ? byId.get(ids[0]) : null;
        if (!r) return null;
        const first = r.label || r.slug;
        // A scope can hold several regions (a ride crossing provinces sets one
        // per region it passes through). Naming only the first would claim the
        // map is showing less than it is, so the rest are counted.
        return ids.length > 1 ? `${first} +${ids.length - 1}` : first;
      }
      if (s.kind === 'country') {
        if (!s.countryCode) return null;
        const rs = byCountry.get(s.countryCode);
        return (rs && rs[0] && rs[0].countryLabel) || s.countryCode;
      }
      return null; // everywhere / myArea: caller owns those strings
    },

    /** Union bbox [west, south, east, north] of the scope's regions; the circle
     *  bbox for myArea; null for everywhere. */
    bbox() {
      if (scope && scope.kind === 'myArea' && scope.myArea) return circleBbox(scope.myArea.center, scope.myArea.radiusKm);
      const rs = this.regions();
      if (!rs.length) return null;
      let acc = null;
      for (const r of rs) {
        if (!r.bbox) continue;
        acc = bboxUnion(acc, r.bbox);
      }
      return acc;
    },

    /** Camera frame for this scope — not always bbox(). Distant islands make
     *  the true extent mostly sea; `r.view` is the mainland outline box. */
    viewBbox() {
      if (scope && scope.kind === 'myArea' && scope.myArea) return this.bbox();
      const rs = this.regions();
      if (!rs.length) return null;
      let acc = null;
      for (const r of rs) {
        const b = r.view || r.bbox;
        if (!b) continue;
        acc = bboxUnion(acc, b);
      }
      return acc || this.bbox();
    },

    /** Does the active scope's box overlap this [w,s,e,n]? The one way to ask.
     *  Used for the "you are looking outside your filter" nudge, where a naive
     *  test on a crossing box said "always overlapping" and the nudge never
     *  fired for the two scopes that wrap. */
    bboxOverlaps(box) {
      const b = this.bbox();
      return b ? bboxesOverlap(b, box) : true;       // Everywhere overlaps everything
    },

    /** Does the active scope's box contain this point? The one way to ask.
     *  A scope box may cross the antimeridian (RegionRegistryProvider), and a
     *  hand-rolled `b[0] <= lng` comparison is wrong for the two that do. */
    bboxHasPoint(lng, lat) {
      const b = this.bbox();
      return b ? bboxHasPoint(b, lng, lat) : true;   // Everywhere contains everything
    },

    /** [lng, lat] centre of the active scope, or null for Everywhere.
     *  Chip ranking anchor (docs/specs/map-and-search.md §4.5) — from the
     *  scope bbox, not the map (chips render before applyScope fits the view). */
    scopeCenter() {
      const b = this.bbox();
      return b ? [bboxCentreLng(b), (b[1] + b[3]) / 2] : null;
    },

    /** Single region id for best-of &region= (only a single named region qualifies).
     *  Kept for compatibility — bestOfRegionIds() supersedes it for callers that
     *  can send a set. */
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

    /** Onboarded home country: My-area payload, anon circle, then timezone.
     *  Compute-only (docs/specs/map-and-search.md §4.5). */
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

    /** Store an anonymous circle (rounded to 2 decimals, radius clamped [10,150]). */
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

    /** Photon geocode hints: scoped bbox + country gate. */
    photonParams() {
      if (!scope || scope.kind === 'everywhere') return { bbox: null, countrycode: null };
      /* A crossing box is dropped rather than sent. Photon reads a bbox as
         minLon,minLat,maxLon,maxLat and has no notion of going round the back,
         so west > east would either return nothing or be silently normalised
         into a box spanning the world, which is the bug this whole change
         exists to remove. `countrycode` still narrows the search, and for the
         two scopes that wrap it is the country that matters anyway. */
      const b = this.bbox();
      return {
        bbox: bboxWraps(b) ? null : b,
        countrycode: scope.countryCode ? scope.countryCode.toLowerCase() : null,
      };
    },

    /** Coverage params {rids, cc} (docs/specs/map-and-search.md §4.5). Region:
     *  ids only; country: ids AND cc; Everywhere: neither. Empty myArea returns
     *  rids: [] (not null) — "in scope: nothing", never an unscoped request. */
    coverageParams() {
      if (!scope || scope.kind === 'everywhere') return { rids: null, cc: null };
      const rids = scope.regionIds.slice().sort((a, b) => a - b);
      if (scope.kind === 'myArea' && !rids.length) return { rids: [], cc: null };
      return { rids: rids.length ? rids : null, cc: scope.kind === 'country' ? scope.countryCode : null };
    },

    /** Scope search for the unified search box (docs/specs/map-and-search.md §4.5).
     *  Pure — registry only, never storage or scopechange. */
    searchScopes(query, limit) {
      const q = (query || '').trim().toLowerCase();
      if (q.length < 1) return [];
      const cap = limit ?? 8;   // ?? not ||: explicit 0 must mean 0
      const rank = (hay) => { const h = (hay || '').toLowerCase(); const i = h.indexOf(q); return i < 0 ? Infinity : (i === 0 ? 0 : 1); };
      const out = [];
      // country rungs
      for (const [cc, rs] of byCountry) {
        const cl = rs[0] && rs[0].countryLabel ? rs[0].countryLabel : cc;
        const score = Math.min(rank(cl), rank(cc));
        if (score < Infinity) out.push({ kind: 'country', cc, label: cl, _s: score - 0.5 }); // -0.5: rung above its regions on a tie
      }
      // regions
      for (const r of regions) {
        const score = Math.min(rank(r.label), rank(r.slug));
        if (score < Infinity) out.push({ kind: 'region', slug: r.slug, cc: r.countryCode || '', label: r.label || r.slug, _s: score });
      }
      // Pin collator locale so diacritic labels sort the same on CI and a dev box.
      out.sort((a, b) => (a._s - b._s) || a.label.localeCompare(b.label, 'en'));
      return out.slice(0, cap).map(({ _s, ...rest }) => rest);
    },

    /** Region whose bbox contains [lng,lat]; nearest-centre on overlap; null
     *  outside every region. Always a region, never a country
     *  (docs/specs/map-and-search.md §4.5). */
    regionOfPoint(lng, lat) {
      let best = null; let bestD = Infinity;
      for (const r of regions) {
        const b = r.bbox;
        if (!bboxHasPoint(b, lng, lat)) continue;
        const cx = bboxCentreLng(b); const cy = (b[1] + b[3]) / 2;
        // Scale longitude by cos(lat); raw degrees over-weight east-west.
        const kx = Math.cos(lat * Math.PI / 180);
        const d = ((cx - lng) * kx) ** 2 + (cy - lat) ** 2;
        if (d < bestD) { bestD = d; best = r; }
      }
      return best;
    },

    /** Ray-casting point-in-polygon; see the module helper. `rings` is a GeoJSON
     *  Polygon coordinate array (outer + holes). Pure. */
    pointInPolygon(point, rings) { return pointInPolygon(point, rings); },

    /** Kilometres from `near` ([lng, lat]) to the nearest point on `region`'s
     *  simplified outline; 0 when `near` is inside it, and the bbox-centre
     *  distance when the region carries no outline. This is the metric
     *  rankByGroundDistance sorts on, exposed for callers that want the number
     *  itself. Pure. */
    edgeDistanceKm(near, region) {
      if (!near || !region) return Infinity;
      const lat = near[1];
      const d2 = groundDistance2(region, near[0], lat, Math.cos(lat * Math.PI / 180));
      return Math.sqrt(d2) * 111.32;   // mean degree of latitude, km
    },

    /** Async click refinement: bbox candidates, then point-in-polygon only when
     *  2+ overlap. regionOfPoint stays sync for chips. */
    async regionOfPointPrecise(lng, lat) {
      const cands = [];
      for (const r of regions) {
        if (!bboxHasPoint(r.bbox, lng, lat)) continue;
        cands.push(r);
      }
      if (!cands.length) return null;
      if (cands.length === 1) return cands[0];
      const nearestByCentre = () => {
        const kx = Math.cos(lat * Math.PI / 180);
        let best = null; let bestD = Infinity;
        for (const r of cands) {
          const b = r.bbox;
          const cx = bboxCentreLng(b); const cy = (b[1] + b[3]) / 2;
          const d = ((cx - lng) * kx) ** 2 + (cy - lat) ** 2;
          if (d < bestD) { bestD = d; best = r; }
        }
        return best;
      };
      const ringsFor = async (r) => {
        if (boundaryCache.has(r.slug)) return boundaryCache.get(r.slug);
        let rings = [];
        try {
          const resp = await fetch('/map/region/' + encodeURIComponent(r.slug) + '/boundary');
          if (resp.ok) {
            const d = await resp.json();
            const g = d && d.geometry;
            // Polygon -> [rings]; MultiPolygon -> concat each polygon's rings.
            if (g && g.type === 'Polygon') rings = [g.coordinates];
            else if (g && g.type === 'MultiPolygon') rings = g.coordinates;
          }
        } catch (e) { rings = []; }   // network/parse failure → treat as no polygon
        boundaryCache.set(r.slug, rings);
        return rings;
      };
      for (const r of cands) {
        const polys = await ringsFor(r);     // array of Polygon ring-sets
        for (const rings of polys) {
          if (pointInPolygon([lng, lat], rings)) return r;
        }
      }
      return nearestByCentre();
    },

    /** Onboarded regions of a country, capped at 8 (docs/specs/map-and-search.md §4.5).
     *  `near: [lng,lat]` sorts by ground distance; else label-sort (pinned 'en'). */
    contextualRegions(cc, opts) {
      const o = opts || {};
      const cap = o.limit != null ? o.limit : 8;
      const list = (byCountry.get(cc) || []).slice();
      if (o.near) {
        return rankByGroundDistance(list, o.near, cap);
      }
      list.sort((a, b) => (a.label || a.slug).localeCompare(b.label || b.slug, 'en'));
      return list.slice(0, cap);
    },

    /** All onboarded regions, nearest-first from `near`, capped (default 8).
     *  Country-agnostic — the cross-border chip offer. */
    regionsNear(near, opts) {
      const cap = (opts && opts.limit != null) ? opts.limit : 8;
      return rankByGroundDistance(regions.slice(), near, cap);
    },

    /** Assign up to 8 regions to compass slots around `origin` by true bearing
     *  (docs/specs/map-and-search.md §4.5). Ground-corrected for latitude.
     *  Nearest-first; displaced at most one slot or overflow. Pure. */
    compassLayout(origin, regionsList) {
      const SLOTS = ['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw'];
      const ANGLE = { n: 0, ne: 45, e: 90, se: 135, s: 180, sw: 225, w: 270, nw: 315 };
      // Max angle a region may be moved from its true bearing to fit a free cell
      // (one slot: 22.5° worst-case ideal offset + 45° of displacement).
      const MAX_SLOT_DISPLACEMENT_DEG = 67.5;
      const result = { n: null, ne: null, e: null, se: null, s: null, sw: null, w: null, nw: null, overflow: [] };
      if (!origin || !Array.isArray(regionsList) || !regionsList.length) return result;
      const ox = origin[0]; const oy = origin[1];
      const kx = Math.cos(oy * Math.PI / 180);
      const withInfo = regionsList.map((r) => {
        const b = r && r.bbox;
        const cx = b ? (b[0] + b[2]) / 2 : ox;
        const cy = b ? (b[1] + b[3]) / 2 : oy;
        const dx = (cx - ox) * kx;
        const dy = cy - oy;
        let bearing = Math.atan2(dx, dy) * 180 / Math.PI;
        if (bearing < 0) bearing += 360;
        return { r, dist: dx * dx + dy * dy, bearing };
      });
      withInfo.sort((a, b) => a.dist - b.dist);
      const free = new Set(SLOTS);
      withInfo.forEach(({ r, bearing }) => {
        if (!free.size) { result.overflow.push(r); return; }
        let slot = SLOTS[Math.round(bearing / 45) % 8];
        if (!free.has(slot)) {
          let best = null; let bestDiff = Infinity;
          free.forEach((s) => {
            const diff = Math.abs(bearing - ANGLE[s]);
            const wrapped = Math.min(diff, 360 - diff);
            if (wrapped < bestDiff) { bestDiff = wrapped; best = s; }
          });
          // Displace by at most one slot (≤ 67.5°). Further would lie about direction.
          if (bestDiff > MAX_SLOT_DISPLACEMENT_DEG) { result.overflow.push(r); return; }
          slot = best;
        }
        result[slot] = r;
        free.delete(slot);
      });
      return result;
    },

    /** MapLibre filter for coverage TILE layers (docs/specs/map-and-search.md §4.5),
     *  or null for Everywhere. Prop-less (empty tokens) still renders — the
     *  artifact lags the DB. */
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
