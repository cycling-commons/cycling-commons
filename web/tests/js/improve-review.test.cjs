// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// Node tests for the contribute wizard's review card
// (web/assets/contribute/review-card.js, docs/plans/2026-08-01-improve-js-i18n.md).
//
// The card is where two untrusted-ish inputs meet: rider-entered field text,
// which a curator and later the public read back, and catalogue strings, which
// arrive through a JSON blob printed inside a <script> block. It used to be
// built by concatenating HTML, so its safety rested entirely on remembering
// escHtml() at every interpolation. These tests are the regression net for
// exactly that bug: a rider value and a translation value must each produce
// ZERO elements, whatever they contain.
//
// review-card.js is a classic script with the module.exports guard scope.js
// uses, so it requires straight into node. Its builders take the document to
// use, which is what lets this file hand them the shim below.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const RC = require('../../assets/contribute/review-card.js');

/* ---------- a document shim with teeth -------------------------------------

   The point of this shim is that it is NOT a no-op. `textContent` stores a
   string, but `innerHTML` actually parses: every tag in the assigned string
   becomes a child element. So if anyone ever rebuilds these functions on
   innerHTML, the "zero elements" assertions below fail rather than passing
   vacuously. `meta: the shim itself creates elements from markup` locks that
   property in. */
function makeDoc() {
  function createElement(tag) {
    return {
      tagName: String(tag).toUpperCase(),
      className: '',
      childNodes: [],
      _text: '',
      get firstChild() { return this.childNodes[0] || null; },
      appendChild(child) {
        this._text = '';
        this.childNodes.push(child);
        return child;
      },
      removeChild(child) {
        const i = this.childNodes.indexOf(child);
        if (i >= 0) this.childNodes.splice(i, 1);
        return child;
      },
      get textContent() {
        return this.childNodes.length
          ? this.childNodes.map((c) => c.textContent).join('')
          : this._text;
      },
      set textContent(v) {
        this.childNodes = [];
        this._text = String(v);
      },
      get innerHTML() { return ''; },
      set innerHTML(html) {
        this.childNodes = [];
        this._text = String(html).replace(/<[^>]*>/g, '');
        const tags = String(html).match(/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/g) || [];
        tags.forEach((raw) => {
          this.childNodes.push(createElement(raw.replace(/^<([a-zA-Z0-9]+).*$/s, '$1')));
        });
      },
    };
  }
  return { createElement };
}

// Every element below `node`, in document order.
function descendants(node) {
  return node.childNodes.reduce((all, c) => all.concat([c], descendants(c)), []);
}

const XSS_ATTR = '<img src=x onerror="alert(1)">';
const XSS_SCRIPT = '</script><script>alert(1)</script>';

test('meta: the shim itself creates elements from markup, so the assertions below are real', () => {
  const doc = makeDoc();
  const el = doc.createElement('div');
  el.innerHTML = '<b>x</b>';

  assert.equal(descendants(el).length, 1, 'innerHTML must produce an element in this shim');
});

test('a rider value of <img src=x onerror=…> produces zero elements and the exact text', () => {
  const doc = makeDoc();
  const row = RC.kvRow('Note for riders', XSS_ATTR, doc);
  const value = row.childNodes[1];

  assert.equal(descendants(value).length, 0);
  assert.equal(value.textContent, XSS_ATTR);
  // The row itself is only ever the two spans the builder makes.
  assert.equal(descendants(row).length, 2);
  assert.deepEqual(descendants(row).map((n) => n.tagName), ['SPAN', 'SPAN']);
});

test('a translation value of </script><script> produces zero elements and the exact text', () => {
  const doc = makeDoc();
  const row = RC.kvRow(XSS_SCRIPT, 'Fontaine de la Sauvenière', doc);
  const label = row.childNodes[0];

  assert.equal(descendants(label).length, 0);
  assert.equal(label.textContent, XSS_SCRIPT);
});

test('a value of "><b>x does not create a <b>', () => {
  const doc = makeDoc();
  const row = RC.kvRow('Label', '"><b>x', doc);

  assert.equal(descendants(row).filter((n) => n.tagName === 'B').length, 0);
  assert.equal(row.childNodes[1].textContent, '"><b>x');
});

test('the row survives null and undefined rather than printing them', () => {
  const doc = makeDoc();
  const row = RC.kvRow(null, undefined, doc);

  assert.equal(row.childNodes[0].textContent, '');
  assert.equal(row.childNodes[1].textContent, '');
  assert.equal(row.className, 'kv');
});

test('a photo entry with a sm URL produces one <img> whose src is that URL', () => {
  const doc = makeDoc();
  const fig = RC.mediaFigure({ name: 'fountain.jpg', sm: '/media/ab/cd/sm.webp' }, doc);
  const imgs = descendants(fig).filter((n) => n.tagName === 'IMG');

  assert.equal(imgs.length, 1);
  assert.equal(imgs[0].src, '/media/ab/cd/sm.webp');
  assert.equal(imgs[0].alt, 'fountain.jpg');
  assert.equal(fig.className, 'rm-item');
  assert.equal(descendants(fig).filter((n) => n.tagName === 'FIGCAPTION')[0].textContent, 'fountain.jpg');
});

test('an entry without a thumbnail is a caption-only figure with no <img>', () => {
  const doc = makeDoc();
  const fig = RC.mediaFigure('🔗 flickr.com link', doc);

  assert.equal(descendants(fig).filter((n) => n.tagName === 'IMG').length, 0);
  assert.equal(fig.className, 'rm-item is-link');
  assert.equal(fig.textContent, '🔗 flickr.com link');
});

test('a hostile name in a photo entry is text, never markup', () => {
  const doc = makeDoc();
  const fig = RC.mediaFigure({ name: XSS_ATTR, sm: '/media/ok.webp' }, doc);
  const cap = descendants(fig).filter((n) => n.tagName === 'FIGCAPTION')[0];

  assert.equal(descendants(cap).length, 0);
  assert.equal(cap.textContent, XSS_ATTR);
});

test('a thumbnail URL that is not http(s) or site-relative yields no <img> at all', () => {
  const doc = makeDoc();

  ['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', '//evil.example/x.png']
    .forEach((sm) => {
      const fig = RC.mediaFigure({ name: 'x', sm }, doc);
      assert.equal(descendants(fig).filter((n) => n.tagName === 'IMG').length, 0, sm);
      assert.equal(fig.className, 'rm-item is-link', sm);
    });

  assert.equal(RC.safeSrc('https://cdn.example/x.webp'), 'https://cdn.example/x.webp');
  assert.equal(RC.safeSrc('/media/x.webp'), '/media/x.webp');
  assert.equal(RC.safeSrc(null), '');
});

test('emphasised() puts the value in its own <b> as text, keeping translator word order', () => {
  const doc = makeDoc();
  const out = RC.emphasised('✓ %source% recognised — licence read automatically.', '%source%', 'Flickr', doc);
  const bolds = descendants(out).filter((n) => n.tagName === 'B');

  assert.equal(bolds.length, 1);
  assert.equal(bolds[0].textContent, 'Flickr');
  assert.equal(out.textContent, '✓ Flickr recognised — licence read automatically.');
});

test('emphasised() cannot be made to emit markup, from either half', () => {
  const doc = makeDoc();
  const out = RC.emphasised(XSS_SCRIPT + '%source%' + XSS_ATTR, '%source%', XSS_ATTR, doc);

  // Exactly the three spans/b the builder makes — nothing parsed out of either string.
  assert.deepEqual(descendants(out).map((n) => n.tagName), ['SPAN', 'B', 'SPAN']);
  assert.equal(out.textContent, XSS_SCRIPT + XSS_ATTR + XSS_ATTR);
});

test('a template with no placeholder still renders, without the value', () => {
  const doc = makeDoc();
  const out = RC.emphasised('Unknown source.', '%source%', 'Flickr', doc);

  assert.equal(out.textContent, 'Unknown source.');
  assert.equal(descendants(out).filter((n) => n.tagName === 'B').length, 0);
});

test('a missing translation renders empty rather than the key or "undefined"', () => {
  const doc = makeDoc();

  assert.equal(RC.kvRow(undefined, 'x', doc).childNodes[0].textContent, '');
  assert.equal(RC.emphasised(undefined, '%source%', 'Flickr', doc).textContent, '');
});

test('clear() empties a container without going through innerHTML', () => {
  const doc = makeDoc();
  const box = doc.createElement('div');
  box.appendChild(RC.kvRow('a', 'b', doc));
  box.appendChild(RC.kvRow('c', 'd', doc));

  RC.clear(box);
  assert.equal(box.childNodes.length, 0);
  assert.equal(box.textContent, '');
});
