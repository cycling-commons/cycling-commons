// SPDX-License-Identifier: AGPL-3.0-only
//
// A place's change history in the drawer is closed by default (owner
// 2026-09-30): one "Show changelog" button opens the clamped list in place,
// and the full log opens from inside it (docs/specs/map-and-search.md).
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../../assets/map/drawer.js', import.meta.url), 'utf8');
const render = src.slice(src.indexOf('function renderHistoryList('), src.indexOf('let _histCache'));

test('the changelog renders as a closed toggle button', () => {
  assert.match(render, /<button type="button" class="cc-d-hist-h" data-hist-toggle aria-expanded="false" aria-controls="cc-d-hist-body"/);
  assert.match(render, /<div id="cc-d-hist-body" hidden>/, 'the list starts hidden');
  assert.match(render, /data-hist-all aria-haspopup="dialog"/, 'the full log opens from inside the list');
  assert.ok(render.indexOf('data-hist-all') > render.indexOf('id="cc-d-hist-body"'), 'the full-log button sits in the hidden body');
});

test('the toggle opens and closes the list and says so', () => {
  const handler = src.slice(src.indexOf("const t = e.target.closest('[data-hist-toggle]')"));
  assert.match(handler, /body\.hidden = !open;/);
  assert.match(handler, /t\.setAttribute\('aria-expanded', String\(open\)\);/);
  assert.match(handler, /t\.textContent = open \? t\.dataset\.hide : t\.dataset\.show;/);
});

test('its labels come from the translated bundle', () => {
  const ctl = readFileSync(new URL('../../src/Controller/MapController.php', import.meta.url), 'utf8');
  for (const [k, v] of [['showChangelog', 'd_show_changelog'], ['hideChangelog', 'd_hide_changelog'], ['changelogAll', 'd_changelog_all']]) {
    assert.match(ctl, new RegExp(`'${k}' => '${v}'`));
  }
});
