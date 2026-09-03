// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The dev catalogue form's two safety rails (docs/specs/translations.md §7.3).

   The form carries one text field per rider locale and one tick box per
   locale deciding which of them is written. Those are two separate choices,
   which is exactly how a developer loses work: edit Dutch, forget to tick
   Dutch, submit, and the edit is gone with nothing said.

   So:
     1. A field whose text differs from what it arrived holding is marked
        `is-changed`, which tints it. Nothing else reads that class.
     2. Submitting with a changed field that is NOT ticked to be written
        asks first, naming the languages whose changes would be dropped.

   Loaded from base.html.twig, gated on the same CC_CATALOGUE_WRITE opt-in
   that renders the form (App\Twig\DeepLExtension), and delegating from
   `document` rather than binding on load: this form can arrive with the page
   or be fetched as text into the translate-mode drawer, where a <script>
   inside the fetched HTML never runs. Must be a FILE: the CSP blocks inline
   handlers silently. */
(function () {
  'use strict';

  var CHANGED = 'is-changed';

  function rowOf(field) {
    return field.closest('[data-locale-row]');
  }

  function localeOf(field) {
    var row = rowOf(field);
    return row ? row.getAttribute('data-locale-row') : null;
  }

  /* A field is changed when it no longer holds the wording the server put
     in it. Compared against data-initial rather than defaultValue: the
     drawer sets this form through innerHTML, and a textarea built that way
     takes its text content as its default, which is the same string only
     until something re-renders. */
  function isChanged(field) {
    var initial = field.getAttribute('data-initial');
    return initial !== null && field.value !== initial;
  }

  function mark(field) {
    field.classList.toggle(CHANGED, isChanged(field));
  }

  function saveBoxFor(form, locale) {
    return form.querySelector('[data-save-pick] input[type="checkbox"][name$="[save_' + locale + ']"]');
  }

  /* The language names of every field the developer edited and did not tick
     to be written. Read from the tick box's own label so the question names
     languages the way the rest of the page does, never locale codes. */
  function droppedLanguages(form) {
    var fields = form.querySelectorAll('[data-locale-row] textarea[data-initial]');
    var dropped = [];
    for (var i = 0; i < fields.length; i++) {
      if (!isChanged(fields[i])) continue;
      var locale = localeOf(fields[i]);
      var box = locale ? saveBoxFor(form, locale) : null;
      if (box && box.checked) continue;
      var label = box ? box.closest('label') : null;
      dropped.push(label ? label.textContent.trim() : locale);
    }
    return dropped;
  }

  document.addEventListener('input', function (ev) {
    var field = ev.target;
    if (!field || 'TEXTAREA' !== field.tagName || !field.hasAttribute('data-initial')) return;
    mark(field);
  });

  document.addEventListener(
    'submit',
    function (ev) {
      var form = ev.target;
      if (!form || !form.querySelector) return;
      var pick = form.querySelector('[data-save-pick]');
      if (!pick) return;

      var dropped = droppedLanguages(form);
      if (0 === dropped.length) return;

      var question = pick.getAttribute('data-discard-tpl').replace('%%LOCALES%%', dropped.join(', '));
      if (!window.confirm(question)) ev.preventDefault();
    },
    true,
  );
})();
