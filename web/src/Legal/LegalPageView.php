<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Legal;

/**
 * What a legal page renders: its versions, the one that applies today, the one
 * announced, and which text file to show.
 *
 * The text of a page is translations/<page>.<locale>.yaml. While a version is
 * announced but not applying yet, its text sits beside it in
 * translations/<page>_next.<locale>.yaml and `?v=next` shows it. That is the
 * only way a `_next` file is ever shown: with no upcoming version the page
 * shows its main file, whatever `_next` files exist, so a text without its
 * version entry never goes live. On the effective date the text moves over
 * the main file; LegalPageViewTest fails while a `_next` file outlives its
 * version (docs/specs/translations.md §6.2).
 *
 * @api
 */
final readonly class LegalPageView
{
    /** The languages every legal text is written in. */
    public const array LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

    public function __construct(private string $translationsDir)
    {
    }

    /**
     * The languages whose announced text for `$page` is not there yet.
     *
     * @return list<string>
     */
    public function missingNextTexts(string $page): array
    {
        return array_values(array_filter(self::LOCALES, fn (string $locale): bool => !is_file("{$this->translationsDir}/{$page}_next.{$locale}.yaml")));
    }

    /**
     * @param class-string<LegalVersions> $versions
     *
     * @return array{versions: list<array{number: int, date: string, effective: string, significant: bool, changes: list<string>}>, current: array{number: int, date: string, effective: string, significant: bool, changes: list<string>}, upcoming: array{number: int, date: string, effective: string, significant: bool, changes: list<string>}|null, text_domain: string, preview: bool, next_available: bool}
     */
    public function params(string $versions, bool $wantNext, \DateTimeImmutable $today): array
    {
        $page = $versions::PAGE;
        $upcoming = $versions::upcoming($today);
        $nextAvailable = null !== $upcoming && is_file("{$this->translationsDir}/{$page}_next.en.yaml");
        $preview = $nextAvailable && $wantNext;

        return [
            'versions' => $versions::all(),
            'current' => $versions::current($today),
            'upcoming' => $upcoming,
            'text_domain' => $preview ? $page.'_next' : $page,
            'preview' => $preview,
            'next_available' => $nextAvailable,
        ];
    }
}
