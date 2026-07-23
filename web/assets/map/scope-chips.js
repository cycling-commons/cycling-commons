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

  const emptyCell = () => ({ kind: 'empty', slug: null, label: null, dir: null });
  const chipOf = (r) => ({ slug: r.slug, label: r.label || r.slug });

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

    // {limit: Infinity} is REQUIRED: a bare contextualRegions(cc) applies the same
    // default cap of 8, which is what made the More chip dead code (fixed 61df4ea).
    const all = scopeApi.contextualRegions(cc, { limit: Infinity });
    model.country = { cc, label: (all[0] || {}).countryLabel || ('All ' + cc) };

    // The compass only means something when the scope IS one named region — a
    // country/everywhere/myArea scope has no single centre to lay a grid around.
    const activeRegion = (scope && scope.kind === 'region' && hasRegions
      && scope.regionIds.length === 1) ? active : null;

    if (activeRegion && i.scopeCenter) {
      model.mode = 'compass';
      // 9 nearest INCLUDING the active region (distance 0 from its own centre, so it
      // always ranks first), then drop it: 8 neighbours + 1 centre = exactly 9.
      const pool = scopeApi.contextualRegions(cc, { near: i.scopeCenter, limit: 9 })
        .filter((r) => r.id !== activeRegion.id).slice(0, 8);
      const layout = scopeApi.compassLayout(i.scopeCenter, pool);
      ROWS.forEach((row) => {
        // Drop a WHOLE vacant row: empty cells are visibility:hidden, so they still
        // reserve height and an entirely empty row leaves a band of dead space.
        // Dropping only whole rows keeps every surviving chip in its true direction.
        if (!row.some((dir) => dir === null || layout[dir])) return;
        model.rows.push(row.map((dir) => {
          if (dir === null) {
            return {
              kind: 'center', slug: activeRegion.slug,
              label: activeRegion.label || activeRegion.slug, dir: null,
            };
          }
          const r = layout[dir];
          if (!r) return emptyCell();
          return { kind: 'region', slug: r.slug, label: r.label || r.slug, dir };
        }));
      });
      // Regions compassLayout could not place without misstating their direction.
      // Still among the 8 nearest, so they stay reachable rather than being dropped.
      model.overflow = layout.overflow.map(chipOf);
      model.more = all.length > pool.length + 1;   // +1 for the centre
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
    const shown = scopeApi.contextualRegions(cc, near ? { near } : {});
    model.chips = shown.map(chipOf);
    model.more = all.length > shown.length;
    return model;
  }

  const API = { chipModel };
  if (typeof window !== 'undefined') window.CCScopeChips = API;
  // Node smoke tests set globalThis.window; also expose via module.exports when present.
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
