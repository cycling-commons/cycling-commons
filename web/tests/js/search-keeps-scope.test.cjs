// SPDX-License-Identifier: AGPL-3.0-only
//
// What the rider picks in the search box is where they went to look
// (map-and-search.md §4.5, owner 2026-10-19: "When I close the Hoorn right
// drawer it goes back to Wallonia"). The pick lifts the scope to the hit's
// own region, as any hit does, and that scope stays when the card closes.
// A deep link, a drawer row or a ride-check row still lifts for a moment
// only: closing its drawer gives the rider's scope back.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = f => fs.readFileSync(path.join(ROOT, 'assets/map', f), 'utf8');
const scopeUi = read('scope-ui.js'), places = read('places.js'), search = read('search-ui.js');

const body = (src, head) => {
  const at = src.indexOf(head);
  assert.ok(at >= 0, `${head} is in the source`);
  let depth = 0, i = src.indexOf('{', at);
  for(let j = i; j < src.length; j++){
    if(src[j] === '{') depth++;
    else if(src[j] === '}' && --depth === 0) return src.slice(i, j + 1);
  }
  throw new Error(`${head} has no end`);
};

test('keepHitScope() drops the memo and stores the scope, so neither closing the drawer nor a reload puts the old one back', () => {
  const keep = body(scopeUi, 'export function keepHitScope(');
  assert.match(keep, /_riderScope\s*=\s*null/);
  assert.match(keep, /window\.CCScope\.save\(\)/);
  // restoreHitScope() puts back only what the memo holds.
  assert.match(body(scopeUi, 'export function restoreHitScope('), /if\(prev && /);
});

test('a hit picked in the search box keeps its scope', () => {
  const follow = body(search, 'function followHit(');
  assert.match(follow, /liftScopeForHit\(m\.ll, m\.rid\)[\s\S]*keepHitScope\(\)/);
  assert.match(search, /import \{[^}]*keepHitScope[^}]*\} from '\.\/scope-ui\.js'/);
});

test('a town or a point picked in the search box keeps its scope', () => {
  const rows = search.match(/go:\(\)=>openPlace\([^\n]*\)/g) || [];
  assert.equal(rows.length, 2, 'the town row and the coordinate row');
  for(const r of rows) assert.match(r, /chosen:true/, r);
  const open = body(places, 'export function openPlace(');
  assert.match(open, /liftScopeForHit\(meta\.ll\);\s*\}\s*if\(meta\.chosen\) keepHitScope\(\);/);
});

test('a town opened from a link or a drawer row lifts for a moment only', () => {
  // openCity (the drawer's city links) and ?town= pass no `chosen`.
  assert.doesNotMatch(body(places, 'export function openCity('), /chosen/);
  const mapJs = read('map.js');
  assert.match(mapJs, /openPlace\(tp\.name, \{ll:tp\.ll, osm:tp\.osm\}\)/);
});
