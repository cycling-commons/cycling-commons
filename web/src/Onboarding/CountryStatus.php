<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding;

/**
 * planned (a plan exists) -> seeded (region rows exist, the harvest may run) -> live.
 *
 * @api
 */
enum CountryStatus: string
{
    case Planned = 'planned';
    case Seeded = 'seeded';
    case Live = 'live';
}
