<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Account;

/**
 * Whether a rider reads kilometres or miles (docs/specs/account-and-auth.md §9).
 *
 * Stored distances never change: the database, the API and every measurement in
 * the Commons stay metric, because a dataset that shifts units per reader is a
 * dataset nobody can join. This enum exists at the very last step before a
 * number becomes text.
 *
 * Separate from {@see ElevationUnit} on purpose. Plenty of riders — most of
 * Britain, for a start — measure a ride in miles and a climb in metres, and a
 * single "imperial" switch would force them to accept a unit they never use.
 *
 * @api User-preference vocabulary; consumed by SettingsType, User and UnitDisplayExtension.
 */
enum DistanceUnit: string
{
    case Km = 'km';
    case Mi = 'mi';

    /** International mile: exactly 1609.344 m, so 1 km is this many miles. */
    public const float MILES_PER_KM = 0.621371192237334;

    /** The same ratio squared, for areas and densities. */
    public const float SQ_MILES_PER_SQ_KM = 0.386102158542446;

    /** A distance held in kilometres, in the rider's unit. */
    public function fromKm(float $km): float
    {
        return self::Mi === $this ? $km * self::MILES_PER_KM : $km;
    }

    /** The reverse, for the few places a rider types a distance in. */
    public function toKm(float $value): float
    {
        return self::Mi === $this ? $value / self::MILES_PER_KM : $value;
    }

    /**
     * A short horizontal distance held in metres, in the rider's unit.
     *
     * Miles take feet here rather than fractions of a mile: "820 ft off the
     * track" is a distance somebody can picture, and "0.16 mi" is not.
     */
    public function fromMetres(float $metres): float
    {
        return self::Mi === $this ? $metres * ElevationUnit::FEET_PER_METRE : $metres;
    }

    /** An area held in square kilometres, in the rider's unit. */
    public function areaFromKm2(float $km2): float
    {
        return self::Mi === $this ? $km2 * self::SQ_MILES_PER_SQ_KM : $km2;
    }

    /**
     * A density held per square kilometre, per the rider's unit of area.
     *
     * The inverse of {@see areaFromKm2()}: a bigger tile holds proportionally
     * more, so places per square mile is the per-km² figure divided by the
     * square-mile-to-square-kilometre ratio, not multiplied by it.
     */
    public function densityFromPerKm2(float $perKm2): float
    {
        return self::Mi === $this ? $perKm2 / self::SQ_MILES_PER_SQ_KM : $perKm2;
    }

    /** The suffix written after a long distance. */
    public function suffix(): string
    {
        return $this->value;
    }

    /** The suffix written after a short distance — see {@see fromMetres()}. */
    public function shortSuffix(): string
    {
        return self::Mi === $this ? 'ft' : 'm';
    }

    /** The suffix written after an area. */
    public function areaSuffix(): string
    {
        return self::Mi === $this ? 'sq mi' : 'km²';
    }

    /** Where a short distance stops being short and is better read as km/mi. */
    public function shortLimitMetres(): float
    {
        return self::Mi === $this ? 402.336 : 1000.0;
    }

    /** The translation key for this option's label in the settings dropdown. */
    public function labelKey(): string
    {
        return match ($this) {
            self::Km => 'form.distance_unit_km',
            self::Mi => 'form.distance_unit_mi',
        };
    }
}
