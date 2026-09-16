// SPDX-License-Identifier: AGPL-3.0-only
//
// route-climbs.js: "Climbs on this route" in the route drawer
// (docs/specs/map-and-search.md §6.3). The server lists the climbs the route
// rides, in order; each row reads "Name · km 112 · ▲ 8.7% avg" in the rider's
// unit, and a route with no climbs shows no section at all.
import test from 'node:test';
import assert from 'node:assert/strict';
import { routeClimbsHtml, routeClimbsSlot, climbAt } from '../../assets/map/route-climbs.js';

const labels = { heading: 'Climbs on this route', at: '{u} {n}', avg: 'avg' };

test('one row per climb, in the order given, with km along and the average gradient', () => {
  const html = routeClimbsHtml([
    { id: 11000, name: 'Côte de la Redoute', alongKm: 204.9, avgGradient: '9.0%' },
    { id: 11003, name: 'Côte de la Roche-aux-Faucons', alongKm: 221.9, avgGradient: '9.6%' },
  ], labels);
  assert.match(html, /<h4 class="cc-near-h">Climbs on this route<\/h4>/);
  const ids = [...html.matchAll(/data-route-climb="(\d+)"/g)].map(m => m[1]);
  assert.deepEqual(ids, ['11000', '11003']);
  assert.match(html, /Côte de la Redoute<\/span><em>km 205 · ▲ 9\.0% avg<\/em>/);
});

test('no climbs, no section', () => {
  assert.equal(routeClimbsHtml([], labels), '');
  assert.equal(routeClimbsHtml(null, labels), '');
});

test('a climb without a measured gradient still lists its km', () => {
  const html = routeClimbsHtml([{ id: 1, name: 'Unmeasured', alongKm: 3.4, avgGradient: null }], labels);
  assert.match(html, /<em>km 3<\/em>/);
  assert.doesNotMatch(html, /▲/);
});

test('names are escaped', () => {
  const html = routeClimbsHtml([{ id: 2, name: '<img src=x onerror=1>', alongKm: 1, avgGradient: '5%' }], labels);
  assert.doesNotMatch(html, /<img/);
});

test('km come from units.js in whole units, in the order the label puts them', () => {
  // units.js falls back to metric without the page's cc-units.js.
  assert.equal(climbAt(111.6, '{u} {n}'), 'km 112');
  assert.equal(climbAt(111.6, '{n} {u}'), '112 km');
});

test('the slot is rendered only for a route with a database id', () => {
  const slot = routeClimbsSlot({ id: 23 }, 'Looking for climbs…');
  assert.match(slot, /id="cc-d-climbs-slot" data-route="23" aria-busy="true">/);
  assert.match(slot, /class="cc-d-spin"[^>]*><\/span><span role="status">Looking for climbs…<\/span>/);
  assert.equal(routeClimbsSlot({ name: 'demo' }, 'Looking for climbs…'), '');
});
