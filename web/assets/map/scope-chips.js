// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// Scope-chip view model (docs/specs/map-and-search.md §4.5): which chips to
// offer and where they sit. Pure: no DOM, no storage, no map. Ranking helpers
// arrive as `scopeApi` so tests can stub them.
(() => {
  'use strict';

  // Row-major (document / tab order). null marks the centre cell.
  const ROWS = [
    ['nw', 'n', 'ne'],
    ['w', null, 'e'],
    ['sw', 's', 'se'],
  ];

  const emptyCell = () => ({ kind: 'empty', slug: null, label: null, dir: null, cc: null, foreign: false });

  /** Chip view model. Unused keys are [] / null, never undefined.
   *  input: scope, isDefault, activeRegions, registry, inferredCountry, scopeCenter, mapCenter, myArea.
   *  scopeApi: contextualRegions(cc, opts), compassLayout(origin, list). */
  function chipModel(input, scopeApi) {
    const i = input || {};
    const scope = i.scope || null;
    const model = {
      mode: 'countries', country: null, rows: [], chips: [], overflow: [],
      more: false, countries: [],
    };

    // A region scope carries its country on the region row, not always on the scope.
    const active = (i.activeRegions && i.activeRegions[0]) || null;
    const hasRegions = !!(scope && scope.regionIds && scope.regionIds.length);
    const scopeCc = (scope && scope.countryCode)
      || (hasRegions && active ? active.countryCode : null)
      || null;
    // isDefault: prefer inferred home over the hardcoded startup country's code.
    const cc = i.isDefault ? (i.inferredCountry || scopeCc) : (scopeCc || i.inferredCountry);

    if (!cc) {
      // Cold start: country rungs, label-sorted with a pinned 'en' collator
      // (registry order is area DESC and would reshuffle on import).
      const seen = new Set();
      (i.registry || []).forEach((r) => {
        if (r.countryCode && !seen.has(r.countryCode)) {
          seen.add(r.countryCode);
          model.countries.push({ cc: r.countryCode, label: r.countryLabel || ('All ' + r.countryCode) });
        }
      });
      model.countries.sort((a, b) => a.label.localeCompare(b.label, 'en'));
      return model;
    }

    // countryLabel is identical for every region of a country; `more` uses the global total.
    const all = scopeApi.contextualRegions(cc, { limit: 1 });
    model.country = { cc, label: (all[0] || {}).countryLabel || ('All ' + cc) };

    // Foreign = chip country ≠ active scope country; serializer marks it "· NL".
    const cue = (r) => ({
      slug: r.slug, label: r.label || r.slug,
      cc: r.countryCode || null,
      foreign: !!(r.countryCode && r.countryCode !== cc),
    });
    // "More" = more onboarded regions exist anywhere (shown set is cross-border).
    const total = (i.registry || []).length;

    // Compass only for a single named region — other kinds have no centre to grid around.
    const activeRegion = (scope && scope.kind === 'region' && hasRegions
      && scope.regionIds.length === 1) ? active : null;

    if (activeRegion && i.scopeCenter) {
      model.mode = 'compass';
      // Adjacency gate (docs/specs/map-and-search.md §4.5): a foreign region is
      // offered only if it shares a border (id in activeRegion.adj). Neighbours
      // first, then same-country fill. Foreign non-neighbours are never eligible.
      const activeAdj = new Set(activeRegion.adj || []);
      const ranked = scopeApi.regionsNear(i.scopeCenter, { limit: total })
        .filter((r) => r.id !== activeRegion.id);
      const adjFirst = ranked.filter((r) => activeAdj.has(r.id));
      const domesticRest = ranked.filter((r) => !activeAdj.has(r.id)
        && r.countryCode === activeRegion.countryCode);
      const pool = adjFirst.concat(domesticRest).slice(0, 8);
      const layout = scopeApi.compassLayout(i.scopeCenter, pool);
      ROWS.forEach((row) => {
        // Drop a whole vacant row (empty cells still reserve height).
        if (!row.some((dir) => dir === null || layout[dir])) return;
        model.rows.push(row.map((dir) => {
          if (dir === null) {
            return { kind: 'center', dir: null, ...cue(activeRegion) };
          }
          const r = layout[dir];
          if (!r) return emptyCell();
          return { kind: 'region', dir, ...cue(r) };
        }));
      });
      // Could not place without misstating direction; still among the 8 nearest.
      model.overflow = layout.overflow.map(cue);
      model.more = total > pool.length + 1;   // +1 for the centre
      return model;
    }

    model.mode = 'linear';
    // Linear anchor: rider's base, else incoming scope centre, else the map.
    // (Compass above anchors on scopeCenter only — pinned by test.)
    const near = (i.myArea && i.myArea.lat != null && i.myArea.lng != null)
      ? [i.myArea.lng, i.myArea.lat]
      : (i.scopeCenter || i.mapCenter || null);
    // Gate foreign chips by the region under the anchor (sync bbox regionOfPoint).
    // Linear: centroid order among eligible (same-country OR adj). Compass: adj-first.
    const anchorRegion = near ? scopeApi.regionOfPoint(near[0], near[1]) : null;
    const linEligible = anchorRegion
      ? (r) => r.countryCode === anchorRegion.countryCode
          || (anchorRegion.adj || []).indexOf(r.id) !== -1
      : () => true;
    const shown = near
      ? scopeApi.regionsNear(near, { limit: total }).filter(linEligible).slice(0, 8)
      : scopeApi.contextualRegions(cc, {});
    model.chips = shown.map(cue);
    model.more = total > shown.length;
    return model;
  }

  const API = { chipModel };
  if (typeof window !== 'undefined') window.CCScopeChips = API;
  // Node smoke tests set globalThis.window; also expose via module.exports when present.
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
