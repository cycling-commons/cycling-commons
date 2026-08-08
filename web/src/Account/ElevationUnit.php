<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Account;

/**
 * Whether a rider reads metres or feet of climbing
 * (docs/specs/account-and-auth.md §9).
 *
 * Independent of {@see DistanceUnit} for the reason that preference explains:
 * miles and metres is a real combination, not an oversight, and the British
 * riders who use it should not have to pick a side.
 *
 * Everything the app stores stays in metres — this converts at render time only.
 *
 * @api User-preference vocabulary; consumed by SettingsType, User and UnitDisplayExtension.
 */
enum ElevationUnit: string
{
    case M = 'm';
    case Ft = 'ft';

    /** International foot: exactly 0.3048 m, so 1 m is this many feet. */
    public const float FEET_PER_METRE = 3.280839895013123;

    /** A height held in metres, in the rider's unit. */
    public function fromMetres(float $metres): float
    {
        return self::Ft === $this ? $metres * self::FEET_PER_METRE : $metres;
    }

    /** The reverse, for the few places a rider types a height in. */
    public function toMetres(float $value): float
    {
        return self::Ft === $this ? $value / self::FEET_PER_METRE : $value;
    }

    /** The suffix written after a height. */
    public function suffix(): string
    {
        return $this->value;
    }

    /** The translation key for this option's label in the settings dropdown. */
    public function labelKey(): string
    {
        return match ($this) {
            self::M => 'form.elevation_unit_m',
            self::Ft => 'form.elevation_unit_ft',
        };
    }
}
