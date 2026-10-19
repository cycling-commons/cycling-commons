// SPDX-License-Identifier: AGPL-3.0-only
/* The planner's routed legs, kept in this browser (route-planner.md section 6.0, owner 2026-10-18: a reopened ride
   asked our server for every leg again, and a visitor's day has 100 legs). Keyed as the planner's leg cache
   (legKeys: both ends and the bike), each with the time it was last part of a ride: kept seven days after that, as
   the draft, and at most KEEP_LEGS, the oldest forgotten first. In the browser's own database (IndexedDB), not in
   local storage: fifty legs with their lines, heights and surfaces are a few megabytes. Nothing of it leaves the
   browser. Every call fails quiet: without the database (a private window, blocked site data) the planner asks our
   server, as it does for a leg it has not kept. Signing out deletes the database (assets/js/signed-out.js). */
import { workFresh } from './plan-model.js';

export const LEG_DB = 'cc-plan';
const STORE = 'legs';
/** The most legs kept: about ten rides of fifty legs, some ten megabytes at most. */
export const KEEP_LEGS = 500;
/* What a kept leg holds: the engine's answer and the repeat check's verdict, nothing the page adds. */
const FIELDS = ['key', 'routed', 'line', 'distM', 'heights', 'surfaces', 'streets', 'runs', 'busyRuns', 'recorded', 'quality', 'speeds', 'detailRuns', 'otherWay', 'repeatChecked', 'fromFile', 'matched'];

/** A kept leg as stored: only FIELDS, and `at`, the time it was last part of a ride. */
export function legRecord(leg, at){
  const out = {};
  for(const f of FIELDS) if(leg[f] !== undefined) out[f] = leg[f];
  out.at = at;
  return out;
}
/** Whether a kept leg is used as it is: routed, within its seven days, and of the server's current answer, which
    carries the road detail and where it lies, `detailRuns` (route-planner.md section 4.4a); an older one is asked of
    the server again. */
export const keptLegUsable = (v, now) => !!v && !!v.routed && workFresh(v.at, now) && 'detailRuns' in v;
/** Whether a kept leg goes, oldest first: past its seven days, or while more than KEEP_LEGS are kept (`over` above 0). */
export const forgetLeg = (at, over, now) => over > 0 || !workFresh(at, now);

let opening = null;
function open(){
  if(!opening) opening = new Promise(resolve => {
    try {
      const req = indexedDB.open(LEG_DB, 1);
      req.onupgradeneeded = () => { req.result.createObjectStore(STORE, { keyPath: 'key' }).createIndex('at', 'at'); };
      req.onsuccess = () => resolve(req.result);
      req.onerror = req.onblocked = () => resolve(null);
    } catch(e){ resolve(null); }
  });
  return opening;
}
const finished = tx => new Promise(resolve => { tx.oncomplete = () => resolve(true); tx.onerror = tx.onabort = () => resolve(false); });

/** The kept legs for these keys that are still fresh, key -> leg; an empty map when the database cannot say. */
export async function storedLegs(keys, now = Date.now()){
  const out = new Map();
  if(!keys.length) return out;
  const db = await open(); if(!db) return out;
  try {
    const tx = db.transaction(STORE, 'readonly'), store = tx.objectStore(STORE);
    for(const k of keys){
      const r = store.get(k);
      r.onsuccess = () => { const v = r.result; if(keptLegUsable(v, now)){ const { at, ...leg } = v; out.set(k, leg); } };
    }
    await finished(tx);
  } catch(e){ return new Map(); }
  return out;
}

/** Keep the ride's routed legs, stamped now, and forget the stale and the oldest past KEEP_LEGS. */
export async function keepLegs(legs, now = Date.now()){
  const keep = legs.filter(l => l && l.routed && l.key);
  if(!keep.length) return;
  const db = await open(); if(!db) return;
  try {
    const tx = db.transaction(STORE, 'readwrite'), store = tx.objectStore(STORE);
    for(const l of keep) store.put(legRecord(l, now));
    const count = store.count();
    count.onsuccess = () => {
      let over = count.result - KEEP_LEGS;
      const cur = store.index('at').openKeyCursor();
      cur.onsuccess = () => {
        const c = cur.result;
        if(!c || !forgetLeg(c.key, over, now)) return;
        store.delete(c.primaryKey); over--; c.continue();
      };
    };
    await finished(tx);
  } catch(e){ /* full or blocked: the legs are asked again next time */ }
}
