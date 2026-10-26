// SPDX-License-Identifier: AGPL-3.0-only
//
// The planner's gravel hints (docs/specs/route-planner.md section 4.2b): which gravel near a leg is worth asking the
// engine to ride, and the line reader that takes it from the road-surface tiles. A synthetic leg 10 km due east at
// 52 degrees north, with gravel laid beside it.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { gunzipSync } from 'node:zlib';
import { gravelCandidates, gravelNetworks, seeksUnpaved, corridorM, corridorTiles, surfaceLinesNear, deadEnds, GRAVEL_HINTS } from '../../assets/map/plan-gravel.js';
import { decodeLines, decodePieces } from '../../assets/lib/road-pieces.js';

const LAT = 52;
const KX = 111320 * Math.cos(LAT * Math.PI / 180);
/* A point x metres east of 5.0 E and y metres north of 52 N. */
const at = (x, y) => [5 + x / KX, LAT + y / 111320];
const line = (cls, from, to, steps = 10) => ({ props: { cls }, coords: Array.from({ length: steps + 1 }, (_, i) => at(from[0] + (to[0] - from[0]) * i / steps, from[1] + (to[1] - from[1]) * i / steps)) });
const BASE = Array.from({ length: 21 }, (_, i) => at(i * 500, 0));   // 10 km east

test('only a gravel or a mountain bike seeks gravel', () => {
  assert.ok(seeksUnpaved('Gravel'));
  assert.ok(seeksUnpaved('MTB'));
  for (const bike of ['Road', 'E-bike', 'Handbike', 'Recumbent', 'Trike', 'Tandem', 'constructor']) assert.equal(seeksUnpaved(bike), false, bike);
  assert.deepEqual(gravelCandidates(BASE, [line('gravel', [2000, 500], [7000, 500])], 'Road'), []);
});

test('the corridor is 12% of the leg, between 800 m and 3 km', () => {
  assert.equal(corridorM(5000), 800);
  assert.equal(corridorM(10000), 1200);
  assert.equal(corridorM(60000), 3000);
});

test('lines whose ends meet are one network; another class or a far line is not joined', () => {
  const nets = gravelNetworks([
    line('gravel', [2000, 500], [4000, 500]),
    line('dirt', [4000, 500], [7000, 500]),
    line('paved', [7000, 500], [9000, 500]),
    line('gravel', [2000, 3000], [3000, 3000]),
  ], 'Gravel', LAT);
  const lengths = nets.map(n => Math.round(n.length)).sort((a, b) => b - a);
  assert.deepEqual(lengths, [5000, 1000], 'gravel and dirt join for a gravel bike; paved is left out');
  assert.equal(gravelNetworks([line('rock', [0, 0], [1000, 0])], 'Gravel', LAT).length, 0, 'rock is not for a gravel bike');
  assert.equal(gravelNetworks([line('rock', [0, 0], [1000, 0])], 'MTB', LAT).length, 1, 'but it is for a mountain bike');
});

test('gravel beside the way gives through points on it, in the direction of the leg', () => {
  const sets = gravelCandidates(BASE, [line('gravel', [2000, 500], [4000, 500]), line('gravel', [4000, 500], [7000, 500])], 'Gravel');
  assert.ok(sets.length >= 1);
  const vias = sets[0];
  assert.ok(vias.length >= 2 && vias.length <= GRAVEL_HINTS.maxVias);
  for (const v of vias) assert.ok(Math.abs((v[1] - LAT) * 111320 - 500) < 1, 'every point lies on the gravel, 500 m north');
  for (let i = 1; i < vias.length; i++) assert.ok(vias[i][0] > vias[i - 1][0], 'eastward, as the leg goes');
});

test('gravel not worth a detour gives nothing', () => {
  // Each alone: two of them would meet, and lines that meet are one network.
  for (const [why, l] of [
    ['400 m: too short', line('gravel', [3000, 400], [3400, 400])],
    ['5 km off the way: outside the 1.2 km corridor', line('gravel', [2000, 5000], [8000, 5000])],
    ['crosses the way rather than following it', line('gravel', [5000, -3000], [5000, 3000])],
    ['the way already rides it', line('gravel', [2000, 10], [8000, 10])],
  ]) assert.deepEqual(gravelCandidates(BASE, [l], 'Gravel'), [], why);
});

test('a track that ends on the middle of another joins its network (a T-junction)', () => {
  const nets = gravelNetworks([line('gravel', [0, 0], [2000, 0]), line('gravel', [1000, 5], [1000, 800])], 'Gravel', LAT);
  assert.equal(nets.length, 1);
  assert.equal(Math.round(nets[0].length), 2795);
});

test('a long straight track beside the way counts, though only its two ends are in the tile', () => {
  // Two points, 4.8 km apart, 700 m north of the way: no corner of it is near the way's middle.
  const sets = gravelCandidates(BASE, [{ props: { cls: 'gravel' }, coords: [at(2600, 700), at(7400, 700)] }], 'Gravel');
  assert.ok(sets.length >= 1);
  assert.ok(sets[0].some(v => v[0] > at(4000, 0)[0] && v[0] < at(6000, 0)[0]), 'a point in the middle of the track');
});

test('two stretches one after the other are also asked together, after the best alone', () => {
  const sets = gravelCandidates(BASE, [line('gravel', [1000, 400], [4000, 400]), line('gravel', [5500, -400], [9000, -400])], 'Gravel');
  assert.ok(sets.length >= 3, 'each alone and both together');
  const both = sets.find(s => s.some(v => v[1] > LAT) && s.some(v => v[1] < LAT));
  assert.ok(both, 'a set with points on both stretches');
  for (let i = 1; i < both.length; i++) assert.ok(both[i][0] > both[i - 1][0], 'the first stretch before the second');
  assert.ok(sets.length <= GRAVEL_HINTS.maxCandidates);
});

test('a leg too short or too long to bother is left alone', () => {
  const g = [line('gravel', [100, 300], [800, 300])];
  assert.deepEqual(gravelCandidates([at(0, 0), at(900, 0)], g, 'Gravel'), [], 'under a kilometre');
  assert.deepEqual(gravelCandidates([at(0, 0), at(90000, 0)], [line('gravel', [10000, 500], [30000, 500])], 'Gravel'), [], 'over 80 km');
});

test('the tiles read are the ones the way crosses and a corridor to either side', () => {
  const tiles = corridorTiles(BASE, 10000);
  assert.ok(tiles.length >= 1);
  assert.ok(tiles.every(t => t.z === GRAVEL_HINTS.zoom));
  assert.equal(new Set(tiles.map(t => `${t.x}/${t.y}`)).size, tiles.length, 'each tile once');
});

test('the surface lines are read from the archive whose bounds hold the tile, once', async () => {
  const entries = { nl: { tiles: { classified: 'nl.pmtiles' }, bounds: [3, 50, 8, 54] }, be: { tiles: { classified: 'be.pmtiles' }, bounds: [2, 49, 3, 50] } };
  const asked = [];
  const fetchTile = async (url) => { asked.push(url); return null; };
  await surfaceLinesNear(BASE, 10000, entries, fetchTile);
  assert.ok(asked.length >= 1 && asked.every(u => u === 'nl.pmtiles'));
  const n = asked.length;
  await surfaceLinesNear(BASE, 10000, entries, fetchTile);
  assert.equal(asked.length, n, 'a tile read once is not fetched again');
});

test('the line reader reads any line layer, with its properties, as the road-piece reader does', () => {
  const TILE = gunzipSync(readFileSync(new URL('./fixtures/roadpieces-14-8419-5411.mvt', import.meta.url)));
  const lines = decodeLines(TILE, 14, 8419, 5411, 'roadpieces_');
  const pieces = decodePieces(TILE, 14, 8419, 5411);
  assert.equal(lines.length, pieces.length);
  assert.deepEqual(lines[0].coords, pieces[0].coords);
  assert.equal(lines[0].props.l, pieces[0].label);
  assert.equal(decodeLines(TILE, 14, 8419, 5411, 'surface_').length, 0, 'another layer prefix reads nothing');
});

test('a line end that touches no other road is a dead end; one on a paved road or mid-way along a track is not', () => {
  const ends = deadEnds([
    line('gravel', [0, 0], [2000, 0]),         // its west end touches nothing, its east end meets the paved road
    line('paved', [2000, -500], [2000, 500]),
    line('dirt', [1000, 5], [1000, 600]),       // ends on the middle of the gravel track: a T-junction, not a dead end there
  ], LAT);
  // West end of the gravel, the paved road's two ends, and the dirt spur's far end.
  assert.equal(ends.length, 4);
});

test('no through-point is put near a dead end: the leg would ride down the track and back', () => {
  // A dead-end spur 600 m north of the way, and a through track 700 m south of it, both 4 km long.
  const spur = line('gravel', [3000, 600], [7000, 600]);
  const through = [line('gravel', [2500, -700], [7500, -700]), line('paved', [2500, -700], [2500, 0]), line('paved', [7500, -700], [7500, 0])];
  for (const vias of gravelCandidates(BASE, [spur, ...through], 'Gravel')) {
    for (const v of vias) {
      if (v[1] > LAT) {
        const x = (v[0] - 5) * KX;
        assert.ok(x > 3150 && x < 6850, `a point on the spur stays 150 m from its ends, at ${Math.round(x)} m`);
      }
    }
  }
});

