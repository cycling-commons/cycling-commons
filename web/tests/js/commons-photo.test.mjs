// SPDX-License-Identifier: AGPL-3.0-only
//
// commons-photo.js: the poll behind the drawer's "Loading image…" spinner
// (docs/specs/coverage-provider.md §7).
//
// Option B, owner decision 2026-08-25: we never hotlink Commons, so on the
// first view of a viewpoint there is nothing to show until our own copy exists.
// The poll is what turns that wait into a spinner rather than an empty box.
//
// Every test below holds one half of the same rule: the spinner must always
// end. A file that turns out to be unusable, a worker that is not running, an
// offline phone, a drawer the rider already closed. None of them may leave
// somebody watching a spinner forever.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { watchCommonsPhoto, photoPollDelays, photoWaitRef } from '../../assets/map/commons-photo.js';

const replies = (states) => {
  let i = 0;
  return async () => ({ ok: true, json: async () => states[Math.min(i++, states.length - 1)] });
};
const noSleep = { sleep: async () => {} };

test('a photo that is ready on the first ask never polls twice', async () => {
  let ready = null, polls = 0;
  const inner = replies([{ state: 'ready', sm: 'a', lg: 'b' }]);
  const fetchImpl = async (...a) => { polls++; return inner(...a); };
  await watchCommonsPhoto('node/1', p => { ready = p; }, () => {}, { fetchImpl, ...noSleep });
  assert.equal(polls, 1);
  assert.equal(ready.sm, 'a');
});

test('pending is polled until ready, and the photo is handed over exactly once', async () => {
  let ready = null, calls = 0;
  const fetchImpl = replies([{ state: 'pending' }, { state: 'pending' }, { state: 'ready', sm: 'x', lg: 'y' }]);
  await watchCommonsPhoto('node/2', p => { calls++; ready = p; }, () => {}, { fetchImpl, ...noSleep });
  assert.equal(calls, 1);
  assert.equal(ready.sm, 'x');
});

test('state none gives up at once, with no polling', async () => {
  let gaveUp = 0, polls = 0;
  const inner = replies([{ state: 'none' }]);
  const fetchImpl = async (...a) => { polls++; return inner(...a); };
  await watchCommonsPhoto('node/3', () => {}, () => { gaveUp++; }, { fetchImpl, ...noSleep });
  assert.equal(polls, 1);
  assert.equal(gaveUp, 1);
});

test('a photo that never arrives gives up rather than spinning forever', async () => {
  let gaveUp = 0, ready = 0;
  const fetchImpl = replies([{ state: 'pending' }]);
  await watchCommonsPhoto('node/4', () => { ready++; }, () => { gaveUp++; }, { fetchImpl, ...noSleep });
  assert.equal(gaveUp, 1);
  assert.equal(ready, 0, 'never both');
});

test('a network error gives up quietly rather than throwing at the drawer', async () => {
  let gaveUp = 0;
  const fetchImpl = async () => { throw new Error('offline'); };
  await watchCommonsPhoto('node/5', () => {}, () => { gaveUp++; }, { fetchImpl, ...noSleep });
  assert.equal(gaveUp, 1);
});

test('an http error is treated as no photo, not as pending', async () => {
  let gaveUp = 0;
  const fetchImpl = async () => ({ ok: false, json: async () => ({}) });
  await watchCommonsPhoto('node/6', () => {}, () => { gaveUp++; }, { fetchImpl, ...noSleep });
  assert.equal(gaveUp, 1);
});

test('a closed drawer stops the poll without calling either callback', async () => {
  let ready = 0, gaveUp = 0, polls = 0;
  const inner = replies([{ state: 'pending' }]);
  const fetchImpl = async (...a) => { polls++; return inner(...a); };
  await watchCommonsPhoto('node/7', () => { ready++; }, () => { gaveUp++; },
    { fetchImpl, ...noSleep, cancelled: () => polls >= 2 });
  assert.equal(ready, 0);
  assert.equal(gaveUp, 0);
  assert.ok(polls <= 2, `${polls} polls`);
});

test('the poll schedule is bounded and backs off', () => {
  const d = photoPollDelays();
  assert.ok(d.length >= 4 && d.length <= 12, `${d.length} polls`);
  assert.ok(d.every((v, i) => i === 0 || v >= d[i - 1]), 'never speeds up');
  assert.ok(d.reduce((a, b) => a + b, 0) <= 45000, 'gives up inside 45s');
});

// watchJson: the same bounded poll, with the caller saying what ready and
// pending look like. The town card polls twice with it: text, then photo.
import { watchJson } from '../../assets/map/commons-photo.js';

test('watchJson answers ready by the caller\'s own test, not a fixed state name', async () => {
  let got = null;
  const fetchImpl = replies([{ state: 'ready', photo: { state: 'pending' } }, { state: 'ready', photo: { state: 'ready', sm: 'x' } }]);
  await watchJson('/map/town/node/1',
    { isReady: d => d.photo && d.photo.state !== 'pending', isPending: d => d.state === 'ready' },
    d => { got = d; }, () => { throw new Error('gave up'); }, { fetchImpl, ...noSleep });
  assert.equal(got.photo.sm, 'x');
});

test('watchJson gives up on an answer that is neither ready nor pending', async () => {
  let gaveUp = false;
  await watchJson('/map/town/node/1',
    { isReady: d => d.state === 'ready', isPending: d => d.state === 'pending' },
    () => { throw new Error('not ready'); }, () => { gaveUp = true; }, { fetchImpl: replies([{ state: 'none' }]), ...noSleep });
  assert.equal(gaveUp, true);
});

// Which OSM point a drawer waits on. A coverage point says so with `hasPhoto`
// and its `ref`; a catalog item that stands for an OSM point and has no photo
// of its own carries `photoRef` (CatalogProvider). Found 2026-09-15: a monument
// materialized from an OSM point opened with no photo, because the catalog
// feature had neither key and the drawer never asked.
test('a coverage point waits on its own ref only when a photo is possible', () => {
  assert.equal(photoWaitRef({ ref: 'node/1', hasPhoto: 1 }), 'node/1');
  assert.equal(photoWaitRef({ ref: 'node/1' }), null);
  assert.equal(photoWaitRef({ hasPhoto: 1 }), null);
});

test('a catalog item waits on the OSM point it stands for', () => {
  assert.equal(photoWaitRef({ id: 46156, photoRef: 'way/721040994' }), 'way/721040994');
  assert.equal(photoWaitRef({ id: 46156 }), null);
});

test('only an OSM ref is ever asked for', () => {
  assert.equal(photoWaitRef({ photoRef: '../../admin' }), null);
  assert.equal(photoWaitRef({ ref: 'relation/5', hasPhoto: 1 }), null);
});
