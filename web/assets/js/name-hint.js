// SPDX-License-Identifier: AGPL-3.0-only
// The display-name hint (docs/specs/account-and-auth.md §9): a moment after the
// rider stops typing, asks whether another account already uses the name and
// says so under the field. Information only; it never blocks the form.
// Markup: templates/partials/_name_hint.html.twig.
(function () {
  'use strict';

  var DELAY_MS = 500;
  var MIN_LENGTH = 2;
  var MAX_LENGTH = 100;

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function wire(hint) {
    var input = document.getElementById(hint.getAttribute('data-for'));
    if (!input || !window.fetch || !window.FormData) return;

    var url = hint.getAttribute('data-url');
    var stamp = hint.getAttribute('data-stamp');
    var timer = null;
    var asked = 0;
    var lastName = null;

    // Only a name in use says anything; a free name needs no message.
    function show(inUse) {
      if (inUse === true) {
        hint.textContent = hint.getAttribute('data-shared');
        hint.setAttribute('data-state', 'shared');
        hint.hidden = false;
      } else {
        hint.textContent = '';
        hint.removeAttribute('data-state');
        hint.hidden = true;
      }
    }

    function ask() {
      var name = input.value.trim();
      if (name === lastName) return;
      lastName = name;
      if (name.length < MIN_LENGTH || name.length > MAX_LENGTH) {
        ++asked;
        show(null);
        return;
      }

      var mine = ++asked;
      var body = new FormData();
      body.append('name', name);
      if (stamp) body.append('form_stamp', stamp);

      fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          // A slower answer for an earlier spelling must not overwrite a newer one.
          if (mine === asked) show(data && typeof data.inUse === 'boolean' ? data.inUse : null);
        })
        .catch(function () { if (mine === asked) show(null); });
    }

    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(ask, DELAY_MS);
    });
    // A value the browser filled in, or a name typed before this script ran.
    input.addEventListener('change', ask);
    if (hint.hidden && input.value.trim() !== '') ask();
    else lastName = input.value.trim();
  }

  ready(function () {
    Array.prototype.forEach.call(document.querySelectorAll('[data-name-hint]'), wire);
  });
})();
