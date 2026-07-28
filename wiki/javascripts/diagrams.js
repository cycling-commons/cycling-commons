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

  function render() {
    var blocks = sources();
    if (!blocks.length) return;
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
      blocks.forEach(function (pre, i) {
        var code = pre.querySelector('code');
        var src = (code || pre).textContent;
        mermaid.render('ccdiag-' + i, src).then(function (res) {
          var fig = document.createElement('div');
          fig.className = 'cc-diagram-out';
          fig.setAttribute('data-src', src);
          fig.innerHTML = res.svg;
          pre.replaceWith(fig);
        }).catch(function (e) {
          // Leave the source visible and say why, rather than an empty box.
          pre.setAttribute('data-diagram-error', (e && e.message) || String(e));
        });
      });
    }).catch(function () { /* offline: the source stays readable */ });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
  // Material's instant navigation swaps the <main> without a page load.
  if (window.document$ && typeof window.document$.subscribe === 'function') {
    window.document$.subscribe(render);
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
})();
