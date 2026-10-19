// SPDX-License-Identifier: AGPL-3.0-only
//
// The filter pill folds into a "!" mark (owner 2026-10-15: "only a ! mark,
// when hovered or clicked you see the message, and clicked again it changes
// back into a ! mark"). docs/specs/map-and-search.md section 4.0.
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const twig = read('templates/map/index.html.twig');
const css = read('assets/styles/map.css');
const panels = read('assets/map/panels.js');

test('the pill is a ! button that owns the message and the reset', () => {
  const pill = twig.slice(twig.indexOf('id="fpill"'), twig.indexOf('</div>', twig.indexOf('id="fpill"')));
  assert.match(pill, /<button type="button" class="fpill-mark" id="fpillMark" aria-expanded="false" aria-controls="fpillBody"[^>]*>!<\/button>/);
  assert.match(pill, /id="fpillBody"[\s\S]*id="fpillMsg"[\s\S]*id="fpillReset"/);
});

test('folded, only the mark shows; hover, focus or an open pill shows the message', () => {
  assert.match(css, /\.fpill-body\{display:none/);
  assert.match(css, /\.fpill:hover \.fpill-body,\.fpill:focus-within \.fpill-body,\.fpill\.open \.fpill-body\{display:inline-flex/);
});

test('a click on the mark opens the pill, the next click folds it again', () => {
  assert.match(panels, /mark\.onclick=\(\)=>\{ const open=!pill\.classList\.contains\('open'\); pill\.classList\.toggle\('open', open\); mark\.setAttribute\('aria-expanded', String\(open\)\); \};/);
});

test('the ! sits at the left edge above the zoom buttons, and above the profile strip when it is open (owner 2026-10-15)', () => {
  assert.match(css, /\.fpill\{position:absolute;left:\.75rem;bottom:13\.2rem;z-index:1200;/);
  // Above the zoom column, which sits on the strip with the map's credits (owner 2026-10-16).
  assert.match(css, /\.map-wrap\.cc-strip-on \.fpill\{bottom:calc\(140px \+ 13\.2rem\)\}/);
  // Above the zoom-level badge too: with the compass the corner is 156 px tall and starts 46 px up, so the badge's
  // top is at 202 px (owner 2026-10-16: the mark sat on the badge; again 2026-10-19 once the compass came).
  assert.match(css, /\.fpill\{position:absolute;left:\.75rem;bottom:13\.2rem;/);
  assert.ok(!/\.fpill\{[^}]*translateX\(-50%\)/.test(css), 'the pill is still centred');
});
