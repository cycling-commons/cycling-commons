// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Outbound links an item carries to the pages that describe it (an official
   site, a Wikipedia article), stored as TWO levels:

     links: [ { label?, urls: [ { url, locale? }, … ] }, … ]

   One entry per DESTINATION, in display order; the urls inside an entry are
   the same page in different languages. A flat list cannot say "these four
   are the same page", which is exactly what locale resolution needs.

   The drawer shows EVERY entry, each resolved to the reader's locale where a
   variant exists: exact locale, else the entry's locale-less default, else
   English, else the first url — an entry with no variant for the reader
   still appears rather than vanishing. https-only is enforced at every
   layer, this one included: a non-https url is dropped here even if one
   slipped past the server, because these hrefs land in <a> tags.

   Caps mirror the server's OutboundLinks (an item with thirty links is an
   advert): entries beyond MAX_ENTRIES are not rendered. */
export const LINKS_MAX_ENTRIES = 4;

/** @returns {Array<{label: string, href: string, domain: string}>} */
export function itemLinks(raw, locale) {
  let links = raw;
  if (typeof links === 'string') {
    try { links = JSON.parse(links); } catch { return []; }
  }
  if (!Array.isArray(links)) return [];
  const out = [];
  for (const entry of links.slice(0, LINKS_MAX_ENTRIES)) {
    if (!entry || !Array.isArray(entry.urls)) continue;
    const urls = entry.urls.filter(u => u && typeof u.url === 'string' && /^https:\/\//i.test(u.url));
    if (!urls.length) continue;
    const pick = urls.find(u => u.locale === locale)
      || urls.find(u => !u.locale)
      || urls.find(u => u.locale === 'en')
      || urls[0];
    let domain = '';
    try { domain = new URL(pick.url).hostname.replace(/^www\./, ''); } catch { continue; }
    // The bare domain is shown beside the label on purpose (the cheapest of
    // the moderator-safety layers, and just as useful to riders): where a
    // link goes should be readable without hovering.
    out.push({
      label: typeof entry.label === 'string' && entry.label.trim() ? entry.label.trim() : domain,
      href: pick.url,
      domain,
    });
  }
  return out;
}
