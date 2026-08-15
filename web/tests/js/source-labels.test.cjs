// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
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

test('every ItemSource case has a SOURCE_LABELS mapping', () => {
  for (const c of enumCases) {
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
