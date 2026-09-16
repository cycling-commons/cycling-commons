// SPDX-License-Identifier: AGPL-3.0-only
//
// Who sees the route drawer's shortcut to the Routes desk
// (docs/specs/route-domain.md §7.1): a curator of that route's own region, and
// nobody else. Both facts arrive per viewer on the map page, never in
// catalog.json, which is one publicly cached document every reader shares.
// The server re-checks region scope when the edit is saved; this only decides
// whether to draw the icon.
import test from 'node:test';
import assert from 'node:assert/strict';
import { curatorMayEdit } from '../../assets/map/util.js';

test('a rider never sees it, whatever the regions say', () => {
  assert.equal(curatorMayEdit(1, {}), false);
  assert.equal(curatorMayEdit(1, { CC_MOD_REGIONS: [1] }), false);
  assert.equal(curatorMayEdit(1, { CC_MOD_REGIONS: null }), false);
});

test('a curator of that region sees it', () => {
  assert.equal(curatorMayEdit(1, { CC_IS_CURATOR: true, CC_MOD_REGIONS: [1, 7] }), true);
});

test('a curator of other regions does not', () => {
  assert.equal(curatorMayEdit(1, { CC_IS_CURATOR: true, CC_MOD_REGIONS: [7, 9] }), false);
  assert.equal(curatorMayEdit(1, { CC_IS_CURATOR: true, CC_MOD_REGIONS: [] }), false);
});

test('a global curator sees it everywhere', () => {
  assert.equal(curatorMayEdit(1, { CC_IS_CURATOR: true, CC_MOD_REGIONS: null }), true);
  assert.equal(curatorMayEdit(99, { CC_IS_CURATOR: true }), true);
});

test('a route with no region is in every curator scope, as the server has it', () => {
  assert.equal(curatorMayEdit(null, { CC_IS_CURATOR: true, CC_MOD_REGIONS: [7] }), true);
});

test('ids compare as text, and a prefix is not a match', () => {
  assert.equal(curatorMayEdit('1', { CC_IS_CURATOR: true, CC_MOD_REGIONS: [1] }), true);
  assert.equal(curatorMayEdit(1, { CC_IS_CURATOR: true, CC_MOD_REGIONS: [11] }), false);
});
