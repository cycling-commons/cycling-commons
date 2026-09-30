// SPDX-License-Identifier: AGPL-3.0-only
//
// along-list.js: a long group in the "what is along" lists (route drawer and
// ride check, docs/specs/map-and-search.md §6.3, §9) shows its first two rows
// and folds the rest behind a real button that says "Show N more" and then
// "Show fewer". A group that would hide only one row shows every row. The
// folded rows are in the DOM from the start, so bindAlongList binds their
// click and hover with the rest.
import test from 'node:test';
import assert from 'node:assert/strict';
import { alongListHtml, bindAlongMore, ALONG_VISIBLE } from '../../assets/map/along-list.js';

const metaFor = letter => ({ B: { color: '#1E88E5', label: 'Water & food', glyph: 'W' }, D: { color: '#6D4C41', label: 'Repair', glyph: 'R' } }[letter] || null);
const labels = { commonsH: 'Along', coverageH: 'Open coverage', empty: 'Nothing', kmOff: '{a} along · {b} off', more: 'Show {n} more', fewer: 'Show fewer' };
const items = n => Array.from({ length: n }, (_, i) => ({ id: 100 + i, name: `Place ${i}`, alongKm: i + 1, distM: 10 * (i + 1), ll: [50, 5] }));
const html = (groups, coverage = [], L = labels) => alongListHtml({ groups, coverage }, { metaFor, labels: L });

test('a group shows two rows, folds the rest and keeps the full count in its header', () => {
  assert.equal(ALONG_VISIBLE, 2);
  const out = html([{ letter: 'B', truncated: false, items: items(47) }]);
  assert.match(out, /Water &amp; food · 47<button /, 'the toggle closes the header line');
  const rest = out.match(/<li class="cc-near-rest" id="(cc-near-rest-\d+)" hidden><ul class="cc-near-list">(.*?)<\/ul><\/li>/);
  assert.ok(rest, 'the folded rows sit in one hidden list item');
  const before = out.slice(0, out.indexOf('class="cc-near-rest"'));
  assert.deepEqual([...before.matchAll(/data-rc-g="B" data-rc-i="(\d+)"/g)].map(m => +m[1]), [0, 1]);
  assert.deepEqual([...rest[2].matchAll(/data-rc-g="B" data-rc-i="(\d+)"/g)].map(m => +m[1]), Array.from({ length: 45 }, (_, i) => i + 2),
    'folded rows keep their index into the group, so bindAlongList finds each place');
  assert.match(out, new RegExp(`<li class="cc-near-grp">.*<button type="button" class="cc-near-more" aria-expanded="false" aria-controls="${rest[1]}" data-more="Show 45 more" data-fewer="Show fewer">Show 45 more</button></li><li><button class="cc-near"`),
    'the toggle sits at the end of the group header, before the first row');
  assert.match(out, /<\/ul><\/li><\/ul>$/, 'the folded rows close the group');
});

test('a group of three shows all three: one row never hides behind a button', () => {
  const out = html([{ letter: 'B', truncated: false, items: items(3) }]);
  assert.equal([...out.matchAll(/data-rc-i="/g)].length, 3);
  assert.doesNotMatch(out, /cc-near-more|cc-near-rest|hidden/);
});

test('four rows fold two; every group and the coverage arm fold on their own, with ids unique on the page', () => {
  const out = html([{ letter: 'B', truncated: false, items: items(4) }, { letter: 'D', truncated: false, items: items(2) }],
    [{ letter: 'D', truncated: true, items: items(6) }]);
  const ids = [...out.matchAll(/aria-controls="([^"]+)"/g)].map(m => m[1]);
  assert.equal(ids.length, 2, 'the four-row group and the six-row coverage group fold, the two-row group does not');
  assert.match(out, />Show 2 more</);
  assert.match(out, />Show 4 more</);
  const again = html([{ letter: 'B', truncated: false, items: items(4) }]).match(/aria-controls="([^"]+)"/)[1];
  assert.equal(new Set([...ids, again]).size, 3);
  for(const id of ids) assert.equal([...out.matchAll(new RegExp(`id="${id}"`, 'g'))].length, 1);
});

test('the button labels are escaped and fall back to English when the bundle has none', () => {
  const out = html([{ letter: 'B', truncated: false, items: items(5) }], [], { ...labels, more: undefined, fewer: '<i>less</i>' });
  assert.match(out, /data-more="Show 3 more" data-fewer="&lt;i&gt;less&lt;\/i&gt;">Show 3 more</);
});

/* A root holding one fold, shaped like what the drawer body gives back. */
function fakeRoot(){
  const rest = { hidden: true };
  const attrs = { 'aria-expanded': 'false', 'aria-controls': 'cc-near-rest-9' };
  let scrolled = 0;
  const button = {
    dataset: { more: 'Show 45 more', fewer: 'Show fewer' },
    textContent: 'Show 45 more',
    getAttribute: k => attrs[k] ?? null,
    setAttribute: (k, v) => { attrs[k] = v; },
    scrollIntoView: () => { scrolled++; },
  };
  const root = {
    querySelectorAll: sel => (sel === '.cc-near-more[aria-controls]' ? [button] : []),
    querySelector: sel => (sel === '#cc-near-rest-9' ? rest : null),
  };
  return { root, rest, button, attrs, scrolls: () => scrolled };
}

test('the button unfolds the rows in place, says so in aria-expanded, and folds them again', () => {
  const { root, rest, button, attrs, scrolls } = fakeRoot();
  bindAlongMore(root);
  button.onclick();
  assert.equal(rest.hidden, false);
  assert.equal(attrs['aria-expanded'], 'true');
  assert.equal(button.textContent, 'Show fewer');
  assert.equal(scrolls(), 0, 'unfolding leaves the view where it is');
  button.onclick();
  assert.equal(rest.hidden, true);
  assert.equal(attrs['aria-expanded'], 'false');
  assert.equal(button.textContent, 'Show 45 more');
  assert.equal(scrolls(), 1, 'folding keeps the button, which holds focus, in view');
});

test('a button whose rows are missing is left alone', () => {
  const { root, button } = fakeRoot();
  root.querySelector = () => null;
  bindAlongMore(root);
  assert.equal(button.onclick, undefined);
});
