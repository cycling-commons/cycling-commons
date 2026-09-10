// SPDX-License-Identifier: AGPL-3.0-only
/* Translate mode on the page (docs/specs/translations.md §4.1).

   The server wrapped every translated string in invisible marks:
     U+2061  21 × (U+200B = 0 | U+200C = 1)  text  U+2062
   Twenty bits of translation_entry.id, then one stale bit. This file turns
   each pair into <span class="tr-hit" data-tr="id" data-stale="0|1">, cleans
   marks out of attributes, and opens the existing /translate/{id} form in a
   drawer. A string that carries a tag (the marks land in different text
   nodes once the browser parses it) is claimed on the element that
   brackets it instead of a span inside it; see wrapSpanningNodes below.
   Nothing here changes what a string says; nothing goes live until a
   curator approves.

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

  /* A start mark with no matching end later in the SAME string: the last
     START token in `text` that is not followed, still within `text`, by an
     END token. Returns the token's own bounds (not the whole string), or
     null when every START in `text` is already closed within it. Pure
     string logic, exported for the Node test. */
  function findOpenMark(text) {
    var re = /⁡([​‌]{21})|⁢/g, m, open = null;
    while ((m = re.exec(text))) {
      open = m[1] !== undefined ? { bits: m[1], start: m.index, end: re.lastIndex } : null;
    }
    return open;
  }

  /* Start tokens and end tokens left in `text` once every complete pair
     that BOTH opens and closes within this SAME string has been discarded,
     plus the position of the first surviving ("residual") end. A
     translated parameter (`%type%` substituted with its own `trans()`
     result before the outer key gets marked) produces exactly such a
     self-contained pair inside one text node: the same-node pass below
     already discards it on sight ("the outer key wins, inner marks go" -
     see its own comment). This is that same discard rule, exposed as
     counts so the spanning pass's guard can apply it too, without being
     fooled into thinking a nested parameter is a second, sibling string.
     A run of well-formed tokens nests like parentheses, so a simple depth
     counter is enough: every START opens one level, every END either
     closes the innermost still-open level in THIS string (discarded, both
     sides) or, if none is open, is itself residual. Pure string logic,
     exported for the Node test. */
  function residualMarks(text) {
    var re = /⁡([​‌]{21})|⁢/g, m, depth = 0, ends = 0, firstEnd = -1;
    while ((m = re.exec(text))) {
      if (m[1] !== undefined) {
        depth++;
      } else if (depth > 0) {
        depth--;
      } else {
        if (firstEnd === -1) firstEnd = m.index;
        ends++;
      }
    }
    return { starts: depth, ends: ends, firstEnd: firstEnd };
  }

  function commonAncestorElement(a, b) {
    var chain = [], p = a.parentElement;
    while (p) { chain.push(p); p = p.parentElement; }
    p = b.parentElement;
    while (p) {
      if (chain.indexOf(p) !== -1) return p;
      p = p.parentElement;
    }
    return null;
  }

  function blocked(el) {
    return !el || SKIP_WRAP[el.nodeName] || el.closest('.tr-hit, #tr-bar, #tr-drawer');
  }

  /* A mark pair whose start and end sit in different text nodes (the string
     carries a tag, translations.md §4.1): the same-node pass below never
     matches it, since MARK_RE requires both marks inside ONE node. This
     pass runs first and claims the element that brackets the whole pair
     instead of a span inside it, so it must strip what it claims before
     wrapTextNodes ever sees those nodes, or the two passes would fight over
     the same marks.

     A translated parameter substituted into the string BEFORE the outer key
     is marked (`'key'|trans({'%x%': other|trans})`) lands as a second,
     self-contained pair nested inside one of the ancestor's text nodes -
     typically inside the very tag the outer string carries. The guard below
     must see straight through that: it is not a second string sharing the
     parent, it is a parameter of THIS one, and `residualMarks()` discards it
     before counting for exactly that reason. */
  function wrapSpanningNodes(root) {
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var nodes = [], n;
    while ((n = walker.nextNode())) {
      if (n.nodeValue.indexOf(START) !== -1 || n.nodeValue.indexOf(END) !== -1) nodes.push(n);
    }
    var used = [], count = 0, stale = 0;
    for (var i = 0; i < nodes.length; i++) {
      if (used[i]) continue;
      var openNode = nodes[i];
      var open = findOpenMark(openNode.nodeValue);
      if (!open) continue;

      // The closing node must carry a RESIDUAL end, not merely any end at
      // all: a node holding a self-contained parameter pair (its own start
      // AND end) carries an end too, but that end is already spoken for by
      // the start right before it in the same node, and pairing it with
      // THIS open mark would stop the search short of the true close.
      var closeIdx = -1;
      for (var j = i + 1; j < nodes.length; j++) {
        if (used[j]) continue;
        var r = residualMarks(nodes[j].nodeValue);
        if (r.ends > 0) { closeIdx = j; break; }
      }
      if (closeIdx === -1) continue;
      var closeNode = nodes[closeIdx];

      if (blocked(openNode.parentNode) || blocked(closeNode.parentNode)) continue;

      var ancestor = commonAncestorElement(openNode, closeNode);
      if (blocked(ancestor)) continue;

      // Every marked node the ancestor contains, not just the open/close
      // pair: a nested parameter's self-contained pair can sit anywhere in
      // between, and an unrelated SIBLING marked string sharing this same
      // ancestor (elsewhere in it, not containing or contained by this
      // pair) must still be seen, or the guard below could not tell the
      // two shapes apart.
      var descendantIdx = [];
      for (var k = 0; k < nodes.length; k++) {
        if (!used[k] && ancestor.contains(nodes[k])) descendantIdx.push(k);
      }

      // Guard: claim the ancestor only when exactly one start and one end
      // remain once every self-contained (same-node) pair is discarded. A
      // nested parameter leaves none of its own behind (it is fully paired
      // in its own node) and never affects this count. Two SIBLING marked
      // strings under one parent, neither containing the other, leave more
      // than one of each and are still declined, left for the same-node
      // pass to strip.
      var totalStarts = 0, totalEnds = 0;
      for (var d = 0; d < descendantIdx.length; d++) {
        var res = residualMarks(nodes[descendantIdx[d]].nodeValue);
        totalStarts += res.starts;
        totalEnds += res.ends;
      }
      if (totalStarts !== 1 || totalEnds !== 1) continue;

      var info = decode(open.bits);
      ancestor.classList.add('tr-hit');
      if (info.stale) ancestor.classList.add('is-stale');
      ancestor.setAttribute('data-tr', String(info.id));
      ancestor.setAttribute('data-stale', info.stale ? '1' : '0');

      // Strip every mark in the claimed subtree: the outer pair's own two
      // tokens, and a nested parameter's two tokens right along with them -
      // "the outer key wins, inner marks go", same as the same-node pass.
      for (var s = 0; s < descendantIdx.length; s++) {
        var node = nodes[descendantIdx[s]];
        node.nodeValue = node.nodeValue.replace(MARK_RE_G, '');
        used[descendantIdx[s]] = true;
      }

      count++; if (info.stale) stale++;
    }
    return { count: count, stale: stale };
  }

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
    // Spanning pass first: it claims and strips whole pairs that cross text
    // nodes before the same-node pass below ever walks those nodes, so a
    // string is never counted, or stripped, by both.
    var spanning = wrapSpanningNodes(root);
    var same = wrapTextNodes(root);
    return { count: spanning.count + same.count, stale: spanning.stale + same.stale };
  }

  /* ---- bar and drawer ----
     Everything below touches `document`, so it is wrapped in the same guard
     as the processing pass at the bottom: the Node test (translate-marks.
     test.cjs) calls this file with `document` undefined to reach only the
     pure helpers below, and every DOM read here would otherwise throw
     before that guard is reached. */
  if (typeof document === 'undefined') {
    if (typeof module !== 'undefined' && module.exports) {
      module.exports = { decode: decode, MARK_RE: MARK_RE, findOpenMark: findOpenMark, residualMarks: residualMarks };
    }
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
