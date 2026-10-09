<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Legal;

/**
 * Every published version of the terms of use: its number, its date and what
 * changed, newest first, shown on /terms as the privacy notice shows its own
 * (PrivacyNoticeVersions). A change to the rules adds a version here; the
 * text itself is translations/terms.<locale>.yaml (docs/specs/translations.md §6.2).
 *
 * @api
 */
final class TermsVersions
{
    public const int CURRENT = 2;

    /** @return list<array{number: int, date: string, changes: list<string>}> translation keys under terms.change */
    public static function all(): array
    {
        return [
            ['number' => 2, 'date' => '2026-10-09', 'changes' => ['terms.change.v2_data', 'terms.change.v2_code', 'terms.change.v2_backups']],
            ['number' => 1, 'date' => '2026-08-01', 'changes' => ['terms.change.v1_first']],
        ];
    }

    /** @return array{number: int, date: string, changes: list<string>} */
    public static function current(): array
    {
        return self::all()[0];
    }
}
