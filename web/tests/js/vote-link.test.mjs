// SPDX-License-Identifier: AGPL-3.0-only
//
// Every drawer that offers a vote links to the season ballot with the row
// picked (route-domain.md §8d). An OpenStreetMap place has no catalogue id
// and gets no link.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { voteHref, VOTE_CATEGORY } from '../../assets/map/vote-link.js';

test('a catalogue row gets the ballot with itself picked', () => {
  assert.equal(voteHref('/vote', 'climbs', 42), '/vote?cat=climbs&pick=42');
  assert.equal(voteHref('/fr/voter', 'stays', '7'), '/fr/voter?cat=where-to-sleep&pick=7');
});

test('a route goes to the quality rides list', () => {
  assert.equal(voteHref('/vote', 'experience', 9), '/vote?cat=quality-rides&pick=9');
});

test('an OpenStreetMap place with no catalogue id gets no link', () => {
  assert.equal(voteHref('/vote', 'scenic', null), null);
  assert.equal(voteHref('/vote', 'scenic', undefined), null);
  assert.equal(voteHref('/vote', 'scenic', 'node/123'), null);
  assert.equal(voteHref('/vote', 'scenic', 0), null);
});

test('a kind that is confirmed, not voted, gets no link', () => {
  assert.equal(voteHref('/vote', 'water', 5), null);
  assert.equal(voteHref('/vote', 'toString', 5), null);
});

test('no ballot address, no link', () => {
  assert.equal(voteHref('', 'climbs', 5), null);
  assert.equal(voteHref(undefined, 'climbs', 5), null);
});

test('the categories are the five votable types', () => {
  assert.deepEqual(Object.values(VOTE_CATEGORY).sort(), ['climbs', 'history-culture', 'quality-rides', 'scenic-views', 'where-to-sleep']);
});
