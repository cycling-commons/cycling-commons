<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\World;

use Symfony\Component\Intl\Countries;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A country's name as it reads inside a sentence: "Nobody is curating the
 * USA yet", "De VS staat op de kaart".
 *
 * A name alone ("United States") is right on a label and wrong in a
 * sentence, where some countries take an article. The catalogue holds the
 * sentence form for those (`country_in_text.<code>`), one per language, so a
 * translator reviews it like any other string; every other country, and a
 * language whose entry is still the plain name, reads the name from the Intl
 * data. `$start` is the form that opens a sentence: "The USA is on the map."
 *
 * @see docs/specs/translations.md §1
 *
 * @api
 */
final readonly class CountryInText
{
    /** The countries the catalogue carries a sentence form for. */
    public const array CODES = [
        'AE', 'BS', 'CF', 'CK', 'DO', 'FK', 'FO', 'GB', 'GM', 'KM', 'KY', 'MH', 'MV', 'NL', 'PH', 'SB', 'SC', 'US',
    ];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function name(string $code, ?string $locale = null, bool $start = false): string
    {
        $code = strtoupper($code);
        $locale ??= $this->translator->getLocale();
        $name = \in_array($code, self::CODES, true)
            ? $this->translator->trans('country_in_text.'.$code, [], 'messages', $locale)
            : (Countries::exists($code) ? Countries::getName($code, $locale) : $code);

        return $start ? mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1) : $name;
    }
}
