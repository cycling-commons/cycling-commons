// SPDX-License-Identifier: AGPL-3.0-only
//
// Translate mode remembers Browse across pages in the same tab, until the
// translator picks Edit or turns the mode off (translations.md §4.1, the bar).
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'translate-mode.js'), 'utf8');
const mod = { exports: {} };
new Function('module', 'exports', 'window', 'document', src)(mod, mod.exports, undefined, undefined);
const { startsEditing, rememberEditing, forgetEditing } = mod.exports;

function memoryStorage() {
  const data = new Map();
  return {
    getItem: (k) => (data.has(k) ? data.get(k) : null),
    setItem: (k, v) => data.set(k, String(v)),
    removeItem: (k) => data.delete(k),
  };
}

const blocked = {
  getItem() { throw new Error('SecurityError'); },
  setItem() { throw new Error('SecurityError'); },
  removeItem() { throw new Error('SecurityError'); },
};

test('a first page opens in Edit', () => {
  assert.equal(startsEditing(memoryStorage()), true);
});

test('Browse carries to the next page', () => {
  const s = memoryStorage();
  rememberEditing(s, false);
  assert.equal(startsEditing(s), false);
});

test('picking Edit again carries Edit', () => {
  const s = memoryStorage();
  rememberEditing(s, false);
  rememberEditing(s, true);
  assert.equal(startsEditing(s), true);
});

test('turning the mode off forgets Browse', () => {
  const s = memoryStorage();
  rememberEditing(s, false);
  forgetEditing(s);
  assert.equal(startsEditing(s), true);
});

test('blocked or missing storage opens in Edit and never throws', () => {
  for (const s of [blocked, null, undefined]) {
    assert.doesNotThrow(() => rememberEditing(s, false));
    assert.doesNotThrow(() => forgetEditing(s));
    assert.equal(startsEditing(s), true);
  }
});
