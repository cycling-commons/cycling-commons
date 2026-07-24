// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// Scope-chip VIEW model — docs/specs/2026-07-23-map-js-phase0-extraction-design.md.
//
// Answers "which scope chips should be offered, and where do they sit", and nothing
// else. Extracted from map.js's renderScopeChips(), where the same decisions sat
// tangled with innerHTML building and were therefore unreachable by any test: two
// owner-reported bugs on 2026-07-23 (a dead More chip, and a compass ranked against
// the OUTGOING scope's centre) were both caller-side defects in this logic calling a
// correctly-tested scope.js, and both needed a browser to find.
//
// Pure: no DOM, no window, no storage, no map, no mutation of the rows passed in.
// scope.js's ranking helpers arrive as `scopeApi` rather than via window.CCScope so
// the function stays deterministic and a test can substitute a stub.
//
// scope.js owns the scope STATE (persistence, URL token, cc:scopechange, geometry);
// this module owns the view derived from it. Kept separate deliberately.
(() => {
  'use strict';

  // Row-major, which is also document and tab order: a screen reader or keyboard tab
  // walks the grid exactly as it reads on screen. null marks the centre cell.
  const ROWS = [
    ['nw', 'n', 'ne'],
    ['w', null, 'e'],
    ['sw', 's', 'se'],
  ];

  const emptyCell = () => ({ kind: 'empty', slug: null, label: null, dir: null, cc: null, foreign: false });

  /** Build the chip view model.
   *
   *  `input`:
   *   - `scope`           — the ACTIVE scope, {kind, regionIds, countryCode}
   *   - `isDefault`       — CCScope.isDefault(): the rider has not chosen yet
   *   - `activeRegions`   — CCScope.regions(), the scope's own regions
   *   - `registry`        — window.CC_REGIONS, for the cold-start country list
   *   - `inferredCountry` — CCScope.inferHomeCountry() or null
   *   - `scopeCenter`     — CCScope.scopeCenter(), [lng,lat] or null
   *   - `mapCenter`       — [lng,lat], the Everywhere fallback anchor only
   *   - `myArea`          — window.CC_MY_AREA, {lat,lng} or null
   *
   *  `scopeApi` supplies `contextualRegions(cc, opts)` and `compassLayout(origin, list)`.
   *
   *  Returns {mode, country, rows, chips, overflow, more, countries}. Keys that do not
   *  apply to the returned mode are empty ([] / null), never undefined. */
  function chipModel(input, scopeApi) {
    const i = input || {};
    const scope = i.scope || null;
    const model = {
      mode: 'countries', country: null, rows: [], chips: [], overflow: [],
      more: false, countries: [],
    };

    // --- which country's regions to offer -----------------------------------
    // A region scope carries its country on the region row, not always on the scope.
    const active = (i.activeRegions && i.activeRegions[0]) || null;
    const hasRegions = !!(scope && scope.regionIds && scope.regionIds.length);
    const scopeCc = (scope && scope.countryCode)
      || (hasRegions && active ? active.countryCode : null)
      || null;
    // map.js's startup default always carries a countryCode, so before isDefault()
    // that branch always won and the inferred home was never reached (owner fix 1).
    const cc = i.isDefault ? (i.inferredCountry || scopeCc) : (scopeCc || i.inferredCountry);

    if (!cc) {
      // Cold start: country rungs from the registry, label-sorted with a pinned 'en'
      // collator. The registry's own order is area DESC, so following it would
      // reshuffle this list whenever a region is imported or a country onboarded.
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

    // Only the label-sorted first region is needed: countryLabel is identical for every
    // region of a country, and `more` now reads the global registry total instead of this
    // list (the old {limit: Infinity} full-country fetch, from before that move, is gone).
    const all = scopeApi.contextualRegions(cc, { limit: 1 });
    model.country = { cc, label: (all[0] || {}).countryLabel || ('All ' + cc) };

    // Country cue: a chip whose country differs from the ACTIVE scope's country is
    // foreign and the serializer marks it "· NL" (2026-07-23-cross-border-chips-design.md §3.2).
    const cue = (r) => ({
      slug: r.slug, label: r.label || r.slug,
      cc: r.countryCode || null,
      foreign: !!(r.countryCode && r.countryCode !== cc),
    });
    // "More" now means "more onboarded regions exist ANYWHERE" — the shown set is
    // cross-border, so the old active-country total is the wrong denominator.
    const total = (i.registry || []).length;

    // The compass only means something when the scope IS one named region — a
    // country/everywhere/myArea scope has no single centre to lay a grid around.
    const activeRegion = (scope && scope.kind === 'region' && hasRegions
      && scope.regionIds.length === 1) ? active : null;

    if (activeRegion && i.scopeCenter) {
      model.mode = 'compass';
      // Adjacency gate (2026-07-24-region-adjacency-and-click-refinement-design.md
      // §2.3 / §2.4): a FOREIGN region is offered only if it shares a border with
      // the active region (id in activeRegion.adj). Border-neighbours are taken
      // first (so Overijssel still reaches Lower Saxony + NRW despite far German
      // centroids), then same-country fill by the existing centroid metric.
      // Domestic non-neighbours never displace an adj entry; foreign non-
      // neighbours are never eligible.
      const activeAdj = new Set(activeRegion.adj || []);
      const ranked = scopeApi.regionsNear(i.scopeCenter, { limit: total })
        .filter((r) => r.id !== activeRegion.id);
      const adjFirst = ranked.filter((r) => activeAdj.has(r.id));
      const domesticRest = ranked.filter((r) => !activeAdj.has(r.id)
        && r.countryCode === activeRegion.countryCode);
      const pool = adjFirst.concat(domesticRest).slice(0, 8);
      const layout = scopeApi.compassLayout(i.scopeCenter, pool);
      ROWS.forEach((row) => {
        // Drop a WHOLE vacant row: empty cells are visibility:hidden, so they still
        // reserve height and an entirely empty row leaves a band of dead space.
        // Dropping only whole rows keeps every surviving chip in its true direction.
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
      // Regions compassLayout could not place without misstating their direction.
      // Still among the 8 nearest, so they stay reachable rather than being dropped.
      model.overflow = layout.overflow.map(cue);
      model.more = total > pool.length + 1;   // +1 for the centre
      return model;
    }

    model.mode = 'linear';
    // Anchor: the rider's own base when they have one, else the INCOMING scope's
    // centre, else the map. NOTE the asymmetry with compass mode above, which
    // anchors on scopeCenter only — that is map.js's existing behaviour, preserved
    // here deliberately and pinned by a test.
    const near = (i.myArea && i.myArea.lat != null && i.myArea.lng != null)
      ? [i.myArea.lng, i.myArea.lat]
      : (i.scopeCenter || i.mapCenter || null);
    // The anchor is a point, not a region, so it has no adj of its own: resolve it
    // to the region it sits in (synchronous bbox regionOfPoint — chip rendering
    // must not go async), then gate foreign chips by THAT region's adj. No region
    // under the anchor (e.g. Everywhere centred over open sea) → ungated, today's
    // behaviour (2026-07-24-region-adjacency-and-click-refinement-design.md §2.3).
    // Linear keeps pure centroid order among eligible (same-country OR adj) so a
    // Duisburg/Amsterdam anchor still surfaces the nearest region first; compass
    // mode above uses adj-first so far-centroid border neighbours still appear.
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
