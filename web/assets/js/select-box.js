// SPDX-License-Identifier: AGPL-3.0-only
/* Our own dropdown (owner 2026-09-18, site-wide 2026-09-19): every <select>
   on the site draws as a button plus a listbox in the page's own style,
   instead of the browser's popup. docs/specs/map-and-search.md §4.0.

   The native <select> stays in the page, visually hidden but rendered, and
   stays the truth: forms submit it, code reads and writes `.value` (the box
   repaints on a write), `change` fires on it, and the browser's own
   validation still runs on it. The box only draws it.

   Looks: every stylesheet rule written for `select` is copied for
   `.cc-sel-btn` when the page loads, so the button wears whatever the page
   gave its selects, context by context, with no per-page CSS. The list opens
   fixed, in the colours of the button it came from.

   Selects added later (a drawer, a Scout card) are picked up by one watcher.
   `data-native` keeps a select native; `multiple` and `size` ones stay native
   on their own. A file, never inline: the CSP blocks inline handlers. */
(function () {
  window.Cc = window.Cc || {};
  if (window.Cc.selectBox) return;

  var boxes = new WeakMap();
  var openBox = null;
  var proto = HTMLSelectElement.prototype;
  var valueDesc = Object.getOwnPropertyDescriptor(proto, 'value');
  var indexDesc = Object.getOwnPropertyDescriptor(proto, 'selectedIndex');

  /* Every rule the page wrote for `select` also applies to the button. Done
     once, sheet by sheet; a sheet we may not read (another origin) is skipped. */
  var mirrored = false;
  function mirrorSelectRules() {
    if (mirrored) return;
    mirrored = true;
    var extra = [];
    function walk(rules, into) {
      for (var i = 0; i < rules.length; i++) {
        var r = rules[i];
        if (r.type === 1 && /\bselect\b/.test(r.selectorText) && !/::?selection/.test(r.selectorText)) {
          var sel = r.selectorText.replace(/\bselect\b/g, '.cc-sel-btn');
          into.push(sel + '{' + r.style.cssText + '}');
        } else if (r.type === 4 && r.cssRules) {
          var inner = [];
          walk(r.cssRules, inner);
          if (inner.length) into.push('@media ' + r.conditionText + '{' + inner.join('') + '}');
        }
      }
    }
    for (var s = 0; s < document.styleSheets.length; s++) {
      var rules;
      try { rules = document.styleSheets[s].cssRules; } catch (e) { continue; }
      if (rules) walk(rules, extra);
    }
    if (!extra.length) return;
    var style = document.createElement('style');
    style.setAttribute('data-cc-select-mirror', '');
    style.textContent = extra.join('\n');
    document.head.appendChild(style);
  }

  function enhance(sel, opts) {
    if (boxes.has(sel)) return boxes.get(sel);
    if (sel.multiple || sel.hasAttribute('data-native') || sel.size > 1) return null;
    opts = opts || {};
    mirrorSelectRules();

    var decorate = opts.decorate || null;
    var wrap = document.createElement('span');
    wrap.className = 'cc-sel';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.classList.add('cc-sel-native');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    var labelEl = (sel.id && document.querySelector('label[for="' + CSS.escape(sel.id) + '"]')) || sel.closest('label');
    var label = sel.getAttribute('aria-label') || (labelEl ? labelEl.textContent.trim() : '');
    wrap.appendChild(btn);

    var msg = document.createElement('span');
    msg.className = 'cc-sel-msg';
    msg.setAttribute('role', 'alert');
    msg.hidden = true;
    wrap.appendChild(msg);

    var list = document.createElement('ul');
    list.className = 'cc-sel-list';
    list.setAttribute('role', 'listbox');
    if (label) list.setAttribute('aria-label', label);
    list.tabIndex = -1;
    list.hidden = true;
    list.id = 'ccSel' + Math.random().toString(36).slice(2, 9);
    btn.setAttribute('aria-controls', list.id);
    document.body.appendChild(list);

    var items = [];
    var active = 0;

    function face(o) {
      var name = document.createElement('span');
      name.className = 'cc-sel-name';
      name.textContent = o ? o.textContent : '';
      var extra = o && decorate ? (decorate(o) || []) : [];
      return [name].concat(extra);
    }
    function syncClass() {
      btn.className = 'cc-sel-btn ' + sel.className.replace(/\bcc-sel-native\b/, '').trim();
      btn.disabled = sel.disabled;
      if (sel.required) btn.setAttribute('aria-required', 'true'); else btn.removeAttribute('aria-required');
    }
    function paint() {
      var o = sel.options[sel.selectedIndex];
      btn.textContent = '';
      face(o).forEach(function (n) { btn.appendChild(n); });
      btn.classList.toggle('cc-sel-empty', !o || '' === o.value);
      btn.setAttribute('aria-label', (label ? label + ': ' : '') + (o ? o.textContent : ''));
      items.forEach(function (li) { li.setAttribute('aria-selected', String(li._opt === o)); });
    }
    function build() {
      list.textContent = '';
      items = Array.prototype.map.call(sel.options, function (o, i) {
        var li = document.createElement('li');
        li.id = list.id + '-' + i;
        li.setAttribute('role', 'option');
        li._opt = o;
        if (o.disabled) li.setAttribute('aria-disabled', 'true');
        face(o).forEach(function (n) { li.appendChild(n); });
        li.addEventListener('mousemove', function () { setActive(i); });
        li.addEventListener('click', function () { choose(i); });
        list.appendChild(li);
        return li;
      });
      paint();
    }
    function usable(i) { return items[i] && !items[i]._opt.disabled; }
    function setActive(i) {
      if (!items.length) return;
      active = Math.max(0, Math.min(items.length - 1, i));
      items.forEach(function (li, j) { li.classList.toggle('active', j === active); });
      list.setAttribute('aria-activedescendant', items[active].id);
      items[active].scrollIntoView({ block: 'nearest' });
    }
    function step(dir) {
      for (var i = active + dir; i >= 0 && i < items.length; i += dir) {
        if (usable(i)) { setActive(i); return; }
      }
    }
    function place() {
      var r = btn.getBoundingClientRect();
      var below = window.innerHeight - r.bottom - 8;
      var above = r.top - 8;
      list.style.minWidth = r.width + 'px';
      list.style.width = 'max-content';
      list.style.maxWidth = Math.min(window.innerWidth - 16, Math.max(r.width, 480)) + 'px';
      list.style.left = Math.max(8, Math.min(r.left, window.innerWidth - list.offsetWidth - 8)) + 'px';
      var h = Math.min(list.scrollHeight, 352);
      if (below >= Math.min(h, 200) || below >= above) {
        list.style.top = (r.bottom + 4) + 'px';
        list.style.bottom = 'auto';
        list.style.maxHeight = Math.max(120, below) + 'px';
      } else {
        list.style.top = 'auto';
        list.style.bottom = (window.innerHeight - r.top + 4) + 'px';
        list.style.maxHeight = Math.max(120, above) + 'px';
      }
    }
    /* The list wears the colours of the control it opened from. */
    function theme() {
      var cs = getComputedStyle(btn);
      var clear = /rgba\(.*,\s*0\)$|^transparent$/.test(cs.backgroundColor);
      if (clear) list.style.removeProperty('--sel-bg'); else list.style.setProperty('--sel-bg', cs.backgroundColor);
      list.style.setProperty('--sel-fg', cs.color);
      list.style.fontFamily = cs.fontFamily;
      list.style.fontSize = cs.fontSize;
    }
    function open() {
      if (btn.disabled) return;
      if (openBox && openBox !== api) openBox.close(false);
      build();
      theme();
      list.hidden = false;
      place();
      btn.setAttribute('aria-expanded', 'true');
      setActive(Math.max(0, sel.selectedIndex));
      list.focus({ preventScroll: true });
      openBox = api;
    }
    function close(refocus) {
      if (list.hidden) return;
      list.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
      if (openBox === api) openBox = null;
      if (refocus) btn.focus();
    }
    function choose(i) {
      if (!usable(i)) return;
      var changed = sel.selectedIndex !== i;
      indexDesc.set.call(sel, i);
      paint();
      clearInvalid();
      close(true);
      if (changed) {
        sel.dispatchEvent(new Event('input', { bubbles: true }));
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    /* The browser's own validation still runs on the select; its message is
       shown here, by the button, since the select itself cannot be seen. */
    function clearInvalid() {
      btn.removeAttribute('aria-invalid');
      msg.hidden = true;
      msg.textContent = '';
    }
    sel.addEventListener('invalid', function (e) {
      e.preventDefault();
      btn.setAttribute('aria-invalid', 'true');
      msg.textContent = sel.validationMessage;
      msg.hidden = false;
      btn.focus();
    });
    /* A label click focuses the select; the button takes it over. */
    sel.addEventListener('focus', function () { if (sel.validity.valid) btn.focus(); });

    btn.addEventListener('click', function () { if (list.hidden) open(); else close(true); });
    btn.addEventListener('keydown', function (e) {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].indexOf(e.key) !== -1) { e.preventDefault(); open(); }
    });
    var typed = '';
    var typedAt = 0;
    list.addEventListener('keydown', function (e) {
      var k = e.key;
      if ('ArrowDown' === k) step(1);
      else if ('ArrowUp' === k) step(-1);
      else if ('Home' === k) { active = -1; step(1); }
      else if ('End' === k) { active = items.length; step(-1); }
      else if ('Enter' === k || ' ' === k) choose(active);
      else if ('Escape' === k) close(true);
      else if ('Tab' === k) { close(false); btn.focus(); return; }
      else if (1 === k.length && /\S/.test(k)) {
        var now = Date.now();
        typed = (now - typedAt < 700 ? typed : '') + k.toLowerCase();
        typedAt = now;
        var from = typed.length > 1 ? active : active + 1;
        for (var n = 0; n < items.length; n++) {
          var i = (from + n) % items.length;
          if (usable(i) && items[i]._opt.textContent.trim().toLowerCase().indexOf(typed) === 0) { setActive(i); break; }
        }
      } else return;
      e.preventDefault();
      e.stopPropagation();   // Esc here must not also close a panel or stop a pick
    });
    /* A press inside the list keeps focus there, so the click lands. */
    list.addEventListener('mousedown', function (e) { e.preventDefault(); });
    list.addEventListener('focusout', function (e) { if (e.relatedTarget !== btn && !list.contains(e.relatedTarget)) close(false); });

    /* Code that sets the value, or swaps the options, still repaints the box. */
    Object.defineProperty(sel, 'value', {
      configurable: true,
      get: function () { return valueDesc.get.call(this); },
      set: function (v) { valueDesc.set.call(this, v); paint(); }
    });
    Object.defineProperty(sel, 'selectedIndex', {
      configurable: true,
      get: function () { return indexDesc.get.call(this); },
      set: function (v) { indexDesc.set.call(this, v); paint(); }
    });
    sel.addEventListener('change', paint);
    new MutationObserver(function (recs) {
      var attrs = recs.some(function (r) { return 'attributes' === r.type; });
      var kids = recs.some(function (r) { return 'childList' === r.type || 'characterData' === r.type; });
      if (attrs) syncClass();
      if (kids) build(); else paint();
    }).observe(sel, { attributes: true, attributeFilter: ['class', 'disabled', 'required'], childList: true, subtree: true, characterData: true });

    var api = { btn: btn, list: list, close: close, refresh: build };
    boxes.set(sel, api);
    syncClass();
    build();
    return api;
  }

  function closeOpen() { if (openBox) openBox.close(false); }

  function each(n, fn) {
    if (1 !== n.nodeType) return;
    if ('SELECT' === n.tagName) fn(n);
    else if (n.querySelectorAll) Array.prototype.forEach.call(n.querySelectorAll('select'), fn);
  }

  var watched = false;
  /* Draw every select under `root`, now and whenever more are added. */
  function init(root) {
    root = root || document.body;
    Array.prototype.forEach.call(root.querySelectorAll('select'), function (s) { enhance(s); });
    if (watched) return;
    watched = true;
    new MutationObserver(function (recs) {
      recs.forEach(function (r) {
        /* A select that left the page takes its list with it. */
        Array.prototype.forEach.call(r.removedNodes, function (n) {
          each(n, function (s) {
            var b = boxes.get(s);
            if (b && !s.isConnected) { b.close(false); b.list.remove(); boxes.delete(s); }
          });
        });
        Array.prototype.forEach.call(r.addedNodes, function (n) { each(n, function (s) { enhance(s); }); });
      });
    }).observe(document.body, { childList: true, subtree: true });
    window.addEventListener('resize', closeOpen);
    document.addEventListener('scroll', function (e) {
      if (openBox && !openBox.list.contains(e.target)) closeOpen();
    }, true);
  }

  window.Cc.selectBox = { enhance: enhance, init: init };
  if ('loading' === document.readyState) document.addEventListener('DOMContentLoaded', function () { init(); });
  else init();
})();
