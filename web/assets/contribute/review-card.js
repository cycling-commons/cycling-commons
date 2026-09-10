// SPDX-License-Identifier: AGPL-3.0-only
/* Wizard review-step DOM builders (docs/specs/moderation-and-contribution.md §1).
   No innerHTML: every node is createElement + textContent. textContent cannot
   produce an element, so rider text and catalogue copy stay characters.
   Markup stays in Twig. (docs/specs/security-architecture.md §4.3) */
(function () {
  'use strict';

  function docOf(d) {
    return d || (typeof document !== 'undefined' ? document : null);
  }

  function str(v) {
    return v === null || v === undefined ? '' : String(v);
  }

  /* img src is a sink: only http(s) and site-relative URLs, else no <img>. */
  function safeSrc(u) {
    var s = str(u).trim();
    if (/^https?:\/\//i.test(s)) return s;
    if (s.charAt(0) === '/' && s.charAt(1) !== '/') return s;
    return '';
  }

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

  function mediaFigure(entry, d) {
    var doc = docOf(d);
    var isLink = typeof entry === 'string';
    var name = isLink ? entry : str(entry && entry.name);
    var src = isLink ? '' : safeSrc(entry && entry.sm);

    /* The caption is what the rider WROTE about the picture, and the filename
       only when they wrote nothing (owner, 2026-08-30: "I do not see the image
       description I gave"). `IMG_20240714_11302.jpg` reviews nothing: the point
       of this step is to read back what you are about to send. */
    var alt = isLink ? '' : str(entry && entry.alt);
    var caption = alt || name;

    var fig = doc.createElement('figure');
    fig.className = src ? 'rm-item' : 'rm-item is-link';
    if (alt) { fig.className += ' has-alt'; }
    if (src) {
      var img = doc.createElement('img');
      img.src = src;
      /* The description IS the alt text once there is one, which is the whole
         reason it was asked for. */
      img.alt = caption;
      img.loading = 'lazy';
      fig.appendChild(img);
    }
    var cap = doc.createElement('figcaption');
    cap.textContent = caption;
    fig.appendChild(cap);
    return fig;
  }

  /* One emphasised span: split the template on its placeholder; value is text. */
  function emphasised(template, placeholder, value, d) {
    var doc = docOf(d);
    var frag = doc.createElement('span');
    var parts = str(template).split(placeholder);
    var text = function (s) {
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

  /* Empty a container without innerHTML = ''. */
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
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
