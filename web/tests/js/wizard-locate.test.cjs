// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// Step 1 of the contribute wizard: the two ways a rider moves a pin, and the
// one thing both must end in. Every change test downstream reads the PIN, not
// the camera - `nothingChanged()` compares WZ.loc against the item's own
// position, and the server compares improve[lat]/[lng] against the row's
// geometry - so a gesture that moves the map without moving the pin ends at
// "Nothing has changed yet" on step 4 with Submit greyed out. Two separate
// defects had exactly that shape (owner 2026-09-10,
// /improve?item=46160&type=bike-services):
//
// 1. picking a geocoder result only flew the camera;
// 2. a drag released off the map never ended, so dragend never fired.
//
// improve.js needs a live MapLibre map and has no unit harness, so these are
// structural pins in the house style of wizard-addpoint.test.cjs. Both defects
// were reproduced and both fixes verified in a real headless Chrome first.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const js = fs.readFileSync(path.join(ROOT, 'assets', 'contribute', 'improve.js'), 'utf8');
const twig = fs.readFileSync(path.join(ROOT, 'templates', 'contribute', 'improve.html.twig'), 'utf8');

/** The body of `row.addEventListener('click', ...)` inside renderResults(). */
function resultClickBody() {
  const at = js.indexOf('function renderResults');
  assert.ok(at > -1, 'renderResults() not found');
  const scope = js.slice(at, js.indexOf('function goCoord', at));
  const m = scope.match(/row\.addEventListener\('click', function \(\) \{([\s\S]*?)\n {8}\}\);/);
  assert.ok(m, 'the search result click handler not found');
  return m[1];
}

test('picking a search result places the pin, not only the camera', () => {
  const body = resultClickBody();
  assert.match(body, /if \(placeAt\) placeAt\(\[lng, lat\]\);/,
    'a picked address must move the pin, or the wizard reports "nothing changed"');
  assert.match(body, /wmap\.flyTo\(\{ center: \[lng, lat\]/,
    'the camera still follows the pick');
});

test('a climb passes through with the camera only', () => {
  // placeAt stays null on the climb branch: the line is the item, and its
  // foot/summit belong to the three-point editor.
  assert.match(js, /var placeAt = null;/,
    'placeAt must default to null so the climb branch cannot be pin-placed by a search');
  assert.match(resultClickBody(), /if \(placeAt\)/,
    'the guard is what keeps a climb search from dropping a stray pin');
});

test('a pasted coordinate and a picked address take the same route', () => {
  const goCoord = js.match(/function goCoord\(pt\) \{([\s\S]*?)\n {4}\}/);
  assert.ok(goCoord, 'goCoord() not found');
  assert.match(goCoord[1], /if \(placeAt\) placeAt\(\[pt\.lng, pt\.lat\]\);/,
    'goCoord is the reference behaviour the result click now matches');
});

test('the wizard gates Next on the pin having moved', () => {
  const nothing = js.match(/function nothingChanged\(\) \{([\s\S]*?)\n {2}\}/);
  assert.ok(nothing, 'nothingChanged() not found');
  assert.match(nothing[1], /if \(pinMoved\(\) \|\| geomChanged\(\)\) return false;/,
    'the pin is what marks a location change; improve[place] is a label the server never diffs');
});

/* The Back/Next bar is `position:sticky; bottom:0` over a map taller than the
   viewport, so a pin dragged downwards is released ON that bar. MapLibre's
   Marker ends its drag on the MAP's own mouseup, fired only from a listener on
   the canvas container, so the map never saw that release: dragend never
   fired, syncLoc() never ran, and step 4 said "Nothing has changed yet" with
   Submit greyed out while the pin sat visibly somewhere new. Reproduced in a
   real headless Chrome on /improve?item=46160 (owner 2026-09-10) - the release
   landed on `target=navrow`, and the marker kept the pointer-events:none its
   own drag handler had set, dead to a second attempt. */
test('a release off the map still ends the drag', () => {
  assert.match(js, /function endDragOffMap\(m\) \{/, 'the safety net must exist');
  assert.match(js, /endDragOffMap\(wmap\);/, 'and must be armed on the wizard map');
});

test('the release is forwarded into the canvas container, not reimplemented', () => {
  const fn = js.match(/function endDragOffMap\(m\) \{([\s\S]*?)\n {2}\}/);
  assert.ok(fn, 'endDragOffMap() not found');
  assert.match(fn[1], /m\.getCanvasContainer\(\)/,
    'forward into the element MapLibre listens on, so its own _onUp runs');
  assert.match(fn[1], /new MouseEvent\('mouseup'/);
  assert.match(fn[1], /new TouchEvent\('touchend'/, 'a finger drag ends off-map too');
  assert.doesNotMatch(fn[1], /syncLoc|setLngLat/,
    'never re-do the library\'s work: dragend is the one path the wizard listens on');
});

test('only a gesture that started on the map is forwarded', () => {
  const fn = js.match(/function endDragOffMap\(m\) \{([\s\S]*?)\n {2}\}/);
  assert.match(fn[1], /if \(!fromMap\) \{ return; \}/,
    'an ordinary click elsewhere on the page must not reach the map');
  assert.match(fn[1], /if \(box\.contains\(e\.target\)\) \{ return; \}/,
    'a release inside the map is already the map\'s own; forwarding it would double-fire');
});

test('the sticky nav bar that causes it is still sticky', () => {
  // If this ever stops being sticky the net is still right (a release outside
  // the window has the same shape), but the comment above would be stale.
  assert.match(twig, /\.navrow\{position:sticky;bottom:0/,
    'improve.html.twig .navrow');
});

/* The threshold that swallowed the real report. A rider zoomed in and nudged
   the pin "a couple of mm"; the wizard recorded the point and put it in its own
   readout, then greyed out Submit and said "Nothing has changed yet". Measured
   in a real headless Chrome on /improve?item=46160: at z19 a 4 px drag travels
   0.36 m, well under the old 1e-5 degrees (1.1 m). At z14 the same 4 px travels
   11.6 m, which is why nobody hit it at the default zoom (owner 2026-09-10). */
test('a nudge counts as a move', () => {
  assert.match(js, /var MOVED_EPS = 1e-7;/,
    'about a centimetre: above unproject float noise, below any drag a hand can mean');
  const fn = js.match(/function pinMoved\(\) \{([\s\S]*?)\n {2}\}/);
  assert.ok(fn, 'pinMoved() not found');
  assert.match(fn[1], /MOVED_EPS/);
  assert.doesNotMatch(fn[1], /1e-5/, 'the old metre-wide threshold must not survive anywhere');
});

test('the browser and the server agree on what a move is', () => {
  // Two thresholds that drift apart give a rider an enabled Submit and a 422.
  const php = fs.readFileSync(path.join(ROOT, 'src', 'Contribution', 'CatalogContributionService.php'), 'utf8');
  assert.match(php, /private const float MOVED_EPS = 1e-7;/);
  // And the recorded point must be able to hold a move that small: the approved
  // string IS the geometry ModerationService::applyEdit writes back.
  assert.match(php, /private const int FORMAT_DECIMALS = 7;/);
});
