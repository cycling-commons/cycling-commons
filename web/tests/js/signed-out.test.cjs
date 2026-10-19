// SPDX-License-Identifier: AGPL-3.0-only
//
// Signing out forgets the ride being planned in this browser: the draft and
// its undo and redo steps (owner 2026-10-15, route-planner.md section 6.0).
// The server marks the way home with `#signed-out`; signed-out.js, on every
// page of the base layout, removes the two planner keys and the mark. The
// script runs here against a stand-in browser, and its keys are pinned to the
// planner's own, so a renamed key cannot leave a ride behind.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const script = read('assets/js/signed-out.js');
const plan = read('assets/map/plan.js');
const legStore = read('assets/map/leg-store.js');
const base = read('templates/base.html.twig');

function run(hash) {
  const store = new Map([['cc-plan-draft', '{}'], ['cc-plan-history', '{}'], ['cc-theme', 'dark']]);
  const replaced = [], deleted = [];
  const context = {
    location: { hash, pathname: '/nl/', search: '?x=1' },
    localStorage: { removeItem: k => store.delete(k) },
    indexedDB: { deleteDatabase: name => deleted.push(name) },
    history: { replaceState: (s, t, url) => replaced.push(url) },
  };
  vm.runInNewContext(script, context);
  return { store, replaced, deleted };
}

test('after signing out the planner keys go, and nothing else does', () => {
  const { store, replaced, deleted } = run('#signed-out');
  assert.deepEqual(deleted, ['cc-plan'], 'the legs routed for the ride stay');
  assert.equal(store.has('cc-plan-draft'), false);
  assert.equal(store.has('cc-plan-history'), false);
  assert.equal(store.get('cc-theme'), 'dark', 'the display preferences stay');
  assert.deepEqual(replaced, ['/nl/?x=1'], 'the mark leaves the address bar');
});

test('any other visit leaves the ride alone', () => {
  for (const hash of ['', '#map', '#signed-outx']) {
    const { store, replaced, deleted } = run(hash);
    assert.equal(store.size, 3, `${hash || 'no hash'} removed something`);
    assert.deepEqual(deleted, [], `${hash || 'no hash'} deleted the legs`);
    assert.deepEqual(replaced, []);
  }
});

test('the script forgets exactly the keys the planner writes', () => {
  const keys = [...plan.matchAll(/const (?:DRAFT|HISTORY)_KEY = '([^']+)';/g)].map(m => m[1]).sort();
  assert.deepEqual(keys, ['cc-plan-draft', 'cc-plan-history']);
  for (const k of keys) assert.ok(script.includes(`'${k}'`), `signed-out.js does not remove ${k}`);
  const db = (legStore.match(/export const LEG_DB = '([^']+)';/) || [])[1];
  assert.equal(db, 'cc-plan');
  assert.ok(script.includes(`deleteDatabase('${db}')`), 'signed-out.js does not delete the legs\' database');
});

test('every page of the base layout carries the script', () => {
  assert.match(base, /<script src="\{\{ asset\('js\/signed-out\.js'\) \}\}"><\/script>/);
});
