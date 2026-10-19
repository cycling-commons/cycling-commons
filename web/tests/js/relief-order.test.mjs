// SPDX-License-Identifier: AGPL-3.0-only
//
// Where the relief shading sits (map-and-search.md section 4.2c, owner 2026-10-19: "Can we draw the hillshade/3d
// relief over the routes when on"): over our lines, the routes among them, and under the planned ride, the pins and
// the labels, which it would dim.
import test from 'node:test';
import assert from 'node:assert/strict';
import { shadeBeforeId } from '../../assets/map/relief-order.js';

const L = (id, type, source = 'ours') => ({ id, type, source });

test('the shading goes right above our last line, under what comes after it', () => {
  const layers = [
    L('background', 'background', null), L('park', 'fill', 'openmaptiles'), L('road_minor', 'line', 'openmaptiles'),
    L('cc-relief-shade', 'hillshade', 'cc-relief-shade-dem'),
    L('surface-cls-paved', 'line'), L('routes-net', 'line'), L('exp-route', 'line'),
    L('plan-casing', 'line'), L('plan-line', 'line'),
    L('water-pins', 'symbol'), L('place_city', 'symbol', 'openmaptiles'),
  ];
  assert.equal(shadeBeforeId(layers, 'cc-relief-shade'), 'plan-casing', 'over the routes, under the planned ride');
});

test('with no planned ride the shading stays under the first pin', () => {
  const layers = [L('road_minor', 'line', 'openmaptiles'), L('exp-route', 'line'), L('cc-relief-shade', 'hillshade', 'dem'), L('water-pins', 'symbol'), L('label', 'symbol', 'openmaptiles')];
  assert.equal(shadeBeforeId(layers, 'cc-relief-shade'), 'water-pins');
});

test('no line of ours: under the basemap labels, as before', () => {
  const layers = [L('road_minor', 'line', 'openmaptiles'), L('cc-relief-shade', 'hillshade', 'dem'), L('label', 'symbol', 'openmaptiles')];
  assert.equal(shadeBeforeId(layers, 'cc-relief-shade'), 'label');
});

test('our last line at the very top: the shading goes on top', () => {
  const layers = [L('label', 'symbol', 'openmaptiles'), L('exp-route', 'line'), L('cc-relief-shade', 'hillshade', 'dem')];
  assert.equal(shadeBeforeId(layers, 'cc-relief-shade'), undefined);
});

/* Found on dev, 2026-10-19: the region's outline and its shade sit over everything, the pins included; counted as
   "our last line" they put the shading over the pins. They, and the lit stretches a card or the planner draws, are
   never what the shading goes over. */
test('the region outline and the lit stretches do not count as the line the shading goes over', () => {
  const layers = [
    L('road_minor', 'line', 'openmaptiles'), L('exp-route', 'line'), L('water-pins', 'symbol'),
    L('route-roads-hl', 'line'), L('surface-sel', 'line'),
    L('region-mask', 'fill'), L('region-adj-line', 'line'), L('region-line', 'line'), L('cc-relief-shade', 'hillshade', 'dem'),
  ];
  assert.equal(shadeBeforeId(layers, 'cc-relief-shade'), 'water-pins');
});
