<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account;

/**
 * Metres or feet of climbing at render time. Independent of {@see DistanceUnit}.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
 */
enum ElevationUnit: string
{
    case M = 'm';
    case Ft = 'ft';

    /** International foot: exactly 0.3048 m. */
    public const float FEET_PER_METRE = 3.280839895013123;

    public function fromMetres(float $metres): float
    {
        return self::Ft === $this ? $metres * self::FEET_PER_METRE : $metres;
    }

    public function toMetres(float $value): float
    {
        return self::Ft === $this ? $value / self::FEET_PER_METRE : $value;
    }

    public function suffix(): string
    {
        return $this->value;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::M => 'form.elevation_unit_m',
            self::Ft => 'form.elevation_unit_ft',
        };
    }
}
