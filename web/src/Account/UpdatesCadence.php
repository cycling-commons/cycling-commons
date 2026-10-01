<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account;

/**
 * How often a rider on the release list agreed to hear from us.
 *
 * One list with a choice rather than two lists, so nobody gets the same news
 * twice. `Big` is the default and is what every rider who opted in under the
 * v1 wording ("a few times a year") agreed to.
 *
 * Each case is a ceiling the rider agreed to, not a schedule: a mail that
 * would exceed it does not go to them.
 *
 * @see docs/specs/roadmap-and-changelog.md §4
 *
 * @api
 */
enum UpdatesCadence: string
{
    /** Big news only, at most 4 times a year. */
    case Big = 'big';

    /** Every update, at most 2 times a month. */
    case Every = 'every';

    /** The sentence beside the radio, which is part of what was agreed. */
    public function labelKey(): string
    {
        return match ($this) {
            self::Big => 'settings.updates_cadence_big',
            self::Every => 'settings.updates_cadence_every',
        };
    }
}
