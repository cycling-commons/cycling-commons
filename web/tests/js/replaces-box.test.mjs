// SPDX-License-Identifier: AGPL-3.0-only
/* The desk card's boxes for a new place (catalog-data-model.md section 5a): the places approval retires, and the
   OpenStreetMap entry the place adds to, which stays its reference and is never under "replaces". */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { replacesHtml } from '../../assets/map/osm-question.js';

const D = { replacesHead: 'Approving replaces these places', replacesOsmHead: 'Approving adds to this OpenStreetMap entry', replacesOsm: 'stays the OSM reference' };

test('an OSM entry alone is not under the replaces heading', () => {
  const html = replacesHtml([], D, 'way/6571337');
  assert.ok(!html.includes('Approving replaces'));
  assert.match(html, /Approving adds to this OpenStreetMap entry/);
  assert.match(html, /OpenStreetMap · way\/6571337 · stays the OSM reference/);
});

test('a retired place and the OSM entry get a box each', () => {
  const html = replacesHtml([{ id: 7, name: 'Hemweg', from: 'ours', metres: 12 }], D, 'way/1');
  assert.equal((html.match(/<div class="cc-mod-replaces/g) || []).length, 2);
  assert.ok(html.indexOf('Approving replaces') < html.indexOf('Approving adds'));
  assert.ok(html.indexOf('#7') < html.indexOf('Approving adds'), 'the retired place sits in the first box');
});

test('a place of ours whose line came from OSM is named as ours', () => {
  const html = replacesHtml([{ id: 62053, name: 'Zuiderdijk', from: 'osm', metres: 0 }], { ...D, replacesOurs: 'Cycling Commons' }, null);
  assert.match(html, /Zuiderdijk · Cycling Commons · /);
  assert.ok(!html.includes('OpenStreetMap'));
});

test('nothing to retire and no OSM entry: no box', () => {
  assert.equal(replacesHtml([], D, null), '');
});
