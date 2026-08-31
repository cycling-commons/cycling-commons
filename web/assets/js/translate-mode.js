// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Translate mode on the page (docs/specs/translations.md §4.1).

   The server wrapped every translated string in invisible marks:
     U+2061  21 × (U+200B = 0 | U+200C = 1)  text  U+2062
   Twenty bits of translation_entry.id, then one stale bit. This file turns
   each pair into <span class="tr-hit" data-tr="id" data-stale="0|1">, cleans
   marks out of attributes, and opens the existing /translate/{id} form in a
   drawer. Nothing here changes what a string says; nothing goes live until
   a curator approves.

   Loaded at the end of <body>, before the deferred scripts, only when the
   mode is active. No inline handlers: the CSP blocks them silently. */
(function () {
  'use strict';

  var START = '⁡', END = '⁢';
  var MARK_RE = /⁡([​‌]{21})([\s\S]*?)⁢/;
  var MARK_RE_G = /⁡[​‌]{21}|⁢/g;

  function decode(bits) {
    var id = 0;
    for (var i = 0; i < 20; i++) id = id * 2 + (bits.charCodeAt(i) === 0x200c ? 1 : 0);
    return { id: id, stale: bits.charCodeAt(20) === 0x200c };
  }

  var SKIP_WRAP = { OPTION: 1, TEXTAREA: 1, TITLE: 1, SCRIPT: 1, STYLE: 1 };

  function cleanAttributes(root) {
    var els = root.querySelectorAll ? root.querySelectorAll('*') : [];
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      for (var a = 0; a < el.attributes.length; a++) {
        var attr = el.attributes[a];
        if (attr.value.indexOf(START) !== -1 || attr.value.indexOf(END) !== -1) {
          el.setAttribute(attr.name, attr.value.replace(MARK_RE_G, ''));
        }
      }
    }
  }

  function wrapTextNodes(root) {
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var nodes = [];
    var n;
    while ((n = walker.nextNode())) if (n.nodeValue.indexOf(START) !== -1 || n.nodeValue.indexOf(END) !== -1) nodes.push(n);
    var count = 0, stale = 0;
    nodes.forEach(function (node) {
      var parent = node.parentNode;
      if (!parent || SKIP_WRAP[parent.nodeName] || parent.closest('.tr-hit, #tr-bar, #tr-drawer')) {
        node.nodeValue = node.nodeValue.replace(MARK_RE_G, '');
        return;
      }
      var text = node.nodeValue, m, frag = document.createDocumentFragment();
      while ((m = MARK_RE.exec(text))) {
        if (m.index > 0) frag.appendChild(document.createTextNode(text.slice(0, m.index).replace(MARK_RE_G, '')));
        var info = decode(m[1]);
        var span = document.createElement('span');
        span.className = 'tr-hit' + (info.stale ? ' is-stale' : '');
        span.setAttribute('data-tr', String(info.id));
        span.setAttribute('data-stale', info.stale ? '1' : '0');
        // Nested marks (a translated parameter): the outer key wins, inner marks go.
        span.textContent = m[2].replace(MARK_RE_G, '');
        frag.appendChild(span);
        count++; if (info.stale) stale++;
        text = text.slice(m.index + m[0].length);
      }
      if (text) frag.appendChild(document.createTextNode(text.replace(MARK_RE_G, '')));
      parent.replaceChild(frag, node);
    });
    return { count: count, stale: stale };
  }

  function process(root) {
    cleanAttributes(root);
    return wrapTextNodes(root);
  }

  /* ---- bar and drawer ----
     Everything below touches `document`, so it is wrapped in the same guard
     as the processing pass at the bottom: the Node test (translate-marks.
     test.cjs) calls this file with `document` undefined to reach only
     `decode` and `MARK_RE`, and every DOM read here would otherwise throw
     before that guard is reached. */
  if (typeof document === 'undefined') {
    if (typeof module !== 'undefined' && module.exports) module.exports = { decode: decode, MARK_RE: MARK_RE };
    return;
  }

  var bar = document.getElementById('tr-bar');
  var drawer = document.getElementById('tr-drawer');
  var editing = true;

  function setEditing(on) {
    editing = on;
    document.body.classList.toggle('tr-editing', on);
    if (bar) {
      bar.querySelector('[data-tr-edit]').setAttribute('aria-pressed', on ? 'true' : 'false');
      bar.querySelector('[data-tr-browse]').setAttribute('aria-pressed', on ? 'false' : 'true');
    }
    var hits = document.querySelectorAll('.tr-hit');
    for (var i = 0; i < hits.length; i++) {
      if (on) { hits[i].setAttribute('tabindex', '0'); hits[i].setAttribute('role', 'button'); }
      else { hits[i].removeAttribute('tabindex'); hits[i].removeAttribute('role'); }
    }
  }

  function openDrawer(url) {
    if (!drawer) return;
    var body = drawer.querySelector('.tr-drawer-body');
    drawer.hidden = false;
    body.innerHTML = '';
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.text(); })
      .then(function (html) { body.innerHTML = html; process(body); wireForm(body); var f = body.querySelector('textarea'); if (f) f.focus(); })
      .catch(function () { body.textContent = drawer.getAttribute('data-error'); });
  }

  function closeDrawer() { if (drawer) { drawer.hidden = true; drawer.querySelector('.tr-drawer-body').innerHTML = ''; } }

  function wireForm(body) {
    var form = body.querySelector('form.tr-form');
    if (form) {
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        fetch(form.getAttribute('action'), { method: 'POST', credentials: 'same-origin', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' } })
          .then(function (r) { return r.text(); })
          .then(function (html) { body.innerHTML = html; process(body); wireForm(body); })
          .catch(function () { body.textContent = drawer.getAttribute('data-error'); });
      });
    }
    var en = body.querySelector('.tr-embed-english');
    if (en) en.addEventListener('click', function (ev) { ev.preventDefault(); openDrawer(en.getAttribute('href')); });
    var close = body.querySelector('.tr-embed-close');
    if (close) close.addEventListener('click', closeDrawer);
  }

  /* The server hands over a base and a suffix and the id goes between them.
     This used to string-replace the literal "/0?" inside one generated URL,
     which would silently no-op if that URL ever lost `embed` or gained a
     parameter that reordered the query string, and every click would then
     open entry 0 and 404. */
  function hitUrl(span) {
    return drawer.getAttribute('data-edit-base') + span.getAttribute('data-tr') + drawer.getAttribute('data-edit-suffix');
  }

  document.addEventListener('click', function (ev) {
    if (!editing) return;
    var hit = ev.target.closest && ev.target.closest('.tr-hit');
    if (!hit || hit.closest('#tr-drawer')) return;
    ev.preventDefault(); ev.stopPropagation();
    openDrawer(hitUrl(hit));
  }, true);

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { closeDrawer(); return; }
    if (!editing || (ev.key !== 'Enter' && ev.key !== ' ')) return;
    var hit = ev.target.closest && ev.target.closest('.tr-hit');
    if (!hit) return;
    ev.preventDefault(); openDrawer(hitUrl(hit));
  });

  if (bar) {
    bar.querySelector('[data-tr-edit]').addEventListener('click', function () { setEditing(true); });
    bar.querySelector('[data-tr-browse]').addEventListener('click', function () { setEditing(false); });
  }
  if (drawer) drawer.querySelector('.tr-drawer-close').addEventListener('click', closeDrawer);

  if (document.body) {
    var stats = process(document.documentElement);
    if (bar) {
      bar.querySelector('[data-tr-count]').textContent = String(stats.count);
      bar.querySelector('[data-tr-stale]').textContent = String(stats.stale);
    }
    setEditing(true);
    new MutationObserver(function (records) {
      records.forEach(function (r) {
        for (var i = 0; i < r.addedNodes.length; i++) {
          var node = r.addedNodes[i];
          if (node.nodeType === 1 && !node.closest('#tr-drawer')) process(node);
          else if (node.nodeType === 3 && node.nodeValue.indexOf(START) !== -1) node.nodeValue = node.nodeValue.replace(MARK_RE_G, '');
        }
      });
    }).observe(document.body, { childList: true, subtree: true });
  }
})();
