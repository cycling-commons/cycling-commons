// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Client-side guard for the registration form: validate BEFORE submit so a
// trivially-wrong field (short password, mismatch, unchecked terms) doesn't
// round-trip to the server and wipe the password boxes (Symfony never echoes
// password values back). This mirrors the server constraints exactly but is
// only a UX shortcut — RegistrationFormType re-validates everything on POST.
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    var email = document.getElementById('registration_form_email');
    if (!email) return;                         // not the registration page
    var form = email.closest('form');
    if (!form) return;

    var name = document.getElementById('registration_form_displayName');
    var pass = document.getElementById('registration_form_plainPassword_first');
    var confirm = document.getElementById('registration_form_plainPassword_second');
    var terms = document.getElementById('registration_form_agreeTerms');
    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    function clearErrors() {
      Array.prototype.forEach.call(form.querySelectorAll('.cc-js-err'), function (el) { el.remove(); });
      Array.prototype.forEach.call(form.querySelectorAll('.is-invalid'), function (el) { el.classList.remove('is-invalid'); });
    }

    // Inject an error list styled identically to the server-rendered ones,
    // placed right after the field's container (matching server placement).
    function addError(input, msg) {
      if (input) input.classList.add('is-invalid');
      var ul = document.createElement('ul');
      ul.className = 'field-errors cc-js-err';
      var li = document.createElement('li');
      li.textContent = msg;
      ul.appendChild(li);
      var box = input && (input.closest('.field') || input.closest('.field-check'));
      if (box) box.parentNode.insertBefore(ul, box.nextSibling);
      else if (input) input.parentNode.insertBefore(ul, input.nextSibling);
      else form.appendChild(ul);
    }

    form.addEventListener('submit', function (e) {
      clearErrors();
      var first = null;
      function fail(input, msg) { addError(input, msg); if (!first) first = input; }

      var ev = email.value.trim();
      if (!ev) fail(email, 'Please enter your email address.');
      else if (!EMAIL_RE.test(ev)) fail(email, 'Please enter a valid email address.');

      if (name) {
        var nv = name.value.trim();
        if (!nv) fail(name, 'Please enter a display name.');
        else if (nv.length < 2) fail(name, 'Display name must be at least 2 characters.');
      }

      if (pass) {
        if (!pass.value) fail(pass, 'Please enter a password.');
        else if (pass.value.length < 12) fail(pass, 'Password must be at least 12 characters.');
      }

      if (confirm && pass && pass.value && confirm.value !== pass.value) {
        fail(confirm, 'The password fields must match.');
      }

      if (terms && !terms.checked) {
        fail(terms, 'You must agree to the terms of service.');
      }

      if (first) {
        e.preventDefault();
        if (typeof first.focus === 'function') first.focus();
      }
    });
  });
})();
