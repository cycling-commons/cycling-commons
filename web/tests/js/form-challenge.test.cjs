// SPDX-License-Identifier: AGPL-3.0-only
//
// support/form-challenge.js on a form that also has its own validator (sign-up,
// register-validate.js). The validator runs first and prevents the submit when
// a field is wrong; the challenge script must then leave it alone. It used to
// prevent it too, solve, and call form.submit(), which skips validation and
// posts the bad form anyway, wiping the password boxes on the way back.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SRC = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/support/form-challenge.js'), 'utf8');

function load() {
  const listeners = {};
  const form = {
    dataset: { challengeUrl: '/form-challenge', powDifficulty: '4' },
    submitted: 0,
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    querySelector() { return null; },
    submit() { this.submitted++; },
  };
  const fields = { pow_nonce: { value: '' }, pow_challenge: { value: '' }, 'pow-status': null };
  const context = {
    document: { getElementById: id => (id === 'cc-guarded-form' ? form : fields[id]) },
    window: { ccPow: { available: () => true, solve: () => Promise.resolve('42') } },
    fetch: () => Promise.resolve({ json: () => ({ ok: true, challenge: 'c', difficulty: 4 }) }),
    Promise,
  };
  vm.runInNewContext(SRC, context);
  const submit = event => listeners.submit.forEach(fn => fn(event));
  return { form, submit };
}

function event(prevented) {
  return {
    defaultPrevented: prevented,
    preventDefault() { this.defaultPrevented = true; this.prevents = (this.prevents || 0) + 1; },
  };
}

const settle = () => new Promise(r => setTimeout(r, 10));

test('a submit the validator already stopped is left alone', async () => {
  const { form, submit } = load();
  const e = event(true);
  submit(e);
  await settle();
  assert.equal(e.prevents, undefined, 'the challenge script must not touch a prevented submit');
  assert.equal(form.submitted, 0, 'a form that failed validation must not be posted');
});

test('a valid submit waits for the check, then posts', async () => {
  const { form, submit } = load();
  const e = event(false);
  submit(e);
  assert.equal(e.prevents, 1);
  await settle();
  assert.equal(form.submitted, 1);
});
