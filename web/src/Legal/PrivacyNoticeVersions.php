<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Legal;

/**
 * Every published version of the privacy notice (docs/specs/privacy-notice.md):
 * its number, its date and what changed, newest first. A change to how data is
 * handled adds a version here; signed-in riders who have not seen it are told
 * on every page until they open the notice, and the account records the
 * version it last saw.
 *
 * @api
 */
final class PrivacyNoticeVersions
{
    public const int CURRENT = 2;

    /** @return list<array{number: int, date: string, changes: list<string>}> translation keys under privacy.change */
    public static function all(): array
    {
        return [
            ['number' => 2, 'date' => '2026-10-07', 'changes' => ['privacy.change.v2_traffic', 'privacy.change.v2_time_zone', 'privacy.change.v2_pseudonym', 'privacy.change.v2_base_location', 'privacy.change.v2_clearer', 'privacy.change.v2_errors']],
            ['number' => 1, 'date' => '2026-10-04', 'changes' => ['privacy.change.v1_first']],
        ];
    }

    /** @return array{number: int, date: string, changes: list<string>} */
    public static function current(): array
    {
        return self::all()[0];
    }
}
