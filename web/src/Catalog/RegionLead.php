<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Region "About" lead. Harvest and curator override are separate stores so import cannot wipe an override. Adapted Wikipedia stays cited; original text must not.
 */
final class RegionLead
{
    /** The locales a lead can be written in: the site's own set. */
    public const array LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

    /**
     * Reader-locale Wikipedia beats a curator lead in another language. English is last resort.
     *
     * @param array<string, mixed>|null $wiki    decoded `region.context`
     * @param array<string, mixed>|null $curated decoded `region.context_curated`
     *
     * @return array{text: string, url: ?string, title: ?string, curated: bool, adapted: bool}|null
     */
    public static function resolve(?array $wiki, ?array $curated, string $locale): ?array
    {
        foreach ([$locale, 'en'] as $try) {
            $own = self::curatedEntry($curated, $try);
            if (null !== $own) {
                // Written-from-scratch text gets no citation, even by accident.
                return $own + ($own['adapted'] ? self::citationFor($wiki, $try) : ['url' => null, 'title' => null]);
            }
            $harvest = self::wikiEntry($wiki, $try);
            if (null !== $harvest) {
                return [
                    'text' => $harvest['extract'],
                    'url' => $harvest['url'],
                    'title' => $harvest['title'],
                    'curated' => false,
                    'adapted' => false,
                ];
            }
        }

        return null;
    }

    /**
     * The stored override for one locale, shaped for an editing form.
     *
     * @param array<string, mixed>|null $curated decoded `region.context_curated`
     *
     * @return array{text: string, derived: bool}|null
     */
    public static function override(?array $curated, string $locale): ?array
    {
        $entry = $curated[$locale] ?? null;
        if (!\is_array($entry) || !\is_string($entry['text'] ?? null) || '' === trim($entry['text'])) {
            return null;
        }

        return ['text' => trim($entry['text']), 'derived' => true === ($entry['derived'] ?? null)];
    }

    /**
     * True when there is an article `$locale` can be adapted from (own locale, then English).
     *
     * @param array<string, mixed>|null $wiki decoded `region.context`
     */
    public static function hasSource(?array $wiki, string $locale): bool
    {
        return null !== self::wikiEntry($wiki, $locale) || null !== self::wikiEntry($wiki, 'en');
    }

    /**
     * @param array<string, mixed>|null $curated
     *
     * @return array{text: string, curated: true, adapted: bool}|null
     */
    private static function curatedEntry(?array $curated, string $locale): ?array
    {
        $own = self::override($curated, $locale);

        return null === $own ? null : ['text' => $own['text'], 'curated' => true, 'adapted' => $own['derived']];
    }

    /**
     * Citation an adapted lead carries; empty when written from scratch.
     *
     * @param array<string, mixed>|null $wiki
     *
     * @return array{url: ?string, title: ?string}
     */
    private static function citationFor(?array $wiki, string $locale): array
    {
        $source = self::wikiEntry($wiki, $locale) ?? self::wikiEntry($wiki, 'en');

        return null === $source
            ? ['url' => null, 'title' => null]
            : ['url' => $source['url'], 'title' => $source['title']];
    }

    /**
     * @param array<string, mixed>|null $wiki
     *
     * @return array{extract: string, url: string, title: string}|null
     */
    private static function wikiEntry(?array $wiki, string $locale): ?array
    {
        $entry = $wiki[$locale] ?? null;
        if (!\is_array($entry)
            || !\is_string($entry['extract'] ?? null)
            || !\is_string($entry['url'] ?? null)
            || !\is_string($entry['title'] ?? null)
            || '' === trim($entry['extract'])) {
            return null;
        }

        return ['extract' => $entry['extract'], 'url' => $entry['url'], 'title' => $entry['title']];
    }
}
