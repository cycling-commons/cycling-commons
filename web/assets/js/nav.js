// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Mobile hamburger + slide-in drawer. No-ops on pages without a collapsible nav.
(function () {
  'use strict';
  if (window.__ccNavDrawer) return;          // guard against double-init
  window.__ccNavDrawer = true;
  var T = window.ccT || function (k, fb) { return fb; };

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function injectStyles() {
    if (document.getElementById('cc-nav-style')) return;
    var css = [
      // flex:0 0 44px so an overfull bar cannot crush the burger.
      '.cc-burger{display:none;flex:0 0 44px;flex-direction:column;justify-content:center;gap:5px;',
        'width:44px;height:44px;padding:10px;margin-left:auto;background:none;border:0;',
        'cursor:pointer;-webkit-tap-highlight-color:transparent}',
      '.cc-burger span{display:block;width:100%;height:2px;border-radius:2px;',
        'background:var(--paper,#EFE6D4)}',
      '.cc-scrim{position:fixed;inset:0;z-index:1000;background:rgba(16,30,22,.55);',
        '-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px);opacity:0;',
        'pointer-events:none;transition:opacity .25s ease}',
      '.cc-scrim.cc-open{opacity:1;pointer-events:auto}',
      '.cc-drawer{position:fixed;top:0;right:0;bottom:0;z-index:1001;',
        'width:min(82vw,320px);display:flex;flex-direction:column;',
        'padding:1rem 1.4rem 2rem;overflow-y:auto;color:var(--paper,#EFE6D4);',
        'font-family:var(--sans,"Spline Sans",system-ui,sans-serif);',
        'background:radial-gradient(70% 120% at 90% 0,rgba(250,190,80,.18),transparent 60%),',
        'linear-gradient(160deg,#15301f 0%,#27513a 100%);',
        'box-shadow:-18px 0 40px -20px rgba(0,0,0,.6);',
        'transform:translateX(100%);visibility:hidden;',
        'transition:transform .28s ease,visibility 0s linear .28s}',
      '.cc-drawer.cc-open{transform:translateX(0);visibility:visible;transition:transform .28s ease}',
      '.cc-drawer-head{display:flex;justify-content:flex-end;margin-bottom:.4rem}',
      '.cc-close{background:none;border:0;color:var(--paper,#EFE6D4);font-size:1.7rem;',
        'line-height:1;cursor:pointer;padding:.2rem .5rem;opacity:.85}',
      '.cc-close:hover{opacity:1}',
      '.cc-drawer-nav{display:flex;flex-direction:column;align-items:stretch;position:static}',
      '.cc-drawer-nav a{box-sizing:border-box;width:100%;color:var(--paper,#EFE6D4);',
        'font-size:1.06rem;padding:.85rem .25rem;border-bottom:1px solid rgba(239,230,212,.12);',
        'opacity:.92;text-decoration:none}',
      '.cc-drawer-nav a:hover{opacity:1}',
      '.cc-drawer-nav a.on{color:var(--trail,#FF5A1F);opacity:1}',
      '.cc-drawer-nav a.acct,.cc-drawer-nav a.nav-cta{margin-top:.9rem;border:1.5px solid ',
        'rgba(239,230,212,.4);border-radius:5px;text-align:center;padding:.8rem;opacity:1;',
        'border-bottom-width:1.5px}',
      '.cc-drawer-nav a.acct+a.acct,.cc-drawer-nav a.nav-cta+a.nav-cta{margin-top:.55rem}',
      '.cc-drawer-nav a.acct.join,.cc-drawer-nav a.nav-cta.join{border-color:var(--trail,#FF5A1F);',
        'color:var(--trail,#FF5A1F)}',
      '.cc-drawer-nav a.acct.fill,.cc-drawer-nav a.nav-cta.fill{background:var(--trail,#FF5A1F);',
        'border-color:var(--trail,#FF5A1F);color:var(--ink,#101E16)}',
      '.cc-drawer-lang{display:flex;flex-direction:column;align-items:stretch;',
        'margin-top:1.1rem;padding-top:.5rem;border-top:1px solid rgba(239,230,212,.18)}',
      '.cc-drawer-lang-h{font-family:var(--mono,ui-monospace,monospace);font-size:.62rem;',
        'letter-spacing:.14em;text-transform:uppercase;opacity:.55;margin-bottom:.1rem}',
      '.cc-drawer-lang a[aria-current]{color:var(--trail,#FF5A1F);opacity:1}',
      '.cc-nav-links a.acct,.cc-nav-links a.nav-cta{white-space:nowrap}',
      '@media(prefers-reduced-motion:reduce){.cc-drawer,.cc-scrim{transition:none}}'
    ].join('');
    var style = document.createElement('style');
    style.id = 'cc-nav-style';
    style.textContent = css;
    document.head.appendChild(style);
  }

  function findNavLinks() {
    var els = document.querySelectorAll('.nav-links, .links, nav.pnav');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (el.closest('.foot')) continue;       // skip footers
      if (el.querySelector('a')) return el;
    }
    return null;
  }

  ready(function () {
    var links = findNavLinks();
    if (!links) return;                         // nothing collapsible on this page
    var anchors = Array.prototype.filter.call(
      links.querySelectorAll('a'),
      function (a) { return !a.closest('[data-nav-menu]'); }
    );
    if (!anchors.length) return;

    injectStyles();
    links.classList.add('cc-nav-links');

    var burger = document.createElement('button');
    burger.type = 'button';
    burger.className = 'cc-burger';
    burger.setAttribute('aria-label', T('nav_open', 'Open menu'));
    burger.setAttribute('aria-expanded', 'false');
    burger.setAttribute('aria-controls', 'cc-drawer');
    burger.innerHTML = '<span></span><span></span><span></span>';
    links.parentNode.insertBefore(burger, links.nextSibling);

    var scrim = document.createElement('div');
    scrim.className = 'cc-scrim';
    scrim.hidden = true;

    var drawer = document.createElement('aside');
    drawer.className = 'cc-drawer';
    drawer.id = 'cc-drawer';
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-modal', 'true');
    drawer.setAttribute('aria-label', T('nav_menu', 'Menu'));
    drawer.setAttribute('aria-hidden', 'true');

    var head = document.createElement('div');
    head.className = 'cc-drawer-head';
    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'cc-close';
    closeBtn.setAttribute('aria-label', T('nav_close', 'Close menu'));
    closeBtn.innerHTML = '✕';
    head.appendChild(closeBtn);

    var dnav = document.createElement('div');
    dnav.className = 'cc-drawer-nav';
    var ctas = [];
    for (var i = 0; i < anchors.length; i++) {
      var clone = anchors[i].cloneNode(true);
      clone.removeAttribute('id');
      if (clone.classList.contains('acct') || clone.classList.contains('nav-cta')) {
        ctas.push(clone);                       // defer CTAs so we can reorder them
      } else {
        dnav.appendChild(clone);                // regular links keep their order
      }
    }
    ctas.sort(function (a, b) {
      return (a.classList.contains('fill') ? 0 : 1) - (b.classList.contains('fill') ? 0 : 1);
    });
    ctas.forEach(function (c) { dnav.appendChild(c); });

    var langMenu = links.querySelector('.lang-menu');
    var drawerLang = null;
    if (langMenu) {
      drawerLang = document.createElement('div');
      drawerLang.className = 'cc-drawer-lang';
      drawerLang.style.display = 'none';
      var langHead = document.createElement('div');
      langHead.className = 'cc-drawer-lang-h';
      var langToggle = langMenu.querySelector('[data-nav-toggle]');
      langHead.textContent = (langToggle && langToggle.getAttribute('aria-label'))
        || T('nav_language', 'Language');
      drawerLang.appendChild(langHead);
      Array.prototype.forEach.call(langMenu.querySelectorAll('[data-nav-dd] a'), function (a) {
        var lc = a.cloneNode(true);
        lc.removeAttribute('id');
        drawerLang.appendChild(lc);
      });
      dnav.appendChild(drawerLang);
    }

    drawer.appendChild(head);
    drawer.appendChild(dnav);
    document.body.appendChild(scrim);
    document.body.appendChild(drawer);

    var navBar = links.parentNode;
    var inlineAnchors = Array.prototype.filter.call(
      links.querySelectorAll('a'),
      function (a) { return !a.closest('[data-nav-menu]'); }
    );
    var inlineSub = inlineAnchors.filter(function (a) {
      return !a.classList.contains('acct') && !a.classList.contains('nav-cta');
    });
    var inlineBlocks = inlineAnchors.filter(function (a) {
      return a.classList.contains('acct') || a.classList.contains('nav-cta');
    });
    function fits() { return navBar.scrollWidth <= navBar.clientWidth + 1; }
    function foldLang(folded) {
      if (!langMenu) return;
      langMenu.style.display = folded ? 'none' : '';
      if (drawerLang) drawerLang.style.display = folded ? '' : 'none';
    }
    function fit() {
      // One write pass, one read pass, one computed write — avoid layout thrash.
      inlineSub.forEach(function (a) { a.style.display = ''; });
      inlineBlocks.forEach(function (a) { a.style.display = ''; });
      foldLang(false);
      burger.style.display = 'none';
      if (fits()) return;                                  // everything fits — no burger
      var deficit = navBar.scrollWidth - navBar.clientWidth;
      var widths = inlineSub.map(function (a) { return a.offsetWidth; });
      var gap = parseFloat(getComputedStyle(navBar).columnGap) || 0;
      burger.style.display = 'flex';                        // need the burger now
      deficit += 44 + gap;                                  // the burger itself takes room
      for (var i = inlineSub.length - 1; i >= 0 && deficit > 0; i--) {
        inlineSub[i].style.display = 'none';                // drop secondary links, last first
        deficit -= widths[i] + gap;
      }
      for (var j = i; j >= 0 && !fits(); j--) {
        inlineSub[j].style.display = 'none';                // corrective: gap rounding
      }
      if (fits()) return;                                  // blocks (+ any links that fit) + burger
      inlineBlocks.forEach(function (a) { a.style.display = 'none'; });  // burger-only
      if (fits()) return;
      foldLang(true);                                       // last resort — language → drawer
    }
    fit();

    var opened = false;                         // source of truth, set synchronously

    function focusables() {
      return drawer.querySelectorAll('a[href],button:not([disabled])');
    }

    function open() {
      if (opened) return;
      opened = true;
      scrim.hidden = false;
      requestAnimationFrame(function () {
        scrim.classList.add('cc-open');
        drawer.classList.add('cc-open');
        var f = focusables();
        if (f.length) f[0].focus();
      });
      drawer.setAttribute('aria-hidden', 'false');
      burger.setAttribute('aria-expanded', 'true');
      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';
    }

    function close() {
      if (!opened) return;
      opened = false;
      scrim.classList.remove('cc-open');
      drawer.classList.remove('cc-open');
      drawer.setAttribute('aria-hidden', 'true');
      burger.setAttribute('aria-expanded', 'false');
      document.documentElement.style.overflow = '';
      document.body.style.overflow = '';
      var hide = function () { scrim.hidden = true; scrim.removeEventListener('transitionend', hide); };
      scrim.addEventListener('transitionend', hide);
      setTimeout(function () { if (!drawer.classList.contains('cc-open')) scrim.hidden = true; }, 350);
      burger.focus();                           // return focus to the toggle
    }

    function isOpen() { return opened; }

    burger.addEventListener('click', open);
    closeBtn.addEventListener('click', close);
    scrim.addEventListener('click', close);
    dnav.addEventListener('click', function (e) {
      if (e.target.closest('a')) close();       // navigating away — close the drawer
    });

    document.addEventListener('keydown', function (e) {
      if (!isOpen()) return;
      if (e.key === 'Escape') { e.preventDefault(); close(); return; }
      if (e.key === 'Tab') {                     // simple focus trap
        var f = focusables();
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });

    var rafPending = false;
    window.addEventListener('resize', function () {
      if (rafPending) return;
      rafPending = true;
      requestAnimationFrame(function () {
        rafPending = false;
        fit();
        if (isOpen() && burger.style.display === 'none') close();
      });
    });
  });
})();
