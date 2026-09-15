// SPDX-License-Identifier: AGPL-3.0-only
// The drawer's add-photo prompt (docs/specs/map-and-search.md §6,
// route-domain.md §4.5): a place opens /improve on the photo step, a live
// route opens its photo form on /propose-route.
import test from 'node:test';
import assert from 'node:assert/strict';
import { addPhotoHref } from '../../assets/map/add-photo.js';

test('a place opens its own improve form on the photo step', () => {
  assert.equal(
    addPhotoHref({ key: 'water', letter: 'A' }, { id: 12, name: 'Fontaine & co' }),
    '/improve?item=12&name=Fontaine%20%26%20co&type=A&add=photo',
  );
});

test('a live route opens the route photo form', () => {
  assert.equal(addPhotoHref({ key: 'experience', letter: 'R' }, { id: 26, name: 'Rondje', state: 'unverified' }), '/propose-route?route=26');
  assert.equal(addPhotoHref({ key: 'experience', letter: 'R' }, { id: 27, name: 'R', state: 'verified' }), '/propose-route?route=27');
});

test('nothing for a route waiting for review, or a row with no id', () => {
  assert.equal(addPhotoHref({ key: 'experience', letter: 'R' }, { id: 26, name: 'R', state: 'submitted' }), null);
  assert.equal(addPhotoHref({ key: 'water', letter: 'A' }, { id: null, name: 'x' }), null);
});
