// SPDX-License-Identifier: AGPL-3.0-only
//
// A search hit the map does not draw gets a stand-in pin with its own pulse
// (map-and-search.md §12). The drawer's point halo sat at the pin's tip, a
// second ring a few pixels off the first (owner 2026-10-08, Hotel Beemster):
// one pin, one ring.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'map', 'drawer.js'), 'utf8');

test('the stand-in pin clears the point halo, so only its own ring shows', () => {
  const m = src.match(/export function revealPinAt\(layer, ll\)\{[\s\S]*?\n\}/);
  assert.ok(m, 'revealPinAt exists');
  assert.match(m[0], /clearHighlight\(\);/);
});
