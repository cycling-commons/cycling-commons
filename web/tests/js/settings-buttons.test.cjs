// SPDX-License-Identifier: AGPL-3.0-only
//
// The settings page's buttons (owner 2026-10-15: "less vertical spacing, and
// transparent buttons with orange text and border"): an outline in the brand
// orange with text in a deeper orange that keeps 4.5:1 on the page, and a
// small gap between the last field and the button.
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const css = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/styles/page/settings/index.css'), 'utf8');

const rule = sel => { const m = css.match(new RegExp('(^|\\n)' + sel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\{([^}]*)\\}')); return m ? m[2] : ''; };
const lum = hex => { const [r, g, b] = [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16) / 255).map(c => c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };

test('a settings button is an orange outline, not a filled block', () => {
  const btn = rule('.btn');
  assert.match(btn, /background:transparent/);
  assert.match(btn, /border:1\.5px solid var\(--trail\)/);
  const text = (btn.match(/color:(#[0-9A-Fa-f]{6})/) || [])[1];
  assert.ok(text, 'the text colour is a literal hex the test can measure');
  assert.ok(ratio(text, '#EFE6D4') >= 4.5, `the button text is ${ratio(text, '#EFE6D4').toFixed(2)}:1 on the page`);
});

test('the gap between the last field and its button is small', () => {
  const m = rule('.submit-row').match(/margin-top:([\d.]+)rem/);
  assert.ok(m && Number(m[1]) <= 0.5, 'the submit row sits more than half a rem below its field');
});

test('a tab never repeats its own name as a heading, and opens without a divider line', () => {
  const twig = fs.readFileSync(path.join(__dirname, '..', '..', 'templates/settings/index.html.twig'), 'utf8');
  const pane = id => twig.slice(twig.indexOf(`id="${id}" role="tabpanel"`), twig.indexOf(`{# /#${id} #}`));
  assert.ok(!/<h2 class="sec"[^>]*>\{\{ 'settings\.presets_h'/.test(pane('map-presets')), 'the Map presets tab repeats its name as a heading');
  assert.ok(!/id="export"[^>]*border-top|border-top[^>]*id="export"/.test(pane('tab-data')), 'the Your data tab opens with a divider line over its first section');
});

test('the first heading of a tab has no top margin', () => {
  assert.match(css, /\.dpane > h2\.sec:first-child,\.dpane > div:first-child > h2\.sec:first-child\{margin-top:0\}/);
});

test('the presets tab uses the page inputs and stays narrow (owner 2026-10-15: no wide fields or cards)', () => {
  assert.ok(!/\.mp-editor input\[type=text\]\{/.test(css), 'the preset editor restyles the page input');
  assert.match(css, /#mpName\{max-width:22rem\}/);
  assert.match(css, /\.mp-list,\.mp-editor\{max-width:42rem\}/);
});

test('an off row in the preset editor is not faded: the switch says off (owner 2026-10-15)', () => {
  assert.ok(!/\.mp-row\.off [^{]*\{[^}]*opacity/.test(css), 'an off row still fades its icon or label');
});

test('no tab heading repeats the name of its tab', () => {
  const twig = fs.readFileSync(path.join(__dirname, '..', '..', 'templates/settings/index.html.twig'), 'utf8');
  const sec = twig.slice(twig.indexOf('id="tab-security" role="tabpanel"'), twig.indexOf('{# /#tab-security #}'));
  assert.ok(!sec.includes("'settings.section_security'|trans"), 'the Security tab opens with a Security heading');
  assert.ok(sec.includes("'settings.section_password'|trans"), 'the password section has no heading of its own');
  const en = fs.readFileSync(path.join(__dirname, '..', '..', 'translations/messages.en.yaml'), 'utf8');
  assert.ok(!/^  section_export: 'Download your data'$/m.test(en), 'the download heading repeats the Your data tab');
});
