// SPDX-License-Identifier: AGPL-3.0-only
//
// A machine-translated description is labelled where it appears, in the
// reader's language (terms, "auto-translated" label; docs/specs/map-and-search.md).
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../../assets/map/drawer.js', import.meta.url), 'utf8');
const ctl = readFileSync(new URL('../../src/Controller/MapController.php', import.meta.url), 'utf8');

test('the drawer writes the label from the translated bundle, never as an English literal', () => {
  assert.doesNotMatch(src, /· auto-translated</, 'no English literal in the drawer');
  assert.match(src, /<span class="cc-d-tr">· \$\{escPend\(D\.autoTranslated \|\| 'auto-translated'\)\}<\/span>/);
});

test('the bundle carries the label in every locale', () => {
  assert.match(ctl, /'autoTranslated' => 'd_auto_translated'/);
  for (const loc of ['en', 'fr', 'nl', 'de', 'es']) {
    const yaml = readFileSync(new URL(`../../translations/messages.${loc}.yaml`, import.meta.url), 'utf8');
    assert.match(yaml, /^ {2}d_auto_translated: '.+'$/m, `messages.${loc}.yaml has d_auto_translated`);
  }
});
