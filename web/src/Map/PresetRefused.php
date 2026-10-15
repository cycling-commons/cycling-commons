<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Map;

/**
 * Why a preset save or change was refused (docs/specs/map-presets.md section 5).
 *
 * @api
 */
final class PresetRefused extends \RuntimeException
{
    /** The id names no preset of this rider; another rider's preset is a missing preset. */
    public const string NOT_FOUND = 'not_found';

    /** The rider already holds the most presets an account may. */
    public const string TOO_MANY = 'too_many';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
