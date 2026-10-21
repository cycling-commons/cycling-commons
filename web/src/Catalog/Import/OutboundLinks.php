<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Import;

/**
 * Shape gate for outbound `links`. https only. Official-site label is refused — that fact has the `web` slot.
 *
 * @see docs/specs/catalog-data-model.md §7
 *
 * @api
 */
final class OutboundLinks
{
    public const int MAX_ENTRIES = 4;
    public const int MAX_URLS_PER_ENTRY = 6;
    public const int MAX_ENTRIES_PER_HOST = 2;
    public const int MAX_LABEL_LENGTH = 40;
    /**
     * Every built language a url may be tagged with. Deliberately all of
     * them, not only the served ones: imported links and links saved while a
     * language was served keep their tag, and an edit of the item posts them
     * back unchanged. The improve form's picker offers only the languages
     * this deployment serves ({@see \App\Routing\Languages::options()}).
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
                // docs/specs/catalog-data-model.md §7 — official site is the `web` slot, never a links entry.
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
                    // https only: these values become <a href> on the public map.
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
                    // One host may not fill the list. Wikipedia language subdomains are distinct hosts.
                    throw new \InvalidArgumentException(sprintf('links: at most %d destinations from %s', self::MAX_ENTRIES_PER_HOST, $host));
                }
            }
        }
    }

    /**
     * One row per destination for a reader of `$locale`: the url in their
     * language, else the language-less one, else English, else the first. The
     * same rule as the map drawer's `itemLinks()` (web/assets/map/links.js),
     * so a page and the drawer send a reader to the same article.
     *
     * @return list<array{label: string, href: string, domain: string}>
     */
    public static function forLocale(mixed $links, string $locale): array
    {
        if (!\is_array($links)) {
            return [];
        }
        $out = [];
        foreach (\array_slice(array_values($links), 0, self::MAX_ENTRIES) as $entry) {
            if (!\is_array($entry) || !\is_array($entry['urls'] ?? null)) {
                continue;
            }
            $urls = array_values(array_filter(
                $entry['urls'],
                static fn (mixed $u): bool => \is_array($u) && \is_string($u['url'] ?? null) && 1 === preg_match('~^https://~i', $u['url']),
            ));
            if ([] === $urls) {
                continue;
            }
            /** @var list<array{url: string, locale?: mixed}> $urls */
            $pick = self::firstWith($urls, static fn (array $u): bool => ($u['locale'] ?? null) === $locale)
                ?? self::firstWith($urls, static fn (array $u): bool => !isset($u['locale']) || '' === $u['locale'])
                ?? self::firstWith($urls, static fn (array $u): bool => 'en' === ($u['locale'] ?? null))
                ?? $urls[0];
            $host = parse_url($pick['url'], \PHP_URL_HOST);
            if (!\is_string($host) || '' === $host) {
                continue;
            }
            $domain = preg_replace('/^www\./', '', $host) ?? $host;
            $label = \is_string($entry['label'] ?? null) ? trim($entry['label']) : '';
            $out[] = ['label' => '' !== $label ? $label : $domain, 'href' => $pick['url'], 'domain' => $domain];
        }

        return $out;
    }

    /**
     * @param list<array{url: string, locale?: mixed}>           $urls
     * @param callable(array{url: string, locale?: mixed}): bool $test
     *
     * @return array{url: string, locale?: mixed}|null
     */
    private static function firstWith(array $urls, callable $test): ?array
    {
        foreach ($urls as $u) {
            if ($test($u)) {
                return $u;
            }
        }

        return null;
    }
}
