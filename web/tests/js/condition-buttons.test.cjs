// SPDX-License-Identifier: AGPL-3.0-only
//
// Closed / Not there anymore are condition reports: they write the place's
// "Still as mapped?" field, which hazards, climbs and stays do not have, so the
// server refuses them there (OsmConfirmTest). The drawer offers them only on
// a letter whose field set has `condition` (moderation-and-contribution.md §10.5).
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'map', 'drawer.js'), 'utf8');

test('the condition buttons ask the letter whether it has the field', () => {
  assert.match(src, /const hasCondition = \(\(window\.CC_FIELD_SCHEMA \|\| \{\}\)\[layer\.letter\] \|\| \[\]\)\.some\(fd => fd\.key === 'condition'\);/);
  assert.match(src, /const stateRow = \(CC_CONFIRMABLE\.has\(layer\.key\) && hasCondition &&/);
  assert.match(src, /\+ \(hasCondition \? condButtons : ''\)/);
});
