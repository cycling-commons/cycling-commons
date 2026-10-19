// SPDX-License-Identifier: AGPL-3.0-only
/* What a recommended route's roads are (docs/specs/route-domain.md §6.4, owner 2026-10-19: "A recommended route should
   also have the road 'quality' info. Like when you plan your own route"): the lines of the route card and where each
   lies, from GET /map/route/{id}/roads. Pure, so node can test it; the card is route-roads.js. */
import { roadDetail, detailSpans, joinSpans } from './plan-model.js';

const SURFACES = ['paved', 'gravel', 'rough', 'unknown'];

/** The card's lines: the surfaces with metres, the main roads without a cycle lane, and the road detail as the planner sums it. */
export function roadsRows(d) {
  const s = (d && d.surfaces) || {};
  const leg = { routed: true, distM: d ? d.distanceM : 0, quality: d && d.quality, speeds: d && d.speeds };
  return {
    surface: SURFACES.filter(k => s[k] > 0).map(k => [k, s[k]]),
    busyM: s.busy || 0,
    detail: roadDetail([leg]),
  };
}

/** Where one line lies along the route, [from, to] metres: a surface by its runs, `busy` by the main-road runs, and
    `sm:`, `tt:`, `sp:` as the planner places them (detailSpans()). */
export function roadsSpans(d, part) {
  if (!d) return [];
  if (SURFACES.includes(part)) {
    const runs = d.runs || [], out = [];
    runs.forEach(([at, cls], i) => { if (cls === part) out.push([at, i + 1 < runs.length ? runs[i + 1][0] : d.distanceM]); });
    return joinSpans(out, 1);
  }
  if (part === 'busy') return (d.busyRuns || []).map(([a, b]) => [a, b]);
  if (/^(sm|tt|sp):/.test(part)) return detailSpans([{ routed: true, distM: d.distanceM, detailRuns: d.detailRuns }], part.slice(0, 2), part.slice(3));
  return [];
}
