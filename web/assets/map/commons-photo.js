// SPDX-License-Identifier: AGPL-3.0-only
/* The poll behind the drawer's "Loading image…" spinner
   (docs/specs/coverage-provider.md §7).

   We never hotlink Commons, so the first rider to open a viewpoint arrives
   before our own copy of the photo exists. This turns that wait into a spinner
   and, just as importantly, into an end: a file that turns out to be unusable,
   a worker that is not running, a phone that went offline, all land on
   onGiveUp rather than on a spinner nobody ever clears.

   A leaf: imports nothing, so the node tests can import it directly. */

/* Back off rather than hammer. A Commons fetch plus a re-encode is seconds, not
   milliseconds, and a drawer left open must not poll all afternoon. Bounded on
   purpose: the last entry is where we stop asking. */
export function photoPollDelays(){
  return [700, 1200, 2000, 3000, 4000, 6000, 8000, 10000];
}

/**
 * Poll one POI's cached Commons photo until it is ready, or give up.
 * Calls onReady at most once, or onGiveUp at most once. Never both.
 *
 * @param {string} ref     `node/462149319`
 * @param {(photo: object) => void} onReady
 * @param {() => void} onGiveUp
 * @param {{fetchImpl?: Function, sleep?: Function, cancelled?: () => boolean}} [opts]
 */
export async function watchCommonsPhoto(ref, onReady, onGiveUp, opts = {}){
  return watchJson('/map/coverage/photo/' + ref,
    { isReady: d => d.state === 'ready', isPending: d => d.state === 'pending' },
    onReady, onGiveUp, opts);
}

/**
 * The same bounded poll for any no-store JSON endpoint that answers
 * pending / ready / none. The town card uses it twice: once for the text,
 * then again for the photo that lands a few seconds later
 * (docs/specs/map-and-search.md §6.5).
 *
 * @param {string} url
 * @param {{isReady: (d: object) => boolean, isPending: (d: object) => boolean}} test
 * @param {(data: object) => void} onReady
 * @param {() => void} onGiveUp
 * @param {{fetchImpl?: Function, sleep?: Function, cancelled?: () => boolean}} [opts]
 */
export async function watchJson(url, test, onReady, onGiveUp, opts = {}){
  const doFetch = opts.fetchImpl || (u => fetch(u, {headers:{Accept:'application/json'}}));
  const sleep = opts.sleep || (ms => new Promise(r => setTimeout(r, ms)));
  const delays = photoPollDelays();

  for(let i = 0; ; i++){
    if(opts.cancelled && opts.cancelled()) return;   // drawer closed: stop, quietly

    let data = null;
    try{
      const res = await doFetch(url);
      if(res && res.ok) data = await res.json();
    }catch(e){
      /* Offline, a 429, a proxy in the way: all mean "nothing right now", and
         none of them are worth an error in a rider's console. */
    }

    if(data && test.isReady(data)){ onReady(data); return; }
    if(!data || !test.isPending(data)){ onGiveUp(); return; }
    if(i >= delays.length){ onGiveUp(); return; }
    await sleep(delays[i]);
  }
}
