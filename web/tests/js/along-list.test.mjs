// SPDX-License-Identifier: AGPL-3.0-only
//
// along-list.js: the "what is along" lists the ride check summary and the
// route drawer share (docs/specs/map-and-search.md §6.3, §9). One renderer:
// group headers with the layer glyph and count, one row per place with km
// along and metres off, the open-coverage arm under its own heading and note.
import test from 'node:test';
import assert from 'node:assert/strict';
import { alongListHtml, routeAlongSlot, listWaitHtml } from '../../assets/map/along-list.js';

const metaFor = letter => ({ B: { color: '#1E88E5', label: 'Water', glyph: 'W' }, D: { color: '#6D4C41', label: 'Repair', glyph: 'R' } }[letter] || null);
const labels = {
  commonsH: 'In the commons along the route', coverageH: 'Open coverage along the route',
  empty: 'Nothing within 250 m yet.', capped: '(capped)', kmOff: '{a} along · {b} off', covNote: 'From open data.',
};

const d = {
  groups: [{ letter: 'B', truncated: false, items: [
    { id: 43008, name: 'Source Barisart', alongKm: 3.4, distM: 34, ll: [50.5, 5.8] },
    { id: 44819, name: '', alongKm: 24.7, distM: 246, ll: [50.4, 5.9] },
  ] }],
  coverage: [
    { letter: 'D', truncated: true, items: [{ id: 1, name: 'SOS', alongKm: 19.4, distM: 15, ll: [50.4, 5.8], ref: 'node/1' }] },
    { letter: 'B', truncated: false, items: [] },
  ],
};

test('commons groups: header with glyph and count, rows in order with km along and metres off', () => {
  const html = alongListHtml(d, { metaFor, labels });
  assert.match(html, /<h4 class="cc-near-h">In the commons along the route<\/h4>/);
  assert.match(html, /<li class="cc-near-grp"><span class="cc-near-k" style="background:#1E88E5;color:#fff">W<\/span>Water · 2<\/li>/);
  const rows = [...html.matchAll(/data-rc-g="B" data-rc-i="(\d)"/g)].map(m => m[1]);
  assert.deepEqual(rows, ['0', '1']);
  assert.match(html, /Source Barisart<\/span><em>3\.4 km along · 34 m off<\/em>/);
  assert.match(html, /data-rc-i="1"><span class="cc-near-nm">Water<\/span>/, 'an unnamed place reads as its layer');
});

test('open coverage: own heading, capped groups say so, empty groups are dropped, note at the end', () => {
  const html = alongListHtml(d, { metaFor, labels });
  assert.match(html, /<h4 class="cc-near-h">Open coverage along the route<\/h4>/);
  assert.match(html, /Repair · 1 \(capped\)/);
  assert.equal([...html.matchAll(/data-rc-c="/g)].length, 1);
  assert.match(html, /<div class="cc-near-note">From open data\.<\/div>$/);
});

test('no commons places: the empty line; no coverage: no coverage section', () => {
  const html = alongListHtml({ groups: [], coverage: [] }, { metaFor, labels });
  assert.match(html, /<div class="cc-near-empty">Nothing within 250 m yet\.<\/div>/);
  assert.doesNotMatch(html, /Open coverage/);
});

test('names are escaped and an unknown letter falls back to a neutral header', () => {
  const html = alongListHtml({ groups: [{ letter: 'Z', truncated: false, items: [{ id: 1, name: '<b>x</b>', alongKm: 1, distM: 2 }] }], coverage: [] }, { metaFor, labels });
  assert.doesNotMatch(html, /<b>x<\/b>/);
  assert.match(html, /background:#6b6f5e/);
});

test('the route drawer names the corridor under the first heading; the ride summary names it in its own meta line', () => {
  assert.match(alongListHtml(d, { metaFor, labels: { ...labels, within: 'Within 250 m of the route' } }),
    /along the route<\/h4><div class="cc-near-note">Within 250 m of the route<\/div><ul/);
  assert.doesNotMatch(alongListHtml(d, { metaFor, labels }), /along the route<\/h4><div class="cc-near-note">/);
});

test('the route drawer slot shows that it is looking while the list is on its way', () => {
  const slot = routeAlongSlot({ id: 24 }, 'Looking along the route…');
  assert.match(slot, /id="cc-d-along-slot" data-route="24" aria-busy="true">/);
  assert.doesNotMatch(slot, /\shidden[\s>]/);
  assert.match(slot, /<span class="cc-d-spin" aria-hidden="true"><\/span><span role="status">Looking along the route…<\/span>/);
  assert.equal(routeAlongSlot({}, 'Looking along the route…'), '');
});

test('the waiting line is the drawer spinner with an escaped label', () => {
  assert.equal(listWaitHtml('<b>Wait</b>'),
    '<div class="cc-d-photo-wait cc-d-list-wait"><span class="cc-d-spin" aria-hidden="true"></span><span role="status">&lt;b&gt;Wait&lt;/b&gt;</span></div>');
});
