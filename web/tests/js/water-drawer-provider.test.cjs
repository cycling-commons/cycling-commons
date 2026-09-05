// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The water drawer credits an authority row's publisher, not OpenStreetMap.
//
// osmDrawer learned this on 2026-09-04 (data-provider-hierarchy.md §7) and
// waterDrawer, which owns letter B's wording, did not: the first RIVM tap the
// owner opened on 2026-09-05 read "Type: Drinking water, OSM" and "Source:
// OpenStreetMap". Both builders now go through the same two helpers, and this
// pins that neither can quietly fall back to OSM again.
//
// drawer.js imports map-init.js, which constructs MapLibre, so the builder is
// read out of the source rather than executed.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'map', 'drawer.js'), 'utf8');

function body(name) {
  const m = src.match(new RegExp(`export function ${name}\\([^)]*\\)\\{([\\s\\S]*?)\\n\\}`));
  assert.ok(m, `${name}() not found in drawer.js`);
  return m[1];
}

test('both drawer builders resolve the publisher through providerOf()', () => {
  for (const name of ['osmDrawer', 'waterDrawer']) {
    assert.match(body(name), /const provider = providerOf\(p\)/, `${name} looks the publisher up in the payload's provider map`);
  }
});

test('the water drawer credits the publisher on every line that used to say OSM', () => {
  const w = body('waterDrawer');
  // The headline's origin word.
  assert.match(w, /provider \? provider\.name : \(community\?sourceLabel\(p\.srcType\):'OSM'\)/, 'headline origin');
  // The Type row's method.
  assert.match(w, /method: p\.type\?undefined:\(provider\?provider\.name:'OSM'\)/, 'type method');
  // The Listed row, same wording as osmDrawer.
  assert.match(w, /if\(provider\) rec\.push\(\{label:D\.listed\|\|'Listed'/, 'listed row');
  // The Source line, composed from the registry fields.
  assert.match(w, /source: provider \? providerSource\(provider\) :/, 'source line');
});

test('an authority row is not told to cross-check itself with the utility', () => {
  assert.match(body('waterDrawer'), /if\(!provider\) rec\.push\(\{label:D\.verify\|\|'Verify'/);
});

test('a register row without a name still gives a rider a handle: the town', () => {
  assert.match(body('waterDrawer'), /if\(p\.town\) rec\.push\(\{label:D\.town\|\|'Town', value:p\.town\}\)/);
});
