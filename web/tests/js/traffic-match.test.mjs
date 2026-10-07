// SPDX-License-Identifier: AGPL-3.0-only
//
// Matching a ride to road pieces (docs/specs/traffic-measurements.md §3.3).
// Geometry is laid out in metres around 52 N 5 E and converted to degrees, so
// each case reads as a street plan: "a cycle path 3 m west of the ride".
import test from 'node:test';
import assert from 'node:assert/strict';
import { matchRide, riddenParts } from '../../assets/lib/traffic-match.js';

const LAT0 = 52;
const LON0 = 5;
const M_LAT = 1 / 111_320;
const M_LON = 1 / (111_320 * Math.cos(LAT0 * Math.PI / 180));
const ll = (xm, ym) => [LON0 + xm * M_LON, LAT0 + ym * M_LAT];

function piece(id, label, pts, side = false) {
  return { id, label, side, highway: label === 'p' ? 'cycleway' : 'secondary', coords: pts.map(([x, y]) => ll(x, y)) };
}

// A ride north along x = xm from y0 to y1, one fix every `stepM` metres at 18 km/h (5 m/s).
function ride(xm, y0, y1, stepM = 10) {
  const out = [];
  const dir = y1 >= y0 ? 1 : -1;
  for (let y = y0, t = 0; dir > 0 ? y <= y1 : y >= y1; y += dir * stepM, t += stepM / 5) {
    const [lon, lat] = ll(xm, y);
    out.push({ t, lat, lon, kmh: 18 });
  }
  return out;
}

test('a ride along one road is placed on it, direction with the drawing', () => {
  const road = piece(1, 'r', [[0, 0], [0, 1000]]);
  const got = matchRide(ride(1, 0, 800), [road]);
  assert.ok(got.every(m => m.way === 1 && m.label === 'r' && m.dir === 'f'));
});

test('a match carries the country of its piece', () => {
  const road = { ...piece(1, 'r', [[0, 0], [0, 1000]]), cc: 'be' };
  const got = matchRide(ride(1, 0, 300), [road]);
  assert.ok(got.every(m => m.cc === 'be'));
});

test('the same road ridden the other way is direction b', () => {
  const road = piece(1, 'r', [[0, 0], [0, 1000]]);
  const got = matchRide(ride(1, 800, 0), [road]);
  assert.ok(got.every(m => m.way === 1 && m.dir === 'b'));
});

test('a cycle path beside a road that says it has one wins over the road', () => {
  // Path 3 m east of the ride, road 6 m west of it: the road is flagged s=1.
  const road = piece(1, 'r', [[-6, 0], [-6, 1000]], true);
  const path = piece(2, 'p', [[3, 0], [3, 1000]]);
  const got = matchRide(ride(0, 0, 800), [road, path]);
  assert.ok(got.every(m => m.way === 2 && m.label === 'p'), JSON.stringify(got.slice(0, 3)));
});

test('even a ride slightly closer to the flagged road stays on the path', () => {
  const road = piece(1, 'r', [[-4, 0], [-4, 1000]], true);
  const path = piece(2, 'p', [[5, 0], [5, 1000]]);
  const got = matchRide(ride(0, 0, 800), [road, path]);
  assert.ok(got.every(m => m.way === 2));
});

test('one jittery fix onto a parallel road does not switch the piece', () => {
  const a = piece(1, 'r', [[0, 0], [0, 1000]]);
  const b = piece(2, 'r', [[10, 0], [10, 1000]]);
  const recs = ride(0, 0, 800);
  const [lon, lat] = ll(9, 400);
  recs[40] = { ...recs[40], lon, lat };
  const got = matchRide(recs, [a, b]);
  assert.ok(got.every(m => m.way === 1), `fix 40 matched ${got[40].way}`);
});

test('a crossing street is not taken where the ride passes over it', () => {
  const a = piece(1, 'r', [[0, 0], [0, 1000]]);
  const cross = piece(3, 'r', [[-500, 400], [500, 400]]);
  const got = matchRide(ride(0, 0, 800), [a, cross]);
  assert.ok(got.every(m => m.way === 1));
});

test('a fix far from every piece is unmatched', () => {
  const a = piece(1, 'r', [[0, 0], [0, 1000]]);
  const got = matchRide(ride(40, 0, 300), [a]);
  assert.ok(got.every(m => m.way === null && m.dir === null));
});

test('a turn from one road onto another hands over at the corner', () => {
  const a = piece(1, 'r', [[0, 0], [0, 500]]);
  const b = piece(2, 'l', [[0, 500], [600, 500]]);
  const recs = [...ride(0, 0, 500)];
  let t = recs[recs.length - 1].t;
  for (let x = 10; x <= 500; x += 10) { t += 2; const [lon, lat] = ll(x, 500); recs.push({ t, lat, lon, kmh: 18 }); }
  const got = matchRide(recs, [a, b]);
  assert.equal(got[10].way, 1);
  assert.equal(got[got.length - 5].way, 2);
  assert.equal(got[got.length - 5].dir, 'f');
});

test('a record without a position is unmatched and does not break the run', () => {
  const a = piece(1, 'r', [[0, 0], [0, 1000]]);
  const recs = ride(0, 0, 500);
  recs[20] = { t: recs[20].t, lat: null, lon: null, kmh: 18 };
  const got = matchRide(recs, [a]);
  assert.equal(got[20].way, null);
  assert.equal(got[19].way, 1);
  assert.equal(got[21].way, 1);
});

test('out and back on one road gets one direction per leg', () => {
  const road = piece(1, 'r', [[0, 0], [0, 1000]]);
  const recs = [...ride(1, 0, 400), ...ride(1, 390, 0)];
  recs.forEach((r, i) => { r.t = i * 2; });
  const got = matchRide(recs, [road]);
  assert.equal(got[10].dir, 'f');
  assert.equal(got[got.length - 10].dir, 'b');
});

test('a lane road that says its cycle path is drawn apart also yields to the path', () => {
  const road = piece(1, 'l', [[-4, 0], [-4, 1000]], true);
  const path = piece(2, 'p', [[5, 0], [5, 1000]]);
  const got = matchRide(ride(0, 0, 800), [road, path]);
  assert.ok(got.every(m => m.way === 2));
});

test('a road cut into short pieces at every side street stays matched, piece by piece', () => {
  // OSM splits a village street at each junction; pieces of 60 m are real road.
  const a = piece(1, 'r', [[0, 0], [0, 60]]);
  const b = piece(2, 'r', [[0, 60], [0, 120]]);
  const c = piece(3, 'r', [[0, 120], [0, 180]]);
  const d = piece(4, 'r', [[0, 180], [0, 600]]);
  const got = matchRide(ride(1, 0, 500), [a, b, c, d]);
  assert.deepEqual(got.filter(m => m.way === null), [], 'nothing unmatched');
  assert.equal(got[3].way, 1);
  assert.equal(got[9].way, 2);
  assert.equal(got[15].way, 3);
  assert.equal(got[30].way, 4);
});

test('a short crossing between two paths is ridden, not dropped', () => {
  // A cycle path, 30 m over a main road on its own piece, then the next path.
  const before = piece(1, 'p', [[0, 0], [0, 300]]);
  const crossing = piece(2, 'p', [[0, 300], [0, 330]]);
  const after = piece(3, 'p', [[0, 330], [0, 700]]);
  const got = matchRide(ride(1, 0, 600), [before, crossing, after]);
  assert.deepEqual(got.filter(m => m.way === null), [], 'nothing unmatched');
  assert.equal(got[31].way, 2);
});


// Back to metres, for reading the drawn parts as a street plan.
const back = ([lon, lat]) => [(lon - LON0) / M_LON, (lat - LAT0) / M_LAT];

test('only the part of a road the ride was on is drawn, not the whole way', () => {
  // A 1 km road; the ride covers 100-400 m of it.
  const road = piece(1, 'r', [[0, 0], [0, 1000]]);
  const recs = ride(1, 100, 400);
  const parts = riddenParts(recs, [road], matchRide(recs, [road]));
  assert.equal(parts.length, 1);
  assert.equal(parts[0].id, 1);
  assert.equal(parts[0].label, 'r');
  const ys = parts[0].coords.map(c => back(c)[1]);
  assert.ok(Math.abs(Math.min(...ys) - 90) < 2, `starts at ${Math.min(...ys)}`);
  assert.ok(Math.abs(Math.max(...ys) - 410) < 2, `ends at ${Math.max(...ys)}`);
});

test('a road turned off halfway is drawn up to the turn, not beyond it', () => {
  const a = piece(1, 'r', [[0, 0], [0, 1000]]);
  const b = piece(2, 'p', [[0, 500], [600, 500]]);
  const recs = [...ride(0, 0, 500)];
  let t = recs[recs.length - 1].t;
  for (let x = 10; x <= 500; x += 10) { t += 2; const [lon, lat] = ll(x, 500); recs.push({ t, lat, lon, kmh: 18 }); }
  const parts = riddenParts(recs, [a, b], matchRide(recs, [a, b]));
  const onA = parts.filter(p => p.id === 1).flatMap(p => p.coords.map(c => back(c)[1]));
  assert.ok(Math.max(...onA) < 520, `road 1 drawn to ${Math.max(...onA)}`);
  const onB = parts.filter(p => p.id === 2).flatMap(p => p.coords.map(c => back(c)[0]));
  assert.ok(Math.max(...onB) < 520, `path 2 drawn to ${Math.max(...onB)}`);
});

test('a bend inside the ridden part keeps its shape', () => {
  const road = piece(1, 'r', [[0, 0], [0, 200], [200, 200]]);
  const recs = [...ride(0, 50, 200)];
  let t = recs[recs.length - 1].t;
  for (let x = 10; x <= 150; x += 10) { t += 2; const [lon, lat] = ll(x, 200); recs.push({ t, lat, lon, kmh: 18 }); }
  const [part] = riddenParts(recs, [road], matchRide(recs, [road]));
  const pts = part.coords.map(back);
  assert.ok(pts.some(([x, y]) => Math.abs(x) < 1 && Math.abs(y - 200) < 1), 'the corner vertex is kept');
  assert.ok(Math.abs(pts[pts.length - 1][0] - 160) < 2, `ends at x ${pts[pts.length - 1][0]}`);
});
