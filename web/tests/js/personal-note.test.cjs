// SPDX-License-Identifier: AGPL-3.0-only
//
// The personal sentence of the drawer (data-provider-hierarchy.md §6.7.3):
// fetched only for a signed-in rider, memoised per item so reopening a
// drawer costs no request, and silent when it fails. The facts never sit
// behind a spinner, and an anonymous visitor never pays for it.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const drawer = fs.readFileSync(path.join(ROOT, 'assets', 'map', 'drawer.js'), 'utf8');

const fn = drawer.match(/function loadMine\(f\)\{[\s\S]*?\n\}/);

test('the drawer has one loader for the personal sentence', () => {
  assert.ok(fn, 'loadMine(f) not found in drawer.js');
});

test('the fragment is never requested for an anonymous visitor', () => {
  assert.match(fn[0], /window\.CC_CONFIRM_TOKEN/, 'the signed-in marker gates the fetch');
  const guard = fn[0].indexOf('CC_CONFIRM_TOKEN'), fetchAt = fn[0].indexOf('fetch(');
  assert.ok(guard !== -1 && fetchAt !== -1 && guard < fetchAt, 'the guard comes before the fetch');
});

test('the answer is memoised per item id', () => {
  assert.match(drawer, /_mineCache\s*=\s*(new Map\(\)|\{\})/, 'a per-item cache exists');
  assert.match(fn[0], /_mineCache/, 'the loader reads and writes it');
});

test('a failed fetch renders nothing rather than a spinner or an error', () => {
  assert.match(fn[0], /\.catch\(\s*\(\)\s*=>\s*null\s*\)/, 'failure resolves to null');
  assert.ok(!/spinner|loading/i.test(fn[0]), 'no placeholder while waiting');
});

test('the sentence states both facts and subtracts neither', () => {
  const en = fs.readFileSync(path.join(ROOT, 'translations', 'messages.en.yaml'), 'utf8');
  const line = en.match(/^  d_personal_reclaimed: '(.*)'$/m);
  assert.ok(line, 'map.d_personal_reclaimed is in the English catalog');
  assert.match(line[1], /\{yours\}/);
  assert.match(line[1], /\{theirs\}/);
  assert.ok(!/replaced/i.test(line[1]), 'never "external data replaced yours"');
  assert.match(drawer, /personalReclaimed/, 'the drawer reads the key');
  assert.match(fs.readFileSync(path.join(ROOT, 'src', 'Controller', 'MapController.php'), 'utf8'), /'personalReclaimed' => 'd_personal_reclaimed'/);
});
