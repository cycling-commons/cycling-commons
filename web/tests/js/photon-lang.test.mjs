// SPDX-License-Identifier: AGPL-3.0-only
//
// Photon speaks default, de, en and fr (map-and-search.md §7.2). A Dutch
// reader asked for "Antwerpen" and got "Antwerp" (owner, 2026-09-07): the
// request carried no language at all.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { photonLang } from '../../assets/map/util.js';

test('the three languages Photon speaks pass through', () => {
  assert.equal(photonLang('en'), 'en');
  assert.equal(photonLang('fr-BE'), 'fr');
  assert.equal(photonLang('de'), 'de');
});

test('every language Photon lacks asks for the place\'s own name', () => {
  assert.equal(photonLang('nl'), 'default');
  assert.equal(photonLang('es'), 'default');
  assert.equal(photonLang(''), 'default');
  assert.equal(photonLang(undefined), 'default');
});
