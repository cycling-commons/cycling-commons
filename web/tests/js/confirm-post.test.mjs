// SPDX-License-Identifier: AGPL-3.0-only
//
// The drawer's Confirm button, when the page's token has gone stale. A tab
// opened before the session changed holds a dead token; the server answers
// 403 {error:'invalid_token'} (GlitchTip #8, 2026-09-28). The button used to
// say "please try again" and resend the same dead token, so every retry failed
// until the page was reloaded. Now it fetches a fresh token once and resends;
// a rider who is signed out is told to sign in.
import test from 'node:test';
import assert from 'node:assert/strict';
import { postConfirm } from '../../assets/map/confirm-post.js';

const reply = (status, body) => Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(body) });

function server(answers) {
  const sent = [];
  const fetch = (url, init) => { sent.push(new URLSearchParams(init.body).get('_token')); return answers.shift()(); };
  return { fetch, sent };
}

test('a live token confirms in one request', async () => {
  const { fetch, sent } = server([() => reply(200, { ok: true, total: 1 })]);
  const out = await postConfirm({ id: 7, stance: 'exists', token: 'live', fetch, refreshToken: () => assert.fail('no refresh needed') });
  assert.deepEqual(out, { ok: true, data: { ok: true, total: 1 } });
  assert.deepEqual(sent, ['live']);
});

test('a dead token is swapped for a fresh one and the click is resent once', async () => {
  const { fetch, sent } = server([
    () => reply(403, { error: 'invalid_token' }),
    () => reply(200, { ok: true, total: 2 }),
  ]);
  const out = await postConfirm({ id: 7, stance: 'exists', token: 'dead', fetch, refreshToken: () => Promise.resolve('fresh') });
  assert.deepEqual(out, { ok: true, data: { ok: true, total: 2 } });
  assert.deepEqual(sent, ['dead', 'fresh']);
});

test('no fresh token means the rider is signed out', async () => {
  const { fetch } = server([() => reply(403, { error: 'invalid_token' })]);
  const out = await postConfirm({ id: 7, stance: 'exists', token: 'dead', fetch, refreshToken: () => Promise.resolve(null) });
  assert.deepEqual(out, { ok: false, reason: 'login' });
});

test('a fresh token that is refused too is not retried forever', async () => {
  const { fetch, sent } = server([
    () => reply(403, { error: 'invalid_token' }),
    () => reply(403, { error: 'invalid_token' }),
  ]);
  const out = await postConfirm({ id: 7, stance: 'exists', token: 'dead', fetch, refreshToken: () => Promise.resolve('fresh') });
  assert.deepEqual(out, { ok: false, reason: 'error' });
  assert.equal(sent.length, 2);
});

test('a 401 asks the rider to sign in, not to try again', async () => {
  const { fetch } = server([() => reply(401, {})]);
  const out = await postConfirm({ id: 7, stance: 'exists', token: 'x', fetch, refreshToken: () => assert.fail('no refresh for a 401') });
  assert.deepEqual(out, { ok: false, reason: 'login' });
});

test('any other failure is an error the rider may retry', async () => {
  const { fetch } = server([() => reply(500, {})]);
  const out = await postConfirm({ id: 7, stance: 'exists', token: 'x', fetch, refreshToken: () => Promise.resolve('y') });
  assert.deepEqual(out, { ok: false, reason: 'error' });
});

test('the request carries the stance and asks for JSON', async () => {
  let seen;
  const fetch = (url, init) => { seen = { url, init }; return reply(200, {}); };
  await postConfirm({ id: 42, stance: 'potable', token: 't', fetch, refreshToken: () => null });
  assert.equal(seen.url, '/items/42/confirm');
  assert.equal(seen.init.method, 'POST');
  assert.equal(new URLSearchParams(seen.init.body).get('stance'), 'potable');
  assert.equal(seen.init.headers.Accept, 'application/json');
});
