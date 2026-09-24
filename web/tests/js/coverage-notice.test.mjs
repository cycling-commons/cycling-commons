// SPDX-License-Identifier: AGPL-3.0-only
//
// coverage-notice.js: whether the "not covered yet" banner shows
// (docs/specs/map-and-search.md §4.5b). Pure decision + state machine, no
// map, no DOM; plus the coastal-tolerance geometry against
// /regions/outlines.json's Polygon/MultiPolygon features.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  initNoticeState, evaluateNotice, dismissNotice,
  distanceToOutlinesDeg, isNearOutlines, PAN_MIN_ZOOM,
} from '../../assets/map/coverage-notice.js';

test('an explicit search hit in a non-onboarded country shows the banner, named', () => {
  const { decision, state } = evaluateNotice(initNoticeState(), {
    searchHit: { countryCode: 'pl', countryName: 'Poland' },
    onboardedAt: false,
  });
  assert.deepEqual(decision, { show: true, countryName: 'Poland', countryCode: 'PL' });
  assert.deepEqual(state, { country: { code: 'PL', name: 'Poland' }, dismissed: false });
});

test('an explicit search hit in an onboarded country shows nothing and clears the excursion', () => {
  const dirty = { country: { code: 'PL', name: 'Poland' }, dismissed: true };
  const { decision, state } = evaluateNotice(dirty, {
    searchHit: { countryCode: 'be', countryName: 'Belgium' },
    onboardedAt: true,
  });
  assert.equal(decision.show, false);
  assert.deepEqual(state, initNoticeState());
});

test('a search hit with no country code says nothing and changes nothing', () => {
  const state0 = { country: { code: 'PL', name: 'Poland' }, dismissed: true };
  const { decision, state } = evaluateNotice(state0, { searchHit: { countryCode: null, countryName: null }, onboardedAt: false });
  assert.equal(decision.show, false);
  assert.deepEqual(state, state0);
});

test('panning into a non-onboarded area with no prior excursion shows the generic banner at zoom 7', () => {
  const { decision, state } = evaluateNotice(initNoticeState(), { zoom: 7, onboardedAt: false, nearOnboarded: false });
  assert.deepEqual(decision, { show: true, countryName: null, countryCode: null });
  assert.deepEqual(state, initNoticeState());   // a pan never starts naming a place
});

test('the same pan at zoom 6 shows nothing, and does not touch the excursion', () => {
  const state0 = { country: { code: 'PL', name: 'Poland' }, dismissed: false };
  const { decision, state } = evaluateNotice(state0, { zoom: 6, onboardedAt: false, nearOnboarded: false });
  assert.equal(decision.show, false);
  assert.deepEqual(state, state0);   // too zoomed out is not "covered": the excursion survives
});

test('panning into an onboarded region shows nothing and clears the excursion', () => {
  const state0 = { country: { code: 'PL', name: 'Poland' }, dismissed: true };
  const { decision, state } = evaluateNotice(state0, { zoom: 10, onboardedAt: true, nearOnboarded: false });
  assert.equal(decision.show, false);
  assert.deepEqual(state, initNoticeState());
});

test('coastal water within tolerance shows nothing, and does not touch the excursion', () => {
  const state0 = { country: null, dismissed: false };
  const { decision, state } = evaluateNotice(state0, { zoom: 12, onboardedAt: false, nearOnboarded: true });
  assert.equal(decision.show, false);
  assert.deepEqual(state, state0);
});

// --- the fix-round-1 scenarios: dismissal and identity across pans --------

test('search, dismiss, then pan: stays hidden however long the pan stays outside coverage', () => {
  let r = evaluateNotice(initNoticeState(), { searchHit: { countryCode: 'pl', countryName: 'Poland' }, onboardedAt: false });
  assert.equal(r.decision.show, true);
  let state = dismissNotice(r.state);
  assert.equal(state.dismissed, true);

  r = evaluateNotice(state, { zoom: 9, onboardedAt: false, nearOnboarded: false });
  assert.equal(r.decision.show, false);
  state = r.state;

  // Further panning, still outside coverage: still hidden - the old bug
  // handed every pan the bare key 'area', which did not match the dismissed
  // 'country:PL' key and re-showed on the very next drag.
  r = evaluateNotice(state, { zoom: 11, onboardedAt: false, nearOnboarded: false });
  assert.equal(r.decision.show, false);
  assert.equal(r.state.dismissed, true);
});

test('search, dismiss, pan into onboarded territory, pan out again: shows again (generic)', () => {
  let r = evaluateNotice(initNoticeState(), { searchHit: { countryCode: 'pl', countryName: 'Poland' }, onboardedAt: false });
  let state = dismissNotice(r.state);

  r = evaluateNotice(state, { zoom: 9, onboardedAt: true, nearOnboarded: false });   // entered an onboarded region
  assert.equal(r.decision.show, false);
  assert.deepEqual(r.state, initNoticeState());   // the excursion is over, dismissal included

  r = evaluateNotice(r.state, { zoom: 9, onboardedAt: false, nearOnboarded: false });   // left it again
  assert.deepEqual(r.decision, { show: true, countryName: null, countryCode: null });   // a fresh excursion: generic, not "Poland"
});

test('a search names a country; a later pan (no new search) keeps naming it', () => {
  let r = evaluateNotice(initNoticeState(), { searchHit: { countryCode: 'pl', countryName: 'Poland' }, onboardedAt: false });
  assert.equal(r.decision.countryName, 'Poland');

  r = evaluateNotice(r.state, { zoom: 9, onboardedAt: false, nearOnboarded: false });
  assert.deepEqual(r.decision, { show: true, countryName: 'Poland', countryCode: 'PL' });
});

test('a new explicit selection of a different country re-arms a dismissed banner', () => {
  let r = evaluateNotice(initNoticeState(), { searchHit: { countryCode: 'pl', countryName: 'Poland' }, onboardedAt: false });
  let state = dismissNotice(r.state);

  r = evaluateNotice(state, { searchHit: { countryCode: 'de', countryName: 'Germany' }, onboardedAt: false });
  assert.deepEqual(r.decision, { show: true, countryName: 'Germany', countryCode: 'DE' });
  assert.equal(r.state.dismissed, false);
});

test('re-selecting the SAME country after dismissal also shows again: a fresh pick is deliberate', () => {
  let r = evaluateNotice(initNoticeState(), { searchHit: { countryCode: 'pl', countryName: 'Poland' }, onboardedAt: false });
  let state = dismissNotice(r.state);

  r = evaluateNotice(state, { searchHit: { countryCode: 'pl', countryName: 'Poland' }, onboardedAt: false });
  assert.deepEqual(r.decision, { show: true, countryName: 'Poland', countryCode: 'PL' });
});

// --- coastal-tolerance geometry, against outline features -----------------
// (evaluateNotice itself takes no centre - the antimeridian question is
// entirely the geometry's, tested below against distanceToOutlinesDeg.)

const SQUARE_A = { type: 'Feature', properties: { cc: 'BE' }, geometry: {
  type: 'Polygon', coordinates: [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]],
} };
// A second polygon 0.3 degrees east of the first: the gap between them is a
// non-onboarded strip 0.3 degrees wide.
const SQUARE_B = { type: 'Feature', properties: { cc: 'NL' }, geometry: {
  type: 'Polygon', coordinates: [[[1.3, 0], [2.3, 0], [2.3, 1], [1.3, 1], [1.3, 0]]],
} };

test('a centre inside an outline is distance 0', () => {
  assert.equal(distanceToOutlinesDeg(0.5, 0.5, [SQUARE_A]), 0);
  assert.equal(isNearOutlines(0.5, 0.5, [SQUARE_A]), true);
});

test('0.05 degrees off a coast is near', () => {
  assert.equal(distanceToOutlinesDeg(-0.05, 0.5, [SQUARE_A]), 0.05);
  assert.equal(isNearOutlines(-0.05, 0.5, [SQUARE_A]), true);
});

test('0.2 degrees off a coast is not near', () => {
  assert.equal(distanceToOutlinesDeg(-0.2, 0.5, [SQUARE_A]), 0.2);
  assert.equal(isNearOutlines(-0.2, 0.5, [SQUARE_A]), false);
});

test('a 0.3-degree-wide non-onboarded strip between two onboarded polygons, 0.15 degrees from each, is NOT near', () => {
  const midpoint = 1 + 0.15;   // 1.15: 0.15 from square A's east edge (x=1), 0.15 from square B's west edge (x=1.3)
  const d = distanceToOutlinesDeg(midpoint, 0.5, [SQUARE_A, SQUARE_B]);
  assert.ok(Math.abs(d - 0.15) < 1e-9, `expected ~0.15, got ${d}`);
  assert.equal(isNearOutlines(midpoint, 0.5, [SQUARE_A, SQUARE_B]), false);
});

test('the antimeridian: a centre wrapped the long way round still measures against the outline', () => {
  // A sliver just west of the seam, and a centre just east of it, expressed
  // the negative way (as MapLibre would hand back a centre past 180°).
  const sliver = { type: 'Feature', properties: { cc: 'NZ' }, geometry: {
    type: 'Polygon', coordinates: [[[179.85, 0], [179.99, 0], [179.99, 1], [179.85, 1], [179.85, 0]]],
  } };
  // -179.99 is 180.01 the long way round: 0.02 degrees from the sliver's 179.99 edge.
  assert.ok(Math.abs(distanceToOutlinesDeg(-179.99, 0.5, [sliver]) - 0.02) < 1e-9);
  assert.equal(isNearOutlines(-179.99, 0.5, [sliver]), true);
  // Without crossing the seam the raw difference is enormous - the fixture
  // is only meaningful because the function tries lng +/- 360 itself.
  assert.ok(Math.abs(-179.99 - 179.99) > 1);
});

test('an empty or missing feature list is simply never near', () => {
  assert.equal(isNearOutlines(0.5, 0.5, []), false);
  assert.equal(isNearOutlines(0.5, 0.5, undefined), false);
});

test('PAN_MIN_ZOOM is exported for callers that reset state at the same threshold', () => {
  assert.equal(typeof PAN_MIN_ZOOM, 'number');
});
