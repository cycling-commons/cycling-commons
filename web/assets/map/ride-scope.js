// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
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
 * Two countries have no common region scope, so that widens to Everywhere.
 * A ride outside every onboarded region answers null: there is nothing better
 * to show it, and moving the rider's scope for nothing is worse than leaving it.
 */
export function rideScopeFor(regions){
  if(!Array.isArray(regions)) return null;
  const ids = regions.map(r => r && r.id).filter(id => id != null);
  if(!ids.length) return null;
  const ccs = [...new Set(regions.map(r => r && r.countryCode).filter(Boolean))];
  if(ccs.length > 1) return { kind:'everywhere', regionIds:[], countryCode:null };
  return { kind:'region', regionIds:ids, countryCode:ccs[0] || null };
}
