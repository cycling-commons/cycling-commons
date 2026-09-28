// SPDX-License-Identifier: AGPL-3.0-only
//
// The town card's "Cycling here" rows (places.js townHtml()) read Wikidata:
// an edition count and the year of the newest one. With no year on record,
// the row says its count and nothing about "last" (owner 2026-09-28).
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const places = fs.readFileSync(path.join(ROOT, 'assets', 'map', 'places.js'), 'utf8');
test('a race with no year on Wikidata says its count and nothing about "last"', () => {
  assert.match(places, /r\.last \? \(D\.raceEditions\|\|'\{n\} editions · last \{y\}'\)[^:]*: \(D\.raceEditionsUndated\|\|'\{n\} editions'\)/);
});
