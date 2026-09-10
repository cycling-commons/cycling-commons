<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account;

/**
 * Kilometres or miles at render time. Stored distances stay metric.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
 */
enum DistanceUnit: string
{
    case Km = 'km';
    case Mi = 'mi';

    /** International mile: exactly 1609.344 m. */
    public const float MILES_PER_KM = 0.621371192237334;

    /** The same ratio squared, for areas and densities. */
    public const float SQ_MILES_PER_SQ_KM = 0.386102158542446;

    public function fromKm(float $km): float
    {
        return self::Mi === $this ? $km * self::MILES_PER_KM : $km;
    }

    public function toKm(float $value): float
    {
        return self::Mi === $this ? $value / self::MILES_PER_KM : $value;
    }

    /** Short distances in miles render as feet. */
    public function fromMetres(float $metres): float
    {
        return self::Mi === $this ? $metres * ElevationUnit::FEET_PER_METRE : $metres;
    }

    public function areaFromKm2(float $km2): float
    {
        return self::Mi === $this ? $km2 * self::SQ_MILES_PER_SQ_KM : $km2;
    }

    /** Inverse of {@see areaFromKm2()}. */
    public function densityFromPerKm2(float $perKm2): float
    {
        return self::Mi === $this ? $perKm2 / self::SQ_MILES_PER_SQ_KM : $perKm2;
    }

    public function suffix(): string
    {
        return $this->value;
    }

    public function shortSuffix(): string
    {
        return self::Mi === $this ? 'ft' : 'm';
    }

    public function areaSuffix(): string
    {
        return self::Mi === $this ? 'sq mi' : 'km²';
    }

    public function shortLimitMetres(): float
    {
        return self::Mi === $this ? 402.336 : 1000.0;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Km => 'form.distance_unit_km',
            self::Mi => 'form.distance_unit_mi',
        };
    }
}
