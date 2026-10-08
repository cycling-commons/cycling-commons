// SPDX-License-Identifier: AGPL-3.0-only
//
// Every icon in both map keys sits on the same light paper tile (owner
// 2026-10-08: "make the background lighter behind every icon"), so a dark
// glyph reads as well as a white one, in the rail Key panel and on /map-key.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = rel => fs.readFileSync(path.join(ROOT, rel), 'utf8');

test('the rail key tile is the key page tile', () => {
  const page = (read('assets/styles/page/pages/map_key.css').match(/\.mk-sw\{[^}]*background:(#[0-9A-Fa-f]{6})/) || [])[1];
  const rail = (read('assets/styles/map.css').match(/\.mkp-sw\{[^}]*background:(#[0-9A-Fa-f]{6})/) || [])[1];
  assert.ok(page, 'the key page tile names its colour');
  assert.equal(rail, page);
});
