// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 BikeCoders
//
// Colours of the curator's measured-traffic layer (docs/specs/traffic-measurements.md §4.6).
// The server sends a band per road and direction, never a number; the bands
// are a first reading, for curators to judge against what riders describe.

export const TRAFFIC_COLOURS = { quiet: '#2E7D4F', moderate: '#C98A0B', busy: '#D92D20' };
const RANK = { quiet: 0, moderate: 1, busy: 2 };
/* A road with felt safety and no measured traffic yet (§10): drawn, so a
   curator can open it, in a colour no band uses. */
export const FELT_ONLY_COLOUR = '#8A8F98';
/* A road piece by its label: blue cycle path, amber cycle lane, purple shared
   road (§3.1), in the ride review and the rider's road-safety mode alike. */
export const LABEL_COLOURS = Object.freeze({ p: '#2F6FDB', l: '#E8A33D', r: '#7A3FB8' });

/** Colour for a band: quiet, moderate or busy. */
export function colourFor(band) {
  return TRAFFIC_COLOURS[band] || TRAFFIC_COLOURS.quiet;
}

/**
 * The shown entries of one group, one per way: the busier direction sets the
 * colour, and both directions travel along for the popup.
 *
 * @returns {Map<number, {traffic: string, label: string, directions: Array}>}
 */
export function perWay(shown, group) {
  const out = new Map();
  for (const entry of shown || []) {
    if (entry.group !== group) continue;
    const cur = out.get(entry.way);
    if (!cur) {
      out.set(entry.way, { traffic: entry.traffic, label: entry.label, directions: [entry] });
      continue;
    }
    cur.directions.push(entry);
    if ((RANK[entry.traffic] ?? 0) > (RANK[cur.traffic] ?? 0)) cur.traffic = entry.traffic;
  }
  return out;
}

/**
 * Felt safety of one group, per way (§10): safe, mixed or unsafe.
 *
 * @returns {Map<number, string>}
 */
export function feltPerWay(felt, group) {
  const out = new Map();
  for (const entry of felt || []) {
    if (entry.group === group) out.set(entry.way, entry.felt);
  }
  return out;
}
