// SPDX-License-Identifier: AGPL-3.0-only
//
// Felt safety, marked by hand (docs/specs/traffic-measurements.md §10): a
// signed-in rider picks a road piece and says safe or unsafe, for a part of
// the day, a day type and a quarter of the last year. The pure part lives in
// assets/lib/road-safety.js; the map mode is pinned by its source.
import test from 'node:test';
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { FELT, MAX_ROADS, MAX_MARKS, quarterOptions, markDefaults, pieceOf, markPayload, pickPiece, labelCounts, chainBetween, tilePoints } from '../../assets/lib/road-safety.js';

const read = p => readFileSync(new URL('../../' + p, import.meta.url), 'utf8');
const mode = read('assets/map/road-safety.js');
const mapJs = read('assets/map/map.js');
const tpl = read('templates/map/index.html.twig');
const controller = read('src/Controller/MapController.php');
const css = read('assets/styles/map.css');

test('a rider says safe or unsafe, nothing between', () => {
  assert.deepEqual([...FELT], ['safe', 'unsafe']);
});

test('the quarters offered are this one and the three before it', () => {
  assert.deepEqual(quarterOptions('2026-Q4'), ['2026-Q4', '2026-Q3', '2026-Q2', '2026-Q1']);
  assert.deepEqual(quarterOptions('2026-Q2'), ['2026-Q2', '2026-Q1', '2025-Q4', '2025-Q3'], 'across the year');
  assert.deepEqual(quarterOptions('nonsense'), []);
});

test('the defaults are now: the band and day type of the rider\'s clock, this quarter', () => {
  // Tuesday 2026-11-17 07:30 UTC, the rider one hour ahead: 08:30 local, morning rush.
  const at = Date.UTC(2026, 10, 17, 7, 30) / 1000;
  assert.deepEqual(markDefaults(at, 3600, new Set()), { band: 1, dayType: 'workday', quarter: '2026-Q4' });
  // Saturday evening, 20:00 local.
  const sat = Date.UTC(2026, 10, 21, 19, 0) / 1000;
  assert.deepEqual(markDefaults(sat, 3600, new Set()), { band: 4, dayType: 'weekend', quarter: '2026-Q4' });
});

test('a public holiday of the road\'s country counts as weekend', () => {
  const at = Date.UTC(2026, 11, 25, 11, 0) / 1000;   // Friday 25 December, noon local
  assert.equal(markDefaults(at, 3600, new Set()).dayType, 'workday');
  assert.equal(markDefaults(at, 3600, new Set(['2026-12-25'])).dayType, 'weekend');
});

test('a local date past New Year is the next quarter', () => {
  const at = Date.UTC(2026, 11, 31, 23, 30) / 1000;
  assert.equal(markDefaults(at, 3600, null).quarter, '2027-Q1');
});

test('a picked piece is its way, label, region and country', () => {
  assert.deepEqual(pieceOf({ id: 4521877, properties: { l: 'r', g: 12, h: 'residential' } }, 'roadpieces_nl'),
    { way: 4521877, label: 'r', region: 12, cc: 'nl' });
  assert.deepEqual(pieceOf({ id: 9, properties: { l: 'p' } }, 'roadpieces_be'), { way: 9, label: 'p', region: null, cc: 'be' });
  assert.equal(pieceOf({ id: 9, properties: { l: 'x' } }, 'roadpieces_be'), null, 'no label, no piece');
  assert.equal(pieceOf({ id: undefined, properties: { l: 'r' } }, 'roadpieces_be'), null, 'no way id, no piece');
  assert.equal(pieceOf({ id: 9, properties: { l: 'r' } }, 'surface_be').cc, null);
});

test('the mark sent is exactly the endpoint\'s shape and nothing more', () => {
  const piece = { way: 4521877, label: 'r', region: 12, cc: 'nl' };
  const mark = markPayload([piece], { bands: [3], dayTypes: ['workday'], quarters: ['2026-Q4'], felt: 'unsafe' });
  assert.deepEqual(mark, { v: 3, roads: [{ way: 4521877, label: 'r', region: 12 }], bands: [3], dayTypes: ['workday'], quarters: ['2026-Q4'], felt: 'unsafe' });
  assert.equal('cc' in mark.roads[0], false, 'the country stays in the browser');
  assert.equal(markPayload([{ ...piece, region: null }], { bands: [3], dayTypes: ['workday'], quarters: ['2026-Q4'], felt: 'safe' }).roads[0].region, null);
});

/* Owner 2026-10-19: "User should be able to CTRL + click multiple road pieces together". */
test('a plain click picks one road, a Ctrl+click adds a road or takes it away again', () => {
  const a = { way: 1, label: 'p', region: null, cc: 'nl' }, b = { way: 2, label: 'r', region: 3, cc: 'nl' }, c = { way: 3, label: 'r', region: 3, cc: 'nl' };
  let picked = pickPiece([], a, false);
  assert.deepEqual(picked.map(p => p.way), [1]);
  picked = pickPiece(picked, b, true);
  picked = pickPiece(picked, c, true);
  assert.deepEqual(picked.map(p => p.way), [1, 2, 3]);
  assert.deepEqual(pickPiece(picked, b, true).map(p => p.way), [1, 3], 'Ctrl+click on a picked road takes it away');
  assert.deepEqual(pickPiece(picked, c, false).map(p => p.way), [3], 'a plain click starts over');
  assert.deepEqual(labelCounts(picked), { p: 1, l: 0, r: 2 });
  const many = Array.from({ length: MAX_ROADS }, (_, i) => ({ way: 100 + i, label: 'r', region: null, cc: null }));
  assert.equal(pickPiece(many, a, true).length, MAX_ROADS, 'never more roads than one answer covers');
  assert.equal(MAX_ROADS, 50);
});

test('the answer for roads picked together carries every road once', () => {
  const when = { bands: [1], dayTypes: ['weekend'], quarters: ['2026-Q3'], felt: 'safe' };
  const mark = markPayload([{ way: 1, label: 'p', region: null, cc: 'nl' }, { way: 2, label: 'r', region: 3, cc: 'nl' }], when);
  assert.deepEqual(mark.roads, [{ way: 1, label: 'p', region: null }, { way: 2, label: 'r', region: 3 }]);
  assert.equal(markPayload([], when), null, 'no road picked');
});

test('an unfinished mark is not sent', () => {
  const piece = { way: 1, label: 'l', region: null, cc: null };
  const when = { bands: [0], dayTypes: ['weekend'], quarters: ['2026-Q1'] };
  assert.equal(markPayload([piece], { ...when, felt: null }), null, 'no feeling chosen');
  assert.equal(markPayload([piece], { ...when, felt: 'mixed' }), null);
  assert.equal(markPayload([piece], { ...when, bands: [5], felt: 'safe' }), null);
  assert.equal(markPayload([piece], { ...when, bands: ['2'], felt: 'safe' }), null);
  assert.equal(markPayload([piece], { ...when, dayTypes: ['holiday'], felt: 'safe' }), null);
  assert.equal(markPayload([piece], { ...when, bands: [], felt: 'safe' }), null, 'no part of the day ticked');
  assert.equal(markPayload([piece], { ...when, quarters: [], felt: 'safe' }), null, 'no quarter ticked');
  assert.equal(markPayload(null, { ...when, felt: 'safe' }), null);
});

test('the mode draws the road pieces from zoom 14 and says to zoom in below it', () => {
  assert.match(mode, /PIECE_ZOOM/);
  assert.match(mode, /minzoom: PIECE_ZOOM/);
  assert.match(mode, /roadSafetyZoom/);
  assert.match(mode, /eachSourceInView\(map, 'roadpieces', 'roadpieces', PIECE_ZOOM/);
});

test('map clicks belong to the mode while it is on: no drawer, no scope change, no stop', () => {
  // The picking claim makes openDrawer, click-to-scope, the coverage detail
  // and the planner stand aside, as for Scout's stretch end.
  assert.match(mode, /claimMapClicks\(\)/);
  assert.match(mode, /releaseMapClicks\(\)/);
  assert.match(mode, /map\.on\('click', onMapClick\)/);
  assert.match(mode, /map\.off\('click', onMapClick\)/);
});

test('Escape and the close button leave the mode', () => {
  assert.match(mode, /'Escape'/);
  assert.match(mode, /stopImmediatePropagation/);
  assert.match(mode, /roadSafetyX/);
  assert.match(mode, /function stop\(/);
});

test('the picked piece is highlighted by its way id', () => {
  assert.match(mode, /\['==', \['id'\], /);
});

test('the mark goes to the endpoint with its stateless token, and the answer is the waiting copy', () => {
  assert.match(mode, /'X-CC-Token': cfg\.token/);
  assert.match(mode, /roadSafetySent/);
  assert.match(mode, /429/);
  assert.match(mode, /roadSafetyLimited/);
});

test('the defaults come from the shared traffic helpers and the holiday files, not a copy', () => {
  assert.match(read('assets/lib/road-safety.js'), /import \{ timeKey \} from '\.\/traffic-summary\.js'/);
  assert.match(mode, /fetchHolidays/);
});

test('the map starts the mode, and a return from sign-in opens it', () => {
  assert.match(mapJs, /initRoadSafety\(\)/);
  assert.match(mode, /'road-safety'/);
});

test('the tools panel has the entry, a guest a sign-in link back to the map', () => {
  assert.match(tpl, /id="roadSafetyGo"/);
  assert.match(tpl, /data-road-safety-login href="\{\{ path\('login', \{_target_path: path\('map', \{'road-safety': 1\}\)\}\) \}\}"/);
  assert.match(tpl, /window\.CC_ROAD_SAFETY = /);
  assert.match(tpl, /csrf_token\('road-safety'\)/);
});

test('the card offers safe or unsafe as chips, the five parts of the day, two day types, and Send', () => {
  const card = tpl.slice(tpl.indexOf('id="roadSafety"'), tpl.indexOf('</section>', tpl.indexOf('id="roadSafety"')));
  assert.match(card, /class="chip" data-felt="safe"/);
  assert.match(card, /class="chip" data-felt="unsafe"/);
  assert.match(card, /\['night', 'morning', 'day', 'evening', 'late'\]/);
  // Every field ticks one or more (owner 2026-10-19: "make all fields checkboxes").
  assert.match(card, /id="roadSafetyBands"[\s\S]*type="checkbox" name="band"/);
  assert.match(card, /type="checkbox" name="dayType" value="workday"/);
  assert.match(card, /type="checkbox" name="dayType" value="weekend"/);
  assert.match(card, /id="roadSafetyQuarters"/);
  assert.doesNotMatch(card, /<select/);
  assert.match(card, /id="roadSafetySend" disabled/);
});

test('every word the mode writes is in the map strings', () => {
  for (const key of ['roadSafetyZoom', 'roadSafetyPick', 'roadSafetyNoTiles', 'roadSafetyLabelP', 'roadSafetyLabelL', 'roadSafetyLabelR',
    'roadSafetyQuarter', 'roadSafetyQuarterNow', 'roadSafetySending', 'roadSafetySent', 'roadSafetyLimited', 'roadSafetyFailed']) {
    assert.match(controller, new RegExp(`'${key}' => 'd_`), key);
  }
});

test('the card stays narrow, its buttons squarish, its chips round, and it fades softly with reduced motion', () => {
  const rule = sel => {
    const m = css.match(new RegExp(sel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\{([^}]*)\\}'));
    assert.ok(m, sel + ' not found');
    return m[1];
  };
  assert.match(rule('.cc-rs'), /width:min\(22rem/);
  assert.match(rule('.cc-rs-send'), /border-radius:4px/);
  assert.match(rule('.cc-rs-x'), /border-radius:4px/);
  assert.doesNotMatch(rule('.cc-rs .chip'), /border-radius/, 'chips keep the round pill of .chip');
  assert.match(css, /prefers-reduced-motion:reduce\)\{\.cc-rs\{animation:ccRsFade \.12s ease!important\}/);
  assert.match(css, /@keyframes ccRsFade\{from\{opacity:0\}to\{opacity:1\}\}/, 'a soft fade, no movement');
});

/* Owner 2026-10-19, option A: a road's card asks "How safe did it feel?" and opens the felt-safety card for that road
   (a road's settings stay what the road is; a feeling is one rider's, anonymous, never a field of the road). */
const drawerJs = read('assets/map/drawer.js');
test('a road card opens the felt-safety card for its own OSM way', () => {
  assert.match(drawerJs, /const feltWay = layer\.key === 'surface'/);
  assert.match(drawerJs, /data-rs-way="\$\{feltWay\}"/);
  assert.match(drawerJs, /grp\(felt, 'cc-d-grp-felt'\)/);
  // A curated road carries its way and its line into the card (map.js), or the button never shows on it.
  assert.match(read('assets/map/map.js'), /wayId:s\.wayId,/);
  assert.match(drawerJs, /feltPath\[Math\.floor\(feltPath\.length \/ 2\)\]/);
  // A guest gets the same sign-in link as the tools panel, back to the mode.
  assert.match(drawerJs, /\[data-road-safety-login\]/);
  assert.match(mode, /export async function markWay\(way, lngLat\)/);
  assert.match(mode, /closest\('\[data-rs-way\]'\)/);
  assert.match(mode, /loadPieces\(\[\[lngLat\.lng, lngLat\.lat\]\], entries\)/);
  assert.match(mode, /roadSafetyNotPiece/);
});

/* Owner 2026-10-19: "on the second ctrl+click it also takes all the roads in between those 2 clicks of the same
   type". The road pieces are OSM ways; two ways touch where they share a vertex. The chain is the shortest run of
   touching ways of one label from the first road to the second. */
const P = (id, label, coords) => ({ id, label, region: 7, cc: 'nl', coords });
test('chainBetween takes the roads of one type between two picked roads', () => {
  // A cycle path in four ways, 1-2-3-4, with a shared road (5) as a short cut from 1 to 4.
  const pieces = [
    P(1, 'p', [[5.00, 52.00], [5.01, 52.00]]),
    P(2, 'p', [[5.01, 52.00], [5.02, 52.00]]),
    P(3, 'p', [[5.02, 52.00], [5.03, 52.00]]),
    P(4, 'p', [[5.03, 52.00], [5.04, 52.00]]),
    P(5, 'r', [[5.01, 52.00], [5.03, 52.00]]),
    P(6, 'p', [[5.02, 52.00], [5.02, 52.01]]),   // a side path off 2/3, not on the way
  ];
  assert.deepEqual(chainBetween(pieces, 1, 4, 'p').sort(), [1, 2, 3, 4], 'the cycle path, never the shared road');
  assert.deepEqual(chainBetween(pieces, 2, 3, 'p').sort(), [2, 3]);
  assert.equal(chainBetween(pieces, 1, 5, 'p'), null, 'the second road is of another type');
  assert.equal(chainBetween(pieces, 1, 99, 'p'), null, 'not in the tiles read');
  // A way clipped into two parts by a tile edge is one road.
  const clipped = [P(1, 'p', [[5.00, 52.00], [5.005, 52.00]]), P(1, 'p', [[5.0051, 52.00], [5.01, 52.00]]), P(2, 'p', [[5.01, 52.00], [5.02, 52.00]])];
  assert.deepEqual(chainBetween(clipped, 1, 2, 'p').sort(), [1, 2]);
  // Not connected: no chain.
  assert.equal(chainBetween([P(1, 'p', [[5, 52], [5.01, 52]]), P(2, 'p', [[5.5, 52], [5.51, 52]])], 1, 2, 'p'), null);
});

test('chainBetween never takes more roads than one answer covers', () => {
  const pieces = Array.from({ length: 80 }, (_, i) => P(i + 1, 'r', [[5 + i * 0.001, 52], [5 + (i + 1) * 0.001, 52]]));
  assert.equal(chainBetween(pieces, 1, 80, 'r'), null);
  assert.equal(chainBetween(pieces, 1, MAX_ROADS, 'r').length, MAX_ROADS);
});

test('tilePoints covers the box of two clicks with a tile to spare, and gives up past a few kilometres', () => {
  const pts = tilePoints([5.05, 52.64], [5.06, 52.645]);
  assert.ok(pts.length >= 9 && pts.length <= 49, `${pts.length} tiles`);
  assert.equal(tilePoints([5.0, 52.6], [5.5, 52.9]), null, 'too far apart to fill');
});

test('the second Ctrl+click fills the roads of the same type in between', () => {
  assert.match(mode, /if \(add && added && from && from\.label === next\.label && from\.way !== next\.way\) fillBetween\(from, last\);/);
  assert.match(mode, /chainBetween\(pieces, a\.way, b\.way, b\.label\)/);
  assert.match(mode, /if \(req !== fillReq \|\| !on\) return;/, 'an older fill applies after a newer click');
});

/* Owner 2026-10-19: "make all fields checkboxes. I ride there all of the time and it is always the same". */
test('one answer may tick several parts of the day, day types and quarters, at most 200 marks', () => {
  const pieces = Array.from({ length: 20 }, (_, i) => ({ way: i + 1, label: 'r', region: null, cc: 'nl' }));
  const all = { bands: [0, 1, 2, 3, 4], dayTypes: ['workday', 'weekend'], quarters: ['2026-Q4'], felt: 'safe' };
  assert.equal(MAX_MARKS, 200);
  assert.ok(markPayload(pieces, all), '20 roads x 5 x 2 x 1 = 200');
  assert.equal(markPayload(pieces, { ...all, quarters: ['2026-Q4', '2026-Q3'] }), null, '400 marks is past one answer');
  assert.equal(markPayload(pieces.slice(0, 1), { ...all, bands: [1, 1] }), null, 'a part of the day ticked twice');
  assert.match(mode, /roadSafetyTooMany/);
  assert.match(mode, /querySelectorAll\('input:checked'\)/);
});
