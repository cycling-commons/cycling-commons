// SPDX-License-Identifier: AGPL-3.0-only
//
// Pins on one spot fan out (docs/specs/map-and-search.md, Pins on one spot fan
// out): grouping by screen distance, seats on a ring or a spiral, and the
// offsets that move each tip onto its seat.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { FAN, compareKeys, groupByPixel, fanSeats, fanLayout } from '../../assets/map/fan-out.js';

const dist = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1]);

test('pins within 12 px of each other are one spot; farther ones are not', () => {
  // A:1 comes first by key, so it seeds the group.
  const pts = [
    {key: 'N:2', x: 100, y: 100},
    {key: 'A:1', x: 100, y: 100},
    {key: 'B:7', x: 108, y: 106},   // 10 px from A:1: joins
    {key: 'W:8', x: 113, y: 100},   // 13 px from A:1: on its own, though 8 px from B:7
  ];
  const groups = groupByPixel(pts).map(g => g.map(i => pts[i].key));
  assert.deepEqual(groups, [['A:1', 'B:7', 'N:2'], ['W:8']]);
});

test('a group never chains on through its members', () => {
  // Ten pins in a row, 10 px apart: chaining would make one group of ten.
  const pts = Array.from({length: 10}, (_, i) => ({key: 'C:' + i, x: i * 10, y: 0}));
  const groups = groupByPixel(pts).map(g => g.map(i => pts[i].key));
  assert.deepEqual(groups, [['C:0', 'C:1'], ['C:2', 'C:3'], ['C:4', 'C:5'], ['C:6', 'C:7'], ['C:8', 'C:9']]);
});

test('the grid finds neighbours across a cell edge', () => {
  // 11.9 and 12.1 fall in different 12 px cells.
  const pts = [{key: 'a', x: 11.9, y: 23.9}, {key: 'b', x: 12.1, y: 24.1}];
  assert.deepEqual(groupByPixel(pts), [[0, 1]]);
});

test('order is by key, numeric aware, whatever order the pins arrive in', () => {
  assert.ok(compareKeys('N:9', 'N:10') < 0);
  const a = [{key: 'N:10', x: 0, y: 0}, {key: 'N:9', x: 0, y: 0}];
  const b = [{key: 'N:9', x: 0, y: 0}, {key: 'N:10', x: 0, y: 0}];
  const la = fanLayout(a), lb = fanLayout(b);
  // N:9 takes the first seat (left) either way.
  assert.deepEqual(la[1], lb[0]);
  assert.deepEqual(la[0], lb[1]);
  assert.ok(la[1][0] < 0);
});

test('a single pin is left where it is', () => {
  assert.deepEqual(fanSeats(1), [[0, 0]]);
  assert.deepEqual(fanLayout([{key: 'N:1', x: 50, y: 50}]), [null]);
  assert.deepEqual(fanLayout([]), []);
});

test('a pair sits left and right at 28 px', () => {
  assert.deepEqual(fanSeats(2), [[-28, 0], [28, 0]]);
  // Grimsel and Susten: one exact foot, two pins.
  const pts = [{key: 'N:41', x: 400, y: 300}, {key: 'N:40', x: 400, y: 300}];
  assert.deepEqual(fanLayout(pts), [[28, 0], [-28, 0]]);
});

test('ring seats are evenly spaced, never closer than the spacing, never straight below', () => {
  for(let n = 2; n <= FAN.ringMax; n++){
    const seats = fanSeats(n);
    assert.equal(seats.length, n);
    const r = Math.hypot(...seats[0]);
    assert.ok(r >= FAN.minRadius - 0.1, `n=${n} radius ${r}`);
    seats.forEach(s => assert.ok(Math.abs(Math.hypot(...s) - r) < 0.2, `n=${n} all on one circle`));
    for(let i = 0; i < n; i++){
      const d = dist(seats[i], seats[(i + 1) % n]);
      assert.ok(d >= FAN.spacing - 0.2, `n=${n} neighbours ${d} apart`);
    }
    // A pin's body (34 px wide) rises from its tip; a tip below the centre
    // must stand at least half a pin to the side, or it hides the point.
    seats.forEach(([x, y]) => { if(y > 0) assert.ok(Math.abs(x) > 17, `n=${n} seat ${x},${y} hides the point`); });
  }
  // Three: top, lower right, lower left.
  const [t, lr, ll] = fanSeats(3);
  assert.ok(t[1] < 0 && Math.abs(t[0]) < 0.1);
  assert.ok(lr[0] > 0 && lr[1] > 0);
  assert.ok(ll[0] < 0 && ll[1] > 0);
});

test('a big group follows a spiral out from the top', () => {
  const n = FAN.ringMax + 4;
  const seats = fanSeats(n);
  assert.equal(seats.length, n);
  assert.ok(Math.abs(seats[0][0]) < 0.1 && seats[0][1] === -FAN.spiralStart);
  const radii = seats.map(s => Math.hypot(...s));
  for(let i = 1; i < n; i++) assert.ok(radii[i] > radii[i - 1], 'radius grows');
  for(let i = 1; i < n; i++) assert.ok(dist(seats[i], seats[i - 1]) >= FAN.spacing - 4, 'neighbours keep apart');
  // No two seats anywhere on the spiral overlap.
  for(let i = 0; i < n; i++) for(let j = i + 1; j < n; j++) assert.ok(dist(seats[i], seats[j]) >= 30);
});

test('offsets move each tip onto its seat around the group centre', () => {
  // Three pins a few pixels apart: the ring centres on their mean.
  const pts = [{key: 'a', x: 100, y: 100}, {key: 'b', x: 106, y: 100}, {key: 'c', x: 103, y: 106}];
  const out = fanLayout(pts);
  const seats = fanSeats(3);
  const cx = 103, cy = 102;
  out.forEach((o, i) => {
    assert.ok(Math.abs(pts[i].x + o[0] - (cx + seats[i][0])) <= 0.5);
    assert.ok(Math.abs(pts[i].y + o[1] - (cy + seats[i][1])) <= 0.5);
  });
});

test('a pile over the cap stays as drawn', () => {
  const pts = Array.from({length: FAN.maxGroup + 1}, (_, i) => ({key: 'k' + i, x: 0, y: 0}));
  assert.ok(fanLayout(pts).every(o => o === null));
  const fits = pts.slice(0, FAN.maxGroup);
  assert.ok(fanLayout(fits).every(o => Array.isArray(o)));
});

test('the same input gives the same layout', () => {
  const pts = Array.from({length: 7}, (_, i) => ({key: 'W:' + (i * 3), x: 200 + (i % 2), y: 200}));
  assert.deepEqual(fanLayout(pts), fanLayout(pts.slice().reverse()).reverse());
});
