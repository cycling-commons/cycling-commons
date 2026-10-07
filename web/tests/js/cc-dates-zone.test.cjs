// SPDX-License-Identifier: AGPL-3.0-only
//
// Times in the rider's zone (docs/specs/account-and-auth.md §9): cc-dates.js
// writes them in the chosen zone, and while the choice is automatic it reports
// the browser's own zone once when it differs from the stored one.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SRC = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'cc-dates.js'), 'utf8');

function load(cfg) {
  const posts = [];
  const window = {
    CC_DATE: cfg,
    fetch: (url, init) => { posts.push({ url, init }); return Promise.resolve({ ok: true }); },
  };
  const context = { window, document: { documentElement: { lang: 'en' }, readyState: 'complete', addEventListener() {} }, Intl, Date, Promise, JSON };
  vm.runInNewContext(SRC, context);
  return { window, posts };
}

const WHEN = '2026-08-01T14:30:00Z';

test('a chosen zone writes the time in that zone, whatever the browser is in', () => {
  const { window } = load({ format: 'ymd', time: 'h24', locale: 'en', timeZone: 'Europe/Amsterdam' });
  assert.equal(window.ccDateTime(WHEN), '2026-08-01 16:30');
  assert.equal(load({ format: 'ymd', time: 'h24', locale: 'en', timeZone: 'Asia/Tokyo' }).window.ccDateTime(WHEN), '2026-08-01 23:30');
});

test('the date follows the zone too, across midnight', () => {
  const { window } = load({ format: 'dmy', time: 'h24', locale: 'en', timeZone: 'Asia/Tokyo' });
  assert.equal(window.ccDate('2026-08-01T20:00:00Z'), '02-08-2026');
});

test('while automatic, the browser reports its zone when it differs from the stored one', () => {
  const own = Intl.DateTimeFormat().resolvedOptions().timeZone;
  const { posts } = load({ format: 'auto', time: 'auto', locale: 'en', report: { token: 't0k', url: '/account/time-zone', detected: 'Pacific/Chatham' } });
  assert.equal(posts.length, 1);
  assert.equal(posts[0].url, '/account/time-zone');
  assert.equal(posts[0].init.headers['X-CC-Token'], 't0k');
  assert.deepEqual(JSON.parse(posts[0].init.body), { zone: own });
});

test('nothing is reported when the stored zone already matches, or when a zone was chosen', () => {
  const own = Intl.DateTimeFormat().resolvedOptions().timeZone;
  assert.equal(load({ format: 'auto', time: 'auto', locale: 'en', report: { token: 't', url: '/u', detected: own } }).posts.length, 0);
  assert.equal(load({ format: 'auto', time: 'auto', locale: 'en', timeZone: 'Europe/Lisbon' }).posts.length, 0);
});

test('every page hands the script the zone and, while automatic, how to report', () => {
  const read = p => fs.readFileSync(path.join(__dirname, '..', '..', p), 'utf8');
  const boot = read('templates/boot.js.twig');
  assert.match(boot, /timeZone: d\.ccTimeZone \|\| null/);
  assert.match(boot, /report: d\.ccTzToken \?/);
  const map = read('templates/map/index.html.twig');
  assert.match(map, /timeZone: app\.user\.timeZone \?\? null/);
  assert.match(map, /csrf_token\('time-zone'\)/);
});
