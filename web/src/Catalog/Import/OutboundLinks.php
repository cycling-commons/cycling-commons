<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Import;

/**
 * Shape gate for an item's outbound links (docs/TODO.md, owner scope
 * 2026-08-14: several different SITES, each possibly in several languages).
 *
 * Two levels, deliberately:
 *
 *   links: [ { label?, urls: [ { url, locale? }, ... ] }, ... ]
 *
 * One entry per destination in display order; the urls inside an entry are
 * the same page in different languages. A flat {url, locale} list conflates
 * destination and translation - three sites in four languages would be
 * twelve rows with no way to say which four are the same page.
 *
 * The caps ARE the anti-spam design (an item with thirty links is an
 * advert, and a cap is cheaper than moderating one afterwards): MAX_ENTRIES
 * destinations, MAX_URLS_PER_ENTRY language variants each, and the same
 * host may not fill the list (MAX_ENTRIES_PER_HOST). https only - these
 * values end in <a href> on the public map. The client mirror lives in
 * web/assets/map/links.js.
 *
 * @api Used by every path that writes a `links` attribute.
 */
final class OutboundLinks
{
    public const int MAX_ENTRIES = 4;
    public const int MAX_URLS_PER_ENTRY = 6;
    public const int MAX_ENTRIES_PER_HOST = 2;
    public const int MAX_LABEL_LENGTH = 40;
    /**
     * The locales a url may be tagged with: the site's own set.
     *
     * Public since 2026-08-16 so the wizard's editor builds its language picker
     * from the same list the validator refuses everything else against - two
     * copies of this would drift the day a sixth language is added, and the
     * drift would show up as a rider's saved link silently rejected.
     */
    public const array LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

    /**
     * @throws \InvalidArgumentException naming the first violation
     */
    public static function assertValid(mixed $links): void
    {
        if (!\is_array($links) || !array_is_list($links)) {
            throw new \InvalidArgumentException('links must be a list of {label?, urls: [{url, locale?}]} entries');
        }
        if (\count($links) > self::MAX_ENTRIES) {
            throw new \InvalidArgumentException(sprintf('links: at most %d destinations per item', self::MAX_ENTRIES));
        }
        /** @var array<string, int> $perHost */
        $perHost = [];
        foreach ($links as $i => $entry) {
            if (!\is_array($entry) || !\is_array($entry['urls'] ?? null) || !array_is_list($entry['urls'])) {
                throw new \InvalidArgumentException(sprintf('links[%d]: entry needs a urls list', $i));
            }
            if (isset($entry['label']) && (!\is_string($entry['label']) || mb_strlen($entry['label']) > self::MAX_LABEL_LENGTH)) {
                throw new \InvalidArgumentException(sprintf('links[%d]: label must be a string of at most %d characters', $i, self::MAX_LABEL_LENGTH));
            }
            if (isset($entry['label']) && 'official site' === strtolower(trim($entry['label']))) {
                // ONE storage slot per fact (owner 2026-08-16): the official
                // website lives in the editable `web` attribute, never as a
                // links entry - two fields for one fact means the rider can
                // only edit one of them.
                throw new \InvalidArgumentException(sprintf('links[%d]: the official site belongs in the web attribute, not in links', $i));
            }
            if ([] !== array_diff(array_keys($entry), ['label', 'urls'])) {
                throw new \InvalidArgumentException(sprintf('links[%d]: only label and urls are allowed', $i));
            }
            $urls = $entry['urls'];
            if ([] === $urls || \count($urls) > self::MAX_URLS_PER_ENTRY) {
                throw new \InvalidArgumentException(sprintf('links[%d]: between 1 and %d urls per destination', $i, self::MAX_URLS_PER_ENTRY));
            }
            $entryHosts = [];
            foreach ($urls as $j => $u) {
                if (!\is_array($u) || !\is_string($u['url'] ?? null)) {
                    throw new \InvalidArgumentException(sprintf('links[%d].urls[%d]: needs a url string', $i, $j));
                }
                if ([] !== array_diff(array_keys($u), ['url', 'locale'])) {
                    throw new \InvalidArgumentException(sprintf('links[%d].urls[%d]: only url and locale are allowed', $i, $j));
                }
                if (isset($u['locale']) && !\in_array($u['locale'], self::LOCALES, true)) {
                    throw new \InvalidArgumentException(sprintf('links[%d].urls[%d]: unknown locale', $i, $j));
                }
                $host = strtolower((string) parse_url($u['url'], \PHP_URL_HOST));
                if ('' === $host || !str_starts_with($u['url'], 'https://')) {
                    // https only: these values end in <a href> on the public map.
                    throw new \InvalidArgumentException(sprintf('links[%d].urls[%d]: https:// urls only', $i, $j));
                }
                if (mb_strlen($u['url']) > 500) {
                    throw new \InvalidArgumentException(sprintf('links[%d].urls[%d]: url too long', $i, $j));
                }
                $entryHosts[preg_replace('/^www\./', '', $host) ?? $host] = true;
            }
            foreach (array_keys($entryHosts) as $host) {
                $perHost[$host] = ($perHost[$host] ?? 0) + 1;
                if ($perHost[$host] > self::MAX_ENTRIES_PER_HOST) {
                    // The same-host rule: one company may not fill the list.
                    // Wikipedia's language subdomains are distinct hosts, so a
                    // sitelink entry never trips this.
                    throw new \InvalidArgumentException(sprintf('links: at most %d destinations from %s', self::MAX_ENTRIES_PER_HOST, $host));
                }
            }
        }
    }
}
