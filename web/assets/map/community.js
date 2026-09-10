// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Route community and utility confirmations; curator decide POST.
   @see docs/specs/route-domain.md §6; docs/specs/moderation-and-contribution.md §10 */
import { I18N, D, tpl, CC_SEASON_LABEL } from './i18n.js';
import { CATALOG, layerByKey, LETTER_KEY } from './catalog.js';
import { render } from './render.js';
import { mapToast, closeDrawer, openDrawer, osmDrawer, renderPendingContext } from './drawer.js';
import { _pickSegs } from './picking.js';
import { dropPendingFromSearch } from './search-ui.js';
import { addCuratedFeature } from './osm-pools.js';
import { showPendingShape } from './pending-shape.js';

const CC_BIKES=['Road','Gravel','MTB','E-bike','Handbike','Recumbent','Trike','Tandem'];
const CC_SEASONS=['spring','summer','autumn','winter'];
const CC_REASONS=[['broken-track',D.reasonBroken||'Wrong / broken track'],['trim-privacy',D.reasonPrivacy||'Trim a private start/end'],['duplicate',D.reasonDuplicate||'Duplicate of another route'],['not-rideable',D.reasonNotRideable||'Not actually rideable'],['other',D.reasonOther||'Something else']];
const _rcTokens={};

export const CC_VOTABLE=new Set(['climbs','stays','scenic','history']);
export const CC_CONFIRMABLE=new Set(['water','services','hazards','transit','shelter','toilets','scenic','history','stays','climbs','surface']);
export const CC_BREAKABLE=new Set(['water','services','toilets']);

const _cfTokens={};

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
  const bikePickOpts=`<option value="" selected disabled>${D.bikeTypePh||'Bike type…'}</option>`+bikeOpts;
  const seasonOpts=CC_SEASONS.map(s=>`<option value="${s}">${CC_SEASON_LABEL[s]||s[0].toUpperCase()+s.slice(1)}</option>`).join('');
  const reasonOpts=CC_REASONS.map(([v,l])=>`<option value="${v}">${l}</option>`).join('');
  // docs/specs/route-domain.md §6 — vote only for verified routes; rode-it for both.
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

export function hydrateRouteCommunity(id){
  const box=document.querySelector(`.cc-rc[data-route="${id}"]`); if(!box) return;
  const segs=_pickSegs[id];
  if(segs && segs.length){
    const m=box.querySelector('[data-rc-marks]');
    if(m) m.textContent = tpl((segs.length===1?D.marksOne:D.marksMany)||`· {n} stretch${segs.length===1?'':'es'} marked`, {n:segs.length});
  }
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

const CC_CF_STANCES={
  potability:[['potable',`✓ ${D.potable||'Potable'}`],['not_potable',`✗ ${D.notPotable||'Not potable'}`]],
  existence:[['exists',`✓ ${D.confirmHere||'Confirm it’s here'}`]],
  accuracy:[['exists',`✓ ${D.asDescribed||'Yes'}`],
            ['not_as_described',`✗ ${D.notAsDescribed||'No'}`]],
};
const CC_CF_NEGATIVE=new Set(['not_potable','not_as_described']);
export function hydrateItemConfirm(id){
  const box=document.querySelector(`.cc-cf[data-item="${id}"]`); if(!box) return;
  fetch(`/items/${id}/confirmations`, {credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(r=>{ if(!r.ok) throw new Error('confirm'); return r.json(); })
    .then(s=>paintItemConfirm(box, s))
    .catch(()=>{});
}
function paintItemConfirm(box, s){
  // Token lives on the GET snapshot, not the POST reply — cache it or a just-confirmed rider is told to log in.
  const id=box.getAttribute('data-item');
  if(s.token) _cfTokens[id]=s.token;
  const authed=!!_cfTokens[id];
  const defs=CC_CF_STANCES[s.stanceKind]||CC_CF_STANCES.existence;
  const heading = s.mine
    ? (s.stanceKind==='potability' ? (D.waterA||'Drinking water')
      : s.stanceKind==='accuracy' ? (D.surfaceCfA||'Surface')
      : (D.hereA||'Still here'))
    : (s.stanceKind==='potability' ? (D.waterQ||'Is the water drinkable?')
      : s.stanceKind==='accuracy' ? (D.surfaceCfQ||'Is it as described?')
      : (D.hereQ||'Is this still here?'));
  const btns=defs.map(([v,l])=>{
    const mine=s.mine===v?' is-mine':'';
    const primary=CC_CF_NEGATIVE.has(v)?'':' cf-yes';
    return `<button class="cc-cf-btn${primary}${mine}" data-cf-act="${v}"${authed?'':' disabled'}>${l}</button>`;
  }).join('');
  /* A curator's word settles the state on its own, so the tally names that
     witness instead of counting heads (owner 2026-09-10). The count still
     shows when riders stand behind it too: the curator is the stronger
     evidence, not the only evidence. */
  const tally = !s.total ? ''
    : s.byCurator
      ? (s.total === 1
          ? (D.confirmedCurator || 'A curator confirmed')
          : tpl(D.confirmedCuratorMany || '{n} confirmed, one a curator', {n: s.total}))
      : tpl((s.total===1?D.confirmedOne:D.confirmedMany) || `{n} rider${s.total===1?'':'s'} confirmed`, {n: s.total});
  const total = tally ? `<span class="cc-cf-total">· ${tally}</span>` : '';
  const mineLabel = s.mine
    ? (defs.find(([v])=>v===s.mine)||[])[1] || s.mine
    : '';
  const yours = s.mine
    ? `<div class="cc-cf-yours">${tpl(
        (s.mineSource === 'form' ? D.youAnsweredOnForm : D.youConfirmed)
          || (s.mineSource === 'form' ? 'You answered: {a} — when you added this place' : 'You answered: {a}'),
        {a:mineLabel})}</div>`
    : '';
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

function warnPick(sel, msg){ if(sel){ sel.classList.add('cc-rc-invalid'); sel.focus(); } mapToast(msg); }
function rcPost(box, act){
  const id=box.dataset.route, token=_rcTokens[id];
  if(!token){ mapToast(D.toastLoginRate||'Please log in to rate routes.'); return; }
  const body=new URLSearchParams(); body.set('_token', token);
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
  dropPendingFromSearch(id);
  render();
}

let _modToken;
function moderationToken(){
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
  if('needs_info'===decision && !note.trim()){
    if(noteEl){ noteEl.classList.add('cc-mod-invalid'); noteEl.focus(); }
    mapToast(D.needsInfoNote||'Ask the rider what you need to know — a needs-info with no question tells them nothing.', {center:true});
    return;
  }
  // docs/specs/photo-uploads.md §5c — per-photo decisions ride the same decide POST.
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
      try {
        if (typeof BroadcastChannel === 'function') {
          const ch = new BroadcastChannel('cc-moderation');
          ch.postMessage({ decided: Number(id), decision });
          ch.close();
        }
      } catch (e) { /* no BroadcastChannel: keep old behaviour */ }
      let reopen = null;
      if(res.item && res.item.feature && LETTER_KEY[res.item.letter]){
        const key = LETTER_KEY[res.item.letter];
        addCuratedFeature(key, res.item.feature);
        const p = res.item.feature.properties || {};
        const g = (res.item.feature.geometry||{}).coordinates || [];
        reopen = () => openDrawer(layerByKey[key], osmDrawer(layerByKey[key], p, [g[1], g[0]], p.src));
      } else if(res.item && res.item.climb){
        const lyr = layerByKey['climbs'];
        if(lyr){
          const at = (lyr.features||[]).findIndex(f => String(f.id) === String(res.item.climb.id));
          if(at >= 0) lyr.features[at] = res.item.climb; else lyr.features.push(res.item.climb);
          render();
          reopen = () => openDrawer(lyr, res.item.climb);
        }
      }
      if(reopen) setTimeout(reopen, 0);
      mapToast('needs_info'===decision
        ? tpl(D.decisionAsked||'Question sent to the rider · {ref} — it leaves the map until they answer, and waits in the desk queue.', {ref:res.reference})
        : tpl(D.decisionRecorded||'Decision recorded ({d}) · {ref}', {d:decision.replace('_',' '), ref:res.reference}),
        {center:true});
    })
    .catch(()=>{ _modToken=undefined; box.querySelectorAll('.cc-mod-btn').forEach(b=>b.disabled=false); mapToast(D.decisionErr||'Could not record the decision — please try again.', {center:true}); });
}
let _pendingShape = null;
export function setPendingShape(shape){ _pendingShape = shape || null; }

export function initCommunity(){
    // Delegated: the drawer body is re-rendered often; per-element listeners do not survive.
    document.addEventListener('click', e=>{
      const t=e.target.closest('[data-cf-change]'); if(!t) return;
      const more=t.parentElement.querySelector('.cc-cf-more'); if(more) more.hidden=false;
      t.hidden=true;
    });
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
          if(s && s.verified) markItemVerified(id);
          mapToast(D.toastThanks||'Thanks — recorded.', {center:true});
        })
        .catch(()=>{ box.querySelectorAll('.cc-cf-btn').forEach(b=>b.disabled=false); mapToast(D.toastErr||'Could not record that — please try again.'); });
    });
    document.addEventListener('click', e=>{
      const btn=e.target.closest('[data-osm-stance]'); if(!btn) return;
      const box=btn.closest('.cc-osmcf'); if(!box) return;
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
          if(ok || 409===status){
            // A curator's own tap applied at once (moderation-and-contribution.md
            // §1.6): say so, and draw the served pin the reply carries.
            const applied = ok && j && j.applied;
            box.innerHTML=`<span class="cc-osmcf-done">${
              409===status ? (D.osmAlready||'Already sent — a curator is looking at it.')
              : applied ? (j.verified ? (D.osmAppliedVerified||'On the map, confirmed by you.') : (D.osmApplied||'On the map. Nobody has confirmed it yet.'))
              : (D.osmSent||'Thanks — a curator will review it.')}</span>`;
            if(applied && j.item && j.item.feature && LETTER_KEY[j.item.letter]){
              addCuratedFeature(LETTER_KEY[j.item.letter], j.item.feature);
            }
            return;
          }
          throw new Error(String(status));
        })
        .catch(()=>{ box.querySelectorAll('.cc-d-act').forEach(b=>b.disabled=false);
                     mapToast(D.osmFailed||'That could not be sent — try again.'); });
    });
    document.addEventListener('click', e=>{
      const btn=e.target.closest('[data-rc-act]'); if(!btn) return;
      const box=btn.closest('.cc-rc'); if(!box) return;
      rcPost(box, btn.dataset.rcAct);
    });
    document.addEventListener('click', e=>{
      const btn=e.target.closest('.cc-mod-btn'); if(!btn) return;
      if(btn.disabled) return;
      submitModeration(btn);
    });
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
      renderPendingContext(side);
      box.classList.toggle('is-before', side === 'before');
    });
    document.addEventListener('change', e=>{ const s=e.target.closest('.cc-rc-invalid'); if(s) s.classList.remove('cc-rc-invalid'); });
    document.addEventListener('input', e=>{ const n=e.target.closest('.cc-mod-note.cc-mod-invalid'); if(n && n.value.trim()) n.classList.remove('cc-mod-invalid'); });
}

export function initCuratorKeys(){
    // A/R arms Approve/Reject and focuses the note; Enter in the note submits. Never on Ctrl/Cmd/Alt.
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
