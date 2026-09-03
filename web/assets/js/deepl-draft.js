// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Dev-only DeepL drafting controls (docs/specs/translations.md §7.1, §7.2).

   One panel renders on translate/_form.html.twig, above the translation
   fields, only when DeepLAvailability::isOn() and the locale being edited
   is not English:
     [data-deepl-locale] one tick box per language DeepL may be asked for,
                         rendered only on the dev catalogue form.
     .tr-deepl-draft     ask DeepL for every ticked language and drop each
                         answer into that language's own field.

   It NEVER writes. A draft is machine output that only the developer can
   judge, so it lands in a field and the catalogue write stays a separate
   act, behind its own per-language tick boxes and its own button.

   Loaded once, from base.html.twig, the same way assets/js/translate-mode.js
   is: the panel these controls live in can arrive already in the page (a
   full /translate/{id} load) or fetched as text and set via innerHTML into
   the translate-mode drawer, and a <script> tag inside fetched HTML never
   runs. Event delegation on `document` covers both without caring which one
   happened. Must be a FILE, never an inline handler: the CSP blocks those
   silently. */
(function () {
  'use strict';

  function panelOf(el) {
    return el.closest('[data-deepl]');
  }

  function statusElement(panel) {
    return panel.querySelector('[data-deepl-status]');
  }

  function setStatus(panel, text, isError) {
    var el = statusElement(panel);
    if (!el) return;
    el.textContent = text || '';
    el.hidden = !text;
    el.classList.toggle('is-error', !!isError);
  }

  /* The ticked languages, or [] on a form that renders no tick boxes at
     all. The server reads an empty list as "the locale being edited", which
     is the only field such a form has. */
  function tickedLocales(panel) {
    var boxes = panel.querySelectorAll('[data-deepl-locale]');
    var picked = [];
    for (var i = 0; i < boxes.length; i++) {
      if (boxes[i].checked) picked.push(boxes[i].getAttribute('data-deepl-locale'));
    }
    return picked;
  }

  /* Where a draft for `locale` goes. The dev catalogue form names its
     fields by locale (translation_proposal[nl]); every other form has one
     textarea and one locale, so the first textarea is that locale's. */
  function fieldFor(panel, locale) {
    var form = panel.closest('form');
    if (!form) return null;
    var byLocale = form.querySelector('[data-locale-row="' + locale + '"] textarea');
    return byLocale || form.querySelector('textarea');
  }

  function setBusy(panel, busy) {
    var buttons = panel.querySelectorAll('button');
    for (var i = 0; i < buttons.length; i++) buttons[i].disabled = busy;
  }

  function errorText(panel, message) {
    return panel.getAttribute('data-error-tpl').replace('%%MESSAGE%%', message || '');
  }

  /* Posts the CSRF token as form data, the same shape translate-mode.js's
     drawer form submit already uses, and always resolves (never rejects
     on a non-2xx status): the caller reads `ok` and `data` itself, since a
     4xx/5xx response here still carries a JSON body worth showing. */
  function post(url, csrfToken, locales) {
    var body = new FormData();
    body.append('_csrf_token', csrfToken);
    for (var i = 0; i < locales.length; i++) body.append('locales[]', locales[i]);
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' },
      body: body,
    }).then(function (response) {
      return response
        .json()
        .catch(function () {
          return {};
        })
        .then(function (data) {
          return { ok: response.ok, data: data };
        });
    });
  }

  function draft(panel) {
    setBusy(panel, true);
    setStatus(panel, panel.getAttribute('data-drafting-label'), false);
    post(panel.getAttribute('data-draft-url'), panel.getAttribute('data-csrf'), tickedLocales(panel))
      .then(function (result) {
        setBusy(panel, false);
        var drafts = result.ok && result.data ? result.data.drafts : null;
        if (!drafts) {
          setStatus(panel, errorText(panel, result.data && result.data.error), true);
          return;
        }
        Object.keys(drafts).forEach(function (locale) {
          var field = fieldFor(panel, locale);
          if (field) field.value = drafts[locale];
        });
        setStatus(panel, '', false);
      })
      .catch(function () {
        setBusy(panel, false);
        setStatus(panel, errorText(panel, ''), true);
      });
  }

  document.addEventListener('click', function (ev) {
    var button = ev.target.closest && ev.target.closest('.tr-deepl-draft');
    if (!button) return;

    var panel = panelOf(button);
    if (!panel) return;

    ev.preventDefault();
    draft(panel);
  });
})();
