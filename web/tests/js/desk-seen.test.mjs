// SPDX-License-Identifier: AGPL-3.0-only
//
// assets/map/desk-seen.js: the map drawer opening a pending submission or a
// Data finding posts that the curator opened it, once, so the desk lists drop
// its unseen bar (moderation-and-contribution.md §5.2f). No token (a rider,
// or a curator without the desk payload) posts nothing; a failed post can be
// tried again on the next opening.
import test from 'node:test';
import assert from 'node:assert/strict';
import { markOpened, unseenMark } from '../../assets/map/desk-seen.js';

function server(answers) {
  const sent = [];
  const fetch = (url, init) => {
    sent.push({ url, method: init.method, body: Object.fromEntries(new URLSearchParams(init.body)) });
    const a = answers.shift();
    return a ? a() : Promise.resolve({ ok: true, json: () => Promise.resolve({ seen: false }) });
  };
  return { fetch, sent };
}
const ok = seen => () => Promise.resolve({ ok: true, json: () => Promise.resolve({ seen }) });

test('opening a pending submission posts once, with the token', async () => {
  const { fetch, sent } = server([ok(true)]);
  assert.equal(await markOpened('submission', 41, { token: 'tok', fetch }), true);
  assert.deepEqual(sent, [{ url: '/moderate/seen', method: 'POST', body: { _token: 'tok', type: 'submission', id: '41' } }]);
  assert.equal(await markOpened('submission', 41, { token: 'tok', fetch }), false, 'the same item again posts nothing');
  assert.equal(sent.length, 1);
});

test('a Data finding is its own kind', async () => {
  const { fetch, sent } = server([ok(true)]);
  await markOpened('catalog_finding', 41, { token: 'tok', fetch });
  assert.equal(sent[0].body.type, 'catalog_finding');
});

test('without a token nothing is posted', async () => {
  const { fetch, sent } = server([]);
  assert.equal(await markOpened('submission', 42, { token: null, fetch }), false);
  assert.equal(await markOpened('submission', 'x', { token: 'tok', fetch }), false, 'nor for an id that is not one');
  assert.equal(sent.length, 0);
});

test('a refused post is tried again on the next opening', async () => {
  const { fetch, sent } = server([() => Promise.resolve({ ok: false, json: () => Promise.resolve(null) }), ok(true)]);
  assert.equal(await markOpened('submission', 43, { token: 'tok', fetch }), false);
  assert.equal(await markOpened('submission', 43, { token: 'tok', fetch }), true);
  assert.equal(sent.length, 2);
});

test('a list row for an unopened pending item carries the bar and its words', () => {
  const unopened = { pend: '7', modeF: { pending: { id: 7, unseen: true } } };
  assert.deepEqual(unseenMark(unopened, 'Not opened yet'), { attr: ' class="is-unseen"', note: '<span class="unseen-note">Not opened yet</span>' });
  assert.deepEqual(unseenMark({ pend: '8', modeF: { pending: { id: 8, unseen: false } } }, 'x'), { attr: '', note: '' });
  assert.deepEqual(unseenMark({ name: 'A fountain' }, 'x'), { attr: '', note: '' }, 'a live place never');
  assert.equal(unseenMark(unopened, '<b>').note, '<span class="unseen-note">&lt;b&gt;</span>', 'the words are escaped');
});
