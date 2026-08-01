// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The wizard review step's DOM builders (docs/plans/2026-08-01-improve-js-i18n.md).

   Why this is its own file: the review card is the one place in the contribute
   wizard where rider-entered text and catalogue strings meet in the same node,
   and it is read back by a curator. It used to be built by concatenating HTML
   and assigning innerHTML, which made every interpolation depend on somebody
   remembering escHtml(). These builders use createElement + textContent
   instead — textContent cannot produce an element, so neither a rider's
   `<img src=x onerror=…>` nor a catalogue string containing markup can become
   anything but visible characters.

   Classic script, mounted through window.Cc like climb-editor.js and
   media-upload.js (the contribute templates load plain scripts, not modules),
   with the module.exports guard scope.js uses so `node --test` can require it
   directly. web/tests/js/improve-review.test.cjs is the regression net.

   The rule this file exists to enforce, and which improve.js repeats in its
   own header: strings crossing into JS are text; markup stays in Twig, where
   |rich sanitises it. */
(function () {
  'use strict';

  function docOf(d) {
    return d || (typeof document !== 'undefined' ? document : null);
  }

  function str(v) {
    return v === null || v === undefined ? '' : String(v);
  }

  /* The review card's only string-into-attribute path. Thumbnail URLs come
     from MediaStorage::url() and are not rider-controlled, but an <img src>
     is a sink either way, so only http(s) and site-relative URLs are hung on
     one — the same rule as safeHref() in web/assets/map/util.js. Anything
     else yields no <img> at all rather than a suspicious one. */
  function safeSrc(u) {
    var s = str(u).trim();
    if (/^https?:\/\//i.test(s)) return s;
    if (s.charAt(0) === '/' && s.charAt(1) !== '/') return s;
    return '';
  }

  /* One label/value row of the review card. Both halves are text: the label is
     a translated catalogue string, the value is whatever the rider typed. */
  function kvRow(label, value, d) {
    var doc = docOf(d);
    var row = doc.createElement('div');
    row.className = 'kv';
    var l = doc.createElement('span');
    l.textContent = str(label);
    var v = doc.createElement('span');
    v.textContent = str(value);
    row.appendChild(l);
    row.appendChild(v);
    return row;
  }

  /* One entry of the review photo strip. An upload has a thumbnail and is
     shown; a photo LINK is a string and has none, so it keeps its caption
     only — a filename tells a rider nothing a thumbnail does not. */
  function mediaFigure(entry, d) {
    var doc = docOf(d);
    var isLink = typeof entry === 'string';
    var name = isLink ? entry : str(entry && entry.name);
    var src = isLink ? '' : safeSrc(entry && entry.sm);

    var fig = doc.createElement('figure');
    fig.className = src ? 'rm-item' : 'rm-item is-link';
    if (src) {
      var img = doc.createElement('img');
      img.src = src;
      img.alt = str(name);
      img.loading = 'lazy';
      fig.appendChild(img);
    }
    var cap = doc.createElement('figcaption');
    cap.textContent = str(name);
    fig.appendChild(cap);
    return fig;
  }

  /* A sentence with exactly one emphasised span, built without ever letting a
     string carry markup: the translated template is split on its placeholder
     and the value goes into its own <b> as text. Translators keep control of
     word order; `value` cannot become an element.

     Used for the "✓ <b>Wikimedia Commons</b> recognised — …" source note,
     which is generated from a pasted URL and so cannot be server-rendered. */
  function emphasised(template, placeholder, value, d) {
    var doc = docOf(d);
    var frag = doc.createElement('span');
    var parts = str(template).split(placeholder);
    var text = function (s) {
      // An empty run adds no node: a template that opens or closes with the
      // placeholder should not leave a stray empty <span> behind.
      if (!s) return;
      var span = doc.createElement('span');
      span.textContent = s;
      frag.appendChild(span);
    };
    text(parts[0]);
    if (parts.length > 1) {
      var b = doc.createElement('b');
      b.textContent = str(value);
      frag.appendChild(b);
      text(parts.slice(1).join(placeholder));
    }
    return frag;
  }

  /* Empty a container without innerHTML = '' — same effect, but it keeps the
     "no innerHTML anywhere in the review path" property greppable. */
  function clear(el) {
    while (el && el.firstChild) el.removeChild(el.firstChild);
    return el;
  }

  var API = {
    kvRow: kvRow,
    mediaFigure: mediaFigure,
    emphasised: emphasised,
    safeSrc: safeSrc,
    clear: clear
  };

  if (typeof window !== 'undefined') {
    window.Cc = window.Cc || {};
    window.Cc.reviewCard = API;
  }
  // Node tests require this file directly; browsers never see a `module`.
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
