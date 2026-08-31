// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The browser decoder must read exactly what MarkerCodec (PHP) writes. Both
// sides are pinned to tests/js/fixtures/translate-marks.json.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'translate-mode.js'), 'utf8');
// The decoder is exported for tests through a guarded CommonJS hook at the end of the file.
const mod = { exports: {} };
new Function('module', 'exports', 'window', 'document', src)(mod, mod.exports, undefined, undefined);
const { decode, MARK_RE } = mod.exports;

const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'translate-marks.json'), 'utf8'));

test('decodes every fixture row', () => {
  for (const row of fixture) {
    const m = MARK_RE.exec(row.encoded);
    assert.ok(m, `no match for id ${row.id}`);
    assert.deepEqual(decode(m[1]), { id: row.id, stale: row.stale });
    assert.equal(m[2], row.text);
  }
});

test('a lone zero-width character is not a mark', () => {
  assert.equal(MARK_RE.exec('a​b'), null);
});
