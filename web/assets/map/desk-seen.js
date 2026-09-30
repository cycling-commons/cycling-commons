// SPDX-License-Identifier: AGPL-3.0-only
/* The map drawer opened a desk item (moderation-and-contribution.md §5.2f): a
   pending submission, or a Data finding resolved here. One small POST to
   /moderate/seen with the curator's token (window.CC_SEEN_TOKEN, printed for
   curators only), once per item per page, and the desk lists show that row
   without its unseen bar from then on. No token, no post: a rider's own
   pending pin opens nothing on any desk. A refused or failed post is tried
   again the next time the item opens.

   unseenMark() is the same bar on a list the map draws: a search row for a
   pending submission this curator has not opened. */

const _sent = new Set();

export function markOpened(kind, id, deps = {}) {
  const token = 'token' in deps ? deps.token : (typeof window !== 'undefined' ? window.CC_SEEN_TOKEN : null);
  const doFetch = deps.fetch || (typeof fetch === 'function' ? fetch : null);
  const key = kind + ':' + id;
  if (!token || !doFetch || !/^[1-9]\d*$/.test(String(id)) || _sent.has(key)) return Promise.resolve(false);
  _sent.add(key);
  const body = new URLSearchParams();
  body.set('_token', token);
  body.set('type', kind);
  body.set('id', String(id));
  return doFetch('/moderate/seen', {
    method: 'POST', credentials: 'same-origin',
    headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString(),
  })
    .then(r => (r.ok ? r.json() : null))
    .then(d => { if (!d) _sent.delete(key); return !!(d && d.seen); })
    .catch(() => { _sent.delete(key); return false; });
}

const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/* The row's class attribute and the screen-reader words for a search hit:
   only a pending submission the payload marks unopened carries them. */
export function unseenMark(hit, words) {
  const p = hit && hit.pend && hit.modeF && hit.modeF.pending;
  if (!p || !p.unseen) return { attr: '', note: '' };
  return { attr: ' class="is-unseen"', note: `<span class="unseen-note">${esc(words)}</span>` };
}
