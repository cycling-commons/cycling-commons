// SPDX-License-Identifier: AGPL-3.0-only
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
    /* The areas inside onboarded countries. Without them the box answered a
       rider's own region with silence: somebody types "Ohio", the list knows
       only country names, and the heading above it has just invited them to
       name a region (owner 2026-09-13). */
    const areaSrc = document.getElementById('cc-areas');
    const areas = areaSrc ? JSON.parse(areaSrc.textContent) : [];
    const href = cc => joinHref.replace(/ZZ(?=[^/]*$)/, cc);

    q.addEventListener('input', () => {
      const needle = q.value.trim().toLowerCase();
      if (needle.length < 2) { hits.innerHTML = ''; return; }

      /* Countries first: somebody typing "Ireland" means the country, and a
         subdivision that happens to share the name must not outrank it. */
      const countries = data.filter(c => c.name.toLowerCase().includes(needle)).slice(0, 6);
      const matched = areas.filter(a => a.name.toLowerCase().includes(needle)).slice(0, 6);

      /* A country already on the Commons still says so, and still leads to its
         join page rather than to its card in the list above: a country being
         here does not mean every part of it is, and that page is where a rider
         names the area nobody covers. */
      const countryRows = countries.map(c => {
        const mark = c.here ? ` <span class="cc-here">${esc(hereLabel)}</span>` : '';
        return `<a role="option" href="${href(c.code)}">${esc(c.name)} <span class="cc">${esc(c.code)}</span>${mark}</a>`;
      });

      /* The area travels in the URL so the form on the far side opens with it
         already filled in: a rider who has typed "Ohio" once should not be
         asked to type it again on the next screen. */
      const areaRows = matched.map(a =>
        `<a role="option" href="${href(a.cc)}?area=${encodeURIComponent(a.name)}">${esc(a.name)} <span class="cc">${esc(a.country)}</span></a>`);

      hits.innerHTML = countryRows.concat(areaRows).join('');
    });
  });
