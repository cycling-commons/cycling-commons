// SPDX-License-Identifier: AGPL-3.0-only
/* The right-click "Add here" list (map-and-search.md, "Add here from the map"): each place type's add form at the
   spot the rider pointed at. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { addHereHtml } from '../../assets/map/add-here.js';

const cfg = { head: 'Add here', href: '/nl/improve', types: [{ type: 'road-surface', label: 'Wegdek' }, { type: 'water-food', label: 'Water & eten' }] };

test('each type links its add form at the spot', () => {
  const html = addHereHtml(cfg, 52.661234, 5.149123, 15.4);
  assert.match(html, /href="\/nl\/improve\?type=road-surface&amp;mode=add&amp;lat=52\.661234&amp;lng=5\.149123&amp;z=15"/);
  assert.match(html, />Water &amp; eten</);
  assert.match(html, /<div class="pop-co">Add here<\/div>/);
});

test('a zoomed-out right-click opens the form close enough to tap a road', () => {
  assert.match(addHereHtml(cfg, 52.66, 5.14, 8), /z=14"/);
});

test('a visitor gets no list', () => {
  assert.equal(addHereHtml(undefined, 52.66, 5.14, 12), '');
});
