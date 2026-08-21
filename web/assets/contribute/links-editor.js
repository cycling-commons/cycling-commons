// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Outbound-links editor (docs/specs/catalog-data-model.md §7).
   Caps here are courtesy; OutboundLinks enforces them server-side. */
(function () {
  'use strict';
  window.Cc = window.Cc || {};

  window.Cc.mountLinksEditor = function (hidden) {
    if (!hidden || hidden.dataset.linksMounted) return null;
    var cfg = window.CC_LINKS || {};
    var T = cfg.i18n || {};
    var MAX_ENTRIES = cfg.maxEntries || 4;
    var MAX_URLS = cfg.maxUrls || 6;
    var MAX_LABEL = cfg.maxLabel || 40;
    var LOCALES = cfg.locales || ['en', 'fr', 'nl', 'de', 'es'];
    var LOCALE_NAMES = cfg.localeNames || {};
    hidden.dataset.linksMounted = '1';

    /* Unreadable JSON is dropped: empty editor, stored value unchanged until save. */
    var entries = [];
    try {
      var parsed = JSON.parse(hidden.value || '[]');
      if (Array.isArray(parsed)) entries = parsed;
    } catch (e) { entries = []; }

    var root = document.createElement('div');
    root.className = 'lk-editor';
    hidden.parentNode.insertBefore(root, hidden.nextSibling);

    function sync() {
      /* Half-filled rows are not links; posting them fails the server's shape check. */
      var out = entries.map(function (e) {
        var urls = (e.urls || []).filter(function (u) { return (u.url || '').trim() !== ''; })
          .map(function (u) {
            var one = { url: u.url.trim() };
            if (u.locale) one.locale = u.locale;
            return one;
          });
        if (!urls.length) return null;
        var entry = { urls: urls };
        var label = (e.label || '').trim();
        if (label) entry.label = label;
        return entry;
      }).filter(Boolean);
      hidden.value = out.length ? JSON.stringify(out) : '';
    }

    function field(tag, cls, attrs) {
      var el = document.createElement(tag);
      if (cls) el.className = cls;
      Object.keys(attrs || {}).forEach(function (k) { el.setAttribute(k, attrs[k]); });
      return el;
    }

    function renderUrl(entry, url, ui) {
      var row = field('div', 'lk-url');

      var input = field('input', 'lk-url-input', {
        type: 'url', placeholder: 'https://…', value: url.url || '',
      });
      input.value = url.url || '';
      input.addEventListener('input', function () { url.url = input.value; sync(); });

      /* Language picker only on the second+ address — the first is "this page". */
      var select = null;
      if (ui.index > 0) {
        select = field('select', 'lk-locale');
        var any = document.createElement('option');
        any.value = '';
        any.textContent = T.anyLanguage || 'any language';
        select.appendChild(any);
        LOCALES.forEach(function (code) {
          var opt = document.createElement('option');
          opt.value = code;
          opt.textContent = LOCALE_NAMES[code] || code;
          if (url.locale === code) opt.selected = true;
          select.appendChild(opt);
        });
        select.addEventListener('change', function () {
          url.locale = select.value || undefined;
          sync();
        });
      }

      var remove = field('button', 'lk-x', { type: 'button' });
      remove.textContent = '×';
      remove.title = T.removeUrl || 'Remove this address';
      remove.addEventListener('click', function () {
        entry.urls.splice(entry.urls.indexOf(url), 1);
        if (!entry.urls.length) entry.urls.push({ url: '' });
        render();
      });

      row.appendChild(input);
      if (select) row.appendChild(select);
      if (entry.urls.length > 1) row.appendChild(remove);
      return row;
    }

    function renderEntry(entry) {
      var box = field('div', 'lk-entry');

      var label = field('input', 'lk-label', {
        type: 'text', maxlength: String(MAX_LABEL),
        placeholder: T.labelPlaceholder || 'What is this page? e.g. Wikipedia',
      });
      label.value = entry.label || '';
      label.addEventListener('input', function () { entry.label = label.value; sync(); });
      box.appendChild(label);

      (entry.urls || []).forEach(function (url, i) {
        box.appendChild(renderUrl(entry, url, { index: i }));
      });

      var actions = field('div', 'lk-actions');
      if ((entry.urls || []).length < MAX_URLS) {
        var addUrl = field('button', 'lk-add', { type: 'button' });
        addUrl.textContent = '+ ' + (T.addLanguage || 'add another language');
        addUrl.addEventListener('click', function () {
          entry.urls.push({ url: '' });
          render();
        });
        actions.appendChild(addUrl);
      }
      var drop = field('button', 'lk-drop', { type: 'button' });
      drop.textContent = T.removeEntry || 'Remove this page';
      drop.addEventListener('click', function () {
        entries.splice(entries.indexOf(entry), 1);
        render();
      });
      actions.appendChild(drop);
      box.appendChild(actions);
      return box;
    }

    function render() {
      root.textContent = '';
      entries.forEach(function (entry) {
        if (!Array.isArray(entry.urls) || !entry.urls.length) entry.urls = [{ url: '' }];
        root.appendChild(renderEntry(entry));
      });

      if (entries.length < MAX_ENTRIES) {
        var add = field('button', 'lk-add lk-add-entry', { type: 'button' });
        add.textContent = '+ ' + (T.addEntry || 'add a page');
        add.addEventListener('click', function () {
          entries.push({ urls: [{ url: '' }] });
          render();
        });
        root.appendChild(add);
      } else {
        var full = field('p', 'lk-full');
        full.textContent = (T.full || 'That is the most pages one place can link to.');
        root.appendChild(full);
      }
      sync();
    }

    render();
    return { sync: sync };
  };
})();
