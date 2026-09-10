<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Import;

/**
 * A name reduced to what makes two rows the SAME place.
 *
 * The PHP twin of `normalise_name()` in `tools/wikimedia/prescreen_seeded.py`.
 * The two are pinned together by `tools/wikimedia/name_key_cases.json`, asserted
 * from both sides (`NameKeyContractTest`, `test_name_key_contract.py`), for the
 * same reason `coverage-contract.json` exists: two implementations of one rule
 * in two languages drift, and drift here means the import guard and the
 * pre-screen report disagree about what a duplicate is. Change one, change the
 * contract file, and the other side fails until it follows.
 *
 * The rule turns on one distinction. A **separator** stands between two words
 * and leaves a space behind; a **joiner** sits inside one word and leaves
 * nothing. So "Saint-Roch" reduces to "saint roch" and matches the sloppy
 * "Saint Roch", while "Mary's" reduces to "marys" and does not become
 * "mary s".
 *
 * This is a comparison key and nothing else. It is never stored, never
 * displayed, and never written back over a name: the catalog keeps
 * "Ferme de l'Espinette" exactly as spelled.
 *
 * @see docs/specs/catalog-data-model.md §5
 *
 * @api
 */
final class NameKey
{
    /**
     * Characters that separate two words. Every dash width is listed because
     * the ASCII fold below deletes them outright, which would close a hyphen
     * up into its neighbours and hide the very pair this class exists to find.
     */
    private const string SEPARATORS = '/[\-\/_\x{00AD}\x{2010}-\x{2015}\x{2212}]+/u';

    /**
     * The comparison key, or '' for a name that reduces to nothing.
     *
     * An empty key is never a match: callers must treat it as "no key", or
     * every punctuation-only name collides with every other one.
     */
    public static function of(string $name): string
    {
        // Separators first, while the dashes still exist to be seen.
        $out = (string) preg_replace(self::SEPARATORS, ' ', $name);

        // Decompose, then drop everything that is not ASCII: "Côte" -> "Cote".
        // Matches Python's NFKD + .encode('ascii', 'ignore') exactly.
        $normalized = \Normalizer::normalize($out, \Normalizer::FORM_KD);
        $out = false === $normalized ? $out : $normalized;
        $out = (string) iconv('UTF-8', 'ASCII//IGNORE', $out);

        // A trailing place qualifier is not part of the dedication:
        // "St Mary's Cathedral, Perth" is the same building as "St Mary's Cathedral".
        $out = explode(',', $out)[0];

        $out = (string) preg_replace('/\bSt\./', 'St', $out);
        // Joiners: apostrophes, points, ampersands. No replacement.
        $out = (string) preg_replace('/[^\w\s]/', '', $out);

        return strtolower(trim((string) preg_replace('/\s+/', ' ', $out)));
    }
}
