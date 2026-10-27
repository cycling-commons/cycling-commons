// SPDX-License-Identifier: AGPL-3.0-only
/* Where the add form's map opens (moderation-and-contribution.md §1.2, "Where the form's map opens"): the region or
   country the map last showed, else the rider's base location, else Spa. */
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { formStart, SPA } = require('../../assets/contribute/form-start.js');

const START = {
  regions: [
    { slug: 'noord-holland', cc: 'NL', view: [4.49, 52.16, 5.32, 53.18] },
    { slug: 'flevoland', cc: 'NL', view: [5.13, 52.27, 6.01, 52.86] },
    { slug: 'liege', cc: 'BE', view: [5.39, 50.13, 6.41, 50.81] },
  ],
  base: { lat: 52.64, lng: 5.06 },
};

test('the region the map last showed', () => {
  assert.deepEqual(formStart('region:noord-holland', START), { bounds: [[4.49, 52.16], [5.32, 53.18]] });
});

test('a country is the box round its regions', () => {
  assert.deepEqual(formStart('country:NL', START), { bounds: [[4.49, 52.16], [6.01, 53.18]] });
});

test('My area, no stored scope, or an unknown region: the base location', () => {
  for (const token of ['myarea', null, 'region:atlantis', 'country:XX']) {
    assert.deepEqual(formStart(token, START), { center: [5.06, 52.64], zoom: 11 }, String(token));
  }
});

test('no base location and nothing stored: Spa', () => {
  assert.deepEqual(formStart(null, { regions: START.regions, base: null }), SPA);
  assert.deepEqual(formStart('region:noord-holland', undefined), SPA, 'no page payload');
});

test('a box across the antimeridian is left out', () => {
  const start = { regions: [{ slug: 'alaska', cc: 'US', view: [172.4, 51.2, -129.9, 71.4] }], base: null };
  assert.deepEqual(formStart('region:alaska', start), SPA);
});

test('improve.js reads it from window.Cc, loaded as a plain script before it', () => {
  const fs = require('node:fs');
  const path = require('node:path');
  const web = path.join(__dirname, '..', '..');
  const twig = fs.readFileSync(path.join(web, 'templates/contribute/improve.html.twig'), 'utf8');
  const js = fs.readFileSync(path.join(web, 'assets/contribute/improve.js'), 'utf8');
  assert.ok(!/^import /m.test(js), 'no import: the form page has no import map');
  assert.match(js, /window\.Cc\.formStart\(/);
  assert.ok(twig.indexOf("asset('contribute/form-start.js')") < twig.indexOf("asset('contribute/improve.js')"));
});
