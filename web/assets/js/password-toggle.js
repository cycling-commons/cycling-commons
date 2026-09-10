// SPDX-License-Identifier: AGPL-3.0-only
// Show/hide toggle on every password input. No-ops on pages without one.
(function () {
  'use strict';
  if (window.__ccPwToggle) return;
  window.__ccPwToggle = true;
  var T = window.ccT || function (k, fb) { return fb; };

  var EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function injectStyles() {
    if (document.getElementById('cc-pw-style')) return;
    var css = [
      '.cc-pw-wrap{position:relative;display:block}',
      // type=text must keep password-field font/underline, or reveal falls back to the browser default.
      '.cc-pw-wrap input{padding-right:2.4rem!important;border:none;border-bottom:1.5px solid rgba(20,22,14,.25);border-radius:0;background:transparent;font-family:var(--sans);font-size:1rem}',
      '.cc-pw-wrap input:focus{border-bottom-color:var(--trail,#FF5A1F);outline:none}',
      '.cc-pw-toggle{position:absolute;right:0;bottom:.15rem;display:flex;align-items:center;justify-content:center;',
        'width:2rem;height:2rem;padding:0;background:none;border:0;cursor:pointer;color:#5a5d4d;opacity:.7;',
        '-webkit-tap-highlight-color:transparent}',
      '.cc-pw-toggle:hover,.cc-pw-toggle:focus-visible{opacity:1;color:var(--ink,#101E16)}',
      '.cc-pw-toggle svg{width:18px;height:18px;display:block}'
    ].join('');
    var s = document.createElement('style');
    s.id = 'cc-pw-style';
    s.textContent = css;
    document.head.appendChild(s);
  }

  ready(function () {
    var inputs = document.querySelectorAll('input[type="password"]');
    if (!inputs.length) return;
    injectStyles();

    Array.prototype.forEach.call(inputs, function (input) {
      if (input.dataset.ccPw) return;
      input.dataset.ccPw = '1';

      var wrap = document.createElement('span');
      wrap.className = 'cc-pw-wrap';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cc-pw-toggle';
      btn.setAttribute('aria-label', T('pw_show', 'Show password'));
      btn.setAttribute('aria-pressed', 'false');
      btn.innerHTML = EYE;
      wrap.appendChild(btn);

      btn.addEventListener('click', function () {
        var reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        btn.innerHTML = reveal ? EYE_OFF : EYE;
        btn.setAttribute('aria-label', reveal ? T('pw_hide', 'Hide password') : T('pw_show', 'Show password'));
        btn.setAttribute('aria-pressed', reveal ? 'true' : 'false');
        input.focus();
      });
    });
  });
})();
