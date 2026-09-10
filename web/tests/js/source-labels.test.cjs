// SPDX-License-Identifier: AGPL-3.0-only
//
// Every ItemSource enum case must have its own drawer label, and `manual`
// must never share the rider label again.
//
// The drawer's "Source ·" line maps ItemSource values to plain-language
// labels in i18n.js. The enum lives in PHP, the labels live in JS, and the
// strings live in five locale files: three lists in three languages that only
// agree because somebody remembered. They stopped agreeing once already:
// `manual` (a row WE seeded by hand, e.g. the Furka Pass) borrowed the
// `user` label and told riders somebody contributed a climb that nobody did
// (owner-reported 2026-08-16). This pins the repair in all three places.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');

const enumSrc = read('src/Catalog/ItemSource.php');
const i18nSrc = read('assets/map/i18n.js');
const LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

const enumCases = [...enumSrc.matchAll(/case \w+ = '(\w+)';/g)].map(m => m[1]);

const labelsBlock = i18nSrc.match(/const SOURCE_LABELS = \{[\s\S]*?\n\};/);

test('ItemSource enum still parses out of the PHP file', () => {
  assert.ok(enumCases.length >= 7, `expected >= 7 cases, got ${enumCases.length}`);
  assert.ok(enumCases.includes('manual'));
  assert.ok(labelsBlock, 'SOURCE_LABELS block not found in i18n.js');
});

// `authority` is deliberately absent from SOURCE_LABELS: its label is the
// publisher's name, which lives in the data_provider row and reaches the map
// with the payload (docs/specs/data-provider-hierarchy.md 7). A constant here
// could only name one of them.
const LABELLED_BY_DATA = ['authority'];

test('the drawer resolves an authority label from the payload, not a constant', () => {
  assert.doesNotMatch(labelsBlock[0], /(^|[\s{,])authority:/,
    'SOURCE_LABELS must not name a provider: a second one makes the constant a lie');
  assert.match(read('assets/map/drawer.js'), /window\.CC_PROVIDERS/,
    'the drawer must read the provider map the payload delivers');
});

test('every ItemSource case has a SOURCE_LABELS mapping', () => {
  for (const c of enumCases.filter(c => !LABELLED_BY_DATA.includes(c))) {
    assert.match(
      labelsBlock[0],
      new RegExp(`(^|[\\s{,])${c}:`),
      `ItemSource '${c}' has no entry in SOURCE_LABELS - a new source would ` +
      'render with no label on the drawer source line',
    );
  }
});

test('manual carries its own label, not the rider one', () => {
  assert.match(labelsBlock[0], /manual:\s*D\.srcManual/,
    "manual must read from D.srcManual - 'Rider-contributed' on a seeded row is a false claim");
  assert.doesNotMatch(labelsBlock[0], /manual:\s*D\.srcRider/);
});

test('the manual label is wired from controller to all five locales', () => {
  const controller = read('src/Controller/MapController.php');
  assert.match(controller, /'srcManual' => 'd_src_manual'/,
    'MapController must translate d_src_manual into the D payload');
  for (const l of LOCALES) {
    assert.match(read(`translations/messages.${l}.yaml`), /^\s*d_src_manual: /m,
      `messages.${l}.yaml is missing d_src_manual`);
  }
});
