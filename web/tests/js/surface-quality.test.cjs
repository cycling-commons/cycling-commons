// SPDX-License-Identifier: AGPL-3.0-only
//
// The quality channel is agreed by three parties: the CONTRACT names the OSM
// smoothness values the extractor ships as `sm`, the CLIENT maps each to a
// tick tone and a drawer label, and the FORM (CatalogFormRegistry) owns the
// five-value vocabulary the drawer displays. A value the pipeline ships but
// the client does not name falls through MapLibre's match to transparent — a
// tick silently missing — and a display label outside the form vocabulary
// would show a rider a word the contribute form then refuses.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const contract = JSON.parse(
  fs.readFileSync(path.join(ROOT, '..', 'pipeline', 'contract', 'coverage-contract.json'), 'utf8'),
);
const source = fs.readFileSync(path.join(ROOT, 'assets/map/surface-tiles.js'), 'utf8');

/** The keys of an object literal read out of the source (importing the module
    would boot MapLibre). */
function literalKeys(name) {
  const m = source.match(new RegExp(`const ${name} = \\{([^}]*)\\}`, 's'));
  assert.ok(m, `${name} not found in surface-tiles.js`);
  // Keys may share a line; values are quoted strings that carry no colon, so
  // a bare word-before-colon match is exact.
  return [...m[1].matchAll(/(\w+):/g)].map(x => x[1]);
}

test('the tick tones cover exactly the contract smoothness vocabulary', () => {
  assert.deepEqual(literalKeys('SM_TONE'), contract.surface.quality.values);
});

test('the drawer labels cover exactly the contract smoothness vocabulary', () => {
  assert.deepEqual(literalKeys('SM_LABEL'), contract.surface.quality.values);
});

test('the drawer labels collapse INTO the form vocabulary, never past it', () => {
  // SM_LABEL's values must all be words the A form offers, or the drawer
  // teaches a vocabulary the contribute wizard then refuses.
  const registry = fs.readFileSync(
    path.join(ROOT, 'src/Catalog/CatalogFormRegistry.php'), 'utf8');
  const m = registry.match(/select\('smoothness',\s*'Smoothness',\s*\[([^\]]*)\]/);
  assert.ok(m, 'the smoothness select not found in CatalogFormRegistry.php');
  const formValues = [...m[1].matchAll(/'([^']+)'/g)].map(x => x[1]);
  const labels = source.match(/const SM_LABEL = \{([^}]*)\}/s)[1];
  for (const [, label] of labels.matchAll(/: '([^']+)'/g)) {
    assert.ok(formValues.includes(label),
      `SM_LABEL maps to '${label}', which the form vocabulary does not offer`);
  }
});

test('the client ticks start where the contract says they do', () => {
  const m = source.match(/^const SM_MIN_ZOOM = (\d+);/m);
  assert.ok(m, 'SM_MIN_ZOOM not found in surface-tiles.js');
  assert.equal(Number(m[1]), contract.surface.quality.minZoom,
    'ticks would appear at a different zoom than the legend note promises');
});

test('sm and mtb are promised tile props', () => {
  for (const prop of ['sm', 'mtb']) {
    assert.ok(contract.surface.tileProps.includes(prop),
      `the client reads ${prop}, which the contract does not promise`);
  }
});
