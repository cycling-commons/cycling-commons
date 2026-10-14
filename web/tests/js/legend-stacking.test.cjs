// SPDX-License-Identifier: AGPL-3.0-only
//
// The map key legend (the road-surface and routes box on the map) always sits
// in front of the feature drawer, on every screen width (owner 2026-10-14):
// a rider reading a surface colour must see the key, whatever is open beside
// it. A structural pin over map.css, read in two contexts: the base rules,
// and the rules inside the phone media blocks, since the drawer becomes a
// sheet with its own stacking there.
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const css = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'styles', 'map.css'), 'utf8');

/* The base sheet without its media blocks, and the bodies of the phone blocks. */
function split(text) {
  let base = '', phone = '';
  let i = 0;
  while (i < text.length) {
    const at = text.indexOf('@media', i);
    if (at < 0) { base += text.slice(i); break; }
    base += text.slice(i, at);
    const open = text.indexOf('{', at);
    let depth = 1, j = open + 1;
    while (j < text.length && depth > 0) { if (text[j] === '{') depth++; else if (text[j] === '}') depth--; j++; }
    const head = text.slice(at, open);
    const body = text.slice(open + 1, j - 1);
    if (/max-width:\s*820px/.test(head)) phone += body + '\n';
    i = j;
  }
  return { base, phone };
}
const { base, phone } = split(css);

function zIndexes(text, selector) {
  const out = [];
  const re = new RegExp('(?:^|[\\s,}])' + selector.replace(/\./g, '\\.') + '\\{([^}]*)\\}', 'g');
  for (const m of text.matchAll(re)) {
    const z = m[1].match(/z-index:(\d+)/);
    if (z) out.push(Number(z[1]));
  }
  return out;
}

test('the legend stacks above the feature drawer on a desktop', () => {
  const legend = zIndexes(base, '.legend'), drawer = zIndexes(base, '.cc-drawer');
  assert.ok(legend.length && drawer.length, 'no z-index on .legend or .cc-drawer');
  assert.ok(Math.max(...legend) > Math.max(...drawer), `legend ${legend} must stack above the drawer ${drawer}`);
});

test('the legend stacks above the drawer sheet on a phone', () => {
  const legend = zIndexes(phone, '.legend'), drawer = zIndexes(phone, '.cc-drawer');
  assert.ok(drawer.length, 'the phone block no longer restacks the drawer');
  assert.ok(legend.length, 'the phone block gives the legend no z-index of its own');
  assert.ok(Math.max(...legend) > Math.max(...drawer), `phone: legend ${legend} must stack above the sheet ${drawer}`);
});

test('the legend stays below the pick bar, tooltips, the lightbox and the climb profile on a desktop', () => {
  const legend = Math.max(...zIndexes(base, '.legend'));
  for (const sel of ['.cc-pickbar', '.cc-tooltip', '.cc-lightbox', '.cc-cp']) {
    const z = zIndexes(base, sel);
    assert.ok(z.length && Math.min(...z) > legend, `${sel} must stack above the legend`);
  }
});

test('the legend stays below the lightbox and the climb profile on a phone', () => {
  const legend = Math.max(...zIndexes(phone, '.legend'));
  for (const sel of ['.cc-lightbox', '.cc-cp']) {
    const z = zIndexes(phone, sel);
    assert.ok(z.length && Math.min(...z) > legend, `phone: ${sel} must stack above the legend`);
  }
});
