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
  var T = window.ccT || function (k, fb) { return fb; };

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function injectStyles() {
    if (document.getElementById('cc-nav-style')) return;
    var css = [
      // flex:0 0 44px, not width alone: the burger sits in the nav's flex row,
      // so the default flex-shrink:1 let an overfull bar CRUSH it — measured at
      // 20px wide and pushed 20px past the viewport on a 390px phone (mobile
      // audit 2026-07-27). It must keep its 44px target and let the bar
      // overflow visibly instead, which is what fit() below reacts to.
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
      // visibility:hidden takes the closed drawer's links out of the keyboard
      // tab order (translateX alone only hides them visually); the 0s/.28s
      // visibility delay keeps the slide-out animation visible on close.
        'transform:translateX(100%);visibility:hidden;',
        'transition:transform .28s ease,visibility 0s linear .28s}',
      '.cc-drawer.cc-open{transform:translateX(0);visibility:visible;transition:transform .28s ease}',
      '.cc-drawer-head{display:flex;justify-content:flex-end;margin-bottom:.4rem}',
      '.cc-close{background:none;border:0;color:var(--paper,#EFE6D4);font-size:1.7rem;',
        'line-height:1;cursor:pointer;padding:.2rem .5rem;opacity:.85}',
      '.cc-close:hover{opacity:1}',
      // position:static + align-items:stretch guard against bare `nav{}` page
      // rules (e.g. the landing page) leaking into the cloned container.
      '.cc-drawer-nav{display:flex;flex-direction:column;align-items:stretch;position:static}',
      '.cc-drawer-nav a{box-sizing:border-box;width:100%;color:var(--paper,#EFE6D4);',
        'font-size:1.06rem;padding:.85rem .25rem;border-bottom:1px solid rgba(239,230,212,.12);',
        'opacity:.92;text-decoration:none}',
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
      // Language section — only rendered while the inline switcher is folded
      // away by fit()'s last tier, so the two never show at once.
      // Column flex, like .cc-drawer-nav itself: the drawer's `a{width:100%}`
      // only lands because the nav blockifies its DIRECT children as flex
      // items, and these anchors are one level deeper.
      '.cc-drawer-lang{display:flex;flex-direction:column;align-items:stretch;',
        'margin-top:1.1rem;padding-top:.5rem;border-top:1px solid rgba(239,230,212,.18)}',
      '.cc-drawer-lang-h{font-family:var(--mono,ui-monospace,monospace);font-size:.62rem;',
        'letter-spacing:.14em;text-transform:uppercase;opacity:.55;margin-bottom:.1rem}',
      '.cc-drawer-lang a[aria-current]{color:var(--trail,#FF5A1F);opacity:1}',
      // Collapse is priority-plus, driven by fit() below (not a fixed
      // breakpoint): the burger's inline display is toggled by JS. CTA blocks
      // never wrap past two lines, so "Explore the map" can't break into three.
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
    // Exclude the account chip's dropdown links: that menu manages its own
    // open/close, so its anchors must not be cloned into the drawer or toggled
    // by fit() (fit would set display:none on them and break the open dropdown).
    var anchors = Array.prototype.filter.call(
      links.querySelectorAll('a'),
      function (a) { return !a.closest('[data-nav-menu]'); }
    );
    if (!anchors.length) return;

    injectStyles();
    links.classList.add('cc-nav-links');

    // Hamburger button, inserted right after the inline links so it lands in
    // the nav bar's flex row.
    var burger = document.createElement('button');
    burger.type = 'button';
    burger.className = 'cc-burger';
    burger.setAttribute('aria-label', T('nav_open', 'Open menu'));
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

    // A <div>, not a <nav>: a <nav> element would inherit any page-level
    // `nav{...}` styles (the landing page absolutely-positions its nav).
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
    // CTAs go at the bottom, with the filled "Explore the map" ahead of the
    // rest (Get involved, Account). Array.sort is stable, so the others keep
    // their original relative order.
    ctas.sort(function (a, b) {
      return (a.classList.contains('fill') ? 0 : 1) - (b.classList.contains('fill') ? 0 : 1);
    });
    ctas.forEach(function (c) { dnav.appendChild(c); });

    // The account chip and the language switcher are <div data-nav-menu>
    // widgets, not <a>s, so the priority-plus tiers below — built from
    // links.querySelectorAll('a') — can never collapse them. On a logged-in
    // phone that left ~111px of un-droppable chrome in a 335px bar: the nav
    // overflowed, the burger was crushed and pushed 20px off-screen, and the
    // site menu was effectively unreachable (mobile audit 2026-07-27; the
    // 07-26 pass ran anonymously, where only the 71px language pill is
    // present and it still fits). The LANGUAGE switcher is the one that folds:
    // its links are cloned here first, so folding it costs no reachability.
    // The account chip stays inline at ~40px — it is the account shell's one
    // shared affordance on every logged-in surface.
    var langMenu = links.querySelector('.lang-menu');
    var drawerLang = null;
    if (langMenu) {
      drawerLang = document.createElement('div');
      drawerLang.className = 'cc-drawer-lang';
      drawerLang.style.display = 'none';
      var langHead = document.createElement('div');
      langHead.className = 'cc-drawer-lang-h';
      var langToggle = langMenu.querySelector('[data-nav-toggle]');
      // The pill already carries the translated "Language" string as its
      // aria-label, so the heading needs no new message key.
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

    // Priority-plus collapse, re-evaluated on resize (width/zoom-agnostic).
    // The burger always holds the full menu (the drawer was cloned from every
    // anchor). Inline, we keep the CTA blocks (Get involved / Explore the map /
    // Log in) and show as many secondary links as fit, dropping them from the
    // END one at a time. Only when NO secondary links remain and it still
    // overflows do we hide the blocks too (burger-only). So the states are:
    //   all links + blocks            → (fits) no burger
    //   N links + blocks + burger     → N counts down 5,4,3,2,1,0 as width shrinks
    //   blocks + burger               → links all in the drawer
    //   burger only                   → even the blocks don't fit
    //   language folded too           → last resort; the switcher moves into
    //                                   the drawer section built above
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
    // Fold the inline language switcher away and reveal its drawer section (or
    // the reverse). Never both at once.
    function foldLang(folded) {
      if (!langMenu) return;
      langMenu.style.display = folded ? 'none' : '';
      if (drawerLang) drawerLang.style.display = folded ? '' : 'none';
    }
    function fit() {
      // Phase the reads and writes instead of interleaving them: the old loop
      // called fits() (a scrollWidth read → forced layout) after every single
      // display write, costing up to ~8 synchronous layouts per resize frame
      // (frontend review 2026-08-09 #4). Now: one write pass (show all), one
      // read pass (overflow + each link's width), one computed write pass,
      // and a short corrective loop that in practice never iterates — it only
      // exists because offsetWidth excludes the flex gap.
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
      // next frame so the transition runs from the hidden state; focus must
      // wait for cc-open too — a visibility:hidden drawer refuses focus
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

    var rafPending = false;
    window.addEventListener('resize', function () {
      if (rafPending) return;
      rafPending = true;
      requestAnimationFrame(function () {
        rafPending = false;
        fit();
        // If the burger is no longer shown (everything fits inline), close any open drawer.
        if (isOpen() && burger.style.display === 'none') close();
      });
    });
  });
})();
