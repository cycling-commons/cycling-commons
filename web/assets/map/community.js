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
import { layerByKey } from './catalog.js';
import { render } from './render.js';
import { mapToast, closeDrawer } from './drawer.js';
import { _pickSegs } from './picking.js';
import { dropPendingFromSearch } from './search-ui.js';

// Route community loop (spec §7). One authenticated fetch on drawer-open
// carries counts + my-state + a stateless CSRF token; the three POSTs reuse it.
const CC_BIKES=['Road','Gravel','MTB','E-bike','Handbike','Recumbent','Trike','Tandem'];
const CC_SEASONS=['spring','summer','autumn','winter'];
const CC_REASONS=[['broken-track',D.reasonBroken||'Wrong / broken track'],['trim-privacy',D.reasonPrivacy||'Trim a private start/end'],['duplicate',D.reasonDuplicate||'Duplicate of another route'],['not-rideable',D.reasonNotRideable||'Not actually rideable'],['other',D.reasonOther||'Something else']];
const _rcTokens={};   // route id → CSRF token from the last snapshot

// Votable point types (climbs/stays/scenic/history) carry the vote CTA;
// routes (experience) have their own vote block. Non-votable UTILITIES are
// confirmed, not voted: water carries a potable/not-potable judgement, the
// rest a plain "still here?" confirmation. Keys match the CATALOG layer keys.
export const CC_VOTABLE=new Set(['climbs','stays','scenic','history']);
export const CC_CONFIRMABLE=new Set(['water','services','hazards','transit','shelter']);
const _cfTokens={};   // item id → CSRF token from the last confirmations snapshot

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
};
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
  const heading = s.stanceKind==='potability'
    ? (D.waterQ||'Is the water drinkable?') : (D.hereQ||'Is this still here?');
  const btns=defs.map(([v,l])=>{
    const n=(s.stances&&s.stances[v])||0;
    const mine=s.mine===v?' is-mine':'';
    return `<button class="cc-cf-btn${mine}" data-cf-act="${v}"${authed?'':' disabled'}>${l} <span class="cc-cf-n">${n}</span></button>`;
  }).join('');
  const total = s.total ? `<span class="cc-cf-total">· ${tpl((s.total===1?D.confirmedOne:D.confirmedMany)||`{n} rider${s.total===1?'':'s'} confirmed`, {n:s.total})}</span>` : '';
  // Once a rider HAS answered, stop rendering a question at them. The buttons
  // stay so they can change their mind; the line above says where they stand.
  const mineLabel = s.mine
    ? (defs.find(([v])=>v===s.mine)||[])[1] || s.mine
    : '';
  const yours = s.mine
    ? `<div class="cc-cf-yours">${tpl(D.youConfirmed||'You answered: {a}', {a:mineLabel})}</div>`
    : '';
  box.querySelector('[data-cf-body]').innerHTML =
    `<div class="cc-cf-h">${heading} ${total}</div><div class="cc-cf-row">${btns}</div>${yours}`;
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
    body.set('moderation_decision[media_reject]', JSON.stringify(rejected));
    body.set('moderation_decision[_token]', token);
    return fetch('/moderate/decide', { method:'POST', credentials:'same-origin',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'},
      body:body.toString() });
  })
    .then(r=>{ if(!r.ok) throw new Error('decide'); return r.json(); })
    .then(res=>{
      hidePendingPin(id); closeDrawer();
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
export function initCommunity(){
    // Delegated: clicking a stance button records/switches it, then repaints.
    document.addEventListener('click', e=>{
      const btn=e.target.closest('[data-cf-act]'); if(!btn) return;
      const box=btn.closest('.cc-cf'); if(!box) return;
      const id=box.getAttribute('data-item'), token=_cfTokens[id];
      if(!token){ mapToast(D.toastLoginConfirm||'Please log in to confirm.'); return; }
      const body=new URLSearchParams(); body.set('_token', token); body.set('stance', btn.getAttribute('data-cf-act'));
      box.querySelectorAll('.cc-cf-btn').forEach(b=>b.disabled=true);
      fetch(`/items/${id}/confirm`, {method:'POST', credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
        .then(r=>{ if(!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(s=>{ paintItemConfirm(box, s); mapToast(D.toastThanks||'Thanks — recorded.', {center:true}); })
        .catch(()=>{ box.querySelectorAll('.cc-cf-btn').forEach(b=>b.disabled=false); mapToast(D.toastErr||'Could not record that — please try again.'); });
    });
    // Delegated click handler for every community button (drawer is re-rendered often).
    document.addEventListener('click', e=>{
      const btn=e.target.closest('[data-rc-act]'); if(!btn) return;
      const box=btn.closest('.cc-rc'); if(!box) return;
      rcPost(box, btn.dataset.rcAct);
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
