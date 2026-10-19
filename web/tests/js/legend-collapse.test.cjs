// SPDX-License-Identifier: AGPL-3.0-only
//
// The map legend collapses on every screen (owner 2026-08-16), and the way it
// does that is the point of these pins.
//
// It used to hide its children by NAMING them:
//   .legend:not(.open) h4, .legend:not(.open) .skey, .legend:not(.open) .skey-note, …
// which is a list somebody has to remember to extend. Adding the routes key
// immediately proved it: `.rkey` was not in that list, so a collapsed legend on
// a phone would have shown a floating route key and nothing else.
//
// So the rule is now structural — one wrapper, one class — and the test that
// matters is that every key section is INSIDE the wrapper. A section outside it
// is invisible in review and obvious only to whoever collapses the panel.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');

const twig = read('templates/map/index.html.twig');
const css = read('assets/styles/map.css');
const panels = read('assets/map/panels.js');

/** Everything between <div class="lg-body" id="lgBody"> and the legend's close. */
function legendMarkup() {
  const m = twig.match(/<div class="legend" hidden>([\s\S]*?)\n {4}<\/div>/);
  assert.ok(m, 'the legend block was not found');
  return m[1];
}

test('every key section sits inside the collapsible body', () => {
  const legend = legendMarkup();
  const bodyAt = legend.indexOf('<div class="lg-body" id="lgBody">');
  assert.ok(bodyAt >= 0, 'the legend has no #lgBody wrapper');

  // The toggle is the ONLY thing allowed above the wrapper.
  const head = legend.slice(0, bodyAt);
  assert.ok(head.includes('id="lgToggle"'), 'the toggle should sit above the body');
  for (const marker of ['<h4>', 'class="skey"', 'class="rkey"', 'skey-note', 'skey-study']) {
    assert.ok(!head.includes(marker),
      `${marker} is outside #lgBody, so collapsing the legend would leave it floating`);
  }
  // …and each of them is inside it.
  const body = legend.slice(bodyAt);
  for (const marker of ['<h4>', 'class="skey"', 'class="rkey"', 'skey-note', 'skey-study']) {
    assert.ok(body.includes(marker), `${marker} is missing from #lgBody`);
  }
});

test('one class hides the body, and the old per-element list is gone', () => {
  // One class still hides the body; it folds now rather than snapping (owner 2026-10-15).
  assert.match(css, /\.legend\.collapsed \.lg-body\{max-height:0;max-width:0;opacity:0;visibility:hidden\}/);
  assert.doesNotMatch(css, /\.legend:not\(\.open\)/,
    'the named-children hide list is exactly what this replaced; leaving it behind gives two sources of truth');
});

test('the toggle is offered on every screen, not only on phones', () => {
  // It used to be display:none outside the mobile media query.
  const rule = css.match(/\.legend \.lg-toggle\{([^}]*)\}/);
  assert.ok(rule, '.legend .lg-toggle rule not found');
  assert.doesNotMatch(rule[1], /display:none/, 'the collapse control has to exist on a laptop too');
});

test('the starting state is decided by room, and only once', () => {
  assert.match(panels, /legend\.classList\.toggle\('collapsed', window\.matchMedia\('\(max-width:760px\)'\)\.matches\)/,
    'a phone must open collapsed - the panel would cover the map it explains');
  // No resize listener re-deciding it: after the first tap the state is the
  // reader's, and having it snap back on an orientation change is worse than
  // any default.
  assert.doesNotMatch(panels, /addEventListener\('resize'[\s\S]{0,200}collapsed/);
});

test('aria-expanded follows the panel, in both directions', () => {
  assert.match(panels, /lgToggle\.setAttribute\('aria-expanded', open\?'true':'false'\)/);
  assert.match(panels, /lgToggle\.onclick=\(\)=>\{ legend\.classList\.toggle\('collapsed'\); foldedByDrawer=false; sync\(\); \}/);
  // The markup ships the desktop truth; panels.js corrects it on a phone.
  assert.match(twig, /id="lgToggle"[\s\S]{0,200}aria-expanded="true"/);
  assert.match(twig, /aria-controls="lgBody"/);
});

/* The legend shows only what is on the map (owner 2026-08-16): six surface
   colours were being explained beside a region with none of them, which teaches
   a rider to read the legend as decoration. */
test('the surface key answers to BOTH the tile skin and the curated layer', () => {
  const m = panels.match(/const surfaceOnMap=\(\)=>([\s\S]*?);\n/);
  assert.ok(m, 'surfaceOnMap not found');
  assert.match(m[1], /surfaceTilesVisible\(\)/, 'the tile skin earns the key');
  assert.match(m[1], /active\.has\('surface'\)/, "so does the curated layer being switched on");
  assert.match(m[1], /layerCounts\(surfaceLayer\)\.shown > 0/,
    'a ticked layer with nothing in scope reads 0/0 - that is the case that started this');
});

test('an empty legend hides itself rather than sitting there as a box', () => {
  // The ride review's key counts too; it exists on the review page only.
  assert.match(panels, /legendEl\.hidden=!!\(surfaceKey\?\.hidden && routesKey\?\.hidden && \(scoutKey\?\.hidden \?\? true\)\)/);
});

test('the legend and its keys are served hidden, so nothing flashes before the script decides', () => {
  assert.match(twig, /<div class="legend" hidden>/);
  assert.match(twig, /<div class="skey-block" id="surfaceKey" hidden>/);
  assert.match(twig, /<div class="rkey" id="routesKey" hidden>/);
});

test('the legend re-syncs from the one place the counts are recomputed', () => {
  const render = read('assets/map/render.js');
  assert.match(render, /document\.dispatchEvent\(new CustomEvent\('cc:counts'\)\)/,
    'updateCounts must announce; scope, mode, best-of and layer toggles all pass through it');
  // The same announcement now also re-decides which filter groups have
  // anything to filter, so the listener is a body rather than a bare callback.
  assert.match(panels, /document\.addEventListener\('cc:counts', \(\)=>\{ syncLegend\(\); syncFilterGroups\(\); \}\)/);
  // An event, not an import: render.js must not reach for the chrome it is
  // drawn under.
  assert.doesNotMatch(render, /from '\.\/panels\.js'/);
});

test('the surface layer is looked up as an object, not called as a function', () => {
  // layerByKey is Object.fromEntries(...), and calling it threw at init - which
  // took the whole legend sync down with it, silently, behind one console line.
  assert.match(panels, /layerByKey\['surface'\]/);
  assert.doesNotMatch(panels, /layerByKey\('surface'\)/);
});

test('the panel is capped so one long note cannot set its width', () => {
  assert.match(css, /\.legend\{[^}]*max-width:min\(17\.5rem,44vw\)/s,
    'the quality-ticks sentence was setting the panel width; it must wrap instead');
});

test('L presses the toggle, but never inside a field or with a modifier', () => {
  const m = panels.match(/document\.addEventListener\('keydown',e=>\{\n      if\(e\.key!=='l'&&e\.key!=='L'\) return;([\s\S]*?)\n    \}\);/);
  assert.ok(m, 'the L key handler was not found');
  assert.match(m[1], /e\.ctrlKey\|\|e\.metaKey\|\|e\.altKey\|\|e\.repeat\|\|legend\.hidden/);
  assert.match(m[1], /closest\('input, textarea, select, \[contenteditable\]'\)/);
  assert.match(m[1], /lgToggle\.click\(\)/, 'one path: the key clicks the button, so aria-expanded follows');
  assert.match(twig, /id="lgToggle"[\s\S]{0,200}aria-keyshortcuts="L"/);
});

test('the fold button keeps its three stripes; each key heading starts with the eye', () => {
  const toggle = twig.slice(twig.indexOf('id="lgToggle"'), twig.indexOf('</button>', twig.indexOf('id="lgToggle"')));
  assert.match(toggle, /<path d="M4 6h16M4 12h16M4 18h16"\/>/, 'the fold button is the three stripes (owner 2026-10-15)');
  assert.ok(!toggle.includes('ccEye'), 'the eye belongs to the key headings, not the fold button');
  for (const key of ['surfaceKey', 'routesKey']) {
    assert.match(twig, new RegExp(`<h4><button type="button" class="lg-eye" data-eye-for="${key}" aria-pressed="true"[^\\n]*\\{\\{ ccEye \\}\\}</button>\\{\\{ '[^']+'\\|trans \\}\\}</h4>`),
      `the ${key} eye sits before the heading text`);
  }
  assert.match(css, /\.legend \.lg-eye\[aria-pressed="false"\] > \.eye-open,\.legend \.lg-eye\[aria-pressed="true"\] > \.eye-shut\{display:none\}/);
});

test('a key eye switches its rows by clicking them, and follows them', () => {
  assert.match(panels, /rows\.filter\(r=>\(r\.getAttribute\('aria-pressed'\)!=='false'\)===on\)\.forEach\(r=>r\.click\(\)\)/,
    'any row on: all off; none on: all on - through the rows, so presets and the map hear one path');
  assert.match(panels, /watch\.observe\(r, \{ attributes: true, attributeFilter: \['aria-pressed'\] \}\)/);
});

test('the fold button shows its L shortcut: a keyboard and a keycap, hidden without a keyboard', () => {
  const btn = twig.slice(twig.indexOf('id="lgToggle"'), twig.indexOf('<div class="lg-body"'));
  assert.match(btn, /<span class="lg-key" aria-hidden="true"><svg[^>]*>[\s\S]*?<\/svg><kbd>L<\/kbd><\/span>/, 'the keycap sits inside the button, so it folds the key too');
  assert.match(btn, /aria-keyshortcuts="L"/);
  assert.match(css, /@media \(hover:none\)\{ \.legend \.lg-key\{display:none\} \}/, 'a phone has no L key to press');
});

test('open, the fold button sits top right and the first key shown takes the row beside it', () => {
  assert.match(css, /\.legend:not\(\.collapsed\) \.lg-toggle\{position:absolute;top:\.6rem;right:\.75rem;margin:0\}/);
  assert.match(css, /\.legend:not\(\.collapsed\) \.lg-body > :not\(\[hidden\]\):not\(:not\(\[hidden\]\) ~ \*\) > h4:first-of-type\{display:flex;align-items:center;min-height:1\.8rem;margin-top:0;padding-right:5\.4rem\}/);
});

test('a place drawer folds the key while open and gives it back on close (owner 2026-10-18)', () => {
  const panels = fs.readFileSync(path.join(ROOT, 'assets/map/panels.js'), 'utf8');
  assert.match(panels, /if\(open && !legend\.classList\.contains\('collapsed'\)\)\{ legend\.classList\.add\('collapsed'\); foldedByDrawer=true; sync\(\); \}/);
  assert.match(panels, /else if\(!open && foldedByDrawer\)\{ legend\.classList\.remove\('collapsed'\); foldedByDrawer=false; sync\(\); \}/);
  assert.match(panels, /lgToggle\.onclick=\(\)=>\{ legend\.classList\.toggle\('collapsed'\); foldedByDrawer=false; sync\(\); \};/, 'a rider\'s own fold is undone by the drawer');
});

/* Owner 2026-10-19: "legend toggle buttons are overlayed the right drawer". */
test('the folded key steps aside of an open drawer, as the top-right buttons do, and stays put on a phone', () => {
  const css = require('node:fs').readFileSync(require('node:path').join(__dirname, '..', '..', 'assets/styles/map.css'), 'utf8');
  assert.match(css, /\.map-wrap\.drawer-open \.legend\{transform:translateX\(calc\(-1 \* min\(380px, 90vw\)\)\)\}/);
  assert.match(css, /\.map-wrap\.drawer-folded \.legend\{transform:translateX\(calc\(-1 \* var\(--fold-nudge, 30px\)\)\)\}/);
  assert.match(css, /\.map-wrap\.drawer-open \.legend,\.map-wrap\.drawer-folded \.legend\{transform:none\}/, 'the phone sheet leaves the key where it is');
});
