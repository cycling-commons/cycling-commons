// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Dev-only DeepL drafting controls (docs/specs/translations.md §7.1, §7.2, §7.3).

   Two buttons render on translate/_form.html.twig, above the "Your
   translation" field, only when DeepLAvailability::isOn() and the locale
   being edited is not English:
     .tr-deepl-one  draft this locale, fill the textarea, write nothing.
     .tr-deepl-all  draft fr/nl/de/es and write them straight into the
                    catalogue through CatalogueWriter, then report back
                    which locales landed.

   Loaded once, from base.html.twig, the same way assets/js/translate-mode.js
   is: the panel these buttons live in can arrive already in the page (a
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

  function textareaFor(panel) {
    var form = panel.closest('form');
    return form ? form.querySelector('textarea') : null;
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
  function post(url, csrfToken) {
    var body = new FormData();
    body.append('_csrf_token', csrfToken);
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

  function draftThis(panel) {
    var textarea = textareaFor(panel);
    setBusy(panel, true);
    setStatus(panel, panel.getAttribute('data-drafting-label'), false);
    post(panel.getAttribute('data-draft-url'), panel.getAttribute('data-csrf'))
      .then(function (result) {
        setBusy(panel, false);
        if (result.ok && typeof result.data.value === 'string') {
          if (textarea) textarea.value = result.data.value;
          setStatus(panel, '', false);
          return;
        }
        setStatus(panel, errorText(panel, result.data && result.data.error), true);
      })
      .catch(function () {
        setBusy(panel, false);
        setStatus(panel, errorText(panel, ''), true);
      });
  }

  function draftAll(panel) {
    setBusy(panel, true);
    setStatus(panel, panel.getAttribute('data-drafting-label'), false);
    post(panel.getAttribute('data-draft-all-url'), panel.getAttribute('data-csrf'))
      .then(function (result) {
        setBusy(panel, false);
        if (!result.ok) {
          setStatus(panel, errorText(panel, result.data && result.data.error), true);
          return;
        }
        var written = (result.data && result.data.written) || [];
        var failed = (result.data && result.data.failed) || {};
        var failedLocales = Object.keys(failed);
        var text = '';
        if (written.length) {
          text = panel.getAttribute('data-wrote-tpl').replace('%%LOCALES%%', written.join(', '));
        }
        if (failedLocales.length) {
          /* The reason, not only the locale code. Every refusal on the
             server composes a full explanation (the key, the file, the
             cause, and the spec section that governs it) and rendering
             just "fr, de" here threw all of it away, leaving a developer
             with no way to learn what went wrong. Set through textContent
             by setStatus(), so a message is text, never markup. */
          var details = failedLocales
            .map(function (locale) {
              var reason = failed[locale];
              return reason ? locale + ': ' + reason : locale;
            })
            .join(' ');
          var failedText = panel.getAttribute('data-failed-tpl').replace('%%DETAILS%%', details);
          text = text ? text + ' ' + failedText : failedText;
        }
        setStatus(panel, text, written.length === 0);
      })
      .catch(function () {
        setBusy(panel, false);
        setStatus(panel, errorText(panel, ''), true);
      });
  }

  document.addEventListener('click', function (ev) {
    var oneButton = ev.target.closest && ev.target.closest('.tr-deepl-one');
    var allButton = !oneButton && ev.target.closest && ev.target.closest('.tr-deepl-all');
    if (!oneButton && !allButton) return;

    var panel = panelOf(oneButton || allButton);
    if (!panel) return;

    ev.preventDefault();
    if (oneButton) {
      draftThis(panel);
    } else {
      draftAll(panel);
    }
  });
})();
