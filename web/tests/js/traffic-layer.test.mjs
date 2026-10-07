// SPDX-License-Identifier: AGPL-3.0-only
//
// The curator's measured-traffic layer (docs/specs/traffic-measurements.md §4.6):
// one colour per road piece, from the busier direction of the chosen group.
import test from 'node:test';
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { colourFor, perWay, TRAFFIC_COLOURS } from '../../assets/lib/traffic-colours.js';

test('quiet, moderate and busy bands', () => {
  assert.equal(colourFor(0.4), TRAFFIC_COLOURS.quiet);
  assert.equal(colourFor(0.99), TRAFFIC_COLOURS.quiet);
  assert.equal(colourFor(1), TRAFFIC_COLOURS.moderate);
  assert.equal(colourFor(2.99), TRAFFIC_COLOURS.moderate);
  assert.equal(colourFor(3), TRAFFIC_COLOURS.busy);
});

test('one entry per way for the chosen group, the busier direction winning', () => {
  const shown = [
    { way: 7, dir: 'f', label: 'r', group: 'workday', carsPerKm: 1.5, carSpeedBand: 60, riders: '5-9', days: '3-9' },
    { way: 7, dir: 'b', label: 'r', group: 'workday', carsPerKm: 3.5, carSpeedBand: null, riders: '5-9', days: '3-9' },
    { way: 7, dir: 'f', label: 'r', group: 'weekend', carsPerKm: 0.5, carSpeedBand: null, riders: '5-9', days: '3-9' },
    { way: 9, dir: 'f', label: 'p', group: 'workday', carsPerKm: 0.2, carSpeedBand: null, riders: '10-19', days: '10+' },
  ];
  const got = perWay(shown, 'workday');
  assert.deepEqual([...got.keys()].sort(), [7, 9]);
  assert.equal(got.get(7).carsPerKm, 3.5);
  assert.equal(got.get(7).directions.length, 2, 'both directions stay available for the popup');
  assert.equal(perWay(shown, 'weekend').get(7).carsPerKm, 0.5);
  assert.equal(perWay(shown, 'weekend').has(9), false);
});

test('a cycle path\'s popup gives the cars beside it as nearby, and colours by passing cars', () => {
  // No car passes a rider on a cycle path: it colours quiet, and the popup
  // names the cars on the road beside it as nearby (noise, not safety).
  const layer = readFileSync(new URL('../../assets/map/traffic-layer.js', import.meta.url), 'utf8');
  assert.match(layer, /'p' === d\.label/);
  assert.match(layer, /trafficNearbyPerKm/);
  assert.match(layer, /d\.nearbyPerKm/);
  const [, path] = [...perWay([{ way: 9, dir: 'f', label: 'p', group: 'all', carsPerKm: 0, nearbyPerKm: 12.5 }], 'all')][0];
  assert.equal(colourFor(path.carsPerKm), TRAFFIC_COLOURS.quiet);
});
