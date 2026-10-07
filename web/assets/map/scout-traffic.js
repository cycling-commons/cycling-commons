// SPDX-License-Identifier: AGPL-3.0-only
/* Step 2 of the ride review: the traffic summary of one ride
   (docs/specs/traffic-measurements.md §3.1). The ride is matched to road
   pieces here, in the browser; the rider sees every line before sending, and
   the click on Send is the consent for this ride. */
import { map } from './map-init.js';
import { D, tpl } from './i18n.js';
import { uKm, uM } from './units.js';
import { summariseRide, makeChunks, sendChunks, postTraffic, unmatchedStops } from '../lib/traffic-ride.js';

const el = id => document.getElementById(id);
const t = (k, fallback) => (D && D[k]) || fallback;

const PIECE_SRC = 'cc-scout-pieces';
const PIECE_LINE = 'cc-scout-pieces-line';
const UNMATCHED_SRC = 'cc-scout-unmatched';
const UNMATCHED_LINE = 'cc-scout-unmatched-line';
/* The part with no road: red and dashed. */
const UNMATCHED_RED = '#D92D20';
const EYE_SVG = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">'
  + '<path d="M1.5 12S5.5 5 12 5s10.5 7 10.5 7-4 7-10.5 7S1.5 12 1.5 12z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>'
  + '<circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>';
/* A sent road takes the colour of where the rider rode relative to the cars:
   blue bike only, amber a painted cycle lane, purple shared with the cars.
   Red dashes are what is not sent. */
const LABEL_COLOUR = { p: '#2F6FDB', l: '#E8A33D', r: '#7A3FB8' };

let summary = null;
let pending = null;
let sent = false;
/* The requests of this ride, kept so a retry resends only what was not acknowledged. */
let chunks = null;
let totals = { added: 0, duplicate: 0 };
/* Whether step 2 is showing: lines that finish matching later are drawn to match. */
let piecesShown = false;
/* Several rides drew their roads (scout-bulk.js). */
let batchShown = false;
/* The eye's place among the parts with no road; -1 before the first click. */
let stop = -1;

function say(text, isError) {
  const box = el('scoutTrafficMsg');
  if (!box) return;
  box.textContent = text || '';
  box.hidden = !text;
  box.classList.toggle('err', !!isError);
}

function km(value) {
  return (Math.round(value * 10) / 10).toLocaleString(document.documentElement.lang || undefined);
}

/** A stretch in the rider's units: "40 m" under a kilometre, "1.2 km" above. */
function distance(metres) {
  if (metres < 1000) return uM(Math.round(metres / 10) * 10);
  return uKm(metres / 1000, 1);
}

function clock(slot) {
  const m = slot * 15;
  return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
}

/* Every field that is sent, in words: the consent covers exactly this. */
function lineText(line) {
  /* The line's local day and quarter hour as a local date, so the rider's own
     date and time settings (cc-dates.js) can write them. */
  const u = new Date(line.day * 86400000);
  const local = new Date(u.getUTCFullYear(), u.getUTCMonth(), u.getUTCDate(), Math.floor(line.slot / 4), (line.slot % 4) * 15);
  const cars = 'p' === line.label
    ? tpl(t('scoutTrafficNearbyN', '{n} cars nearby'), { n: line.nearby || 0 })
    : tpl(t('scoutTrafficCarsN', '{n} cars passed'), { n: line.passes });
  return tpl(t('scoutTrafficLine', '{label}, {dir}, {dist}, {cars}, {day} {date} {time}'), {
    label: t('scoutLabel' + line.label.toUpperCase(), line.label),
    dir: 'f' === line.dir ? t('scoutDirF', 'one way') : t('scoutDirB', 'the other way'),
    dist: distance(line.distanceM),
    cars,
    day: 'weekend' === line.dayType ? t('scoutDayWeekend', 'weekend') : t('scoutDayWorkday', 'workday'),
    date: window.ccDate ? window.ccDate(local) : local.getFullYear() + '-' + String(local.getMonth() + 1).padStart(2, '0') + '-' + String(local.getDate()).padStart(2, '0'),
    time: window.ccTime ? window.ccTime(local) : clock(line.slot),
  });
}

function drawPieces(matched, unmatchedLines) {
  removePieces();
  if (!matched && !unmatchedLines) return;
  if ((matched || []).length) {
    map.addSource(PIECE_SRC, {
      type: 'geojson',
      data: {
        type: 'FeatureCollection',
        features: matched.map(p => ({
          type: 'Feature', properties: { label: p.label }, geometry: { type: 'LineString', coordinates: p.coords },
        })),
      },
    });
    map.addLayer({
      id: PIECE_LINE, type: 'line', source: PIECE_SRC,
      layout: { 'line-cap': 'round', 'line-join': 'round', visibility: piecesShown ? 'visible' : 'none' },
      paint: {
        'line-color': ['match', ['get', 'label'], 'p', LABEL_COLOUR.p, 'l', LABEL_COLOUR.l, LABEL_COLOUR.r],
        'line-width': 6,
        'line-opacity': 0.9,
      },
    });
  }
  /* The part with no road, above the roads: a piece drawn whole can lie under
     a stretch of it. Not sent. */
  if ((unmatchedLines || []).length) {
    map.addSource(UNMATCHED_SRC, {
      type: 'geojson',
      data: {
        type: 'FeatureCollection',
        features: unmatchedLines.map(coords => ({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: coords } })),
      },
    });
    map.addLayer({
      id: UNMATCHED_LINE, type: 'line', source: UNMATCHED_SRC,
      layout: { 'line-cap': 'round', 'line-join': 'round', visibility: piecesShown ? 'visible' : 'none' },
      paint: { 'line-color': UNMATCHED_RED, 'line-width': 5, 'line-opacity': 0.95, 'line-dasharray': ['literal', [1.5, 1.5]] },
    });
  }
}

/**
 * Several rides at once: draw their roads like one ride's, each stretch once,
 * and fit the map to them (scout-bulk.js, ride-batch.js).
 */
export function drawBatch(drawing) {
  batchShown = true;
  piecesShown = true;
  drawPieces(drawing.matched, drawing.unmatchedLines);
  if (drawing.bounds) map.fitBounds(drawing.bounds, { padding: 80, maxZoom: 15 });
  syncScoutKey();
}

/** Colour each car marker: blue when it was only nearby, beside a cycle path. */
function markPassKinds() {
  const kinds = (summary && summary.passKinds) || [];
  document.querySelectorAll('.scout-pass[data-pass]').forEach(marker => {
    const kind = kinds[Number(marker.dataset.pass)];
    marker.classList.toggle('nearby', 'nearby' === kind);
  });
}

/** The map legend's step 2 key: the whole key, shown with the step. */
function syncScoutKey() {
  const box = el('scoutKey');
  if (!box) return;
  box.hidden = !(piecesShown && (summary || batchShown));
  document.dispatchEvent(new Event('cc:legend'));
}

/* The radar total says again what step 2's own line says; one line is enough there. */
function syncRadarFact() {
  const radarFact = el('scoutRadarFact');
  if (radarFact) radarFact.hidden = !!(piecesShown && summary && summary.lines.length > 0);
}

/** Pan and zoom to the next part of the ride that matched no road, longest first. */
function showUnmatched() {
  const stops = unmatchedStops(summary && summary.unmatchedLines);
  if (!stops.length) return;
  stop = (stop + 1) % stops.length;
  map.fitBounds(stops[stop].bounds, { padding: 80, maxZoom: 17 });
  const n = document.querySelector('#scoutTrafficUnmatched .scout-fact-eye-n');
  if (n) {
    n.hidden = stops.length < 2;
    n.textContent = (stop + 1) + '/' + stops.length;
  }
}

/** The step's own lines, kept above the ride line by the review's layer raise. */
export const TRAFFIC_LINE_IDS = [PIECE_LINE, UNMATCHED_LINE];

function removePieces() {
  if (map.getLayer(PIECE_LINE)) map.removeLayer(PIECE_LINE);
  if (map.getSource(PIECE_SRC)) map.removeSource(PIECE_SRC);
  if (map.getLayer(UNMATCHED_LINE)) map.removeLayer(UNMATCHED_LINE);
  if (map.getSource(UNMATCHED_SRC)) map.removeSource(UNMATCHED_SRC);
}

function render() {
  const facts = el('scoutTrafficFacts');
  const unmatched = el('scoutTrafficUnmatched');
  const preview = el('scoutTrafficPreview');
  const list = el('scoutTrafficLines');
  const send = el('scoutTrafficSend');
  if (!facts || !summary) return;
  const noTiles = !summary.lines.length && !Object.keys((window.CC_TILES || {}).roadpieces || {}).length;
  if (noTiles) {
    facts.textContent = t('scoutTrafficNoTiles', 'Road data is not available yet, so no traffic summary can be made.');
  } else if (!summary.lines.length) {
    facts.textContent = t('scoutTrafficNothing', 'No part of this ride with the radar on lies on a road we know, so there is nothing to send.');
  } else {
    /* One line: what passed the rider, what only drove beside a cycle path,
       and what was on a part not sent; together they make the radar total. */
    const parts = [tpl(t('scoutTrafficFacts', '{km} km matched to roads, {n} cars passed you'), { km: km(summary.matchedKm), n: summary.cars })];
    if (summary.nearby > 0) {
      parts.push(tpl(t('scoutTrafficFactsNearby', '{n} nearby beside a cycle path'), { n: summary.nearby }));
    }
    const off = summary.allCars - summary.cars - summary.nearby;
    if (off > 0) parts.push(tpl(t('scoutTrafficFactsOff', '{n} on parts not sent'), { n: off }));
    facts.textContent = parts.join(', ');
  }
  facts.classList.toggle('ok', !!summary.lines.length);
  if (unmatched) {
    // Without road data nothing could be matched; that line already says so.
    const off = !noTiles && summary.unmatchedKm >= 0.05;
    unmatched.hidden = !off;
    unmatched.textContent = '';
    if (off) {
      const text = document.createElement('span');
      text.textContent = tpl(t('scoutTrafficUnmatched', '{km} km could not be matched to roads (red on the map); that part and its cars are not sent'), { km: km(summary.unmatchedKm) });
      const eye = document.createElement('button');
      eye.type = 'button';
      eye.className = 'scout-fact-eye';
      eye.title = t('scoutShowBare', 'Show on the map');
      eye.setAttribute('aria-label', eye.title);
      eye.innerHTML = EYE_SVG;
      eye.addEventListener('click', showUnmatched);
      const n = document.createElement('span');
      n.className = 'scout-fact-eye-n';
      n.hidden = true;
      unmatched.append(text, ' ', eye, n);
    }
  }
  if (list) {
    list.textContent = '';
    summary.lines
      .slice()
      .sort((a, b) => a.day - b.day || a.slot - b.slot)
      .forEach(line => {
        const li = document.createElement('li');
        li.textContent = lineText(line);
        list.appendChild(li);
      });
  }
  if (preview) preview.hidden = !summary.lines.length;
  chunks = makeChunks(summary.lines);
  totals = { added: 0, duplicate: 0 };
  if (send) send.hidden = !summary.lines.length || sent;
  drawPieces(summary.matched, summary.unmatchedLines);
  syncScoutKey();
  syncRadarFact();
  markPassKinds();
}

/** Start matching a freshly opened ride. Safe to call for every ride; a ride without radar resolves to nothing. */
export function prepareTraffic(fit) {
  resetTraffic();
  const mine = summariseRide(fit, { entries: (window.CC_TILES || {}).roadpieces || {} })
    .then(result => {
      if (pending !== mine) return;
      summary = result;
      say('');
      render();
    })
    .catch(() => {
      if (pending !== mine) return;
      say(t('scoutTrafficNothing', 'No part of this ride with the radar on lies on a road we know, so there is nothing to send.'), true);
    });
  pending = mine;
  return mine;
}

export function resetTraffic() {
  summary = null;
  batchShown = false;
  stop = -1;
  pending = null;
  sent = false;
  chunks = null;
  removePieces();
  say('');
  ['scoutTrafficFacts', 'scoutTrafficLines'].forEach(id => { const n = el(id); if (n) n.textContent = ''; });
  ['scoutTrafficUnmatched', 'scoutTrafficPreview', 'scoutTrafficSend'].forEach(id => { const n = el(id); if (n) n.hidden = true; });
  syncScoutKey();
  syncRadarFact();
  markPassKinds();
}

export function showTrafficStep(on) {
  const box = el('scoutTraffic');
  if (box) box.hidden = !on;
  if (on && !summary && pending) {
    const facts = el('scoutTrafficFacts');
    if (facts) facts.textContent = t('scoutTrafficLoading', 'Matching the ride to roads…');
  }
}

export function showMatchedPieces(on) {
  piecesShown = !!on;
  syncScoutKey();
  syncRadarFact();
  TRAFFIC_LINE_IDS.forEach(id => {
    if (map.getLayer(id)) map.setLayoutProperty(id, 'visibility', on ? 'visible' : 'none');
  });
}

async function send(button) {
  if (!summary || !chunks || !summary.lines.length) return;
  button.disabled = true;
  const label = button.textContent;
  button.textContent = t('scoutSending', 'Sending…');
  const result = await sendChunks(chunks, postTraffic);
  totals.added += result.added;
  totals.duplicate += result.duplicate;
  button.textContent = label;
  if (result.ok) {
    sent = true;
    button.hidden = true;
    say(tpl(t('scoutTrafficSent', 'Sent: {added} stretches added, {duplicate} were already known.'), totals));
    return;
  }
  button.disabled = false;
  say('rate_limited' === result.error
    ? t('scoutTrafficLimited', 'You have sent a lot in the last hour. Wait a while and send again; nothing is counted twice.')
    : t('scoutTrafficFailed', 'Sending stopped. What was sent is kept; send again to finish, nothing is counted twice.'), true);
}

export function initScoutTraffic() {
  /* The review's map key is the legend box on the map: the link unfolds it
     when the rider folded it away. */
  const keyBtn = el('scoutTrafficKey');
  if (keyBtn) {
    keyBtn.addEventListener('click', () => {
      const legend = document.querySelector('.legend');
      const toggle = document.getElementById('lgToggle');
      if (legend && toggle && legend.classList.contains('collapsed')) toggle.click();
    });
  }
  const button = el('scoutTrafficSend');
  if (button) button.addEventListener('click', () => send(button));
}
