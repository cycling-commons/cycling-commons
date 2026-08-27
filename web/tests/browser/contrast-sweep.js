// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/*
  Measure every piece of text on the public pages against the background it is
  actually painted on, and report anything under WCAG AA.
  docs/specs/contact-and-support.md, docs/TODO.md item 11

  Why this exists: the accessibility statement itself shipped with a paragraph
  at 1.12:1, invisible dark ink on dark green. The cause is a shape that no
  stylesheet review catches, because both halves look right on their own:

      .banner { color: var(--paper) }   -> specificity 0,0,1,0
      .legal p { color: #3a3d30 }       -> specificity 0,0,1,1, so this wins

  A container sets a light colour for a dark panel, and a more specific
  descendant rule repaints the text inside it. You only see it in a browser,
  on the rendered pixels, which is what this does.

  Run:  node web/tests/browser/contrast-sweep.js [baseUrl]
  Exit: 0 clean, 1 if anything is under AA.
*/
'use strict';

const BASE = process.argv[2] || 'http://127.0.0.1:8001';

const PAGES = [
  '/', '/about', '/accessibility', '/privacy', '/terms', '/licenses',
  '/contact', '/report-bug', '/known-issues', '/pages', '/credits',
  '/regions', '/coverage', '/join', '/developers', '/scout',
];

/* WCAG 2.2: 4.5:1 for body text, 3:1 for large text (>=24px, or >=18.66px bold). */
const AA_NORMAL = 4.5;
const AA_LARGE = 3.0;

const IN_PAGE = () => {
  const toRgb = (s) => {
    const m = String(s).match(/[\d.]+/g);
    return m ? m.slice(0, 3).map(Number) : null;
  };
  const lum = (c) => {
    const a = c.map((v) => {
      v /= 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
  };
  const ratio = (f, b) => {
    const l1 = lum(f), l2 = lum(b);
    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
  };

  /* The nearest ancestor that actually paints something. A transparent
     background means the text sits on whatever is behind it. */
  const painted = (el) => {
    let n = el;
    while (n) {
      const c = getComputedStyle(n).backgroundColor;
      if (c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent') {
        const rgba = String(c).match(/[\d.]+/g);
        /* A translucent layer is not a reliable ground; keep walking. */
        if (!rgba || rgba.length < 4 || Number(rgba[3]) > 0.85) return c;
      }
      n = n.parentElement;
    }
    return 'rgb(255, 255, 255)';
  };

  const findings = [];
  const seen = new Set();

  for (const el of document.querySelectorAll('body *')) {
    /* Only elements with their OWN visible text, so a wrapper is not blamed
       for its children. */
    const own = [...el.childNodes]
      .filter((n) => n.nodeType === 3)
      .map((n) => n.textContent.trim())
      .join(' ')
      .trim();
    if (!own) continue;

    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || Number(cs.opacity) < 0.1) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) continue;

    const size = parseFloat(cs.fontSize);
    const bold = Number(cs.fontWeight) >= 700;
    const large = size >= 24 || (bold && size >= 18.66);

    const fg = toRgb(cs.color);
    const bg = toRgb(painted(el));
    if (!fg || !bg) continue;

    const got = ratio(fg, bg);
    const need = large ? 3.0 : 4.5;
    if (got >= need) continue;

    const key = el.tagName + '|' + el.className + '|' + own.slice(0, 30);
    if (seen.has(key)) continue;
    seen.add(key);

    findings.push({
      selector: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : ''),
      text: own.slice(0, 60),
      color: cs.color,
      background: painted(el),
      fontSize: Math.round(size * 10) / 10,
      ratio: Math.round(got * 100) / 100,
      needs: need,
    });
  }
  return findings;
};

(async () => {
  let chromium;
  try {
    ({ chromium } = require('playwright'));
  } catch {
    console.error('playwright is not installed here. Run this from a checkout that has it,');
    console.error('or use the Playwright MCP browser instead.');
    process.exit(2);
  }

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

  let bad = 0;
  for (const path of PAGES) {
    const res = await page.goto(BASE + path, { waitUntil: 'domcontentloaded' }).catch(() => null);
    if (!res || !res.ok()) {
      console.log(`SKIP ${path} (${res ? res.status() : 'no response'})`);
      continue;
    }
    const findings = await page.evaluate(IN_PAGE);
    if (!findings.length) {
      console.log(`ok   ${path}`);
      continue;
    }
    bad += findings.length;
    console.log(`FAIL ${path}`);
    for (const f of findings) {
      console.log(`       ${f.ratio}:1 (needs ${f.needs}) ${f.selector}`);
      console.log(`         ${f.color} on ${f.background} at ${f.fontSize}px`);
      console.log(`         "${f.text}"`);
    }
  }

  await browser.close();
  console.log(bad ? `\n${bad} contrast failure(s).` : '\nEvery measured string clears WCAG AA.');
  process.exit(bad ? 1 : 0);
})();
