// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Country typeahead on /regions: filter the baked localized country list and
   link to /join/{cc}.

   A file rather than an inline block, so the page carries no CSP nonce and can
   be held in a shared cache (docs/specs/page-caching.md §3.2). The country
   list still ships with the page, in the #cc-countries JSON block; only the
   behaviour moved. */
  /* Country typeahead: filter the baked localized country list, link to /join/{cc}.
     Keyboard: results are plain links — Tab/Enter work natively. */
  document.addEventListener('DOMContentLoaded', () => {
    /* The block carries the list as its text and the two server-side values as
       attributes. Those two are why this used to have to be inline: a file
       cannot hold a translation or a generated URL. */
    const src = document.getElementById('cc-countries');
    const data = JSON.parse(src.textContent);
    const q = document.getElementById('cc-country-q');
    const hits = document.getElementById('cc-country-hits');
    // 'ZZ' satisfies the route's [A-Za-z]{2} requirement at generation time and
    // is a user-assigned ISO code no real country will ever use; the replace
    // targets only the final path segment.
    const joinHref = src.dataset.joinHref;
    const hereLabel = src.dataset.hereLabel;
    const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    q.addEventListener('input', () => {
      const needle = q.value.trim().toLowerCase();
      if (needle.length < 2) { hits.innerHTML = ''; return; }
      const top = data.filter(c => c.name.toLowerCase().includes(needle)).slice(0, 8);
      /* A country already on the Commons says so and jumps to its own place in
         the list above, rather than offering to request what is already there. */
      hits.innerHTML = top.map(c => {
        const href = c.here ? ('#country-' + c.code) : joinHref.replace(/ZZ(?=[^/]*$)/, c.code);
        const mark = c.here ? ` <span class="cc-here">${esc(hereLabel)}</span>` : '';
        return `<a role="option" href="${href}">${esc(c.name)} <span class="cc">${esc(c.code)}</span>${mark}</a>`;
      }).join('');
    });
  });
