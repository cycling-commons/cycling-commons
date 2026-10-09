<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Legal;

/**
 * Every published version of the terms of use, newest first, shown on /terms
 * as the privacy notice shows its own; the rules for dates, notice and the bar
 * are LegalVersions'. The text itself is translations/terms.<locale>.yaml
 * (docs/specs/translations.md §6.2), and the account records the version it
 * last saw. The texts the terms include by reference are pinned to the
 * version that last accepted them (includedTexts(), TermsIncludedTexts).
 *
 * @api
 */
final class TermsVersions extends LegalVersions
{
    public const int CURRENT = 2;

    /**
     * The texts the terms include by reference, each pinned to the version
     * that last accepted it, newest pin first.
     *
     * @return array<string, non-empty-list<array{sha256: string, terms: int, date: string, kind: string, reason: string}>>
     */
    public static function includedTexts(): array
    {
        return TermsIncludedTexts::PINS;
    }

    #[\Override]
    protected static function entries(): array
    {
        return self::all()[0];
    }
}
