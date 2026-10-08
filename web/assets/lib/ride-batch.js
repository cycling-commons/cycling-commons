// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 BikeCoders
//
// Several rides at once (docs/specs/traffic-measurements.md §3.2): what a
// batch of ride summaries adds up to. One total, the period the rides cover
// (from the lines' own dates), a breakdown per year, and the roads to draw:
// each stretch once, however often it was ridden, so years of rides stay light
// on the map. Nothing here is sent; the lines go out as they are.

const yearOf = day => new Date(day * 86400000).getUTCFullYear();
const round = n => Math.round(n * 1e5) / 1e5;
const partKey = p => p.id + '|' + p.coords[0].map(round).join(',') + '|' + p.coords[p.coords.length - 1].map(round).join(',');
const lineKey = coords => coords[0].map(round).join(',') + '|' + coords[coords.length - 1].map(round).join(',');

export function createBatch() {
  let files = 0;
  let rides = 0;
  let km = 0;
  let cars = 0;
  let nearby = 0;
  let fromDay = null;
  let toDay = null;
  const years = new Map();
  const parts = new Map();
  const unmatched = new Map();

  return {
    /** One file read (a .zip can hold several rides). */
    file() { files++; },

    /** One ride's summary (summariseRide()). */
    add(summary) {
      rides++;
      km += summary.matchedKm;
      cars += summary.cars;
      nearby += summary.nearby;
      const days = summary.lines.map(l => l.day);
      if (days.length) {
        const first = Math.min(...days);
        fromDay = fromDay === null ? first : Math.min(fromDay, first);
        toDay = toDay === null ? Math.max(...days) : Math.max(toDay, ...days);
        const y = yearOf(first);
        const entry = years.get(y) || { year: y, rides: 0, km: 0, cars: 0, nearby: 0 };
        entry.rides++;
        entry.km = round(entry.km + summary.matchedKm);
        entry.cars += summary.cars;
        entry.nearby += summary.nearby;
        years.set(y, entry);
      }
      for (const p of summary.matched || []) {
        if (p.coords && p.coords.length > 1 && !parts.has(partKey(p))) parts.set(partKey(p), p);
      }
      for (const coords of summary.unmatchedLines || []) {
        if (coords.length > 1 && !unmatched.has(lineKey(coords))) unmatched.set(lineKey(coords), coords);
      }
    },

    summary() {
      return {
        files, rides, km: round(km), cars, nearby, fromDay, toDay,
        years: [...years.values()].sort((a, b) => b.year - a.year),
      };
    },

    /** The roads and the parts with no road, each once, and the box that holds them. */
    drawing() {
      const matched = [...parts.values()];
      const unmatchedLines = [...unmatched.values()];
      let box = null;
      const grow = ([x, y]) => {
        if (!box) { box = [[x, y], [x, y]]; return; }
        box[0][0] = Math.min(box[0][0], x); box[0][1] = Math.min(box[0][1], y);
        box[1][0] = Math.max(box[1][0], x); box[1][1] = Math.max(box[1][1], y);
      };
      matched.forEach(p => p.coords.forEach(grow));
      unmatchedLines.forEach(l => l.forEach(grow));
      return { matched, unmatchedLines, bounds: box };
    },
  };
}
