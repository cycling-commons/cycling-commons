// SPDX-License-Identifier: AGPL-3.0-only
/* Our own dropdown (owner 2026-09-18): every <select> on the map page draws as
   a button plus a listbox in the map's style, instead of the browser's.

   The native <select> stays in the page, hidden, and stays the truth: forms
   submit it, code reads and writes `.value`, and `change` still fires on it.
   The box only draws it. Selects added later (drawer HTML, Scout cards) are
   picked up by one watcher on the page. A select that must stay native says
   so with `data-native`.

   Keyboard: Enter, Space or the arrows open; the arrows, Home and End move;
   a letter jumps to the next option starting with it; Enter or Space picks;
   Esc closes and Tab moves on. The list is `position: fixed`, so a panel or
   card with overflow hidden never cuts it off. */

const boxes = new WeakMap();
let openBox = null;

const proto = HTMLSelectElement.prototype;
const valueDesc = Object.getOwnPropertyDescriptor(proto, 'value');
const indexDesc = Object.getOwnPropertyDescriptor(proto, 'selectedIndex');

/** Draw one select as our box. `decorate(option)` may return extra nodes for a row. */
export function enhanceSelect(sel, opts = {}) {
  if (boxes.has(sel)) return boxes.get(sel);
  if (sel.multiple || sel.hasAttribute('data-native') || sel.size > 1) return null;

  const decorate = opts.decorate || null;
  const wrap = document.createElement('span');
  wrap.className = 'cc-sel';
  sel.parentNode.insertBefore(wrap, sel);
  wrap.appendChild(sel);
  sel.hidden = true;
  sel.tabIndex = -1;

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.setAttribute('aria-haspopup', 'listbox');
  btn.setAttribute('aria-expanded', 'false');
  const label = sel.getAttribute('aria-label')
    || (sel.id && document.querySelector('label[for="' + CSS.escape(sel.id) + '"]')?.textContent.trim())
    || sel.closest('label')?.firstChild?.textContent?.trim() || '';
  wrap.appendChild(btn);

  const list = document.createElement('ul');
  list.className = 'cc-sel-list';
  list.setAttribute('role', 'listbox');
  if (label) list.setAttribute('aria-label', label);
  list.tabIndex = -1;
  list.hidden = true;
  list.id = 'ccSel' + Math.random().toString(36).slice(2, 9);
  btn.setAttribute('aria-controls', list.id);
  document.body.appendChild(list);

  let items = [];
  let active = 0;

  const face = o => {
    const name = document.createElement('span');
    name.className = 'cc-sel-name';
    name.textContent = o ? o.textContent : '';
    const extra = o && decorate ? (decorate(o) || []) : [];
    return [name, ...extra];
  };
  const syncClass = () => {
    btn.className = 'cc-sel-btn ' + sel.className;
    btn.disabled = sel.disabled;
  };
  const paint = () => {
    const o = sel.options[sel.selectedIndex];
    btn.textContent = '';
    btn.append(...face(o));
    btn.setAttribute('aria-label', (label ? label + ': ' : '') + (o ? o.textContent : ''));
    items.forEach(li => li.setAttribute('aria-selected', String(li._opt === o)));
  };
  const build = () => {
    list.textContent = '';
    items = [...sel.options].map((o, i) => {
      const li = document.createElement('li');
      li.id = list.id + '-' + i;
      li.setAttribute('role', 'option');
      li._opt = o;
      if (o.disabled) li.setAttribute('aria-disabled', 'true');
      li.append(...face(o));
      li.addEventListener('mousemove', () => setActive(i));
      li.addEventListener('click', () => choose(i));
      list.appendChild(li);
      return li;
    });
    paint();
  };
  const usable = i => items[i] && !items[i]._opt.disabled;
  const setActive = i => {
    if (!items.length) return;
    active = Math.max(0, Math.min(items.length - 1, i));
    items.forEach((li, j) => li.classList.toggle('active', j === active));
    list.setAttribute('aria-activedescendant', items[active].id);
    items[active].scrollIntoView({ block: 'nearest' });
  };
  const step = dir => {
    for (let i = active + dir; i >= 0 && i < items.length; i += dir) {
      if (usable(i)) { setActive(i); return; }
    }
  };
  const place = () => {
    const r = btn.getBoundingClientRect();
    const below = window.innerHeight - r.bottom - 8;
    const above = r.top - 8;
    list.style.minWidth = r.width + 'px';
    list.style.left = Math.max(8, Math.min(r.left, window.innerWidth - list.offsetWidth - 8)) + 'px';
    const h = Math.min(list.scrollHeight, 352);
    if (below >= Math.min(h, 200) || below >= above) {
      list.style.top = (r.bottom + 4) + 'px';
      list.style.bottom = 'auto';
      list.style.maxHeight = Math.max(120, below) + 'px';
    } else {
      list.style.top = 'auto';
      list.style.bottom = (window.innerHeight - r.top + 4) + 'px';
      list.style.maxHeight = Math.max(120, above) + 'px';
    }
  };
  /* The list wears the colours of the control it opened from. */
  const theme = () => {
    const cs = getComputedStyle(btn);
    const clear = /rgba\(.*,\s*0\)$|^transparent$/.test(cs.backgroundColor);
    if (clear) list.style.removeProperty('--sel-bg'); else list.style.setProperty('--sel-bg', cs.backgroundColor);
    list.style.setProperty('--sel-fg', cs.color);
    list.style.fontFamily = cs.fontFamily;
  };
  const open = () => {
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
  };
  const close = refocus => {
    if (list.hidden) return;
    list.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    if (openBox === api) openBox = null;
    if (refocus) btn.focus();
  };
  const choose = i => {
    if (!usable(i)) return;
    const changed = sel.selectedIndex !== i;
    indexDesc.set.call(sel, i);
    paint();
    close(true);
    if (changed) {
      sel.dispatchEvent(new Event('input', { bubbles: true }));
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
  };

  btn.addEventListener('click', () => (list.hidden ? open() : close(true)));
  btn.addEventListener('keydown', e => {
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); open(); }
  });
  let typed = '';
  let typedAt = 0;
  list.addEventListener('keydown', e => {
    const k = e.key;
    if ('ArrowDown' === k) step(1);
    else if ('ArrowUp' === k) step(-1);
    else if ('Home' === k) { active = -1; step(1); }
    else if ('End' === k) { active = items.length; step(-1); }
    else if ('Enter' === k || ' ' === k) choose(active);
    else if ('Escape' === k) close(true);
    else if ('Tab' === k) { close(false); btn.focus(); return; }
    else if (1 === k.length && /\S/.test(k)) {
      const now = Date.now();
      typed = (now - typedAt < 700 ? typed : '') + k.toLowerCase();
      typedAt = now;
      const from = typed.length > 1 ? active : active + 1;
      for (let n = 0; n < items.length; n++) {
        const i = (from + n) % items.length;
        if (usable(i) && items[i]._opt.textContent.trim().toLowerCase().startsWith(typed)) { setActive(i); break; }
      }
    } else return;
    e.preventDefault();
    e.stopPropagation();   // Esc here must not also close the panel or stop a pick
  });
  /* A press inside the list keeps focus there, so the click lands. */
  list.addEventListener('mousedown', e => e.preventDefault());
  list.addEventListener('focusout', e => { if (e.relatedTarget !== btn && !list.contains(e.relatedTarget)) close(false); });

  /* Code that sets the value, or swaps the options, still repaints the box. */
  Object.defineProperty(sel, 'value', {
    configurable: true,
    get() { return valueDesc.get.call(this); },
    set(v) { valueDesc.set.call(this, v); paint(); },
  });
  Object.defineProperty(sel, 'selectedIndex', {
    configurable: true,
    get() { return indexDesc.get.call(this); },
    set(v) { indexDesc.set.call(this, v); paint(); },
  });
  sel.focus = () => btn.focus();
  sel.addEventListener('change', paint);
  new MutationObserver(recs => {
    if (recs.some(r => 'attributes' === r.type)) syncClass();
    if (recs.some(r => 'childList' === r.type || 'characterData' === r.type)) build();
    else paint();
  }).observe(sel, { attributes: true, attributeFilter: ['class', 'disabled'], childList: true, subtree: true, characterData: true });

  const api = { btn, list, close, refresh: build };
  boxes.set(sel, api);
  syncClass();
  build();
  return api;
}

/** Close the open list when the page scrolls or resizes under it. */
function closeOpen() { if (openBox) openBox.close(false); }

/** Draw every select under `root`, now and whenever more are added. */
export function initSelectBoxes(root = document.body) {
  root.querySelectorAll('select').forEach(s => enhanceSelect(s));
  const each = (n, fn) => {
    if (1 !== n.nodeType) return;
    if ('SELECT' === n.tagName) fn(n);
    else n.querySelectorAll?.('select').forEach(fn);
  };
  new MutationObserver(recs => {
    recs.forEach(r => {
      /* A select that left the page takes its list with it. */
      r.removedNodes.forEach(n => each(n, s => {
        const b = boxes.get(s);
        if (b && !s.isConnected) { b.close(false); b.list.remove(); boxes.delete(s); }
      }));
      r.addedNodes.forEach(n => each(n, s => enhanceSelect(s)));
    });
  }).observe(root, { childList: true, subtree: true });
  window.addEventListener('resize', closeOpen);
  document.addEventListener('scroll', e => {
    if (openBox && !openBox.list.contains(e.target)) closeOpen();
  }, true);
}
