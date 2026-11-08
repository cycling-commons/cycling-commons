// SPDX-License-Identifier: AGPL-3.0-only
//
// The basemap's names over our lines (map-and-search.md section 4.1): a road surface, route or road-type line drawn
// over a road name made the name unreadable. Our lines go under the basemap's first name layer; the planned ride,
// the region outline and a lit stretch stay over the names, and so does every icon (a symbol, never moved).
import test from 'node:test';
import assert from 'node:assert/strict';
import { linesUnderNames } from '../../assets/map/names-order.js';

const L = (id, type, source = 'ours') => ({ id, type, source });
const BASE = [
  L('background', 'background', null), L('road_minor', 'line', 'openmaptiles'),
  L('road_one_way_arrow', 'symbol', 'openmaptiles'), L('cc-cycleways', 'line', 'openmaptiles'),
  L('bridge_street', 'line', 'openmaptiles'), L('building', 'fill', 'openmaptiles'),
];

test('our lines above the names go right under the first name layer, in their own order', () => {
  const layers = [...BASE, L('waterway_line_label', 'symbol', 'openmaptiles'), L('highway-name-minor', 'symbol', 'openmaptiles'),
    L('surftile-paved', 'line', 'surface-nl'), L('routes-net', 'line', 'routes-nl'), L('roadpieces-nl-safety', 'line', 'roadpieces-nl')];
  assert.deepEqual(linesUnderNames(layers), {
    before: 'waterway_line_label', ids: ['surftile-paved', 'routes-net', 'roadpieces-nl-safety'] });
});

test('a symbol of the basemap among its roads (the one-way arrows) is not the name layer', () => {
  const layers = [...BASE, L('label_city', 'symbol', 'openmaptiles'), L('surface-cls-paved', 'line')];
  assert.equal(linesUnderNames(layers).before, 'label_city');
});

test('the planned ride, a ride under review, the region outline and lit stretches stay over the names', () => {
  const layers = [...BASE, L('label_city', 'symbol', 'openmaptiles'),
    L('plan-casing', 'line'), L('plan-line', 'line'), L('region-line', 'line'), L('trail-hl', 'line'), L('surface-sel', 'line'),
    L('ridecheck-case', 'line'), L('ridecheck', 'line'), L('cc-scout-ride-line', 'line'), L('cc-scout-pieces-line', 'line')];
  assert.deepEqual(linesUnderNames(layers).ids, []);
});

test('icons and fills of ours are never moved', () => {
  const layers = [...BASE, L('label_city', 'symbol', 'openmaptiles'), L('water-nl-cov', 'symbol'), L('region-mask', 'fill'),
    L('rideheat', 'heatmap')];
  assert.deepEqual(linesUnderNames(layers).ids, []);
});

test('our lines already under the names stay put', () => {
  const layers = [...BASE, L('surftile-paved', 'line', 'surface-nl'), L('label_city', 'symbol', 'openmaptiles')];
  assert.deepEqual(linesUnderNames(layers).ids, []);
});

test('no basemap names (a style still loading): nothing moves', () => {
  assert.deepEqual(linesUnderNames([...BASE, L('surftile-paved', 'line', 'surface-nl')]), { before: undefined, ids: [] });
});

test('moveLinesUnderNamesOn() moves each line on the map before the name layer, and nothing when in place', async () => {
  const { moveLinesUnderNamesOn } = await import('../../assets/map/names-order.js');
  const order = ['road_minor', 'label_city', 'seg', 'plan-line'];
  const kind = { road_minor: ['line', 'openmaptiles'], label_city: ['symbol', 'openmaptiles'], seg: ['line', 'seg'], 'plan-line': ['line', 'plan'] };
  const moves = [];
  const m = {
    getLayersOrder: () => order.slice(),
    getLayer: id => ({ id, type: kind[id][0], source: kind[id][1] }),
    moveLayer: (id, before) => { moves.push([id, before]); order.splice(order.indexOf(id), 1); order.splice(order.indexOf(before), 0, id); },
  };
  assert.equal(moveLinesUnderNamesOn(m), 1);
  assert.deepEqual(moves, [['seg', 'label_city']]);
  assert.deepEqual(order, ['road_minor', 'seg', 'label_city', 'plan-line']);
  assert.equal(moveLinesUnderNamesOn(m), 0, 'a second run moves nothing');
  assert.equal(moveLinesUnderNamesOn({ getLayer: () => null }), 0, 'a map without getLayersOrder');
});

test('road names carry a solid white halo and darker text, so they read over a coloured line', async () => {
  const { LABEL_PAINT } = await import('../../assets/map/basemap-tone.js');
  for (const id of ['highway-name-path', 'highway-name-minor', 'highway-name-major']) {
    assert.equal(LABEL_PAINT[id]['text-halo-color'], '#ffffff', id);
    assert.ok(LABEL_PAINT[id]['text-halo-width'] >= 1.5, id);
    assert.ok(LABEL_PAINT[id]['text-color'], id);
  }
});

test('the road editor puts its lines under the names after every style change', async () => {
  const { readFileSync } = await import('node:fs');
  const tpl = readFileSync(new URL('../../templates/contribute/improve.html.twig', import.meta.url), 'utf8');
  const js = readFileSync(new URL('../../assets/contribute/improve.js', import.meta.url), 'utf8');
  assert.match(tpl, /import \{ moveLinesUnderNamesOn \} from "\{\{ asset\('map\/names-order\.js'\) \}\}";/);
  assert.match(tpl, /window\.CcLinesUnderNames = moveLinesUnderNamesOn;/);
  assert.match(js, /if \(window\.CcLinesUnderNames\) window\.CcLinesUnderNames\(wmap\);/);
});
