// SPDX-License-Identifier: AGPL-3.0-only
//
// Map chrome theme (map-and-search.md §4.6). The mechanism is one attribute:
// html[data-map-theme="light"] flips the chrome token values that :root
// defines dark. These pins hold the pieces together:
//
//  - the tokens exist in BOTH places (a token defined only in :root silently
//    never themes; one defined only in the light block breaks dark mode);
//  - the server renders the attribute (anti-flash), and the anonymous
//    localStorage boot runs ONLY for visitors — a logged-in rider's profile
//    value must never lose to a shared device's localStorage;
//  - theme.js persists through CC_MAP_THEME when present and localStorage
//    when not, the exact split panels.js uses for the view mode.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');

const css = read('assets/styles/map.css');
const twig = read('templates/map/index.html.twig');
const themeJs = read('assets/map/theme.js');
const entry = read('assets/map/map.js');

// Every chrome token the theme flips. Extend this list when a new token joins.
const TOKENS = ['--chrome-bg', '--chrome-fg', '--chrome-fg-solid', '--chrome-glass', '--chrome-head'];

test('every chrome token has a dark default and a light override', () => {
  const rootAt = css.indexOf(':root{');
  assert.ok(rootAt >= 0, 'map.css has no :root block');
  const lightMatch = css.match(/html\[data-map-theme="light"\]\{([\s\S]*?)\}/);
  assert.ok(lightMatch, 'map.css has no html[data-map-theme="light"] block');
  const rootBlock = css.slice(rootAt, css.indexOf('}', rootAt));
  for (const t of TOKENS) {
    assert.ok(rootBlock.includes(t + ':'), `${t} missing from :root (dark default)`);
    assert.ok(lightMatch[1].includes(t + ':'), `${t} missing from the light override block`);
  }
});

test('the chrome no longer hardcodes the dark paper/ink literals', () => {
  // The tokenisation is only real if the old literals are gone: a stray
  // rgba(239,230,212,…) is a surface that stays dark-themed in light mode.
  assert.equal((css.match(/rgba\(239,\s*230,\s*212/g) || []).length, 0,
    'rgba(239,230,212,…) literals remain — use rgb(var(--chrome-fg) / a)');
  assert.equal((css.match(/rgba\(20,\s*22,\s*14/g) || []).length, 0,
    'rgba(20,22,14,…) literals remain — use rgb(var(--chrome-glass) / a)');
});

test('the server renders the theme attribute and the anonymous boot stays visitor-only', () => {
  assert.ok(twig.includes('data-map-theme="{{ map_theme }}"'),
    '<html> does not carry the server-rendered theme');
  const anonBlock = twig.match(/\{% if not is_granted\('ROLE_USER'\) %\}([\s\S]*?)\{% endif %\}/);
  assert.ok(anonBlock, 'no visitor-only block in the head');
  assert.ok(anonBlock[1].includes("localStorage.getItem('cc:mapTheme')"),
    'the anonymous boot script does not read cc:mapTheme');
  assert.ok(twig.includes('window.CC_MAP_THEME'),
    'the riders-only block does not emit CC_MAP_THEME');
});

test('chrome text never drops below the AA-contrast alpha floor', () => {
  // WCAG AA (owner-reported 2026-08-20): fg-token TEXT at alpha .5 measures
  // ~4.4:1 on the dark chrome and ~3.2:1 on the light chrome; AA small text
  // needs 4.5:1, and .65 is the lowest alpha that passes BOTH themes.
  // `color:` declarations only - borders/backgrounds are decorative, and
  // state-dimming via `opacity` (off/disabled rows) is a control state, not
  // body copy. scrollbar-color is excluded by the lookbehind.
  const bad = [];
  for (const m of css.matchAll(/(?<![a-z-])color:rgb\(var\(--chrome-fg\) \/ \.(\d+)\)/g)) {
    const alpha = parseFloat('0.' + m[1]);
    if (alpha < 0.65) bad.push(m[0]);
  }
  assert.deepEqual(bad, [], 'text alphas below the .65 floor: ' + bad.join(', '));
});

test('theme.js toggles the attribute and persists profile-first', () => {
  assert.ok(themeJs.includes("setAttribute('data-map-theme'"), 'theme.js never sets the attribute');
  assert.ok(themeJs.includes('window.CC_MAP_THEME'), 'theme.js ignores the profile endpoint');
  assert.ok(themeJs.includes("'cc:mapTheme'") && themeJs.includes('localStorage.setItem('),
    'theme.js has no localStorage fallback for visitors (key cc:mapTheme)');
  assert.ok(entry.match(/initTheme.*from '\.\/theme\.js'/s) || entry.includes("from './theme.js'"),
    'map.js never wires theme.js in');
});
