// SPDX-License-Identifier: AGPL-3.0-only
//
// A search row names where the place is (map-and-search.md §7.1): five
// castles can share "Château Gaillard", and the group heading already says
// the category. The name gets two lines and a tooltip, and the list comes
// back when the rider returns to a search box that still holds a query.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const read = rel => fs.readFileSync(path.join(__dirname, '..', '..', rel), 'utf8');
const src = read('assets/map/search-ui.js');
const css = read('assets/styles/map.css');

test('a coverage hit carries its region, and the row shows it in place of the category', () => {
  assert.match(src, /where:h\.region\|\|''/);
  assert.match(src, /const sub=m\.where\|\|m\.kind;/);
  assert.match(src, /title="\$\{escH\(m\.name\+\(m\.where \? ' · '\+m\.where : ''\)\)\}"/);
});

test('a long name gets two lines before it is cut', () => {
  assert.match(css, /\.search-res \.snm\{[^}]*-webkit-line-clamp:2/);
});

test('returning to a search box that still holds a query brings the list back', () => {
  assert.match(src, /sBox\.addEventListener\('focus', reopenS\);/);
  assert.match(src, /const reopenS=\(\)=>\{ if\(!sRes\.hidden \|\| !sBox\.value\.trim\(\)\) return;/);
});

test('a hit that is our own item opens the item, never an OSM view of it', () => {
  assert.match(src, /go:\(\)=>h\.itemId!=null \? openApiHit\(h\.letter, h\.itemId, h\.rid\) : openCoverageByRef\(h\.ref, h\.letter, h\.ll, h\.n\)/);
});
