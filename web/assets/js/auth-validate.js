// SPDX-License-Identifier: AGPL-3.0-only
// Client-side guard for auth forms. UX only — the server re-validates on POST.
(function () {
  'use strict';

  var T = window.ccT || function (k, fb, vars) { var s = fb; if (vars) { for (var p in vars) { s = s.replace('%' + p + '%', vars[p]); } } return s; };
  var PW_MIN = 12;
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
        return lab ? T('err_field', 'Please enter your %field%.', {field: lab.toLowerCase()}) : fallback;
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

          if (/_second$/.test(id)) {
            var firstEl = document.getElementById(id.replace(/_second$/, '_first'));
            if (firstEl && firstEl.value && el.value !== firstEl.value) fail(el, T('err_mismatch', 'The password fields must match.'));
            return;
          }

          if (el.type === 'email') {
            var ev = el.value.trim();
            if (el.required && !ev) fail(el, T('err_email_required', 'Please enter your email address.'));
            else if (ev && !EMAIL_RE.test(ev)) fail(el, T('err_email_invalid', 'Please enter a valid email address.'));
            return;
          }

          if (el.type === 'password') {
            if (el.required && !el.value) { fail(el, enterMsg(el, T('err_password_required', 'Please enter a password.'))); return; }
            if (/_first$/.test(id) && el.value && el.value.length < PW_MIN) {
              fail(el, T('err_password_min', 'Password must be at least %count% characters.', {count: PW_MIN}));
            }
            return;
          }

          if (el.required) {
            if (el.type === 'checkbox') {
              if (!el.checked) fail(el, labelFor(el) || T('err_checkbox', 'Please check this box to continue.'));
            } else if (!el.value.trim()) {
              fail(el, enterMsg(el, T('err_required', 'This field is required.')));
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
