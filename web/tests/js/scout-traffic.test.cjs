// SPDX-License-Identifier: AGPL-3.0-only
//
// The two-step ride review and bulk mode (docs/specs/traffic-measurements.md
// §3.1, §3.2). These modules mount against the live map and panel, so the
// contract is pinned structurally; the logic they call is tested in
// traffic-ride.test.mjs and its neighbours.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const review = read('assets/map/scout-review.js');
const traffic = read('assets/map/scout-traffic.js');
const bulk = read('assets/map/scout-bulk.js');
const panel = read('templates/map/_scout_review.html.twig');

function fn(src, name) {
  const m = src.match(new RegExp('function ' + name + '\\(([^)]*)\\) \\{([\\s\\S]*?)\\n\\}'));
  assert.ok(m, name + '() not found');
  return m[2];
}

test('the step bar shows only for a ride with radar data', () => {
  const show = fn(review, 'show');
  assert.match(show, /el\('scoutSteps'\)\.hidden = !hasRadar/);
});

test('a ride with radar and no tags opens on the traffic step, without the no-tags message', () => {
  const show = fn(review, 'show');
  assert.match(show, /if \(hasRadar && !hasTags\) setStep\(2\)/);
  assert.doesNotMatch(show, /scoutNoTags/, 'the old "nothing to review" line must not sit beside 41 cars');
  assert.match(show, /scoutNothing/, 'a ride with neither says there is nothing to send');
});

test('step 1 shows the tags and hides the cars, step 2 the other way round', () => {
  const step = fn(review, 'setStep');
  assert.match(step, /showCars\(2 === n\)/);
  assert.match(step, /showTagLayers\(1 === n\)/);
  assert.match(step, /showMatchedPieces\(2 === n\)/);
});

test('the traffic step names what it cannot place and sends through the traffic endpoint only', () => {
  assert.match(traffic, /scoutTrafficUnmatched/);
  assert.match(traffic, /sendChunks\(chunks/);
  assert.match(traffic, /makeChunks\(/);
  assert.match(traffic, /postTraffic/);
  assert.doesNotMatch(traffic, /\/scout\/tags/);
});

test('bulk mode sends traffic and never tags', () => {
  assert.match(bulk, /summariseRide\(/);
  assert.match(bulk, /sendChunks\(chunks/);
  assert.doesNotMatch(bulk, /\/scout\/tags|sendOne|sendStretch/);
});

test('bulk mode reads files in the browser and uploads none', () => {
  assert.doesNotMatch(bulk, /FormData|\.upload|new XMLHttpRequest/);
  assert.match(bulk, /unzipSync/);
});

test('every id the modules address exists in the panel', () => {
  const ids = new Set([...panel.matchAll(/id="([^"]+)"/g)].map(m => m[1]));
  for (const src of [traffic, bulk, review]) {
    for (const m of src.matchAll(/el\('(scout(?:Traffic|Bulk|Step|Next)[A-Za-z]*)'\)/g)) {
      assert.ok(ids.has(m[1]), m[1] + ' is not in the panel');
    }
  }
});

test('a hidden panel block stays hidden when its class sets a display', () => {
  // A class rule with display: beats the browser's [hidden]{display:none}, so
  // the single-ride and several-rides blocks both showed, one under the other.
  const css = read('assets/styles/map.css');
  const tags = panel.match(/<[a-z]+\b[^>]*\bhidden\b[^>]*>/g) || [];
  const classes = new Set();
  tags.forEach(t => {
    const m = t.match(/class="([^"]+)"/);
    if (m) m[1].split(/\s+/).forEach(c => classes.add(c));
  });
  const missing = [...classes].filter(c => {
    const esc = c.replace(/[-]/g, '\\-');
    const sets = new RegExp('\\.' + esc + '\\{[^}]*display:(?!none)').test(css);
    return sets && !css.includes('.' + c + '[hidden]{display:none}');
  });
  assert.deepEqual(missing, []);
});

test('one picker takes one ride or many, never a folder', () => {
  // A folder picker does not exist on phones, and a second picker for several
  // rides is one choice too many: the ride picker takes many .fit files or a
  // .zip, and several files go to several-rides mode.
  assert.doesNotMatch(panel, /webkitdirectory/);
  assert.doesNotMatch(panel, /scoutBulkFiles|scoutBulkPick|scout-bulk-lead|review_bulk_lead/);
  const input = panel.match(/<input[^>]*id="scoutFile"[^>]*>/);
  assert.ok(input, 'scoutFile input missing');
  assert.match(input[0], /\bmultiple\b/);
  const init = fn(review, 'initScoutReview');
  assert.match(init, /files\.length > 1\) startBulk\(files\)/);
});

test('dropping several files starts several rides, one file the single review', () => {
  const drop = review.match(/addEventListener\('drop', e => \{([\s\S]*?)\n    \}\);/);
  assert.ok(drop, 'drop handler not found');
  assert.match(drop[1], /files\.length > 1/);
  assert.match(drop[1], /startBulk\(/);
  assert.match(bulk, /export function startBulk\(/);
});

test('a .zip that is not a Scout export opens its .fit rides', () => {
  // Any .zip with .fit files is welcome: one ride opens the review, several
  // go to several-rides mode. Only a Scout export from a newer app is refused.
  const load = fn(review, 'loadFile');
  assert.match(load, /fitEntries\(file\.name, bytes/);
  assert.match(load, /rides\.length > 1\) \{ startBulk\(\[file\]\)/);
  assert.match(load, /'version' === code/);
});

test('a ride without tags greys out the tags step and says why on hover', () => {
  const step = fn(review, 'setStep');
  assert.match(step, /setAttribute\('aria-disabled', rideHasTags \? 'false' : 'true'\)/);
  assert.match(step, /scoutStepNoTags/);
  assert.match(review, /if \(!tagsBtn\.matches\('\[aria-disabled="true"\]'\)\) setStep\(1\)/);
  const css = read('assets/styles/map.css');
  assert.match(css, /\.scout-step\[aria-disabled="true"\]\{[^}]*cursor:not-allowed/);
});

test('one link shows what is sent, below the send button', () => {
  // The intro and the list of lines are one thing: what this click sends.
  const step = panel.match(/<section class="scout-traffic" id="scoutTraffic"[\s\S]*?<\/section>/)[0];
  assert.equal((step.match(/review_traffic_preview/g) || []).length, 1, 'one "what is sent" link');
  assert.doesNotMatch(step, /review_traffic_about/);
  const send = step.indexOf('id="scoutTrafficSend"');
  const preview = step.indexOf('id="scoutTrafficPreview"');
  assert.ok(send > 0 && preview > send, 'the link sits below the button');
  assert.match(step, /<summary>\{\{ 'scout\.review_traffic_preview'\|trans \}\}<\/summary>\s*<p>\{\{ 'scout\.review_traffic_intro'\|trans \}\}<\/p>\s*<ol id="scoutTrafficLines">/);
  const bulk = panel.match(/<section class="scout-traffic" id="scoutBulk"[\s\S]*?<\/section>/)[0];
  assert.ok(bulk.indexOf('<details class="scout-traffic-preview"') > bulk.indexOf('id="scoutBulkSend"'), 'several rides: the link sits below the button too');
});

test('the traffic step draws the part with no road, and tells the rider which cars that leaves out', () => {
  const render = fn(traffic, 'render');
  assert.match(render, /summary\.allCars - summary\.cars - summary\.nearby/);
  assert.match(render, /scoutTrafficFactsOff/);
  assert.match(traffic, /unmatchedLines/);
  assert.match(fn(traffic, 'showMatchedPieces'), /TRAFFIC_LINE_IDS/);
  assert.match(traffic, /TRAFFIC_LINE_IDS = \[[^\]]*UNMATCHED_LINE\]/);
});

test('without road data only that is said, not the unmatched distance as well', () => {
  const render = fn(traffic, 'render');
  assert.match(render, /const noTiles = /);
  assert.match(render, /const off = !noTiles && summary\.unmatchedKm >= 0\.05/);
});

test('the unmatched line has an eye that fits the map to the part with no road, drawn red', () => {
  const render = fn(traffic, 'render');
  assert.match(render, /scout-fact-eye/);
  assert.match(render, /aria-label/);
  assert.match(traffic, /function showUnmatched\(\)/);
  assert.match(fn(traffic, 'showUnmatched'), /fitBounds\(/);
  assert.match(traffic, /UNMATCHED_LINE[\s\S]*'line-color': UNMATCHED_RED/);
  const css = read('assets/styles/map.css');
  assert.match(css, /\.scout-fact-eye\{/);
});

test('each click on the eye goes to the next part with no road, and says which', () => {
  const show = fn(traffic, 'showUnmatched');
  assert.match(show, /unmatchedStops\(/);
  assert.match(show, /stop = \(stop \+ 1\) % stops\.length/);
  assert.match(show, /maxZoom: 17/);
  assert.match(traffic, /scout-fact-eye-n/);
});

test('road lines that finish matching after step 2 opened are drawn visible', () => {
  // A ride without tags opens on step 2 at once; matching ends later. Lines
  // added hidden then stayed hidden, with no tags step to toggle them back.
  assert.match(fn(traffic, 'showMatchedPieces'), /piecesShown = !!on/);
  const draw = fn(traffic, 'drawPieces');
  assert.doesNotMatch(draw, /visibility: 'none'/);
  assert.equal((draw.match(/visibility: piecesShown \? 'visible' : 'none'/g) || []).length, 2, 'the roads and the red');
});

test('the review lines lie above the ride line, and red above green', () => {
  assert.match(traffic, /export const TRAFFIC_LINE_IDS = \[PIECE_LINE, UNMATCHED_LINE\]/);
  assert.match(fn(review, 'raiseScoutLayers'), /\.\.\.TRAFFIC_LINE_IDS/);
});

test('good facts are plain white; amber stays for what the rider should notice', () => {
  const render = fn(traffic, 'render');
  assert.match(render, /facts\.classList\.toggle\('ok', !!summary\.lines\.length\)/);
  assert.match(review, /className = firstOk && 0 === i \? 'scout-fact ok' : 'scout-fact'/);
  const css = read('assets/styles/map.css');
  assert.match(css, /\.scout-fact\.ok\{[^}]*color:var\(--chrome-fg-solid\)/);
});

test('with every car on a matched road, step 2 says it in one line', () => {
  assert.match(review, /p\.id = 'scoutRadarFact'/);
  const sync = fn(traffic, 'syncRadarFact');
  assert.match(sync, /piecesShown && summary && summary\.lines\.length > 0/);
});

test('the key shows the car as the map draws it, without a speed that overflows', () => {
  const mapPage = read('templates/map/index.html.twig');
  const group = mapPage.match(/<div class="grp" id="mk-scout">[\s\S]*?<\/div>\s*\{% endif %\}/)[0];
  assert.doesNotMatch(group, /km\/h/);
  assert.match(group, /class="scout-pass"/);
});

test('the map legend shows the whole step 2 key while step 2 is open', () => {
  const mapPage = read('templates/map/index.html.twig');
  const legend = mapPage.slice(mapPage.indexOf('<div class="legend" hidden>'));
  const key = legend.match(/<div class="rkey" id="scoutKey" hidden>[\s\S]*?<\/div>\s*<\/div>\s*\{% endif %\}/)[0];
  for (const k of ['scout_path_h', 'scout_lane_h', 'scout_road_h', 'scout_none_h', 'scout_car_h']) {
    assert.match(key, new RegExp("'legend\\." + k + "'\\|trans"), k);
  }
  assert.doesNotMatch(key, /rkey-row"[^>]*hidden/, 'every row always shows');
  const sync = fn(traffic, 'syncScoutKey');
  assert.doesNotMatch(sync, /scoutKeyNone|scoutKeySent/);
  assert.match(sync, /dispatchEvent\(new Event\('cc:legend'\)\)/);
  const panels = read('assets/map/panels.js');
  assert.match(panels, /addEventListener\('cc:legend', syncLegend\)/);
});

test('the map key link opens the legend on the map, never the left drawer', () => {
  assert.doesNotMatch(traffic, /ib-key|dwr-body/);
  const init = fn(traffic, 'initScoutTraffic');
  assert.match(init, /classList\.contains\('collapsed'\)/);
  assert.match(init, /getElementById\('lgToggle'\)/);
});


test('a sent road takes the colour of its kind; red dashes on top are not sent', () => {
  // Bike only blue, painted cycle lane amber, shared with the cars purple.
  assert.match(traffic, /const LABEL_COLOUR = \{ p: '#2F6FDB', l: '#E8A33D', r: '#7A3FB8' \}/);
  const draw = fn(traffic, 'drawPieces');
  assert.match(draw, /'line-color': \['match', \['get', 'label'\], 'p', LABEL_COLOUR\.p, 'l', LABEL_COLOUR\.l, LABEL_COLOUR\.r\]/);
  assert.doesNotMatch(traffic, /SENT_GREEN|PIECE_CASE|LABEL_CASE/);
  assert.equal((draw.match(/line-dasharray/g) || []).length, 1, 'only the part with no road is dashed');
  assert.ok(draw.indexOf('id: PIECE_LINE') < draw.indexOf('id: UNMATCHED_LINE'), 'red is added after the roads');
});

test('every key lists the three kinds and what is not sent, with no separate "sent" row', () => {
  const mapPage = read('templates/map/index.html.twig');
  const panelKey = mapPage.match(/<div class="grp" id="mk-scout">[\s\S]*?\{% endif %\}/)[0];
  const pageKey = read('templates/pages/map_key.html.twig');
  for (const key of [panelKey, pageKey]) {
    for (const k of ['scout_path_h', 'scout_lane_h', 'scout_road_h', 'scout_none_h', 'scout_car_h']) {
      assert.match(key, new RegExp("'legend\\." + k + "'\\|trans"), k);
    }
    assert.doesNotMatch(key, /scout_sent_h|mk-edge/);
  }
  assert.doesNotMatch(mapPage, /scout_sent_h|skey-ln edge/);
});

test('every key names the grey line as the GPS track', () => {
  const mapPage = read('templates/map/index.html.twig');
  const legend = mapPage.slice(mapPage.indexOf('<div class="legend" hidden>'));
  const onMap = legend.match(/<div class="rkey" id="scoutKey" hidden>[\s\S]*?\{% endif %\}/)[0];
  const panelKey = mapPage.match(/<div class="grp" id="mk-scout">[\s\S]*?\{% endif %\}/)[0];
  const pageKey = read('templates/pages/map_key.html.twig');
  for (const key of [onMap, panelKey, pageKey]) assert.match(key, /'legend\.scout_gps_h'\|trans/);
  assert.match(panelKey, /'legend\.scout_gps_t'\|trans/);
  assert.match(pageKey, /'legend\.scout_gps_t'\|trans/);
});

test('a car marker shows the bare speed; the legend names the unit', () => {
  // "48" instead of "48 km/h": the markers along a busy road stop overlapping.
  const pass = fn(review, 'passEl');
  assert.match(pass, /sp\.textContent = pass\.ground == null \? '\?' : uSpeedValue\(pass\.ground\)/);
  assert.match(pass, /d\.title = /, 'the full speed with its unit on hover');
  const units = read('assets/map/units.js');
  assert.match(units, /export function uSpeedValue\(/);
  const cc = read('assets/js/cc-units.js');
  assert.match(cc, /window\.ccSpeedValue = ccSpeedValue/);
  const mapPage = read('templates/map/index.html.twig');
  const pageKey = read('templates/pages/map_key.html.twig');
  for (const key of [mapPage, pageKey]) {
    assert.match(key, /'legend\.scout_car_h'\|trans\(\{'%unit%': ccSpeedUnit\}\)/);
  }
});

test('a cycle-path line names its cars as nearby, a road line as passing', () => {
  const text = fn(traffic, 'lineText');
  assert.match(text, /'p' === line\.label/);
  assert.match(text, /scoutTrafficNearbyN/);
  assert.match(text, /scoutTrafficCarsN/);
});

test('a short stretch reads in metres, and the date and time follow the rider\'s settings', () => {
  const dist = fn(traffic, 'distance');
  assert.match(dist, /metres < 1000/);
  assert.match(dist, /uM\(Math\.round\(metres \/ 10\) \* 10\)/);
  const text = fn(traffic, 'lineText');
  assert.match(text, /window\.ccDate/);
  assert.match(text, /window\.ccTime/);
  assert.doesNotMatch(text, /toLocaleDateString/);
});

test('the facts say how many passed and how many were only nearby', () => {
  const render = fn(traffic, 'render');
  assert.match(render, /summary\.nearby/);
  assert.match(render, /scoutTrafficFactsNearby/);
});

test('several rides total the passing and the nearby cars apart', () => {
  // The sums live in ride-batch.js (ride-batch.test.mjs); the panel names both.
  assert.match(bulk, /scoutBulkPassed/);
  assert.match(bulk, /scoutBulkNearby/);
  assert.match(bulk, /sum\.nearby/);
});

test('a nearby car\'s marker has its own colour, and the legend names both', () => {
  assert.match(fn(review, 'placePasses'), /radar\.passes\.forEach\(\(pass, i\) =>/);
  assert.match(fn(review, 'passEl'), /d\.dataset\.pass = String\(i\)/);
  assert.match(traffic, /function markPassKinds\(\)/);
  assert.match(fn(traffic, 'markPassKinds'), /classList\.toggle\('nearby', 'nearby' === kind\)/);
  const css = read('assets/styles/map.css');
  assert.match(css, /\.scout-pass\.nearby\{[^}]*#2F6FDB/);
  const mapPage = read('templates/map/index.html.twig');
  const pageKey = read('templates/pages/map_key.html.twig');
  for (const key of [mapPage, pageKey]) {
    assert.match(key, /'legend\.scout_near_h'\|trans\(\{'%unit%': ccSpeedUnit\}\)/);
    assert.match(key, /class="(scout-pass|mk-chip) nearby"/);
  }
});

test('several rides draw their roads like one ride, and the map fits them', () => {
  assert.match(traffic, /export function drawBatch\(drawing\)/);
  const draw = fn(traffic, 'drawBatch');
  assert.match(draw, /drawPieces\(drawing\.matched, drawing\.unmatchedLines\)/);
  assert.match(draw, /fitBounds\(drawing\.bounds/);
  assert.match(bulk, /createBatch\(\)/);
  assert.match(bulk, /drawBatch\(batch\.drawing\(\)\)/);
});

test('several rides show a summary, a folded breakdown per year, and what was sent', () => {
  assert.match(panel, /id="scoutBulkSum"/);
  assert.match(panel, /<details class="scout-bulk-years" id="scoutBulkYears" hidden>/);
  assert.match(bulk, /scoutBulkSent/);
  assert.match(bulk, /window\.ccDate/);
  assert.doesNotMatch(bulk, /scoutBulkTotal/);
});
