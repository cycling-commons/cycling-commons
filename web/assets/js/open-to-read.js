// SPDX-License-Identifier: AGPL-3.0-only
// A message or a room post counts as read when its reader OPENS it, never
// because the page listing it loaded (moderation-and-contribution.md §7.5a,
// §13.7). An unread item renders closed: its content sits in a
// <details data-open-read>, and the item carries data-read-url. Opening it
// posts once to that url with the list's CSRF token (data-read-token on an
// ancestor, which also names the counter kind in data-read-kind), then:
//   - the item drops its unread classes (data-unread-class), hides its
//     [data-unread-only] marks, shows its [data-read-only] ones, and gains
//     `is-open`;
//   - the <details> is unwrapped, so the opened item reads the way a read one
//     renders, and focus lands on its content;
//   - when the server says it came off the count, every counter carrying
//     data-count-of="<kind>" goes down by one: the account chip, the Room
//     tab, the Unread filter chip.
// A link to #<item id> (the profile's "answer the curator") opens that item.
// Without this script the <details> still opens and nothing is marked.
//
// The same unread classes carry the unseen bar (`is-unseen`,
// moderation-and-contribution.md §5.2f), the one mark a list row wears until
// its reader opens it. A desk row opens on a page of its own instead: it has
// no data-read-url, and following one of its [data-opens] links (a click or a
// middle click) settles it here at once, so the list left behind in its tab,
// or reached again by Back, shows it opened. The page it leads to records the
// opening; nothing is posted from here.
(function () {
  'use strict';

  function each(list, fn) { Array.prototype.forEach.call(list, fn); }

  /* One counter step down, in its text and in any label that repeats it. */
  function lower(kind) {
    each(document.querySelectorAll('[data-count-of~="' + kind + '"]'), function (el) {
      var n = parseInt(el.textContent, 10);
      if (isNaN(n) || n <= 0) return;
      var next = n - 1;
      el.textContent = String(next);
      ['title', 'aria-label'].forEach(function (attr) {
        var v = el.getAttribute(attr);
        if (v) el.setAttribute(attr, v.replace(String(n), String(next)));
      });
      if (next === 0 && !el.hasAttribute('data-count-keep-zero')) el.hidden = true;
    });
  }

  function settle(item) {
    (item.getAttribute('data-unread-class') || '').split(/\s+/).forEach(function (c) {
      if (c) item.classList.remove(c);
    });
    each(item.querySelectorAll('[data-unread-only]'), function (el) { el.hidden = true; });
    each(item.querySelectorAll('[data-read-only]'), function (el) { el.hidden = false; });
  }

  /* The opened item reads like a read one: no disclosure left around its content. */
  function unwrap(details) {
    var summary = details.querySelector('summary');
    if (summary) details.removeChild(summary);
    var first = details.firstElementChild;
    var parent = details.parentNode;
    while (details.firstChild) parent.insertBefore(details.firstChild, details);
    parent.removeChild(details);
    if (first) {
      first.setAttribute('tabindex', '-1');
      first.focus();
    }
  }

  function opened(details) {
    var item = details.closest('[data-read-url]');
    if (!item || item.hasAttribute('data-read-sent')) return;
    item.setAttribute('data-read-sent', '1');
    item.classList.add('is-open');
    var host = item.closest('[data-read-token]');
    var kind = host ? host.getAttribute('data-read-kind') : null;
    var body = new URLSearchParams();
    body.set('_token', host ? host.getAttribute('data-read-token') : '');
    unwrap(details);

    return fetch(item.getAttribute('data-read-url'), {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        // Refused or failed: the item stays unread, as a reload will show it.
        if (!data) return;
        settle(item);
        if (data.read && kind) lower(kind);
      })
      .catch(function () { /* offline: the item stays unread */ });
  }

  function followed(e) {
    var link = e.target && e.target.closest ? e.target.closest('a[data-opens]') : null;
    var row = link ? link.closest('[data-unread-class]') : null;
    if (row && !row.hasAttribute('data-read-url')) settle(row);
  }
  document.addEventListener('click', followed);
  document.addEventListener('auxclick', followed);

  each(document.querySelectorAll('details[data-open-read]'), function (details) {
    details.addEventListener('toggle', function () {
      if (details.open) opened(details);
    });
  });

  var id = (window.location && window.location.hash || '').slice(1);
  if (id) {
    var target = document.getElementById(id);
    var closed = target && target.querySelector('details[data-open-read]');
    if (closed) closed.open = true;
  }
})();
