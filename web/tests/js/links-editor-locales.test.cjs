// SPDX-License-Identifier: AGPL-3.0-only
//
// contribute/links-editor.js: the per-address language picker offers the
// languages this deployment serves (CC_LINKS.locales, from the Languages
// provider), named from CC_LINKS.localeNames. An address already tagged with
// a language that is no longer served keeps its tag, shown by name, and the
// saved value carries it unchanged.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SRC = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/contribute/links-editor.js'), 'utf8');

function element(tag) {
  return {
    tagName: tag.toUpperCase(),
    children: [],
    attrs: {},
    dataset: {},
    className: '',
    value: '',
    selected: false,
    _text: '',
    set textContent(v) { this._text = v; if ('' === v) this.children = []; },
    get textContent() { return this._text; },
    setAttribute(k, v) { this.attrs[k] = v; },
    appendChild(c) { this.children.push(c); c.parentNode = this; return c; },
    insertBefore(c) { this.children.push(c); c.parentNode = this; return c; },
    addEventListener() {},
  };
}

function all(node, tag, out = []) {
  for (const c of node.children) {
    if (c.tagName === tag) out.push(c);
    all(c, tag, out);
  }
  return out;
}

function mount(links) {
  const parent = element('div');
  const hidden = element('input');
  hidden.value = JSON.stringify(links);
  parent.appendChild(hidden);
  const window = {
    CC_LINKS: {
      locales: ['en', 'nl'],
      localeNames: { en: 'English', fr: 'Français', nl: 'Nederlands', de: 'Deutsch', es: 'Español' },
      i18n: {},
    },
  };
  vm.runInNewContext(SRC, { window, document: { createElement: element } });
  window.Cc.mountLinksEditor(hidden);

  return { hidden, selects: all(parent, 'SELECT') };
}

const options = select => select.children.map(o => [o.value, o.textContent, o.selected]);

test('the picker offers only the served languages, by name', () => {
  const { selects } = mount([{ urls: [{ url: 'https://a.example/' }, { url: 'https://a.example/nl', locale: 'nl' }] }]);
  assert.equal(selects.length, 1, 'the first address has no picker');
  assert.deepEqual(options(selects[0]).map(([v, t]) => [v, t]), [['', 'any language'], ['en', 'English'], ['nl', 'Nederlands']]);
  assert.equal(options(selects[0]).find(([, , s]) => s)[0], 'nl');
});

test('a stored tag for a language no longer served is kept and named', () => {
  const { hidden, selects } = mount([{ urls: [{ url: 'https://a.example/' }, { url: 'https://a.example/fr', locale: 'fr' }] }]);
  const opts = options(selects[0]);
  assert.deepEqual(opts.map(([v]) => v), ['', 'en', 'nl', 'fr']);
  assert.deepEqual(opts[3], ['fr', 'Français', true]);
  assert.equal(JSON.parse(hidden.value)[0].urls[1].locale, 'fr', 'the saved value keeps the tag');
});
