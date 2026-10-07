// SPDX-License-Identifier: AGPL-3.0-only
//
// Colours of the curator's measured-traffic layer (docs/specs/traffic-measurements.md §4.6).
// The bands are a first reading, for curators to judge against what riders
// describe; they are not yet the Quiet / Moderate / Busy of the road field.

export const TRAFFIC_COLOURS = { quiet: '#2E7D4F', moderate: '#C98A0B', busy: '#D92D20' };
export const MODERATE_FROM = 1;
export const BUSY_FROM = 3;

/** Colour for a number of cars per kilometre. */
export function colourFor(carsPerKm) {
  if (carsPerKm >= BUSY_FROM) return TRAFFIC_COLOURS.busy;
  if (carsPerKm >= MODERATE_FROM) return TRAFFIC_COLOURS.moderate;
  return TRAFFIC_COLOURS.quiet;
}

/**
 * The shown entries of one group, one per way: the busier direction sets the
 * colour, and both directions travel along for the popup.
 *
 * @returns {Map<number, {carsPerKm: number, label: string, directions: Array}>}
 */
export function perWay(shown, group) {
  const out = new Map();
  for (const entry of shown || []) {
    if (entry.group !== group) continue;
    const cur = out.get(entry.way);
    if (!cur) {
      out.set(entry.way, { carsPerKm: entry.carsPerKm, label: entry.label, directions: [entry] });
      continue;
    }
    cur.directions.push(entry);
    if (entry.carsPerKm > cur.carsPerKm) cur.carsPerKm = entry.carsPerKm;
  }
  return out;
}
