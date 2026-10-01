// SPDX-License-Identifier: AGPL-3.0-only
//
// A text's credit line ends in the same two ringed icons on the town card
// (script, assets/map/town-text.js) and on the region page (Twig,
// templates/partials/_text_credit_actions.html.twig): "!" to report the text
// and "✎" to edit it, styled once in styles/text-credit.css
// (docs/specs/moderation-and-contribution.md §3.1b). The partial is rendered
// here by a small reader of the few Twig forms it uses, and must give the
// script's markup byte for byte.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { textCreditActionsHtml } from '../../assets/map/town-text.js';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (...p) => fs.readFileSync(path.join(root, ...p), 'utf8');
const partial = read('templates', 'partials', '_text_credit_actions.html.twig');
const css = read('assets', 'styles', 'text-credit.css');

const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

/* Renders the partial: comments, whitespace control, `set`, `if a [or b]`
   and `{{ name }}`, which is all it uses. */
function renderPartial(vars) {
  const src = partial
    .replace(/\{#[\s\S]*?#\}/g, '')
    .replace(/\s*\{%-/g, '{%').replace(/-%\}\s*/g, '%}');
  const tokens = src.split(/(\{%[\s\S]*?%\}|\{\{[\s\S]*?\}\})/).filter((t) => t !== '');
  const truthy = (expr) => expr.split(/\s+or\s+/).some((name) => !!vars[name.trim()]);
  let out = '';
  const live = [true];
  for (const t of tokens) {
    const tag = /^\{%\s*([\s\S]*?)\s*%\}$/.exec(t);
    if (tag) {
      const body = tag[1];
      if (body.startsWith('if ')) live.push(live[live.length - 1] && truthy(body.slice(3)));
      else if (body === 'endif') live.pop();
      else if (!body.startsWith('set ')) throw new Error('unexpected tag ' + body);
      continue;
    }
    if (!live[live.length - 1]) continue;
    const v = /^\{\{\s*(\w+)\s*\}\}$/.exec(t);
    out += v ? esc(vars[v[1]] ?? '') : t;
  }
  assert.equal(live.length, 1, 'balanced if/endif');
  return out;
}

const CASES = [
  {
    report_href: '/report/region/42?from=/regions/flanders', report_label: 'Report this text',
    edit_href: '/regions/flanders/text', edit_label: 'Edit this text',
  },
  { report_href: '/report/town/node-1?name=A&from=/map', report_label: 'Report this text', edit_href: '', edit_label: 'Edit this text' },
  { report_href: '', report_label: 'Report this text', edit_href: '/regions/x/text', edit_label: 'Edit this text' },
  { report_href: '', report_label: 'x', edit_href: '', edit_label: 'y' },
];

test('the Twig partial and the town card draw the same two icons, byte for byte', () => {
  for (const c of CASES) {
    const js = textCreditActionsHtml({ reportHref: c.report_href, reportLabel: c.report_label, editHref: c.edit_href, editLabel: c.edit_label });
    assert.equal(renderPartial(c), js, JSON.stringify(c));
  }
  const both = renderPartial(CASES[0]);
  assert.equal(both,
    '<span class="tc-acts"><a class="ring-ico ring-ico--report" href="/report/region/42?from=/regions/flanders" title="Report this text" aria-label="Report this text">!</a>'
    + '<a class="ring-ico ring-ico--edit" href="/regions/flanders/text" title="Edit this text" aria-label="Edit this text">✎</a></span>');
  assert.equal(renderPartial(CASES[3]), '', 'nothing when neither has an address');
});

test('one stylesheet defines the credit line and the ringed icon, and no other sheet does', () => {
  for (const sel of ['.tc-line{', '.tc-line a{', '.tc-acts{', '.ring-ico{', '.ring-ico--edit{']) {
    assert.ok(css.includes(sel), sel);
  }
  const others = ['map.css', 'atlas.css', path.join('page', 'pages', 'region.css')]
    .map((f) => read('assets', 'styles', f).replace(/\/\*[\s\S]*?\*\//g, ''));
  for (const sheet of others) {
    assert.doesNotMatch(sheet, /\.(ring-ico|tc-acts|tc-line|cc-bang|cc-pen|rep-bang|rg-about-pen)\b[^{]*\{/, 'the ring has one definition');
  }
});

test('the icons are never underlined, and the line\'s text links are', () => {
  // `.tc-line a` underlines the credit links; the icon rule outranks both it
  // and the map's `.cc-city-links a` (0,1,1).
  assert.match(css, /\.tc-line a\{[^}]*text-decoration:underline/);
  assert.match(css, /\.tc-line a\.ring-ico[^{]*\{text-decoration:none\}/);
  assert.match(css, /\.ring-ico\{[^}]*display:inline-flex[^}]*text-decoration:none/s);
  const map = read('assets', 'styles', 'map.css');
  assert.doesNotMatch(map, /\.cc-city-links a\{[^}]*text-decoration/, 'the map leaves underlines to the shared sheet');
});

test('the map page and every site page load the shared sheet, and the pages use it', () => {
  assert.match(read('templates', 'map', 'index.html.twig'), /asset\('styles\/text-credit\.css'\)/);
  assert.match(read('templates', 'partials', '_head.html.twig'), /asset\('styles\/text-credit\.css'\)/);
  const region = read('templates', 'pages', 'region.html.twig');
  assert.match(region, /include\('partials\/_text_credit_actions\.html\.twig'/);
  assert.match(region, /class="rg-about-attrib tc-line"/);
  assert.match(read('assets', 'map', 'places.js'), /<div class="cc-city-links tc-line">/);
});
