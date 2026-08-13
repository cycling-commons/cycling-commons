// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The community loop, in two halves that share one shape.

   Routes (route-domain spec §7): rode-it / vote / suggest-a-correction, with one
   authenticated snapshot fetch on drawer-open carrying counts, my-state and a
   stateless CSRF token the three POSTs reuse.

   Non-votable utilities (water potability, "still here?"): public counts, login
   to confirm, one toggle-able stance per rider.

   Plus the curator's moderation submit, which lives here because it is the same
   pattern again — a token, a POST, a repaint.
   Extracted from map.js by the module split.

   All three delegated listeners are registered by initCommunity() rather than at
   module scope (§4.2). They were three separate top-level registrations in the
   entry; their order relative to each other never mattered, because they match
   disjoint selectors ([data-cf-act], [data-rc-act], .cc-rc-invalid). */
import { I18N, D, tpl, CC_SEASON_LABEL } from './i18n.js';
import { CATALOG, layerByKey, LETTER_KEY } from './catalog.js';
import { render } from './render.js';
import { mapToast, closeDrawer, openDrawer, osmDrawer, renderPendingContext } from './drawer.js';
import { _pickSegs } from './picking.js';
import { dropPendingFromSearch } from './search-ui.js';
import { addCuratedFeature } from './osm-pools.js';
import { showPendingShape } from './pending-shape.js';

// Route community loop (spec §7). One authenticated fetch on drawer-open
// carries counts + my-state + a stateless CSRF token; the three POSTs reuse it.
const CC_BIKES=['Road','Gravel','MTB','E-bike','Handbike','Recumbent','Trike','Tandem'];
const CC_SEASONS=['spring','summer','autumn','winter'];
const CC_REASONS=[['broken-track',D.reasonBroken||'Wrong / broken track'],['trim-privacy',D.reasonPrivacy||'Trim a private start/end'],['duplicate',D.reasonDuplicate||'Duplicate of another route'],['not-rideable',D.reasonNotRideable||'Not actually rideable'],['other',D.reasonOther||'Something else']];
const _rcTokens={};   // route id → CSRF token from the last snapshot

/* Votable point types carry the vote CTA; routes (experience) have their own
   vote block. Confirmation is a SEPARATE question and the two overlap: voting
   ranks a region's best, confirming says the place is still there.

   EVERY point letter is confirmable, including climbs. "Could it vanish" was
   the wrong test — a castle does not go anywhere either, and it is confirmable
   (owner 2026-08-12). A confirmation is a rider saying *I was there and this is
   right*: that it exists, that it is where we say, that it is what we call it.
   A climb can be wrong about all three.

   Mirrors ItemType::isVotable()/isConfirmable(); keys are CATALOG layer keys. */
export const CC_VOTABLE=new Set(['climbs','stays','scenic','history']);
// 'surface' joined 2026-08-13 (owner-reported: the drawer said "not confirmed
// yet" to a second rider with no way to answer). Its panel words itself as
// "as described" (stanceKind 'accuracy'); the gone/closed condition row and
// the OSM-point one-tap row stay OFF for it — a stretch is not a place that
// vanishes, and the tile drawer has its own surface confirm flow.
export const CC_CONFIRMABLE=new Set(['water','services','hazards','transit','shelter','toilets','scenic','history','stays','climbs','surface']);
/* Where "out of order" is a thing that can happen. A tap, a pump and a toilet
   have working parts; a viewpoint does not, and offering a rider a button that
   cannot be true of what they are looking at teaches them to distrust the rest
   of the row. Closed and "not there anymore" need no such list - any place can
   be shut, and any place can be gone. */
export const CC_BREAKABLE=new Set(['water','services','toilets']);

const _cfTokens={};   // item id → CSRF token from the last confirmations snapshot

/* Flip a loaded feature to verified, in place.

   The `v` flag is what the renderer reads for the "?" badge and what the drawer
   reads for its status line, so both follow from one write. Searching every
   layer rather than being told which: a confirmation panel knows an item id and
   nothing else, and the id is unique across the catalog. */
function markItemVerified(itemId){
  for(const layer of CATALOG){
    const f = (layer.features||[]).find(x => x.id != null && String(x.id) === String(itemId));
    if(!f) continue;
    f.v = 1;
    render();
    return;
  }
}

export function routeCommunityPanel(id, state){
  const bikeL=I18N.bikes||{};
  const bikeOpts=CC_BIKES.map(b=>`<option value="${b}">${bikeL[b]||b}</option>`).join('');
  // Bike type has no safe default (it changes what a ride/vote means), so the
  // picker opens on a disabled placeholder — the rider must choose actively.
  const bikePickOpts=`<option value="" selected disabled>${D.bikeTypePh||'Bike type…'}</option>`+bikeOpts;
  const seasonOpts=CC_SEASONS.map(s=>`<option value="${s}">${CC_SEASON_LABEL[s]||s[0].toUpperCase()+s.slice(1)}</option>`).join('');
  const reasonOpts=CC_REASONS.map(([v,l])=>`<option value="${v}">${l}</option>`).join('');
  // Vote block only for verified routes (spec D7); rode-it for both.
  const voteBlock = state==='verified' ? `
    <div class="cc-rc-vote">
      <label class="cc-rc-l">${D.recommend||'Recommend it'} <span class="cc-rc-count" data-rc="votes"></span></label>
      <div class="cc-rc-row"><select class="cc-rc-season">${seasonOpts}</select><select class="cc-rc-vbike">${bikePickOpts}</select>
        <button class="cc-rc-btn" data-rc-act="vote">▲ ${D.vote||'Vote'}</button></div>
    </div>` : '';
  const rideProgress = state==='unverified' ? `<span class="cc-rc-count" data-rc="rides">…</span>` : '';
  return `<div class="cc-rc" data-route="${id}" data-state="${state||''}">
    <div class="cc-rc-ride">
      <label class="cc-rc-l">${D.rodeThis||'I rode this'} ${rideProgress}</label>
      <div class="cc-rc-row"><select class="cc-rc-rbike">${bikePickOpts}</select>
        <button class="cc-rc-btn" data-rc-act="rode-it">✓ ${D.rodeThis||'I rode this'}</button></div>
    </div>
    ${voteBlock}
    <details class="cc-rc-suggest"><summary>${D.suggestCorrection||'Suggest a correction'}</summary>
      <select class="cc-rc-reason">${reasonOpts}</select>
      <textarea class="cc-rc-note" placeholder="${D.optionalDetail||'Optional detail…'}"></textarea>
      <button type="button" class="cc-rc-mark" data-rc-mark="${id}">✎ ${D.markParts||'Mark the part(s) on the map'}</button>
      <span class="cc-rc-marks" data-rc-marks></span>
      <button class="cc-rc-btn" data-rc-act="suggest">${D.send||'Send'}</button>
    </details>
    <a class="cc-d-act edit" href="/routes/${id}.gpx">⤓ ${D.downloadGpx||'Download GPX'}</a>
    <div class="cc-rc-login" hidden>${D.loginRate||'Log in to rate this route'} · <a href="/login">${I18N.login||'Log in'}</a></div>
  </div>`;
}

// Called from openDrawer after the route drawer HTML lands.
export function hydrateRouteCommunity(id){
  const box=document.querySelector(`.cc-rc[data-route="${id}"]`); if(!box) return;
  // Restore the "N stretches marked" indicator if a picking session was already
  // committed for this route (e.g. drawer closed without Send, then reopened) —
  // otherwise the marks silently ride along on the next Send with no visible cue.
  const segs=_pickSegs[id];
  if(segs && segs.length){
    const m=box.querySelector('[data-rc-marks]');
    if(m) m.textContent = tpl((segs.length===1?D.marksOne:D.marksMany)||`· {n} stretch${segs.length===1?'':'es'} marked`, {n:segs.length});
  }
  // Anonymous visitors get the login prompt WITHOUT the request: the endpoint
  // would only 401, the page already knows (CC_RIDECHECK is emitted for
  // ROLE_USER only), and every anonymous route open was logging that 401 as
  // console noise — two failed requests per drawer (review 2026-08-09).
  if(!window.CC_RIDECHECK){
    const l=box.querySelector('.cc-rc-login'); if(l) l.hidden=false;
    box.classList.add('cc-rc-anon');
    return;
  }
  fetch(`/routes/${id}/community`, {credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(r=>{ if(r.status===401||r.status===403){ box.querySelector('.cc-rc-login').hidden=false; box.classList.add('cc-rc-anon'); throw new Error('anon'); } if(!r.ok) throw new Error('community'); return r.json(); })
    .then(s=>{ _rcTokens[id]=s.token; paintRouteCommunity(box, s); })
    .catch(()=>{});
}

function paintRouteCommunity(box, s){
  const rides=box.querySelector('[data-rc="rides"]'); if(rides) rides.textContent=`· ${tpl(D.ridesProgress||'{n} of {m} to verify', {n:s.rideCount, m:s.threshold})}`;
  const votes=box.querySelector('[data-rc="votes"]'); if(votes) votes.textContent=s.voteCount?`· ${tpl((s.voteCount===1?D.voteOne:D.voteMany)||`{n} vote${s.voteCount>1?'s':''}`, {n:s.voteCount})}`:'';
  if(s.iRode){ const b=box.querySelector('[data-rc-act="rode-it"]'); if(b){ b.textContent=`✓ ${D.youRode||'You rode this'}`; b.disabled=true; } }
  if(s.iVotedThisSeason){ const b=box.querySelector('[data-rc-act="vote"]'); if(b){ b.textContent=`✓ ${D.votedSeason||'Voted this season'}`; b.disabled=true; } }
}

// --- Community confirmations for non-votable utilities (water potability /
// "still here?"). Public counts, login to confirm — mirrors the route
// community loop but simpler (one toggle-able stance per rider). ---
const CC_CF_STANCES={
  potability:[['potable',`✓ ${D.potable||'Potable'}`],['not_potable',`✗ ${D.notPotable||'Not potable'}`]],
  existence:[['exists',`✓ ${D.confirmHere||'Confirm it’s here'}`]],
  // A surface segment: the positive stance is the same `exists` record, but
  // the words are "as described" — a road rarely leaves; what a rider vouches
  // for is the description they rode (owner-reported 2026-08-13). The "no" is
  // a stance of its own and may carry a note for the curators (the why box
  // below).
  accuracy:[['exists',`✓ ${D.asDescribed||'Yes'}`],
            ['not_as_described',`✗ ${D.notAsDescribed||'No'}`]],
};
// Negative stances: never verify, and they are the ones that may carry a
// "why" for the curators.
const CC_CF_NEGATIVE=new Set(['not_potable','not_as_described']);
export function hydrateItemConfirm(id){
  const box=document.querySelector(`.cc-cf[data-item="${id}"]`); if(!box) return;
  fetch(`/items/${id}/confirmations`, {credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(r=>{ if(!r.ok) throw new Error('confirm'); return r.json(); })
    .then(s=>paintItemConfirm(box, s))
    .catch(()=>{});   // enhancement only — never blocks the drawer
}
function paintItemConfirm(box, s){
  // Only the GET snapshot carries a token; the POST reply does not. Deriving
  // "is this rider signed in" from THIS payload alone therefore told a rider
  // who had just successfully confirmed to log in — with their own
  // confirmation already counted on screen. A cached token means the server
  // has already told us they may post.
  const id=box.getAttribute('data-item');
  if(s.token) _cfTokens[id]=s.token;
  const authed=!!_cfTokens[id];
  const defs=CC_CF_STANCES[s.stanceKind]||CC_CF_STANCES.existence;
  // Stop asking someone a question they have already answered: the heading
  // states the subject instead of putting the question again — being asked
  // "is the water drinkable?" over your own answer reads as though the answer
  // never landed.
  const heading = s.mine
    ? (s.stanceKind==='potability' ? (D.waterA||'Drinking water')
      : s.stanceKind==='accuracy' ? (D.surfaceCfA||'Surface')
      : (D.hereA||'Still here'))
    : (s.stanceKind==='potability' ? (D.waterQ||'Is the water drinkable?')
      : s.stanceKind==='accuracy' ? (D.surfaceCfQ||'Is it as described?')
      : (D.hereQ||'Is this still here?'));
  const btns=defs.map(([v,l])=>{
    const n=(s.stances&&s.stances[v])||0;
    const mine=s.mine===v?' is-mine':'';
    // The vouching is the button people should SEE (owner 2026-08-13): the
    // positive stance renders filled orange, the negative stays an outline.
    const primary=CC_CF_NEGATIVE.has(v)?'':' cf-yes';
    return `<button class="cc-cf-btn${primary}${mine}" data-cf-act="${v}"${authed?'':' disabled'}>${l} <span class="cc-cf-n">${n}</span></button>`;
  }).join('');
  const total = s.total ? `<span class="cc-cf-total">· ${tpl((s.total===1?D.confirmedOne:D.confirmedMany)||`{n} rider${s.total===1?'':'s'} confirmed`, {n:s.total})}</span>` : '';
  // The label of their own stance, for the "you answered" line behind the
  // toggle.
  const mineLabel = s.mine
    ? (defs.find(([v])=>v===s.mine)||[])[1] || s.mine
    : '';
  // Where they answered matters to exactly one reader: the person who added
  // the place and answered on the form. Told only "you answered", they would
  // wonder when they confirmed somewhere they have never been back to.
  const yours = s.mine
    ? `<div class="cc-cf-yours">${tpl(
        (s.mineSource === 'form' ? D.youAnsweredOnForm : D.youConfirmed)
          || (s.mineSource === 'form' ? 'You answered: {a} — when you added this place' : 'You answered: {a}'),
        {a:mineLabel})}</div>`
    : '';
  // Answered already? Then the panel is one line and a way back in: the
  // subject, how many riders have said something, and "change my answer".
  // What you answered, and the buttons to change it, appear only if you ask
  // for them — a rider who has already answered came to look at the place,
  // and everything shown to them by default is noise on top of it.
  // A "no" is a yes/no answer, nothing more (owner 2026-08-13, second pass):
  // what changed belongs in the EDIT FORM, which feeds the one moderation
  // pipeline — a comment box here would have been a second entry point to the
  // moderators. So a negative answer points at the edit button instead.
  // Two lines, LAST in the panel (owner 2026-08-13): the question, then the
  // action — sitting directly above the drawer's own "Edit this item" button
  // it points at.
  const useEdit = (s.mine && CC_CF_NEGATIVE.has(s.mine))
    ? `<div class="cc-cf-use-edit"><span>${D.cfUseEditQ||'Something changed?'}</span><span>${D.cfUseEdit||'Use ✎ Edit this item to correct the record.'}</span></div>`
    : '';
  box.querySelector('[data-cf-body]').innerHTML = s.mine
    ? `<div class="cc-cf-h">${heading} ${total}</div>
       <button type="button" class="cc-cf-change" data-cf-change>${D.changeAnswer||'Change my answer'}</button>
       <div class="cc-cf-more" hidden>${yours}<div class="cc-cf-row">${btns}</div></div>${useEdit}`
    : `<div class="cc-cf-h">${heading} ${total}</div><div class="cc-cf-row">${btns}</div>`;
  box.querySelector('.cc-cf-login').hidden = authed;
}

// Flag a picker the rider left on its placeholder: red border + focus + toast.
function warnPick(sel, msg){ if(sel){ sel.classList.add('cc-rc-invalid'); sel.focus(); } mapToast(msg); }
function rcPost(box, act){
  const id=box.dataset.route, token=_rcTokens[id];
  if(!token){ mapToast(D.toastLoginRate||'Please log in to rate routes.'); return; }
  const body=new URLSearchParams(); body.set('_token', token);
  // Bike type must be actively chosen (no default) — block + warn if empty.
  if(act==='rode-it'){
    const sel=box.querySelector('.cc-rc-rbike');
    if(!sel.value){ warnPick(sel, D.pickBikeRode||'Pick the bike type you rode it on first.'); return; }
    body.set('bike_type', sel.value);
  }
  if(act==='vote'){
    const sel=box.querySelector('.cc-rc-vbike');
    if(!sel.value){ warnPick(sel, D.pickBikeVote||'Pick a bike type to recommend it for first.'); return; }
    body.set('season', box.querySelector('.cc-rc-season').value); body.set('bike_type', sel.value);
  }
  if(act==='suggest'){
    body.set('reason', box.querySelector('.cc-rc-reason').value);
    body.set('note', box.querySelector('.cc-rc-note').value);
    const segs=_pickSegs[id]; if(segs && segs.length) body.set('segments', JSON.stringify(segs));
  }
  box.querySelectorAll('.cc-rc-btn').forEach(b=>b.disabled=true);
  fetch(`/routes/${id}/${act}`, {method:'POST', credentials:'same-origin',
    headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
    .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
    .then(s=>{
      box.querySelectorAll('.cc-rc-btn').forEach(b=>b.disabled=false);
      if(act==='suggest'){ delete _pickSegs[id]; const m=box.querySelector('[data-rc-marks]'); if(m) m.textContent=''; mapToast(D.toastCurator||'Thanks — a curator will review it.'); box.querySelector('.cc-rc-suggest').open=false; box.querySelector('.cc-rc-note').value=''; return; }
      paintRouteCommunity(box, s);
      if(act==='rode-it' && s.state==='verified' && box.dataset.state==='unverified'){ mapToast(D.toastVerified||'Verified — thanks for confirming this route!'); box.dataset.state='verified'; }
      else mapToast(D.toastRecorded||'Recorded — thanks!');
    })
    .catch(err=>{ box.querySelectorAll('.cc-rc-btn').forEach(b=>b.disabled=false); mapToast(err.message==='429'?(D.toastLimit||'Daily limit reached — try again tomorrow.'):(D.toastErr||'Could not record that — please try again.')); });
}

function hidePendingPin(id){
  const layer=layerByKey.pending; if(!layer) return;
  layer.features=layer.features.filter(f=>!(f.pending && String(f.pending.id)===String(id)));
  dropPendingFromSearch(id);   // keep the search index in step (W36)
  render();
}

// Stateless same-origin CSRF: the decision form carries a _token placeholder tied
// to the csrf-token cookie (HttpOnly → unreadable from JS). The map page renders no
// such form, so fetch one token from /moderate and reuse it (stable for the session);
// a failed decision clears it so the next attempt re-fetches a fresh one.
let _modToken;
function moderationToken(){
  // The decision CSRF token is emitted directly on the map page
  // (window.CC_MOD_TOKEN) — approval happens only here now, so there is no
  // /moderate decision form to scrape. Reject if it is somehow absent so the
  // caller's error state fires rather than a silent CSRF failure later.
  if(_modToken) return _modToken;
  _modToken = window.CC_MOD_TOKEN
    ? Promise.resolve(window.CC_MOD_TOKEN)
    : Promise.reject(new Error('moderation token not available'));
  return _modToken;
}
export function submitModeration(btn){
  const box=btn.closest('.cc-mod'); if(!box) return;
  const id=box.dataset.id, decision=btn.dataset.decision;
  const noteEl=box.querySelector('.cc-mod-note');
  const note=(noteEl||{}).value||'';
  // "Needs info" IS the question. Sent blank, the rider is told a curator
  // needs more information and nothing else, while the submission leaves the
  // map queue until they answer — so the note is required for this decision
  // only. The server refuses it too (ModerationService); this is here so the
  // cursor lands in the right box instead of a POST coming back 422.
  if('needs_info'===decision && !note.trim()){
    if(noteEl){ noteEl.classList.add('cc-mod-invalid'); noteEl.focus(); }
    mapToast(D.needsInfoNote||'Ask the rider what you need to know — a needs-info with no question tells them nothing.', {center:true});
    return;
  }
  // Per-photo decisions ride the SAME decide POST — no second endpoint, no
  // second mechanism (docs/specs/photo-uploads.md §5c).
  const rejected = Array.prototype.slice
    .call(box.querySelectorAll('.cc-mod-photo-cb'))
    .filter(cb => !cb.checked)
    .map(cb => cb.dataset.media);
  box.querySelectorAll('.cc-mod-btn').forEach(b=>b.disabled=true);
  moderationToken().then(token=>{
    const body=new URLSearchParams();
    body.set('moderation_decision[submission_id]', id);
    body.set('moderation_decision[decision]', decision);
    body.set('moderation_decision[note]', note);
    // Approve & confirm in one stroke (drawer checkbox): the server records
    // the curator's own confirmation after the approval, which verifies.
    const alsoCb=box.querySelector('.cc-mod-confirm-cb');
    if(alsoCb && alsoCb.checked && decision==='approve') body.set('moderation_decision[and_confirm]', '1');
    body.set('moderation_decision[media_reject]', JSON.stringify(rejected));
    body.set('moderation_decision[_token]', token);
    return fetch('/moderate/decide', { method:'POST', credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'},
      body:body.toString() });
  })
    .then(r=>{ if(!r.ok) throw new Error('decide'); return r.json(); })
    .then(res=>{
      hidePendingPin(id); closeDrawer();
      /* Tell the desk. A curator reviews on the map and comes back to a
         /moderate tab that has been open all along, which still lists the row
         they just decided — the data is correct and the page is a photograph of
         a minute ago (owner-reported 2026-08-12). Same-origin broadcast rather
         than a reload: the desk removes that one row and keeps the curator's
         place in a queue they may be halfway down. */
      try {
        if (typeof BroadcastChannel === 'function') {
          const ch = new BroadcastChannel('cc-moderation');
          ch.postMessage({ decided: Number(id), decision });
          ch.close();
        }
      } catch (e) { /* a browser without it simply keeps the old behaviour */ }
      /* An approved contribution takes the place of the pin that just went —
         and the curator stays on it. The response carries the item as the
         catalog's own shape, so the change lands on the map AND the drawer
         reopens showing the applied result, instead of closing on a toast and
         leaving the curator to reload to find out what they just did
         (owner-reported 2026-08-03).

         Two shapes because the map has two: a pool letter arrives as a GeoJSON
         feature, B · climbs as its own object. A · segments and K · routes send
         nothing and keep the old close-and-toast — their payloads are not
         rebuildable on this path. */
      let reopen = null;
      if(res.item && res.item.feature && LETTER_KEY[res.item.letter]){
        const key = LETTER_KEY[res.item.letter];
        addCuratedFeature(key, res.item.feature);
        const p = res.item.feature.properties || {};
        const g = (res.item.feature.geometry||{}).coordinates || [];
        reopen = () => openDrawer(layerByKey[key], osmDrawer(layerByKey[key], p, [g[1], g[0]], p.src));
      } else if(res.item && res.item.climb){
        // Replace in place so the drawer, the rail counts and the drawn line
        // all read the approved values; render() repaints from these features.
        const lyr = layerByKey['climbs'];
        if(lyr){
          const at = (lyr.features||[]).findIndex(f => String(f.id) === String(res.item.climb.id));
          if(at >= 0) lyr.features[at] = res.item.climb; else lyr.features.push(res.item.climb);
          render();
          reopen = () => openDrawer(lyr, res.item.climb);
        }
      }
      // After the repaint, so the drawer opens onto the feature the map is
      // now drawing rather than the one it was drawing a frame ago.
      if(reopen) setTimeout(reopen, 0);
      // Centred, like the other decision-weight confirmations: the drawer has
      // just closed and the pin has just gone, so a corner toast is easy to
      // miss — and needs-info says where the submission WENT, because the pin
      // leaving the map is otherwise indistinguishable from losing it.
      mapToast('needs_info'===decision
        ? tpl(D.decisionAsked||'Question sent to the rider · {ref} — it leaves the map until they answer, and waits in the desk queue.', {ref:res.reference})
        : tpl(D.decisionRecorded||'Decision recorded ({d}) · {ref}', {d:decision.replace('_',' '), ref:res.reference}),
        {center:true});
    })
    .catch(()=>{ _modToken=undefined; box.querySelectorAll('.cc-mod-btn').forEach(b=>b.disabled=false); mapToast(D.decisionErr||'Could not record the decision — please try again.', {center:true}); });
}
/* The shape of the pending submission whose drawer is open, so the delegated
   before/after handler above has something to draw. Set by the drawer when it
   renders a pending card; cleared when it closes. */
let _pendingShape = null;
export function setPendingShape(shape){ _pendingShape = shape || null; }

export function initCommunity(){
    // "Change my answer" reveals what you answered and the buttons to change it.
    document.addEventListener('click', e=>{
      const t=e.target.closest('[data-cf-change]'); if(!t) return;
      const more=t.parentElement.querySelector('.cc-cf-more'); if(more) more.hidden=false;
      t.hidden=true;
    });
    // Delegated: clicking a stance button records/switches it, then repaints.
    document.addEventListener('click', e=>{
      const btn=e.target.closest('[data-cf-act]'); if(!btn) return;
      const box=btn.closest('.cc-cf'); if(!box) return;
      const id=box.getAttribute('data-item'), token=_cfTokens[id];
      if(!token){ mapToast(D.osmLogin||'Please log in to confirm.'); return; }
      const body=new URLSearchParams(); body.set('_token', token); body.set('stance', btn.getAttribute('data-cf-act'));
      box.querySelectorAll('.cc-cf-btn').forEach(b=>b.disabled=true);
      fetch(`/items/${id}/confirm`, {method:'POST', credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
        .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(s=>{
          paintItemConfirm(box, s);
          /* The pin, not just the panel. When a press VERIFIES the item — a
             curator's does — the feature the map is drawing still carries the
             old "not confirmed yet" flag, because the catalog payload was
             fetched at page load. Setting it here and repainting is the
             difference between a decision that happened and one that appears
             not to have (owner-reported 2026-08-12). */
          if(s && s.verified) markItemVerified(id);
          mapToast(D.toastThanks||'Thanks — recorded.', {center:true});
        })
        .catch(()=>{ box.querySelectorAll('.cc-cf-btn').forEach(b=>b.disabled=false); mapToast(D.toastErr||'Could not record that — please try again.'); });
    });
    /* One-tap confirmation of an OSM place. It is not a confirmation yet — it
       is the submission that makes one possible, so the toast says "a curator
       will review it" rather than "recorded". Saying otherwise would promise a
       tally that does not exist until the item does. */
    document.addEventListener('click', e=>{
      const btn=e.target.closest('[data-osm-stance]'); if(!btn) return;
      const box=btn.closest('.cc-osmcf'); if(!box) return;
      // Two arms of one gesture: an OSM place we do not hold yet (POST creates
      // the item), and a place already ours (POST proposes an edit to it). The
      // rider presses the same word either way, so the handler is one.
      const ref=box.getAttribute('data-osm-ref');
      const itemId=box.getAttribute('data-item-cond');
      const url=itemId ? `/items/${encodeURIComponent(itemId)}/condition` : '/osm/confirm';
      const token = window.CC_CONFIRM_TOKEN;
      if(!token){ mapToast(D.toastLoginConfirm||'Please log in to confirm.'); return; }
      box.querySelectorAll('.cc-d-act').forEach(b=>b.disabled=true);
      const body=new URLSearchParams();
      body.set('_token', token); body.set('stance', btn.getAttribute('data-osm-stance'));
      if(!itemId) body.set('ref', ref);
      fetch(url, {method:'POST', credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'},
        body:body.toString()})
        .then(r=>r.json().then(j=>({ok:r.ok, status:r.status, j})))
        .then(({ok, status, j})=>{
          /* 409 is not a failure — somebody already sent this place, and saying
             "try again" would invite exactly the duplicate we just refused. */
          if(ok || 409===status){
            box.innerHTML=`<span class="cc-osmcf-done">${
              409===status ? (D.osmAlready||'Already sent — a curator is looking at it.')
                           : (D.osmSent||'Thanks — a curator will review it.')}</span>`;
            return;
          }
          throw new Error(String(status));
        })
        .catch(()=>{ box.querySelectorAll('.cc-d-act').forEach(b=>b.disabled=false);
                     mapToast(D.osmFailed||'That could not be sent — try again.'); });
    });
    // Delegated click handler for every community button (drawer is re-rendered often).
    document.addEventListener('click', e=>{
      const btn=e.target.closest('[data-rc-act]'); if(!btn) return;
      const box=btn.closest('.cc-rc'); if(!box) return;
      rcPost(box, btn.dataset.rcAct);
    });
    /* The moderation decision buttons, for exactly the same reason — and this
       is the one place where getting it wrong is expensive. They used to be
       bound per element in drawer.js right after the card was written; any
       later re-render of the drawer body replaced those nodes and the listener
       went with them. The card came back looking identical, with an Approve
       button that did nothing at all: no request, no toast, no error, nothing
       to tell a curator their click had not registered. It presented as
       intermittent, because whether it worked depended on whether a re-render
       happened to land between opening the drawer and pressing the button. */
    document.addEventListener('click', e=>{
      const btn=e.target.closest('.cc-mod-btn'); if(!btn) return;
      if(btn.disabled) return;         // a decision is already in flight
      submitModeration(btn);
    });
    /* Before/after for a proposed climb shape. Delegated for the same reason
       as everything else here, and it redraws the line on the MAP rather than
       changing anything in the card — coordinates in a text diff are not
       something a curator can review (pending-shape.js). */
    document.addEventListener('click', e=>{
      const btn=e.target.closest('.cc-shape-btn'); if(!btn || btn.disabled) return;
      const box=btn.closest('.cc-mod'); if(!box) return;
      const side=btn.dataset.shapeSide;
      const shape=_pendingShape;
      if(!shape) return;
      box.querySelectorAll('.cc-shape-btn').forEach(b=>{
        const on = b === btn;
        b.classList.toggle('on', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      showPendingShape(shape, side);
      // The switch moves the WHOLE review, not just the line: the item's own
      // rows repaint to the same side, so a curator compares values in place.
      renderPendingContext(side);
      box.classList.toggle('is-before', side === 'before');
    });
    // Clear the "must pick a bike type" warning as soon as the rider chooses one.
    document.addEventListener('change', e=>{ const s=e.target.closest('.cc-rc-invalid'); if(s) s.classList.remove('cc-rc-invalid'); });
    // Same for the "needs info needs a question" mark — as soon as one is typed.
    document.addEventListener('input', e=>{ const n=e.target.closest('.cc-mod-note.cc-mod-invalid'); if(n && n.value.trim()) n.classList.remove('cc-mod-invalid'); });
}

// Curator keyboard: it arms a moderation decision, so it belongs with the
// moderation submit rather than in the entry (§4.2 — the entry keeps the CALL,
// not the handler).
export function initCuratorKeys(){
    // curator keyboard: A approve / R reject when a pending drawer is open — plain
    // keys only (never on Ctrl/Cmd/Alt combos, e.g. Ctrl+R reload). Pressing a key
    // ARMS the decision and focuses the note (it no longer submits instantly —
    // the drawer used to close before a note could be typed); Enter inside the
    // note sends the armed decision (Shift+Enter keeps inserting a newline).
    // Mouse clicks on the buttons submit immediately, as before.
    document.addEventListener('keydown', e=>{
      if(e.ctrlKey||e.metaKey||e.altKey) return;
      const t=e.target;
      const box=document.querySelector('#drawer.open .cc-mod'); if(!box) return;
      if(t && (t.tagName==='INPUT'||t.tagName==='TEXTAREA'||t.tagName==='SELECT'||t.isContentEditable)){
        if(e.key==='Enter' && !e.shiftKey && t.classList && t.classList.contains('cc-mod-note')){
          const armed=box.querySelector('.cc-mod-btn.armed');
          if(armed){ e.preventDefault(); armed.click(); }
        }
        return;
      }
      const arm=cls=>{
        const b=box.querySelector('.cc-mod-btn.'+cls); if(!b) return;
        box.querySelectorAll('.cc-mod-btn').forEach(x=>x.classList.toggle('armed', x===b));
        const n=box.querySelector('.cc-mod-note'); if(n) n.focus();
      };
      if(e.key==='a'||e.key==='A'){ e.preventDefault(); arm('approve'); }
      if(e.key==='r'||e.key==='R'){ e.preventDefault(); arm('reject'); }
    });
}
