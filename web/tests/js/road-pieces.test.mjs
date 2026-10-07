// SPDX-License-Identifier: AGPL-3.0-only
//
// Road pieces in the browser (docs/specs/traffic-measurements.md §2): which
// z14 tiles a ride touches, and the lines a road-piece tile holds. The
// fixture is a real tile built by the pipeline's tippecanoe call from three
// pieces near 5.00 E 52.00 N: way 100 (shared road, sidepath flag), way 200
// (cycle path) and way 4294967999 (cycle lane, an id past 32 bits).
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { gunzipSync } from 'node:zlib';
import { tilesForTrack, decodePieces, loadPieces } from '../../assets/lib/road-pieces.js';

const TILE = gunzipSync(readFileSync(new URL('./fixtures/roadpieces-14-8419-5411.mvt', import.meta.url)));

test('a ride inside one tile needs that tile only', () => {
  assert.deepEqual(tilesForTrack([[5.001, 52.001], [5.002, 52.003]]), [{ z: 14, x: 8419, y: 5411 }]);
});

test('a ride across a tile edge needs both tiles, once each', () => {
  // x 8419 spans 4.9878..5.0098 E at z14.
  const got = tilesForTrack([[5.005, 52.001], [5.012, 52.001], [5.013, 52.001]]);
  assert.deepEqual(got.map(t => t.x).sort(), [8419, 8420]);
});

test('a long straight leg also needs the tiles it passes between two points', () => {
  const got = tilesForTrack([[5.000, 52.001], [5.050, 52.001]]);
  assert.deepEqual(got.map(t => t.x).sort(), [8419, 8420, 8421]);
});

test('every piece in the tile comes back with its way id, label and flag', () => {
  const pieces = decodePieces(TILE, 14, 8419, 5411);
  const byId = new Map(pieces.map(p => [p.id, p]));
  assert.deepEqual([...byId.keys()].sort((a, b) => a - b), [100, 200, 4294967999]);
  assert.equal(byId.get(100).label, 'r');
  assert.equal(byId.get(100).side, true);
  assert.equal(byId.get(200).label, 'p');
  assert.equal(byId.get(200).side, false);
  assert.equal(byId.get(4294967999).label, 'l');
  assert.equal(byId.get(4294967999).highway, 'tertiary');
});

test('coordinates come back as lon/lat within a few metres of the source', () => {
  const road = decodePieces(TILE, 14, 8419, 5411).find(p => p.id === 100);
  assert.equal(road.coords.length, 3);
  const [lon, lat] = road.coords[0];
  assert.ok(Math.abs(lon - 5.0010) < 0.00005, `lon ${lon}`);
  assert.ok(Math.abs(lat - 52.0010) < 0.00005, `lat ${lat}`);
});

test('a layer that is not a road-piece layer is ignored', () => {
  assert.deepEqual(decodePieces(new Uint8Array(0), 14, 8419, 5411), []);
});

const ENTRIES = {
  nl: { tiles: { roadpieces: 'https://t/nl.pmtiles' }, bounds: [3.3, 50.7, 7.2, 53.6] },
  be: { tiles: { roadpieces: 'https://t/be.pmtiles' }, bounds: [2.5, 49.4, 6.4, 51.5] },
};

test('each tile is read from the country archive whose bounds hold it', async () => {
  const asked = [];
  const fetchTile = async (url, z, x, y) => { asked.push([url, z, x, y]); return TILE; };
  const got = await loadPieces([[5.001, 52.001], [5.002, 52.003]], ENTRIES, fetchTile);
  assert.deepEqual(asked, [['https://t/nl.pmtiles', 14, 8419, 5411]]);
  assert.equal(got.missingTiles, 0);
  assert.ok(got.pieces.some(p => p.id === 200));
  assert.ok(got.pieces.every(p => p.cc === 'nl'), 'each piece knows its country, for holidays and the clock');
});

test('a tile no archive covers is counted, never guessed', async () => {
  const got = await loadPieces([[13.4, 52.5]], ENTRIES, async () => TILE);
  assert.equal(got.pieces.length, 0);
  assert.equal(got.missingTiles, 1);
});

test('a tile that fails to load counts as missing and the rest still load', async () => {
  const fetchTile = async (url, z, x) => { if (x === 8420) throw new Error('offline'); return TILE; };
  const got = await loadPieces([[5.005, 52.001], [5.012, 52.001]], ENTRIES, fetchTile);
  assert.equal(got.missingTiles, 1);
  assert.ok(got.pieces.length > 0);
});

test('a tile read once is not fetched again for the next ride', async () => {
  let fetched = 0;
  const fetchTile = async () => { fetched++; return TILE; };
  await loadPieces([[5.001, 52.001]], ENTRIES, fetchTile);
  const again = await loadPieces([[5.002, 52.002]], ENTRIES, fetchTile);
  assert.equal(fetched, 1);
  assert.ok(again.pieces.some(p => p.id === 100));
});

test('where two country archives overlap, the tile is read from both', async () => {
  const overlap = {
    nl: { tiles: { roadpieces: 'https://t/nl.pmtiles' }, bounds: [3.3, 50.7, 7.2, 53.6] },
    be: { tiles: { roadpieces: 'https://t/be.pmtiles' }, bounds: [2.5, 49.4, 6.4, 52.5] },
  };
  const asked = [];
  const got = await loadPieces([[5.001, 52.001]], overlap, async url => { asked.push(url); return TILE; });
  assert.deepEqual(asked.sort(), ['https://t/be.pmtiles', 'https://t/nl.pmtiles']);
  assert.deepEqual([...new Set(got.pieces.map(p => p.cc))].sort(), ['be', 'nl']);
  assert.equal(got.missingTiles, 0);
});

test('a piece carries the region its tile names, and none when the tile names none', () => {
  // Source: fixtures/roadpieces-14-8419-5411.geojson, encoded with the build's tippecanoe flags.
  const byId = new Map(decodePieces(TILE, 14, 8419, 5411).map(p => [p.id, p]));
  assert.equal(byId.get(100).region, 7);
  assert.equal(byId.get(200).region, 9);
  assert.equal(byId.get(4294967999).region, null);
});
