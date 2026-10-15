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
    public const int CURRENT = 3;

    #[\Override]
    protected static function entries(): array
    {
        return [
            // Everything since the published version 2 ships as ONE version 3
            // (owner 2026-10-15: "combine all changes into v3, online we are
            // at v2"): the kept address period, roadmap votes and proposals,
            // saved rides, the forecast service, map presets. Not significant:
            // each is optional, new processing a rider chooses, or a clearer
            // statement of what already happens; nothing changes for data
            // already given.
            ['number' => 3, 'date' => '2026-10-15', 'effective' => '2026-10-15', 'significant' => false, 'changes' => [
                'privacy.change.v3_kept_address', 'privacy.change.v4_roadmap', 'privacy.change.v5_rides', 'privacy.change.v6_forecast', 'privacy.change.v7_presets',
            ]],
            ['number' => 2, 'date' => '2026-10-09', 'effective' => '2026-10-09', 'significant' => true, 'changes' => ['privacy.change.v2_traffic', 'privacy.change.v2_time_zone', 'privacy.change.v2_pseudonym', 'privacy.change.v2_base_location', 'privacy.change.v2_clearer', 'privacy.change.v2_errors', 'privacy.change.v2_notice', 'privacy.change.v2_more', 'privacy.change.v2_statements', 'privacy.change.v2_suspension', 'privacy.change.v2_authorities']],
            ['number' => 1, 'date' => '2026-10-04', 'effective' => '2026-10-04', 'significant' => false, 'changes' => ['privacy.change.v1_first']],
        ];
    }
}
