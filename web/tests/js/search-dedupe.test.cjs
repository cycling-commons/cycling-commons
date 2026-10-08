// SPDX-License-Identifier: AGPL-3.0-only
//
// One place, one row (docs/specs/map-and-search.md §7.1): our own item can
// come back from the coverage search (its curated arm carries `itemId`) and
// from the worldwide /v1/search (its `id`). Ammersoyen Castle showed twice.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'map', 'search-ui.js'), 'utf8');

test('a coverage hit keeps the id of the item it stands for', () => {
  assert.match(src, /letter:h\.letter, ll:h\.ll, cov:1, osm:!h\.curated, itemId:h\.itemId,/);
});

test('a worldwide hit the coverage search already returned is not listed again', () => {
  assert.match(src, /const covIds = _covSQ===q \? new Set\(_covHits\.filter\(m=>m\.itemId!=null\)\.map\(m=>m\.letter\+':'\+m\.itemId\)\) : new Set\(\);/);
  assert.match(src, /_apiHits\.forEach\(m=>\{ if\(covIds\.has\(m\.letter\+':'\+m\.id\)\) return;/);
});
