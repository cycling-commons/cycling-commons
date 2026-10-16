// SPDX-License-Identifier: AGPL-3.0-only
/* Which scope a loaded ride asks for (docs/specs/map-and-search.md §4.5).
   A leaf: no DOM, no map, no storage — the decision alone, so it can be tested. */

/** Comparable identity of a scope, so "already looking there" is one string test. */
export function scopeKey(s){
  if(!s) return '';
  return [s.kind, (s.regionIds || []).slice().sort((a, b) => a - b).join('.'), s.countryCode || ''].join('|');
}

/**
 * The scope for a ride, from the regions its track crosses.
 *
 * A scope holds a SET of region ids, so a ride through three provinces takes
 * all three rather than picking a winner and leaving the last stretch unscoped.
 * A ride across a border keeps every region it crosses in that one set,
 * whatever their country: Everywhere is not a place to look at (owner,
 * 2026-09-06), and a region set never asks the server for more than the ride.
 * A ride outside every onboarded region answers null: there is nothing better
 * to show it, and moving the rider's scope for nothing is worse than leaving it.
 */
export function rideScopeFor(regions){
  if(!Array.isArray(regions)) return null;
  const ids = regions.map(r => r && r.id).filter(id => id != null);
  if(!ids.length) return null;
  const ccs = [...new Set(regions.map(r => r && r.countryCode).filter(Boolean))];
  return { kind:'region', regionIds:ids, countryCode: ccs.length === 1 ? ccs[0] : null };
}

/**
 * Whether a scope already shows every region a ride crosses, so the ride need not move it (route-planner.md
 * section 5, owner 2026-10-16). A wider scope, the ride's country or Everywhere, covers it: narrowing that to the
 * ride's regions would hide what the rider chose to see. No scope, or a ride outside every region, asks nothing.
 */
export function scopeCovers(scope, regions){
  if(!scope || scope.kind === 'everywhere' || !Array.isArray(regions)) return true;
  return regions.every(r => !r || (scope.kind === 'country'
    ? r.countryCode === scope.countryCode
    : (scope.regionIds || []).includes(r.id)));
}
