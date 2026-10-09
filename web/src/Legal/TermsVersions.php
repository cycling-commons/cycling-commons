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
    public const string PAGE = 'terms';
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
        return [
            ['number' => 2, 'date' => '2026-10-09', 'effective' => '2026-10-09', 'significant' => false, 'changes' => ['terms.change.v2_data', 'terms.change.v2_code', 'terms.change.v2_backups', 'terms.change.v2_notice', 'terms.change.v2_more', 'terms.change.v2_reasons', 'terms.change.v2_suspension', 'terms.change.v2_authorities', 'terms.change.v2_included']],
            ['number' => 1, 'date' => '2026-08-01', 'effective' => '2026-08-01', 'significant' => false, 'changes' => ['terms.change.v1_first']],
        ];
    }
}
