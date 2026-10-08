// SPDX-License-Identifier: AGPL-3.0-only
//
// The curator's measured-traffic layer (docs/specs/traffic-measurements.md §4.6):
// one colour per road piece, from the busier direction of the chosen group.
// Curators see a band (quiet, moderate, busy), never a number.
import test from 'node:test';
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { colourFor, perWay, TRAFFIC_COLOURS } from '../../assets/lib/traffic-colours.js';

const layer = readFileSync(new URL('../../assets/map/traffic-layer.js', import.meta.url), 'utf8');

test('each band has its colour', () => {
  assert.equal(colourFor('quiet'), TRAFFIC_COLOURS.quiet);
  assert.equal(colourFor('moderate'), TRAFFIC_COLOURS.moderate);
  assert.equal(colourFor('busy'), TRAFFIC_COLOURS.busy);
});

test('one entry per way for the chosen group, the busier direction winning', () => {
  const shown = [
    { way: 7, dir: 'f', label: 'r', group: 'workday', traffic: 'moderate', nearby: 'quiet', carSpeedBand: 60, days: '3-9' },
    { way: 7, dir: 'b', label: 'r', group: 'workday', traffic: 'busy', nearby: 'quiet', carSpeedBand: null, days: '3-9' },
    { way: 7, dir: 'f', label: 'r', group: 'weekend', traffic: 'quiet', nearby: 'quiet', carSpeedBand: null, days: '3-9' },
    { way: 9, dir: 'f', label: 'p', group: 'workday', traffic: 'quiet', nearby: 'busy', carSpeedBand: null, days: '10+' },
  ];
  const got = perWay(shown, 'workday');
  assert.deepEqual([...got.keys()].sort(), [7, 9]);
  assert.equal(got.get(7).traffic, 'busy');
  assert.equal(got.get(7).directions.length, 2, 'both directions stay available for the popup');
  assert.equal(perWay(shown, 'weekend').get(7).traffic, 'quiet');
  assert.equal(perWay(shown, 'weekend').has(9), false);
});

test('a cycle path\'s popup gives the band of the cars beside it as nearby, and colours by passing cars', () => {
  // No car passes a rider on a cycle path: it colours quiet, and the popup
  // names the cars on the road beside it as nearby (noise, not safety).
  assert.match(layer, /'p' === d\.label/);
  assert.match(layer, /trafficNearbyBand/);
  assert.match(layer, /d\.nearby/);
  const [, path] = [...perWay([{ way: 9, dir: 'f', label: 'p', group: 'all', traffic: 'quiet', nearby: 'busy' }], 'all')][0];
  assert.equal(colourFor(path.traffic), TRAFFIC_COLOURS.quiet);
});

test('the layer never shows a number of cars or riders', () => {
  assert.doesNotMatch(layer, /carsPerKm|nearbyPerKm|d\.riders/);
  assert.match(layer, /colourFor\(entry\.traffic\)/);
});
