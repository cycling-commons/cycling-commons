// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Mobile navigation — adds a hamburger button + slide-in drawer to the site
// nav on small screens. Self-contained: detects the page's primary nav-links
// container (the markup varies per page: .nav-links on the landing page,
// .topnav/.top .links on content pages, nav.pnav on login), clones the links
// into a right-hand drawer, and injects its own styles so it works whether or
// not the page links atlas.css. No-ops on pages without a collapsible nav
// (e.g. the map, which has its own mobile rail drawer).
(function () {
  'use strict';
  if (window.__ccNavDrawer) return;          // guard against double-init
  window.__ccNavDrawer = true;

  var BREAKPOINT = 780;                       // matches the existing CSS breakpoint

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function injectStyles() {
    if (document.getElementById('cc-nav-style')) return;
    var css = [
      '.cc-burger{display:none;flex-direction:column;justify-content:center;gap:5px;',
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
        'transform:translateX(100%);transition:transform .28s ease}',
      '.cc-drawer.cc-open{transform:translateX(0)}',
      '.cc-drawer-head{display:flex;justify-content:flex-end;margin-bottom:.4rem}',
      '.cc-close{background:none;border:0;color:var(--paper,#EFE6D4);font-size:1.7rem;',
        'line-height:1;cursor:pointer;padding:.2rem .5rem;opacity:.85}',
      '.cc-close:hover{opacity:1}',
      '.cc-drawer-nav{display:flex;flex-direction:column}',
      '.cc-drawer-nav a{color:var(--paper,#EFE6D4);font-size:1.06rem;padding:.85rem .25rem;',
        'border-bottom:1px solid rgba(239,230,212,.12);opacity:.92;text-decoration:none}',
      '.cc-drawer-nav a:hover{opacity:1}',
      '.cc-drawer-nav a.on{color:var(--trail,#FF5A1F);opacity:1}',
      // CTA links (Get involved / Explore the map / Account) become buttons
      '.cc-drawer-nav a.acct,.cc-drawer-nav a.nav-cta{margin-top:.9rem;border:1.5px solid ',
        'rgba(239,230,212,.4);border-radius:5px;text-align:center;padding:.8rem;opacity:1;',
        'border-bottom-width:1.5px}',
      '.cc-drawer-nav a.acct+a.acct,.cc-drawer-nav a.nav-cta+a.nav-cta{margin-top:.55rem}',
      '.cc-drawer-nav a.acct.join,.cc-drawer-nav a.nav-cta.join{border-color:var(--trail,#FF5A1F);',
        'color:var(--trail,#FF5A1F)}',
      '.cc-drawer-nav a.acct.fill,.cc-drawer-nav a.nav-cta.fill{background:var(--trail,#FF5A1F);',
        'border-color:var(--trail,#FF5A1F);color:var(--ink,#101E16)}',
      '@media(max-width:' + BREAKPOINT + 'px){',
        '.cc-burger{display:flex}.cc-nav-links{display:none!important}}',
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
    var anchors = links.querySelectorAll('a');
    if (!anchors.length) return;

    injectStyles();
    links.classList.add('cc-nav-links');

    // Hamburger button, inserted right after the inline links so it lands in
    // the nav bar's flex row.
    var burger = document.createElement('button');
    burger.type = 'button';
    burger.className = 'cc-burger';
    burger.setAttribute('aria-label', 'Open menu');
    burger.setAttribute('aria-expanded', 'false');
    burger.setAttribute('aria-controls', 'cc-drawer');
    burger.innerHTML = '<span></span><span></span><span></span>';
    links.parentNode.insertBefore(burger, links.nextSibling);

    // Scrim + drawer (appended to <body> so they overlay everything).
    var scrim = document.createElement('div');
    scrim.className = 'cc-scrim';
    scrim.hidden = true;

    var drawer = document.createElement('aside');
    drawer.className = 'cc-drawer';
    drawer.id = 'cc-drawer';
    drawer.setAttribute('role', 'dialog');
    drawer.setAttribute('aria-modal', 'true');
    drawer.setAttribute('aria-label', 'Menu');
    drawer.setAttribute('aria-hidden', 'true');

    var head = document.createElement('div');
    head.className = 'cc-drawer-head';
    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'cc-close';
    closeBtn.setAttribute('aria-label', 'Close menu');
    closeBtn.innerHTML = '✕';
    head.appendChild(closeBtn);

    var dnav = document.createElement('nav');
    dnav.className = 'cc-drawer-nav';
    for (var i = 0; i < anchors.length; i++) {
      var clone = anchors[i].cloneNode(true);
      clone.removeAttribute('id');
      dnav.appendChild(clone);
    }

    drawer.appendChild(head);
    drawer.appendChild(dnav);
    document.body.appendChild(scrim);
    document.body.appendChild(drawer);

    var opened = false;                         // source of truth, set synchronously

    function focusables() {
      return drawer.querySelectorAll('a[href],button:not([disabled])');
    }

    function open() {
      if (opened) return;
      opened = true;
      scrim.hidden = false;
      // next frame so the transition runs from the hidden state
      requestAnimationFrame(function () {
        scrim.classList.add('cc-open');
        drawer.classList.add('cc-open');
      });
      drawer.setAttribute('aria-hidden', 'false');
      burger.setAttribute('aria-expanded', 'true');
      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';
      var f = focusables();
      if (f.length) f[0].focus();
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
      // fallback in case transitionend doesn't fire (reduced motion / display)
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

    window.addEventListener('resize', function () {
      if (isOpen() && window.innerWidth > BREAKPOINT) close();
    });
  });
})();
