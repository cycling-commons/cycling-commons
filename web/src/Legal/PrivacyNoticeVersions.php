<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Legal;

/**
 * Every published version of the privacy notice (docs/specs/privacy-notice.md),
 * newest first; the rules for dates, notice and the bar are LegalVersions'. A
 * change to how data is handled adds a version here, and the account records
 * the version it last saw.
 *
 * @api
 */
final class PrivacyNoticeVersions extends LegalVersions
{
    public const string PAGE = 'privacy';
    public const int CURRENT = 2;

    #[\Override]
    protected static function entries(): array
    {
        return [
            // Significant (traffic summaries are new processing), published before the notice rule (owner 2026-10-09).
            ['number' => 2, 'date' => '2026-10-09', 'effective' => '2026-10-09', 'significant' => true, 'changes' => ['privacy.change.v2_traffic', 'privacy.change.v2_time_zone', 'privacy.change.v2_pseudonym', 'privacy.change.v2_base_location', 'privacy.change.v2_clearer', 'privacy.change.v2_errors', 'privacy.change.v2_notice', 'privacy.change.v2_more', 'privacy.change.v2_statements', 'privacy.change.v2_suspension', 'privacy.change.v2_authorities']],
            ['number' => 1, 'date' => '2026-10-04', 'effective' => '2026-10-04', 'significant' => false, 'changes' => ['privacy.change.v1_first']],
        ];
    }
}
