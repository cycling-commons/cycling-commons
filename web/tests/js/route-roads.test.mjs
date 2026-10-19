// SPDX-License-Identifier: AGPL-3.0-only
//
// What a recommended route's roads are, on its card (route-domain.md §6.4, owner 2026-10-19: "A recommended route
// should also have the road 'quality' info. Like when you plan your own route"): the planner's own surfaces, main
// roads, smoothness, track grades and speed limits, and each line lights its stretches on the route.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { roadsRows, roadsSpans } from '../../assets/map/route-roads-model.js';

const read = p => readFileSync(new URL('../../' + p, import.meta.url), 'utf8');

const D = {
  distanceM: 10000,
  surfaces: { paved: 7000, gravel: 2000, rough: 0, unknown: 1000, busy: 3000, cycle: 0, signals: 0 },
  runs: [[0, 'paved'], [5000, 'gravel'], [7000, 'paved'], [9000, 'unknown']],
  busyRuns: [[0, 3000]],
  quality: { smoothness: { good: 4000, horrible: 500 }, tracktype: { grade2: 1500 } },
  speeds: { 50: 3000, unset: 7000 },
  detailRuns: { smoothness: [[0, 4000, 'good'], [6000, 6500, 'horrible']], tracktype: [[5000, 6500, 'grade2']], speeds: [[0, 3000, '50'], [3000, 10000, 'unset']] },
};

test('the route card lists what a planned ride lists, each line with the part it lights', () => {
  const rows = roadsRows(D);
  assert.deepEqual(rows.surface, [['paved', 7000], ['gravel', 2000], ['unknown', 1000]], 'a surface with no metres is left out');
  assert.equal(rows.busyM, 3000);
  assert.deepEqual(rows.detail.smoothness, [['good', 4000], ['very_bad', 500]]);
  assert.deepEqual(rows.detail.tracktype, [['grade2', 1500]]);
  assert.deepEqual(rows.detail.speeds, [['50', 3000], ['unset', 7000]]);
});

test('each part lies where its runs say along the route', () => {
  assert.deepEqual(roadsSpans(D, 'paved'), [[0, 5000], [7000, 9000]]);
  assert.deepEqual(roadsSpans(D, 'unknown'), [[9000, 10000]], 'the last run ends with the route');
  assert.deepEqual(roadsSpans(D, 'busy'), [[0, 3000]]);
  assert.deepEqual(roadsSpans(D, 'sm:very_bad'), [[6000, 6500]]);
  assert.deepEqual(roadsSpans(D, 'tt:grade2'), [[5000, 6500]]);
  assert.deepEqual(roadsSpans(D, 'sp:unset'), [[3000, 10000]]);
  assert.deepEqual(roadsSpans(D, 'nothing'), []);
});

test('the route card asks for its roads and lights them on the route', () => {
  const drawer = read('assets/map/drawer.js');
  assert.match(drawer, /routeRoadsSlot\(f, D\.routeRoadsWait/);
  assert.match(drawer, /hydrateRouteRoads\(f\.id, f\.geom && f\.geom\.path\)/, 'the card hands its own line to the hover');
  const mod = read('assets/map/route-roads.js');
  assert.match(mod, /\/map\/route\/\$\{encodeURIComponent\(routeId\)\}\/roads/);
  assert.match(mod, /sliceAlong\(\[leg\], a, b\)/);
  assert.match(drawer, /clearRouteRoads\(\);/, 'closing the card takes the highlight away');
});

/* Owner 2026-10-19: "make the road description option a thing you can click to open default closed", with the
   space above it of the card's other sections. */
test('the roads block is a closed fold headed like the card\'s other sections', () => {
  const mod = read('assets/map/route-roads.js');
  assert.match(mod, /<details class="cc-d-roadsfold"><summary class="cc-near-h">/);
  assert.doesNotMatch(mod, /<details class="cc-d-roadsfold" open/);
  const css = read('assets/styles/map.css');
  assert.match(css, /\.cc-d-roadsfold summary\{[^}]*cursor:pointer/);
  assert.match(css, /\.cc-d-roadsfold summary::-webkit-details-marker\{display:none\}/);
});

/* Owner 2026-10-19: "make that selectable up till 5km like we have in the user route planner". */
test('the route card picks how far along the route it looks, as the planner does', () => {
  const lp = read('assets/map/listed-place.js');
  assert.match(lp, /const ROUTE_ALONG_STEPS = \[250, 500, 1000, 2000, 5000\];/);
  assert.match(lp, /hydrateRouteSlot\(routeId, 'cc-d-along-slot', 'along\?r=' \+ reach,/);
  assert.match(lp, /<select class="cc-along-r"/);
  // Owner 2026-10-19: "No default always 250 for every new route".
  assert.match(lp, /export function hydrateRouteAlong\(routeId, reach = 250\)/);
  assert.doesNotMatch(lp, /localStorage/, 'a reach is never kept for the next route');
  assert.match(read('assets/styles/map.css'), /\.cc-along-r\{/);
});
