<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Ops;

/**
 * The console commands the worker host runs every day, from timers kept
 * outside this repository (docs/specs/operations.md, daily jobs). Each one's
 * last good run is recorded, and the admin dashboard warns when one is late.
 */
final class DailyJobs
{
    /** In the order the dashboard lists them. */
    public const array COMMANDS = [
        'app:accounts:purge-unverified',
        'app:accounts:dormancy',
        'app:media:gc',
        'app:moderation:gc',
        'app:catalog:link-osm',
        'app:catalog:findings',
        'app:vote:freeze',
        'app:community:sync-contributors',
    ];

    /** A daily job is late once its last good run is older than this: a day plus slack for a slow run. */
    public const int MAX_AGE_HOURS = 26;
}
