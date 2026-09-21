/* SPDX-License-Identifier: CC-BY-SA-4.0
   Render the wiki's diagrams ourselves instead of using Material's built-in
   Mermaid integration.

   Why: Material 9.7.6 fetches `mermaid@11` — a FLOATING major — and the 11.16
   that resolves to blanks every diagram (verified: the blocks become empty
   <div class="mermaid">, with no console error, in both `mkdocs serve` and a
   static build; the same sources parse and render fine when called directly).
   So we take the fence for ourselves (`class: cc-diagram`, which Material
   ignores), and load a PINNED mermaid.

   Degrades honestly: with no network the diagram source stays on the page as a
   readable code block rather than vanishing. */
(function () {
  'use strict';
  // The UMD build, not the ESM one: the ESM build resolves render() with an
  // EMPTY svg string here (all chunks load, no error, eight empty frames),
  // while the UMD global renders the same sources to 21-200 kB of SVG.
  var MERMAID = 'https://unpkg.com/mermaid@11.4.1/dist/mermaid.min.js';

  function sources() {
    return Array.prototype.slice.call(document.querySelectorAll('pre.cc-diagram'));
  }

  function load(src) {
    if (window.mermaid) return Promise.resolve();
    if (load._p) return load._p;
    load._p = new Promise(function (resolve, reject) {
      var el = document.createElement('script');
      el.src = src; el.async = true;
      el.onload = resolve; el.onerror = reject;
      document.head.appendChild(el);
    });
    return load._p;
  }

  /* Read the palette off the page instead of hardcoding it, so a diagram is
     legible in BOTH schemes. Hardcoding light values put near-black label text
     on the dark scheme's dark node fills — unreadable (reported 2026-07-28). */
  function palette() {
    var cs = getComputedStyle(document.body);
    var v = function (name, fallback) {
      return (cs.getPropertyValue(name) || '').trim() || fallback;
    };
    var fg = v('--md-default-fg-color', '#101E16');
    var bg = v('--md-default-bg-color', '#EFE6D4');
    var deep = v('--cc-paper-deep', '#F8F2E4');
    return {
      primaryColor: deep, primaryTextColor: fg, primaryBorderColor: v('--cc-spruce', '#1C3A2A'),
      lineColor: v('--md-default-fg-color--light', '#5A5D4D'),
      secondaryColor: deep, tertiaryColor: bg,
      background: bg, mainBkg: deep, nodeTextColor: fg,
      clusterBkg: deep, clusterBorder: v('--cc-spruce', '#1C3A2A'),
      edgeLabelBackground: bg, textColor: fg, fontSize: '15px',
    };
  }

  /* One pass at a time, and a fresh id namespace per pass.

     Both matter, and the second is what broke every diagram on 2026-08-24.
     mermaid.render(id, src) parks a scratch element under that id and removes
     it BY ID when it finishes. With a fixed 'ccdiag-<i>' two overlapping passes
     shared every id, so the later pass's cleanup deleted the SVG the earlier
     one had already put on the page: the diagram drew, stood for about a
     second, and the box collapsed to its own padding with nothing in the
     console. */
  var pass = 0;
  var running = false;
  var again = false;

  function render() {
    if (running) { again = true; return; }
    var blocks = sources();
    if (!blocks.length) return;
    running = true;
    var mine = ++pass;
    var settled = function () {
      running = false;
      if (again) { again = false; render(); }
    };
    load(MERMAID).then(function () {
      var mermaid = window.mermaid;
      if (!mermaid) return;
      // securityLevel strict: these are our own authored diagrams, but the
      // wiki is public and there is no reason to allow HTML in labels.
      mermaid.initialize({
        startOnLoad: false, securityLevel: 'loose', theme: 'base',
        fontFamily: '"Hanken Grotesk", system-ui, sans-serif',
        // securityLevel 'loose' so <br/> in a label is a real line break. These
        // diagrams are authored in this repo, not user input — under 'strict'
        // mermaid strips the tags and every label runs together on one line.
        themeVariables: palette(),
      });
      return Promise.all(blocks.map(function (pre, i) {
        var code = pre.querySelector('code');
        var src = (code || pre).textContent;
        return mermaid.render('ccdiag-' + mine + '-' + i, src).then(function (res) {
          // A pass that started before a re-render may land on a detached node.
          if (!pre.isConnected) return;
          var fig = document.createElement('div');
          fig.className = 'cc-diagram-out';
          fig.setAttribute('data-src', src);
          fig.setAttribute('tabindex', '0');
          fig.setAttribute('role', 'button');
          fig.setAttribute('aria-label', 'Show this diagram larger');
          fig.innerHTML = res.svg;
          pre.replaceWith(fig);
        }).catch(function (e) {
          // Leave the source visible and say why, rather than an empty box.
          pre.setAttribute('data-diagram-error', (e && e.message) || String(e));
        });
      }));
    }).catch(function () { /* offline: the source stays readable */ })
      .then(settled, settled);
  }

  /* Material's document$ is a ReplaySubject: subscribing fires immediately with
     the current document, and again on every instant navigation. So when it
     exists it is the ONLY trigger. Calling render() here as well started a
     second pass in the same tick as the first, which is how the id collision
     above got the chance to happen at all. */
  if (window.document$ && typeof window.document$.subscribe === 'function') {
    window.document$.subscribe(render);
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
  // Re-render on a scheme flip: Material stamps data-md-color-scheme on <body>,
  // and an SVG already drawn keeps the palette it was drawn with.
  if (window.MutationObserver) {
    new MutationObserver(function () {
      document.querySelectorAll('.cc-diagram-out').forEach(function (out) {
        var src = out.getAttribute('data-src');
        if (!src) return;
        var pre = document.createElement('pre');
        pre.className = 'cc-diagram';
        pre.textContent = src;
        out.replaceWith(pre);
      });
      render();
    }).observe(document.body, { attributes: true, attributeFilter: ['data-md-color-scheme'] });
  }

  /* A drawn diagram is sized to the text column, which is too small to read a
     system map. Click (or Enter) opens the same SVG in a native <dialog> at
     the viewport width. Delegated, because render() replaces the nodes. */
  function zoom(out) {
    var svg = out.querySelector('svg');
    if (!svg || typeof HTMLDialogElement === 'undefined') return;
    var dlg = document.createElement('dialog');
    dlg.className = 'cc-diagram-zoom';
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'cc-diagram-zoom-close';
    close.setAttribute('aria-label', 'Close');
    close.textContent = '×';
    var box = document.createElement('div');
    box.className = 'cc-diagram-zoom-box';
    box.appendChild(svg.cloneNode(true));
    dlg.appendChild(close);
    dlg.appendChild(box);
    dlg.addEventListener('click', function (e) {
      if (e.target === dlg || e.target === close) dlg.close();
    });
    dlg.addEventListener('close', function () { dlg.remove(); });
    document.body.appendChild(dlg);
    dlg.showModal();
  }
  document.addEventListener('click', function (e) {
    var out = e.target && e.target.closest ? e.target.closest('.cc-diagram-out') : null;
    if (out) zoom(out);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var out = e.target && e.target.classList && e.target.classList.contains('cc-diagram-out') ? e.target : null;
    if (out) { e.preventDefault(); zoom(out); }
  });
})();
