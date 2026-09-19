// SPDX-License-Identifier: AGPL-3.0-only
//
// One way to write a date (account-and-auth.md §9, owner 2026-09-20).
//
// A rider picks how dates are written for them once, in their settings. Two
// halves carry that choice: `cc_date`/`cc_datetime`/`cc_month` on the server,
// and `window.ccDate`/`ccMonth`/`ccTime` in the browser, both reading the same
// preference. Anything that formats a date itself writes it in a format the
// rider did not choose, which is how a footer ends up saying 2026-09-20 to
// somebody whose settings say 20-09-2026.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');

test('the map carries the rider date bridge, as it carries the units one', () => {
  const map = read('templates/map/index.html.twig');
  assert.match(map, /window\.CC_DATE = /, 'the map does not extend base.html.twig, so it sets CC_DATE itself');
  assert.match(map, /cc_user_date_format\(\)/);
  assert.match(map, /cc_user_time_format\(\)/);
  assert.ok(map.includes("asset('js/cc-dates.js')"), 'and loads the formatter that reads it');
});

test('the build stamp is written in the rider format', () => {
  const version = read('assets/js/version.js');
  assert.match(version, /window\.ccDate/, 'version.js must format the build date through ccDate');
  assert.ok(!/\+ V\.date/.test(version), 'version.js still prints the raw ISO date somewhere');
});

test('no template prints a date value raw', () => {
  const walk = dir => fs.readdirSync(path.join(ROOT, dir), {withFileTypes: true}).flatMap(e => {
    const rel = dir + '/' + e.name;
    return e.isDirectory() ? walk(rel) : (e.name.endsWith('.twig') ? [rel] : []);
  });
  // A machine-readable value is not a date a person reads: the Atom feed is
  // ISO by its own spec, and `datetime="..."` attributes are ISO by HTML's.
  const skip = new Set(['templates/pages/changelog.atom.twig']);
  // `{{ x.takenAt }}`, `{{ h.when }}` and friends: a property whose name says
  // it is a moment, printed with no filter on it.
  const raw = /\{\{-?\s*[a-zA-Z_][\w.]*(?:[Aa]t|[Dd]ate|[Dd]ateTime)\s*-?\}\}/;
  for (const file of walk('templates')) {
    if (skip.has(file)) continue;
    for (const [i, line] of read(file).split('\n').entries()) {
      if (line.includes('datetime=')) continue;
      assert.ok(!raw.test(line), `${file}:${i + 1} prints a date with no cc_date/cc_datetime/cc_month on it:\n${line.trim()}`);
    }
  }
});
