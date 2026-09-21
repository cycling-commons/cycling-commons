// SPDX-License-Identifier: AGPL-3.0-only
//
// The OSM question on a new place, in the map drawer (catalog-data-model.md
// §5b, moderation-and-contribution.md §5.4): the open state offers every
// candidate and "Not in OSM" last, the answered states are one chip, and
// every server-sent string is escaped before it meets innerHTML.
import test from 'node:test';
import assert from 'node:assert/strict';
import { osmQuestionHtml, osmAnsweredHtml } from '../../assets/map/osm-question.js';

const D = { osmQuestion: 'Which OSM object is this place?', osmNone: 'Not in OSM', osmUnnamed: 'Unnamed object',
  osmChipNone: 'not in OSM', osmTipLinked: 'Linked to OpenStreetMap', osmScope: 'Within 250 m.' };

test('no question, nothing drawn', () => {
  assert.equal(osmQuestionHtml(null, D), '');
  assert.equal(osmQuestionHtml({}, D), '');
});

test('open: the candidates, then "Not in OSM" last', () => {
  const html = osmQuestionHtml({ state: 'open', ref: null, candidates: [
    { ref: 'node/1', name: 'Uitkijkpunt', distanceM: 41.6 },
    { ref: 'way/2', name: null, distanceM: 120 },
  ] }, D);
  assert.match(html, /data-osm-state="open"/);
  assert.match(html, /Which OSM object is this place\?/);
  assert.match(html, /data-osm-answer="node\/1"[^>]*><b>Uitkijkpunt<\/b> <span>42 m · node\/1<\/span>/);
  assert.match(html, /data-osm-answer="way\/2"[^>]*><b>Unnamed object<\/b> <span>120 m · way\/2<\/span>/);
  assert.match(html, /Within 250 m\./);
  const none = html.lastIndexOf('data-osm-answer=""');
  assert.ok(none > html.lastIndexOf('data-osm-answer="way/2"'), 'the "not in OSM" answer comes last');
  assert.match(html.slice(none), /Not in OSM/);
});

test('open with nothing nearby: only "Not in OSM", and no scope line about a list', () => {
  const html = osmQuestionHtml({ state: 'open', ref: null, candidates: [] }, D);
  assert.equal((html.match(/data-osm-answer=/g) || []).length, 1);
  assert.doesNotMatch(html, /cc-mod-osm-scope/);
});

test('linked: a chip that links to the object', () => {
  const html = osmQuestionHtml({ state: 'linked', ref: 'node/930340800', candidates: [] }, D);
  assert.match(html, /data-osm-state="linked"/);
  assert.match(html, /href="https:\/\/www\.openstreetmap\.org\/node\/930340800"/);
  assert.match(html, /⌖ node\/930340800/);
  assert.doesNotMatch(html, /data-osm-answer/);
});

test('none: the recorded answer, no link', () => {
  const html = osmAnsweredHtml('none', null, D);
  assert.match(html, /data-osm-state="none"/);
  assert.match(html, /⌖ not in OSM/);
  assert.doesNotMatch(html, /href=/);
});

test('names and refs are escaped', () => {
  const html = osmQuestionHtml({ state: 'open', candidates: [{ ref: 'node/1"', name: '<b>x</b>', distanceM: 1 }] }, D);
  assert.doesNotMatch(html, /<b>x<\/b>/);
  assert.match(html, /&lt;b&gt;x&lt;\/b&gt;/);
  assert.doesNotMatch(html, /data-osm-answer="node\/1""/);
});
