<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Resolves the "About" lead a region page shows, from the two stores that can
 * hold one: the build-time Wikipedia harvest (`region.context`) and the
 * curator override written on the Regions desk (`region.context_curated`).
 *
 * Two stores rather than one field, deliberately (migration
 * Version20260816120000): `app:regions:import-context` replaces `context`
 * wholesale on every harvest, so an override kept in there would live exactly
 * until the next run. Keeping our addition beside the upstream copy is the
 * same shape the catalog uses for OSM, and it means the override survives the
 * importer by construction rather than by a guard somebody has to remember.
 *
 * **The attribution rule is the reason this class exists.** A Wikipedia
 * extract is CC BY-SA 4.0, and what a curator did to it decides what the page
 * must say:
 *  - untouched harvest      → cite the article, verbatim, no note;
 *  - curator ADAPTED it     → still cite the article (the licence follows the
 *                             derivative) AND say the text was changed, so a
 *                             reader never attributes our edit to Wikipedia;
 *  - curator WROTE it       → no Wikipedia credit at all. Crediting a source
 *                             for text that did not come from it is the worse
 *                             error of the two, so `derived` defaults to that
 *                             reading whenever it is not explicitly set.
 *
 * A curator may adapt the English article into their own language, which is
 * why `derived` accepts the English entry as its citation when the reader's
 * locale has no article of its own - a translation is a derivative work.
 */
final class RegionLead
{
    /** The locales a lead can be written in: the site's own set. */
    public const array LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

    /**
     * The lead to render for `$locale`, or null when the region has none.
     *
     * Resolution order, and it is not the obvious one: a curator lead in the
     * READER's locale wins, but a Wikipedia article in the reader's locale
     * beats a curator lead written in a language they may not read. English is
     * the last resort on both sides, in the same pairing.
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
                // Only an ADAPTED lead carries the citation. Written-from-scratch
                // text gets no url and no title, so the template has nothing to
                // render a credit line out of even by accident.
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
     * True when a curator MAY mark a lead in `$locale` as adapted - that is,
     * when there is an article for it to be adapted from. Their own locale
     * first, English second (translating the English lead is a derivative
     * work, and the commonest real case for a language Wikipedia is thin in).
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
     * The citation an ADAPTED lead carries, and nothing when it was written
     * from scratch. Reads from the entry's own locale, then English, matching
     * what `hasSource()` allowed the curator to claim.
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
