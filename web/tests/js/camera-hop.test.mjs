// SPDX-License-Identifier: AGPL-3.0-only
//
// Animate the move, or cut to it (docs/specs/map-and-search.md §8). A map
// animates over the tiles it has, and it only fetched the ones for viewports it
// has drawn, so a hop onto ground the rider has not been looking at slides over
// the background colour for a second. Owner-reported 2026-09-16: "now it
// scrolls to the location while show a grey screen".
//
// The numbers below are the owner's own hops, measured on the dev map at a
// 1391 px wide container.
import test from 'node:test';
import assert from 'node:assert/strict';
import { hopIsNear, metresPerPixel, groundDistanceM } from '../../assets/map/camera-hop.js';

const W = 1391, H = 864;
const FRIESLAND = { center: [5.79, 52.97], zoom: 9.13, width: W, height: H };
const LIEGE_ROUTE = { center: [5.87, 50.56], zoom: 10.52 };
const GRONINGEN = { center: [6.6, 53.2], zoom: 9.2 };

test('the viewport and the hop are measured in the same ground metres', () => {
  // z9.13 at 53N: 156543 * cos(53) / 2^9.13 metres a pixel, times 1391 px.
  const mpp = metresPerPixel(FRIESLAND.zoom, FRIESLAND.center[1]);
  assert.ok(mpp > 160 && mpp < 175, `expected ~168 m/px, got ${mpp}`);
  const ground = mpp * W;
  assert.ok(ground > 225000 && ground < 245000, `expected a ~234 km viewport, got ${ground}`);
  const hop = groundDistanceM(FRIESLAND.center, LIEGE_ROUTE.center);
  assert.ok(hop > 260000 && hop < 280000, `expected a ~269 km hop, got ${hop}`);
});

test('the hop the owner reported grey is a cut: it crosses more ground than is on screen', () => {
  assert.equal(hopIsNear(FRIESLAND, LIEGE_ROUTE), false);
});

test('the hops the owner called right stay animated', () => {
  // "When i now go back to groningen it does pan and zoom": 62 km inside a 234 km viewport.
  assert.equal(hopIsNear(FRIESLAND, GRONINGEN), true);
  // A pin in the town being read: the whole move is inside the current view.
  assert.equal(hopIsNear(FRIESLAND, { center: [5.8, 52.98], zoom: 14 }), true);
});

test('zooming in animates, because the tiles on screen scale up until the deeper ones land', () => {
  assert.equal(hopIsNear(FRIESLAND, { center: FRIESLAND.center, zoom: 15 }), true);
});

test('zooming far out cuts: it uncovers ground around the view, which has no tiles either', () => {
  assert.equal(hopIsNear(FRIESLAND, { center: FRIESLAND.center, zoom: 8 }), true, 'one level out is a view the tiles on screen still mostly fill');
  assert.equal(hopIsNear(FRIESLAND, { center: FRIESLAND.center, zoom: 6 }), false, 'three levels out is eight times the ground, all of it new');
});

test('nothing to judge on animates, rather than cutting on a guess', () => {
  assert.equal(hopIsNear(null, LIEGE_ROUTE), true);
  assert.equal(hopIsNear(FRIESLAND, null), true);
  assert.equal(hopIsNear({ center: [0, 0], zoom: NaN, width: W }, LIEGE_ROUTE), true);
  assert.equal(hopIsNear({ center: [0, 0], zoom: 9, width: 0 }, LIEGE_ROUTE), true);
});

test('the thresholds belong to the caller', () => {
  assert.equal(hopIsNear(FRIESLAND, LIEGE_ROUTE, { reach: 2 }), true, 'a wider reach animates the same hop');
  assert.equal(hopIsNear(FRIESLAND, GRONINGEN, { reach: 0.1 }), false);
});
