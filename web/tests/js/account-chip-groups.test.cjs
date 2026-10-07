// SPDX-License-Identifier: AGPL-3.0-only
//
// The account chip's menu groups: Personal (spruce), Moderation (clay) and
// Admin (ochre), each a solid header over a light tint. map.css carries its own
// copy of the chip's rules, so the map menu must colour them as atlas.css does.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const chip = read('templates/partials/_account_chip.html.twig');

test('the admin link sits in its own coloured group', () => {
  assert.match(chip, /<div class="acct-group acct-group-admin">\s*<div class="acct-sec acct-sec-admin">/);
  assert.doesNotMatch(chip, /class="acct-sep" href="\{\{ path\('admin'\) \}\}"/);
});

for (const file of ['assets/styles/atlas.css', 'assets/styles/map.css']) {
  test(file + ' colours all three group headers and tints', () => {
    const css = read(file);
    assert.match(css, /\.acct-sec-personal\{background:var\(--spruce\)\}/);
    assert.match(css, /\.acct-sec-mod\{background:var\(--clay\)\}/);
    assert.match(css, /\.acct-sec-admin\{background:var\(--ochre\)/);
    for (const g of ['personal', 'mod', 'admin']) assert.match(css, new RegExp('\\.acct-group-' + g + '\\{background:'));
  });
}
