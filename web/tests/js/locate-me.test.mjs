// SPDX-License-Identifier: AGPL-3.0-only
//
// The Locate me button (docs/specs/map-and-search.md §4.0): where the rider's
// own dot lands, and what a refused or failed lookup says.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { locateOffset, locateErrorMessage, offsetAsPadding } from '../../assets/map/util.js';

test('with no drawer open the dot lands in the middle of the map', () => {
  assert.deepEqual(locateOffset(false, 1920, 1080, 0, 1080), [0, 0]);
  assert.deepEqual(locateOffset(false, 400, 1000, 0, 1000), [0, 0]);
});

test('with a drawer open the dot lands where a clicked spot does', () => {
  assert.deepEqual(locateOffset(true, 1920, 1080, 0, 1080), [-150, 0]);
  // Phone: the free half above the sheet, same numbers as pinOffset.
  assert.deepEqual(locateOffset(true, 400, 1000, 0, 1000), [0, -250]);
});

test('the offset becomes padding on the side the point moves away from', () => {
  assert.deepEqual(offsetAsPadding([0, 0]), { left: 0, right: 0, top: 0, bottom: 0 });
  // Desktop drawer: 150 px left is 300 px of padding on the right.
  assert.deepEqual(offsetAsPadding([-150, 0]), { left: 0, right: 300, top: 0, bottom: 0 });
  // Phone sheet: 195 px up is 390 px of padding at the bottom.
  assert.deepEqual(offsetAsPadding([0, -195]), { left: 0, right: 0, top: 0, bottom: 390 });
  assert.deepEqual(offsetAsPadding([20, 10]), { left: 40, right: 0, top: 20, bottom: 0 });
});

test('a refused permission and a failed lookup say different things', () => {
  const strings = { locateDenied: 'blocked', locateFailed: 'failed' };
  assert.equal(locateErrorMessage(1, strings), 'blocked');      // PERMISSION_DENIED
  assert.equal(locateErrorMessage(2, strings), 'failed');       // POSITION_UNAVAILABLE
  assert.equal(locateErrorMessage(3, strings), 'failed');       // TIMEOUT
  assert.equal(locateErrorMessage(undefined, strings), 'failed');
});

test('an English sentence stands in when the bundle has none', () => {
  assert.match(locateErrorMessage(1, {}), /browser settings/);
  assert.match(locateErrorMessage(2, null), /could not be found/);
});
