// SPDX-License-Identifier: AGPL-3.0-only
//
// osm-tags.js: the scenic-view facts OSM holds and the drawer now shows
// (docs/specs/coverage-provider.md §5). Both helpers exist because OSM values
// are free text: `ele` is usually "484" but sometimes "484 m" or "1200 ft",
// and `direction` is either a bearing or a compass point. A wrong guess would
// print a confident number that is not what the mapper wrote, so anything not
// plainly metres is handed back untouched instead.
import test from 'node:test';
import assert from 'node:assert/strict';
import { osmMetres, viewDirection, osmRefUrl, OSM_REF, bikesOnBoard, BIKES_ON_BOARD_LABEL, bikeAccess, bikeSourceText,
  osmDuration, durationText, ferryFacts, FERRY_FACT_LABEL } from '../../assets/map/osm-tags.js';

test('metres are read from the shapes OSM actually writes', () => {
  assert.equal(osmMetres('484'), 484);        // Costo Liso, node 12969271187
  assert.equal(osmMetres(' 484 m'), 484);
  assert.equal(osmMetres('1084.5'), 1084.5);
  assert.equal(osmMetres('-3'), -3);          // below sea level is a real elevation
});

test('a value that is not plainly metres is refused, never guessed', () => {
  assert.equal(osmMetres('1200 ft'), null);
  assert.equal(osmMetres('about 500'), null);
  assert.equal(osmMetres(''), null);
});

test('a bearing becomes a compass point and keeps its degrees', () => {
  assert.equal(viewDirection('225'), 'SW · 225°');
  assert.equal(viewDirection('0'), 'N · 0°');
  assert.equal(viewDirection('360'), 'N · 0°');
  assert.equal(viewDirection('370'), 'N · 10°');   // wraps, never "370°"
  assert.equal(viewDirection('-90'), 'W · 270°');
});

test('a compass point stays itself, and prose stays prose', () => {
  assert.equal(viewDirection('sw'), 'SW');
  assert.equal(viewDirection('N'), 'N');
  assert.equal(viewDirection('towards the lake'), 'towards the lake');
});

// osmRefUrl: the id behind a shareable coverage POI. Thousands of scenic views
// are named "Viewpoint", so ?feature=<name> was one link for all of them and
// the OSM source link sent a rider to a coordinate query. Both now use the ref,
// which means the guard has two jobs at once: it decides what goes in an href,
// and it decides what /map/coverage/poi/{node|way}/{id} will accept. Letting a
// third shape through would mint share links that resolve to nothing.
test('a served ref becomes the exact OSM page', () => {
  assert.equal(osmRefUrl('node/462149319'), 'https://www.openstreetmap.org/node/462149319');
  assert.equal(osmRefUrl('way/12345'), 'https://www.openstreetmap.org/way/12345');
});

test('anything the poi endpoint would refuse is refused here too', () => {
  assert.equal(osmRefUrl('relation/7'), null);   // the route requires node|way
  assert.equal(osmRefUrl('node/'), null);
  assert.equal(osmRefUrl('node/12a'), null);
  assert.equal(osmRefUrl('pivot/abc'), null);    // Tourisme Wallonie rows are not OSM
  assert.equal(osmRefUrl(''), null);
  assert.equal(osmRefUrl(null), null);
  assert.equal(osmRefUrl(undefined), null);
});

test('no ref can smuggle a scheme or a host into the href', () => {
  assert.equal(osmRefUrl('node/1 javascript:alert(1)'), null);
  assert.equal(osmRefUrl('https://evil.example/node/1'), null);
  assert.equal(osmRefUrl('node/1/../../evil'), null);
  assert.equal(osmRefUrl('node/1\nnode/2'), null);   // anchored, so no second line
});

test('OSM_REF is not sticky or global, so repeated tests never alternate', () => {
  assert.equal(OSM_REF.flags, '');
  assert.equal(OSM_REF.test('node/1'), true);
  assert.equal(OSM_REF.test('node/1'), true);
});

// bikesOnBoard: letter F's Bikes on board row (docs/specs/coverage-provider.md §5).
// OSM writes `bicycle` for whether a bike may come aboard and `bicycle:fee` for
// whether it costs extra. The drawer shows a row only for a value it can say
// in plain words; anything else is no row, never a guess.
test('bike access on board reads the values OSM uses', () => {
  assert.equal(bikesOnBoard({bicycle:'yes'}), 'allowed');   // way/1078891286 Enkhuizen - Medemblik
  assert.equal(bikesOnBoard({bicycle:'designated'}), 'allowed');
  assert.equal(bikesOnBoard({bicycle:'permissive'}), 'allowed');
  assert.equal(bikesOnBoard({bicycle:' Yes '}), 'allowed');
  assert.equal(bikesOnBoard({bicycle:'no'}), 'no');
  assert.equal(bikesOnBoard({bicycle:'dismount'}), 'dismount');
});

test('a bike fee is added only where a bike may come aboard', () => {
  assert.equal(bikesOnBoard({bicycle:'yes', 'bicycle:fee':'yes'}), 'allowed_fee');
  assert.equal(bikesOnBoard({bicycle:'dismount', 'bicycle:fee':'yes'}), 'dismount_fee');
  assert.equal(bikesOnBoard({bicycle:'no', 'bicycle:fee':'yes'}), 'no');
  assert.equal(bikesOnBoard({bicycle:'yes', 'bicycle:fee':'no'}), 'allowed');
});

test('an unknown or absent value gives no row', () => {
  assert.equal(bikesOnBoard({}), null);
  assert.equal(bikesOnBoard(null), null);
  assert.equal(bikesOnBoard({'bicycle:fee':'yes'}), null);   // a fee alone says nothing about access
  assert.equal(bikesOnBoard({bicycle:'destination'}), null);
  assert.equal(bikesOnBoard({bicycle:'yes;no'}), null);
  assert.equal(bikesOnBoard({bicycle:''}), null);
});

test('every answer has exactly one drawer label key', () => {
  assert.deepEqual(Object.keys(BIKES_ON_BOARD_LABEL).sort(),
    ['allowed', 'allowed_fee', 'dismount', 'dismount_fee', 'no']);
  for (const tags of [{bicycle:'yes'}, {bicycle:'no'}, {bicycle:'dismount', 'bicycle:fee':'yes'}]) {
    assert.ok(BIKES_ON_BOARD_LABEL[bikesOnBoard(tags)]);
  }
});

// bikeAccess: the dock's own `bicycle` tag, or what it inherited from the ferry
// routes that end at it (`cc:` keys the harvest writes, coverage-provider.md §3).
// The drawer has to say which, so a rider never reads a route's answer as the
// dock's own tag.
test('the dock\'s own tag is its own answer', () => {
  assert.deepEqual(bikeAccess({bicycle:'yes'}), {answer:'allowed', routes:0});
  assert.deepEqual(bikeAccess({bicycle:'no', 'cc:bicycle_from_route':'yes', 'cc:ferry_route':'way/1'}), {answer:'no', routes:0});
});

test('an inherited answer says how many routes gave it', () => {
  // node/4986359087 Enkhuizen (-Stavoren), from way/146810803
  assert.deepEqual(bikeAccess({'cc:bicycle_from_route':'yes', 'cc:ferry_route':'way/146810803'}), {answer:'allowed', routes:1});
  assert.deepEqual(bikeAccess({'cc:bicycle_from_route':'dismount', 'cc:bicycle:fee_from_route':'yes', 'cc:ferry_route':'way/1;way/2'}),
    {answer:'dismount_fee', routes:2});
  assert.equal(bikeAccess({'cc:bicycle_from_route':'maybe', 'cc:ferry_route':'way/1'}), null);
  assert.equal(bikeAccess({}), null);
});

test('the source of an inherited answer is worded, with the route name when there is one', () => {
  const words = {one:'from the ferry {name}', route:'via its ferry route', routes:'via its ferry routes'};
  assert.equal(bikeSourceText({answer:'allowed', routes:1}, 'Enkhuizen - Stavoren', words), 'from the ferry Enkhuizen - Stavoren');
  assert.equal(bikeSourceText({answer:'allowed', routes:1}, null, words), 'via its ferry route');
  assert.equal(bikeSourceText({answer:'allowed', routes:2}, 'Enkhuizen - Stavoren', words), 'via its ferry routes');
  assert.equal(bikeSourceText({answer:'allowed', routes:0}, 'X', words), null);
});

// osmDuration: OSM `duration` is "HH:MM", "HH:MM:SS" or ISO 8601 ("PT85M").
// Anything else, a bare "10" among them (minutes? hours?), is refused.
test('a crossing time is read from the duration shapes OSM documents', () => {
  assert.equal(osmDuration('PT85M'), 85);        // way/146810803 Enkhuizen - Stavoren
  assert.equal(osmDuration('PT1H35M'), 95);
  assert.equal(osmDuration('PT2H'), 120);
  assert.equal(osmDuration('00:05'), 5);
  assert.equal(osmDuration('0:08'), 8);
  assert.equal(osmDuration('01:30'), 90);
  assert.equal(osmDuration('00:05:00'), 5);
  assert.equal(osmDuration('PT30S'), 1);         // a chain ferry is under a minute, never "0 min"
});

test('a value that is not plainly a duration is refused', () => {
  for(const raw of ['10', '1', '5 min', 'about an hour', '00:75', 'PT', 'P1D', '00:00', 'PT0M', '', null, undefined]){
    assert.equal(osmDuration(raw), null, String(raw));
  }
});

test('a duration is written in hours and minutes', () => {
  const words = {h:'{h} h', min:'{m} min', hMin:'{h} h {m} min'};
  assert.equal(durationText(85, words), '1 h 25 min');
  assert.equal(durationText(120, words), '2 h');
  assert.equal(durationText(5, words), '5 min');
});

// ferryFacts: what a `route=ferry` object says beyond bikes. Only a ferry route
// has them; a station's `fee` is not a fare.
test('a ferry route gives its crossing time, season, hours, fare and website', () => {
  assert.deepEqual(ferryFacts({route:'ferry', duration:'PT85M', seasonal:'April-October', toll:'yes',
    opening_hours:'May-Sep 08:45-17:25', website:'https://www.veerboot.info'}),
    {minutes:85, season:null, seasonText:'April-October', hours:'May-Sep 08:45-17:25', fare:'paid', web:'https://www.veerboot.info'});
});

test('season yes and no are words; free text stays verbatim', () => {
  assert.equal(ferryFacts({route:'ferry', seasonal:'yes'}).season, 'seasonal');
  assert.equal(ferryFacts({route:'ferry', seasonal:'no'}).season, 'allYear');
  assert.equal(ferryFacts({route:'ferry', seasonal:' summer '}).seasonText, 'summer');
});

test('the fare reads toll or fee; yes wins, a price we cannot word gives no row', () => {
  assert.equal(ferryFacts({route:'ferry', fee:'yes'}).fare, 'paid');
  assert.equal(ferryFacts({route:'ferry', toll:'no', fee:'yes'}).fare, 'paid');
  assert.equal(ferryFacts({route:'ferry', toll:'no'}).fare, 'free');
  assert.equal(ferryFacts({route:'ferry', fee:'0.50 euro', duration:'PT5M'}).fare, null);
});

test('the website falls back through contact:website and url', () => {
  assert.equal(ferryFacts({route:'ferry', 'contact:website':'https://a.example'}).web, 'https://a.example');
  assert.equal(ferryFacts({route:'ferry', url:'https://b.example'}).web, 'https://b.example');
});

test('only a ferry route has ferry facts, and one with none gives null', () => {
  assert.equal(ferryFacts({railway:'station', fee:'yes'}), null);
  assert.equal(ferryFacts({route:'ferry', duration:'10'}), null);
  assert.equal(ferryFacts(null), null);
});

test('every fact token names a drawer string', () => {
  for(const k of ['seasonal', 'allYear', 'paid', 'free']) assert.ok(FERRY_FACT_LABEL[k], k);
});
