// SPDX-License-Identifier: AGPL-3.0-only
// The ballot countdown's steps (owner 2026-10-03), the same as App\Vote\Countdown.
const test = require('node:test');
const assert = require('node:assert/strict');
const { step, fill } = require('../../assets/js/countdown.js');

test('more than two days left counts days', () => {
  assert.deepEqual(step(58 * 86400 + 3600), { key: 'days', vars: { '%count%': '58' } });
  assert.equal(step(172800).key, 'days');
});

test('the last two days count hours', () => {
  assert.deepEqual(step(172799), { key: 'hours', vars: { '%count%': '47' } });
  assert.deepEqual(step(86400), { key: 'hours', vars: { '%count%': '24' } });
});

test('the last day counts hours and minutes', () => {
  assert.deepEqual(step(5 * 3600 + 12 * 60 + 40), { key: 'hm', vars: { '%h%': '5', '%m%': '12' } });
});

test('the last hour is a clock to the second', () => {
  assert.deepEqual(step(42 * 60 + 13), { key: 'hms', vars: { '%time%': '00:42:13' } });
  assert.deepEqual(step(5), { key: 'hms', vars: { '%time%': '00:00:05' } });
});

test('at the deadline voting has closed', () => {
  assert.equal(step(0).key, 'closed');
  assert.equal(step(-10).key, 'closed');
});

test('a step fills its template', () => {
  assert.equal(fill('%h% h %m% min left', { '%h%': '5', '%m%': '12' }), '5 h 12 min left');
});
