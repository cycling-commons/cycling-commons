// SPDX-License-Identifier: AGPL-3.0-only
/* Several rides at once (docs/specs/traffic-measurements.md §3.2): many .fit
   files, or the archive a bike computer platform exports. Every ride is read and matched
   here, one at a time, and never uploaded; the rider sees one total and one
   button. Traffic summaries only: tags are reviewed one ride at a time. */
import { D, tpl } from './i18n.js';
import { parseFit } from '../lib/scout-fit.js';
import { unzipSync, gunzipSync } from '../lib/fflate-0.8.3.js';
import { fitEntries } from '../lib/ride-archive.js';
import { summariseRide, makeChunks, sendChunks, postTraffic } from '../lib/traffic-ride.js';
import { createBatch } from '../lib/ride-batch.js';
import { drawBatch } from './scout-traffic.js';
import { uKm } from './units.js';

const el = id => document.getElementById(id);
const t = (k, fallback) => (D && D[k]) || fallback;
const tools = { unzipSync, gunzipSync };

let lines = [];
let chunks = null;
let totals = { added: 0, duplicate: 0 };
let running = false;
let rideTotal = 0;

function say(id, text, isError) {
  const box = el(id);
  if (!box) return;
  box.textContent = text || '';
  box.hidden = !text;
  box.classList.toggle('err', !!isError);
}

const pause = () => new Promise(resolve => setTimeout(resolve, 0));

/* One file at a time: years of picked rides are never in memory at once.
   A zip archive is unpacked whole, which is why a very large export is better
   unpacked first and its .fit files picked. */
async function* rideEntries(files) {
  for (const file of files) {
    const name = file.webkitRelativePath || file.name;
    if (!/\.(fit|fit\.gz|zip)$/i.test(name)) continue;
    let entries;
    try {
      entries = fitEntries(name, new Uint8Array(await file.arrayBuffer()), tools);
    } catch (e) {
      entries = [{ name, bytes: null, error: true }];
    }
    yield* entries;
  }
}

async function run(files, onStart) {
  if (running) return;
  running = true;
  onStart();
  lines = [];
  const box = el('scoutBulk');
  if (box) box.hidden = false;
  say('scoutBulkMsg', '');
  ['scoutBulkSum', 'scoutBulkYears'].forEach(id => { const n = el(id); if (n) n.hidden = true; });
  const send = el('scoutBulkSend');
  if (send) { send.hidden = true; send.disabled = false; }

  const candidates = files.filter(f => /\.(fit|fit\.gz|zip)$/i.test(f.webkitRelativePath || f.name));
  const batch = createBatch();
  candidates.forEach(() => batch.file());
  let skipped = 0;
  let read = 0;
  for await (const entry of rideEntries(files)) {
    read++;
    say('scoutBulkProgress', tpl(t('scoutBulkReading', 'Reading ride {i} of {n}…'), { i: read, n: Math.max(read, candidates.length) }));
    await pause();
    if (entry.error) { skipped++; continue; }
    let summary;
    try {
      const b = entry.bytes;
      summary = await summariseRide(parseFit(b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength)),
        { entries: (window.CC_TILES || {}).roadpieces || {} });
    } catch (e) {
      skipped++;
      continue;
    }
    if (!summary) continue;
    batch.add(summary);
    lines.push(...summary.lines);
  }
  chunks = makeChunks(lines);
  totals = { added: 0, duplicate: 0 };
  const sum = batch.summary();
  rideTotal = sum.rides;

  say('scoutBulkSkipped', skipped ? tpl(t('scoutBulkSkipped', '{n} file(s) could not be read and were skipped'), { n: skipped }) : '');
  if (!sum.rides) {
    say('scoutBulkProgress', t('scoutBulkNone', 'None of these files has radar data.'));
  } else {
    say('scoutBulkProgress', '');
    renderSummary(sum);
    drawBatch(batch.drawing());
    if (send) send.hidden = !lines.length;
    if (!lines.length) say('scoutBulkMsg', t('scoutTrafficNothing', 'No part of this ride with the radar on lies on a road we know, so there is nothing to send.'));
  }
  running = false;
}

/** A day number (days since 1970) as a date in the rider's format. */
function dayText(day) {
  const u = new Date(day * 86400000);
  const local = new Date(u.getUTCFullYear(), u.getUTCMonth(), u.getUTCDate(), 12);
  return window.ccDate ? window.ccDate(local) : u.toISOString().slice(0, 10);
}

/** The batch in a few rows, and its breakdown per year (folded). */
function renderSummary(sum) {
  const lang = document.documentElement.lang || undefined;
  const n = v => Number(v).toLocaleString(lang);
  const rows = [
    [t('scoutBulkRides', 'Rides with radar'), tpl(t('scoutBulkRidesN', '{rides} from {files} files'), { rides: n(sum.rides), files: n(sum.files) })],
    [t('scoutBulkPeriod', 'Period'), sum.fromDay === null ? '' : dayText(sum.fromDay) + ' – ' + dayText(sum.toDay)],
    [t('scoutBulkMatched', 'Matched to roads'), uKm(sum.km, 0)],
    [t('scoutBulkPassed', 'Cars passed you'), n(sum.cars)],
    [t('scoutBulkNearby', 'Cars nearby, beside a cycle path'), n(sum.nearby)],
  ];
  const table = el('scoutBulkSum');
  if (table) {
    const body = table.tBodies[0];
    body.textContent = '';
    rows.forEach(([label, value]) => {
      const tr = document.createElement('tr');
      const th = document.createElement('th');
      th.scope = 'row';
      th.textContent = label;
      const td = document.createElement('td');
      td.textContent = value;
      tr.append(th, td);
      body.appendChild(tr);
    });
    table.hidden = false;
  }
  const years = el('scoutBulkYears');
  const yearRows = el('scoutBulkYearRows');
  if (years && yearRows) {
    yearRows.textContent = '';
    sum.years.forEach(y => {
      const tr = document.createElement('tr');
      [String(y.year), n(y.rides), uKm(y.km, 0), n(y.cars), n(y.nearby)].forEach(v => {
        const td = document.createElement('td');
        td.textContent = v;
        tr.appendChild(td);
      });
      yearRows.appendChild(tr);
    });
    years.hidden = !sum.years.length;
  }
}

async function sendAll(button) {
  if (!lines.length || !chunks) return;
  button.disabled = true;
  const result = await sendChunks(chunks, postTraffic, (i, n) => {
    say('scoutBulkMsg', tpl(t('scoutBulkSending', 'Sending part {i} of {n}…'), { i, n }));
  });
  totals.added += result.added;
  totals.duplicate += result.duplicate;
  if (result.ok) {
    button.hidden = true;
    say('scoutBulkMsg', tpl(t('scoutBulkSent', '{rides} rides sent: {added} stretches added, {duplicate} were already known.'), { rides: rideTotal, ...totals }));
    return;
  }
  button.disabled = false;
  say('scoutBulkMsg', 'rate_limited' === result.error
    ? t('scoutTrafficLimited', 'You have sent a lot in the last hour. Wait a while and send again; nothing is counted twice.')
    : t('scoutTrafficFailed', 'Sending stopped. What was sent is kept; send again to finish, nothing is counted twice.'), true);
}

let clearSingle = () => {};

/** Read and summarise several rides: the picker's files, or several dropped at once. */
export function startBulk(files) {
  return run([...files], clearSingle);
}

/** @param {{onStart: () => void}} hooks onStart clears the single-ride review */
export function initScoutBulk({ onStart }) {
  clearSingle = onStart;
  const send = el('scoutBulkSend');
  if (send) send.addEventListener('click', () => sendAll(send));
}

/** Leaving bulk mode for a single ride hides its section. */
export function hideBulk() {
  const box = el('scoutBulk');
  if (box) box.hidden = true;
}
