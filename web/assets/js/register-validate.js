// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Client-side guard for the registration form: validate BEFORE submit so a
// trivially-wrong field (short password, mismatch, unchecked terms) doesn't
// round-trip to the server and wipe the password boxes (Symfony never echoes
// password values back). This mirrors the server constraints exactly but is
// only a UX shortcut — RegistrationFormType re-validates everything on POST.
(function () {
  'use strict';

  var T = window.ccT || function (k, fb, vars) { var s = fb; if (vars) { for (var p in vars) { s = s.replace('%' + p + '%', vars[p]); } } return s; };

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
      if (!ev) fail(email, T('err_email_required', 'Please enter your email address.'));
      else if (!EMAIL_RE.test(ev)) fail(email, T('err_email_invalid', 'Please enter a valid email address.'));

      if (name) {
        var nv = name.value.trim();
        if (!nv) fail(name, T('err_name_required', 'Please enter a display name.'));
        else if (nv.length < 2) fail(name, T('err_name_min', 'Display name must be at least %count% characters.', {count: 2}));
      }

      if (pass) {
        if (!pass.value) fail(pass, T('err_password_required', 'Please enter a password.'));
        else if (pass.value.length < 12) fail(pass, T('err_password_min', 'Password must be at least %count% characters.', {count: 12}));
      }

      if (confirm && pass && pass.value && confirm.value !== pass.value) {
        fail(confirm, T('err_mismatch', 'The password fields must match.'));
      }

      if (terms && !terms.checked) {
        fail(terms, T('err_terms', 'You must agree to the terms of service.'));
      }

      if (first) {
        e.preventDefault();
        if (typeof first.focus === 'function') first.focus();
      }
    });
  });
})();
