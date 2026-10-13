// SPDX-License-Identifier: AGPL-3.0-only
//
// The race calendar (assets/data/races.js) behind the home header's colours.
// A race counts as "on" for its whole calendar day where the rider is, and
// the header takes race colours only then (assets/home/hero.js): between
// races it keeps its own look. Run in Amsterdam time, where reading a bare
// date as UTC midnight once kept every one-day race from ever being "on".
'use strict';

process.env.TZ = 'Europe/Amsterdam';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

global.window = {};
global.document = { querySelectorAll: () => [], readyState: 'complete', addEventListener() {} };
require(path.join(__dirname, '../../assets/data/races.js'));
const { pickRace } = global.window.CC;
const RealDate = Date;

function at(isoLocal) {
  global.Date = class extends RealDate {
    constructor(...a) { super(...(a.length ? a : [isoLocal])); }
  };
  try { return pickRace(); } finally { global.Date = RealDate; }
}

test('a one-day race is on from the first to the last minute of its day', () => {
  for (const time of ['2026-10-10T00:05:00', '2026-10-10T12:00:00', '2026-10-10T23:55:00']) {
    const pick = at(time);
    assert.equal(pick.status, 'on', time);
    assert.equal(pick.r.name, 'Il Lombardia', time);
  }
});

test('Gravel Worlds runs 10 and 11 October 2026; the classic wins the shared day', () => {
  assert.equal(at('2026-10-11T09:00:00').r.name, 'UCI Gravel Worlds');
  assert.equal(at('2026-10-11T09:00:00').status, 'on');
  assert.equal(at('2026-10-12T00:05:00').status, 'next');
});

test('between races the next one is named but not on, so the header keeps its own look', () => {
  const pick = at('2026-10-13T20:00:00');
  assert.equal(pick.status, 'next');
  assert.equal(pick.r.name, 'UCI Track Worlds');
});

test('with no race on, the coordinate is a famous climb, the same one all day', () => {
  const { placeFor, DEFAULT_PLACES } = global.window.CC;
  const morning = (() => { global.Date = class extends RealDate { constructor(...a) { super(...(a.length ? a : ['2026-10-13T00:05:00'])); } }; try { return placeFor(new Date()); } finally { global.Date = RealDate; } })();
  const night = (() => { global.Date = class extends RealDate { constructor(...a) { super(...(a.length ? a : ['2026-10-13T23:55:00'])); } }; try { return placeFor(new Date()); } finally { global.Date = RealDate; } })();
  assert.ok(DEFAULT_PLACES.includes(morning));
  assert.equal(morning, night);
  const next = (() => { global.Date = class extends RealDate { constructor(...a) { super(...(a.length ? a : ['2026-10-14T12:00:00'])); } }; try { return placeFor(new Date()); } finally { global.Date = RealDate; } })();
  assert.notEqual(next, morning);
});

test('on a race day, the coordinate is that race\'s finish', () => {
  global.Date = class extends RealDate { constructor(...a) { super(...(a.length ? a : ['2026-10-11T12:00:00'])); } };
  try {
    assert.equal(global.window.CC.placeFor(new Date()).label, 'FINISH · NICE');
  } finally { global.Date = RealDate; }
});
