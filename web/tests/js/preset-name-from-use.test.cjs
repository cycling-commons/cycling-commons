// SPDX-License-Identifier: AGPL-3.0-only
//
// The preset editor's name follows a "Use this preset for" chip until the rider
// types a name of their own (docs/specs/map-presets.md section 4).
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { suggestName } = require('../../assets/settings/map-presets.js');
const USES = ['Default', 'Plan a ride', 'Check my ride', 'Scout review'];

test('an empty name takes the label of the chip switched on', () => {
  assert.equal(suggestName('', 'Check my ride', true, ['Check my ride'], USES), 'Check my ride');
});

test('a name a chip put there follows the next chip', () => {
  assert.equal(suggestName('Check my ride', 'Plan a ride', true, ['Plan a ride', 'Check my ride'], USES), 'Plan a ride');
});

test('switching the named chip off falls back to a tool still on, else empty', () => {
  assert.equal(suggestName('Plan a ride', 'Plan a ride', false, ['Check my ride'], USES), 'Check my ride');
  assert.equal(suggestName('Plan a ride', 'Plan a ride', false, [], USES), '');
  assert.equal(suggestName('Plan a ride', 'Check my ride', false, ['Plan a ride'], USES), 'Plan a ride');
});

test('a name the rider typed stays', () => {
  assert.equal(suggestName('Gravel weekend', 'Check my ride', true, ['Check my ride'], USES), 'Gravel weekend');
  assert.equal(suggestName('Gravel weekend', 'Check my ride', false, [], USES), 'Gravel weekend');
});

test('the Use chips write the name field', () => {
  const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'settings', 'map-presets.js'), 'utf8');
  assert.match(src, /\$\('mpUse'\)\.addEventListener\('click'[\s\S]*?\$\('mpName'\)\.value = suggestName\(/);
});
