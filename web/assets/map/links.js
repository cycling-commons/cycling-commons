// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Outbound links: one row per destination, locale-resolved, https-only.
   @see docs/specs/catalog-data-model.md §7 */
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
    // docs/specs/catalog-data-model.md §7 — show the destination domain.
    out.push({
      label: typeof entry.label === 'string' && entry.label.trim() ? entry.label.trim() : domain,
      href: pick.url,
      domain,
    });
  }
  return out;
}
