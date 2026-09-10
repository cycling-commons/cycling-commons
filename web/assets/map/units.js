// SPDX-License-Identifier: AGPL-3.0-only
//
// Rider units (docs/specs/account-and-auth.md §9). Conversion is js/cc-units.js;
// this wraps the globals for node tests / failed load. Fallback is metric.

const G = typeof window !== 'undefined' ? window : {};

/** A ride-scale distance given in kilometres: "42.2 km" or "26.2 mi". */
export function uKm(km, decimals) {
  if (G.ccKm) return G.ccKm(km, decimals);
  return Number(km).toFixed(decimals == null ? 1 : decimals) + ' km';
}

/** A short distance given in metres: "250 m" or "820 ft". */
export function uM(metres, decimals) {
  if (G.ccM) return G.ccM(metres, decimals);
  return Number(metres).toFixed(decimals == null ? 0 : decimals) + ' m';
}

/** Height climbed or altitude, given in metres: "1,240 m" or "4,068 ft". */
export function uElev(metres, decimals) {
  if (G.ccElev) return G.ccElev(metres, decimals);
  return Number(metres).toFixed(decimals == null ? 0 : decimals) + ' m';
}

/** The bare converted number, for axis ticks that write their unit once. */
export function uKmValue(km, decimals) {
  if (G.ccKmValue) return G.ccKmValue(km, decimals);
  return decimals == null ? Number(km) : Number(Number(km).toFixed(decimals));
}

export function uElevValue(metres, decimals) {
  if (G.ccElevValue) return G.ccElevValue(metres, decimals);
  return decimals == null ? Number(metres) : Number(Number(metres).toFixed(decimals));
}

/** A speed given in km/h: "32 km/h" or "20 mph". Follows the distance unit. */
export function uSpeed(kmh, decimals) {
  if (G.ccSpeed) return G.ccSpeed(kmh, decimals);
  return Number(kmh).toFixed(decimals == null ? 0 : decimals) + ' km/h';
}

/** The unit words themselves, for an axis caption above a column of numbers. */
export function uDistUnit() { return G.ccDistUnit || 'km'; }
export function uElevUnit() { return G.ccElevUnit || 'm'; }
