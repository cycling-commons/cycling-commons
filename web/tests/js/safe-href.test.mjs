// SPDX-License-Identifier: AGPL-3.0-only
//
// safeHref() is the one gate for every link the map modules build
// (security-architecture.md §4.2): http(s) or a path on this site, else '#'.
// OpenStreetMap tags reach it verbatim, so a browser's reading of the path is
// what counts: `/\host` and a path with a tab or a newline in it resolve to
// another host (audit 2026-10-04).
import test from 'node:test';
import assert from 'node:assert/strict';
import { safeHref } from '../../assets/map/util.js';

test('web addresses and site paths pass', () => {
  assert.equal(safeHref('https://example.org/a?b=1'), 'https://example.org/a?b=1');
  assert.equal(safeHref('http://example.org'), 'http://example.org');
  assert.equal(safeHref('/regions/noord-holland'), '/regions/noord-holland');
  assert.equal(safeHref('https://example.org/a b'), 'https://example.org/a b', 'a plain space leads nowhere else');
});

test('anything that leaves the site by another road is refused', () => {
  for (const bad of [
    '//evil.example/x',
    '/\\evil.example/x',
    '/\\\\evil.example',
    '/\t/evil.example',
    '/\n/evil.example',
    '/a\\b',
    'javascript:alert(1)',
    'evil.example',
    '',
  ]) {
    assert.equal(safeHref(bad), '#', JSON.stringify(bad));
  }
});
