// SPDX-License-Identifier: AGPL-3.0-only
//
// coverage-notice.js: whether the "not covered yet" banner shows
// (docs/specs/map-and-search.md §4.5b). Pure decision, no map, no DOM.
import test from 'node:test';
import assert from 'node:assert/strict';
import { noticeFor } from '../../assets/map/coverage-notice.js';

test('an explicit search hit in a non-onboarded country shows the banner, named', () => {
  const d = noticeFor({
    searchHit: { countryCode: 'pl', countryName: 'Poland' },
    onboardedAt: false,
    dismissedKey: null,
  });
  assert.deepEqual(d, { show: true, key: 'country:PL', countryName: 'Poland', countryCode: 'PL' });
});

test('an explicit search hit in an onboarded country shows nothing', () => {
  const d = noticeFor({
    searchHit: { countryCode: 'be', countryName: 'Belgium' },
    onboardedAt: true,
    dismissedKey: null,
  });
  assert.equal(d.show, false);
});

test('a search hit with no country code says nothing, whatever onboardedAt claims', () => {
  const d = noticeFor({ searchHit: { countryCode: null, countryName: null }, onboardedAt: false });
  assert.equal(d.show, false);
});

test('panning into a non-onboarded area shows the generic banner at zoom 7', () => {
  const d = noticeFor({ zoom: 7, centre: { lat: 52.5, lng: 13.4 }, onboardedAt: false, nearOnboarded: false });
  assert.deepEqual(d, { show: true, key: 'area', countryName: null, countryCode: null });
});

test('the same pan at zoom 6 shows nothing: too far out to be "at" anywhere', () => {
  const d = noticeFor({ zoom: 6, centre: { lat: 52.5, lng: 13.4 }, onboardedAt: false, nearOnboarded: false });
  assert.equal(d.show, false);
});

test('panning into an onboarded region shows nothing', () => {
  const d = noticeFor({ zoom: 10, centre: { lat: 50.5, lng: 4.5 }, onboardedAt: true, nearOnboarded: false });
  assert.equal(d.show, false);
});

test('coastal water within the 0.1 degree tolerance of an onboarded region counts as covered', () => {
  const d = noticeFor({ zoom: 12, centre: { lat: 51.3, lng: 3.2 }, onboardedAt: false, nearOnboarded: true });
  assert.equal(d.show, false);
});

test('dismissal holds until the centre (or hit) is a different key', () => {
  const shown = noticeFor({ zoom: 9, onboardedAt: false, nearOnboarded: false, dismissedKey: null });
  assert.equal(shown.show, true);
  const stillDismissed = noticeFor({ zoom: 9, onboardedAt: false, nearOnboarded: false, dismissedKey: 'area' });
  assert.equal(stillDismissed.show, false);
  // A different non-onboarded country, reached by an explicit hit, is a different key: it re-arms.
  const differentCountry = noticeFor({
    searchHit: { countryCode: 'de', countryName: 'Germany' },
    onboardedAt: false,
    dismissedKey: 'country:PL',
  });
  assert.deepEqual(differentCountry, { show: true, key: 'country:DE', countryName: 'Germany', countryCode: 'DE' });
  // The same country stays dismissed.
  const sameCountry = noticeFor({
    searchHit: { countryCode: 'pl', countryName: 'Poland' },
    onboardedAt: false,
    dismissedKey: 'country:PL',
  });
  assert.equal(sameCountry.show, false);
});

test('antimeridian centre: the decision does not depend on which side of the seam the number lands on', () => {
  const east = noticeFor({ zoom: 9, centre: { lat: -41.3, lng: 179.9 }, onboardedAt: false, nearOnboarded: false });
  const west = noticeFor({ zoom: 9, centre: { lat: -41.3, lng: -179.9 }, onboardedAt: false, nearOnboarded: false });
  assert.deepEqual(east, { show: true, key: 'area', countryName: null, countryCode: null });
  assert.deepEqual(west, { show: true, key: 'area', countryName: null, countryCode: null });
});
