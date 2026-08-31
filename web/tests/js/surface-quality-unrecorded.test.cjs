// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// A road with no `smoothness` tag says so, rather than going quiet.
//
// The map draws quality as coloured ticks, and only where the tag exists. So a
// road nobody has assessed looks exactly like a road with no ticks for any
// other reason, and the drawer used to omit the row entirely: a rider could not
// tell "nobody recorded it" from "we do not track this" (owner 2026-08-31).
//
// The site already answers this everywhere else. Traffic, when it is inferred
// rather than measured, says so and ends "Ride it and tell us". Missing surface
// is a named legend class, "Surface not recorded", with its own colour and its
// own toggle. Smoothness was the one channel that stayed silent.
//
// What is pinned here is the shape, not the wording: an `empty` row (the same
// style the catalog drawer uses for an unset field) carrying a link into the
// wizard for THIS way, so the gap is also the invitation to close it.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const src = fs.readFileSync(path.join(ROOT, 'assets/map/surface-tiles.js'), 'utf8');

/** The `openSurfaceDrawer` body, where the record rows are built. */
const drawerFn = src.slice(src.indexOf('export function openSurfaceDrawer'));

test('a tagged road still shows its smoothness, with the OSM method badge', () => {
  assert.match(drawerFn, /if \(p\.sm && SM_LABEL\[p\.sm\]\)/);
  assert.match(drawerFn, /label: D\.smoothness \|\| 'Smoothness'[\s\S]{0,200}method: 'OSM'/);
});

test('an untagged road gets a row too, not silence', () => {
  // The `else` is the whole point: no branch, no row, and the rider learns
  // nothing from a field that simply is not there.
  assert.match(drawerFn, /\} else \{[\s\S]*?empty: true/, 'the missing case must push its own row');
});

test('the row is the empty style, and links into the wizard for this way', () => {
  assert.match(drawerFn, /empty: true/, 'reuse the catalog drawer\'s unset-field style');
  assert.match(drawerFn, /'\/improve\?ref=' \+ encodeURIComponent\(p\.ref\)/,
    'the link must carry the way ref: materialize-on-edit creates the item');
  assert.match(drawerFn, /&field=smoothness/,
    'the wizard must open on the smoothness field, not the top of the form');
  assert.match(drawerFn, /encodeURIComponent\(layer\.letter\)/,
    'the type must come from the layer, not a letter typed twice');
});

test('the field name matches the one the contribute form actually offers', () => {
  // Two spellings would give a link that opens the wizard on nothing.
  const registry = fs.readFileSync(path.join(ROOT, 'src/Catalog/CatalogFormRegistry.php'), 'utf8');
  assert.match(registry, /CatalogField::select\('smoothness'/,
    'RoadSurface must still have a field keyed `smoothness`');
});

test('the fallback string exists in all five locales', () => {
  // Only reached by a feature with no way ref, which should not happen; a
  // missing string would still render the key at a rider.
  for (const loc of ['en', 'fr', 'nl', 'de', 'es']) {
    const yaml = fs.readFileSync(path.join(ROOT, `translations/messages.${loc}.yaml`), 'utf8');
    assert.match(yaml, /^ {2}d_not_recorded:/m, `${loc} is missing d_not_recorded`);
  }
  const controller = fs.readFileSync(path.join(ROOT, 'src/Controller/MapController.php'), 'utf8');
  assert.match(controller, /'notRecorded' => 'd_not_recorded'/,
    'the string has to reach the client, or D.notRecorded is undefined');
});
