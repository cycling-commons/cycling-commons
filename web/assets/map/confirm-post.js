// SPDX-License-Identifier: AGPL-3.0-only
/* The drawer's Confirm POST, with one recovery from a stale token.
   A tab opened before the session changed holds a dead CSRF token; the server
   answers 403 {error:'invalid_token'}. Resending the same token can never work,
   so ask for a fresh one (the GET snapshot carries it) and resend once. No
   fresh token, or a 401, means the rider is signed out: say so, instead of
   "please try again". Resolves {ok:true, data} or {ok:false, reason:'login'|'error'}. */

function send(fetch, id, stance, token) {
  const body = new URLSearchParams();
  body.set('_token', token);
  body.set('stance', stance);
  return fetch(`/items/${id}/confirm`, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString(),
  });
}

async function staleToken(r) {
  if (r.status !== 403) return false;
  try { return (await r.json()).error === 'invalid_token'; } catch { return false; }
}

export async function postConfirm({ id, stance, token, fetch, refreshToken }) {
  try {
    let r = await send(fetch, id, stance, token);
    if (await staleToken(r)) {
      const fresh = await refreshToken(id);
      if (!fresh) return { ok: false, reason: 'login' };
      r = await send(fetch, id, stance, fresh);
    }
    if (r.ok) return { ok: true, data: await r.json() };
    return { ok: false, reason: r.status === 401 ? 'login' : 'error' };
  } catch {
    return { ok: false, reason: 'error' };
  }
}
