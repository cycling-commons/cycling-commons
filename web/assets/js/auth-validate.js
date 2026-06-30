// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Generic client-side guard for auth forms (login / reset password / settings).
// Validates BEFORE submit so a trivially-wrong field doesn't round-trip to the
// server and wipe password boxes (Symfony never echoes passwords back). Works
// by convention on any <form class="cc-validate">:
//   - required empty            → "Please enter your <label>."
//   - type=email, bad format    → "Please enter a valid email address."
//   - password id ending _first → required + minimum length (PW_MIN)
//   - password id ending _second→ must match its _first sibling
//   - single password           → required only (e.g. current password, login)
// The server (Symfony form types) re-validates everything — this is only UX.
// (The registration page has its own register-validate.js for its IsTrue terms
// checkbox; this file deliberately leaves that form alone.)
(function () {
  'use strict';

  var PW_MIN = 12;                              // matches server Length(min: 12) on new passwords
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    var forms = document.querySelectorAll('form.cc-validate');
    if (!forms.length) return;

    Array.prototype.forEach.call(forms, function (form) {
      function labelFor(el) {
        var l = el.id ? form.querySelector('label[for="' + el.id + '"]') : null;
        return l ? l.textContent.trim() : '';
      }
      function clearErrors() {
        Array.prototype.forEach.call(form.querySelectorAll('.cc-js-err'), function (e) { e.remove(); });
        Array.prototype.forEach.call(form.querySelectorAll('.is-invalid'), function (e) { e.classList.remove('is-invalid'); });
      }
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
      function enterMsg(el, fallback) {
        var lab = labelFor(el);
        return lab ? ('Please enter your ' + lab.toLowerCase() + '.') : fallback;
      }

      form.addEventListener('submit', function (e) {
        clearErrors();
        var first = null;
        function fail(input, msg) { addError(input, msg); if (!first) first = input; }

        Array.prototype.forEach.call(form.querySelectorAll('input, select, textarea'), function (el) {
          if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return;
          if (el.disabled || el.readOnly) return;
          if (el.name === '_csrf_token' || el.name === '_remember_me') return;

          var id = el.id || '';

          // Confirm-password field: must match its _first sibling.
          if (/_second$/.test(id)) {
            var firstEl = document.getElementById(id.replace(/_second$/, '_first'));
            if (firstEl && firstEl.value && el.value !== firstEl.value) fail(el, 'The password fields must match.');
            return;
          }

          if (el.type === 'email') {
            var ev = el.value.trim();
            if (el.required && !ev) fail(el, 'Please enter your email address.');
            else if (ev && !EMAIL_RE.test(ev)) fail(el, 'Please enter a valid email address.');
            return;
          }

          if (el.type === 'password') {
            if (el.required && !el.value) { fail(el, enterMsg(el, 'Please enter a password.')); return; }
            if (/_first$/.test(id) && el.value && el.value.length < PW_MIN) {
              fail(el, 'Password must be at least ' + PW_MIN + ' characters.');
            }
            return;
          }

          if (el.required) {
            if (el.type === 'checkbox') {
              if (!el.checked) fail(el, labelFor(el) || 'Please check this box to continue.');
            } else if (!el.value.trim()) {
              fail(el, enterMsg(el, 'This field is required.'));
            }
          }
        });

        if (first) {
          e.preventDefault();
          if (typeof first.focus === 'function') first.focus();
        }
      });
    });
  });
})();
