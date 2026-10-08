// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 BikeCoders
//
// The ride files inside a folder entry or an exported archive
// (docs/specs/traffic-measurements.md §3.2). Bike computer platforms export a
// zip of zips with .fit and .fit.gz files among profile data; only the rides
// are kept, unpacked in memory, never uploaded.

const MAX_DEPTH = 3;
const wanted = name => /\.(fit|fit\.gz|zip)$/i.test(name);

/**
 * @param {string} name file or entry name
 * @param {Uint8Array} bytes its content
 * @param {{unzipSync: Function, gunzipSync: Function}} tools fflate
 * @returns {Array<{name: string, bytes: Uint8Array|null, error?: true}>}
 */
export function fitEntries(name, bytes, tools, depth = 0) {
  if (/\.fit$/i.test(name)) return [{ name, bytes }];
  try {
    if (/\.fit\.gz$/i.test(name)) return [{ name, bytes: tools.gunzipSync(bytes) }];
    if (/\.zip$/i.test(name) && depth < MAX_DEPTH) {
      const inner = tools.unzipSync(bytes, { filter: f => wanted(f.name) });
      return Object.entries(inner).flatMap(([n, b]) => fitEntries(n, b, tools, depth + 1));
    }
  } catch (e) {
    return [{ name, bytes: null, error: true }];
  }
  return [];
}
