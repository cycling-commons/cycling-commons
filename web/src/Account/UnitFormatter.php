<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Every distance and every height, written in the units the reader asked for
 * (docs/specs/account-and-auth.md §9).
 *
 * The same argument as the date preference: a preference is only worth having
 * if it is honoured everywhere. A dropdown that converts the route list and
 * leaves the climb card in kilometres is worse than no dropdown, because the
 * rider now believes the site listens and then reads a number in a unit they
 * do not use.
 *
 * Values passed in are ALWAYS metric, because that is what the app stores.
 * Conversion happens here, at the last step before a number becomes text, and
 * nowhere else: nothing upstream knows the reader's preference, and no stored
 * value is ever written in miles or feet.
 *
 * Anonymous visitors get metric, which is what the app has always shown.
 *
 * @api Used by UnitDisplayExtension (Twig), MapController and the contribution forms.
 */
final class UnitFormatter
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function distanceUnit(): DistanceUnit
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getDistanceUnit() : DistanceUnit::Km;
    }

    public function elevationUnit(): ElevationUnit
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getElevationUnit() : ElevationUnit::M;
    }

    /** "42.2 km" or "26.2 mi", from a distance in kilometres. */
    public function distance(int|float|string|null $km, int $decimals = 1): string
    {
        if (!is_numeric($km)) {
            return '';
        }

        $unit = $this->distanceUnit();

        return $this->number($unit->fromKm((float) $km), $decimals).' '.$unit->suffix();
    }

    /**
     * "250 m" or "820 ft", from a short distance in metres — the scale a person
     * paces out rather than rides.
     *
     * Past the point where feet stop being readable (a kilometre, or the
     * quarter mile that is about the same size) it promotes itself to the long
     * form, so "1500 m" never reads as "4921 ft".
     */
    public function shortDistance(int|float|string|null $metres, int $decimals = 0): string
    {
        if (!is_numeric($metres)) {
            return '';
        }

        $unit = $this->distanceUnit();
        $m = (float) $metres;
        if (abs($m) >= $unit->shortLimitMetres()) {
            return $this->distance($m / 1000.0, 1);
        }

        return $this->number($unit->fromMetres($m), $decimals).' '.$unit->shortSuffix();
    }

    /** "1,240 m" or "4,068 ft" of climbing, from a height in metres. */
    public function elevation(int|float|string|null $metres, int $decimals = 0): string
    {
        if (!is_numeric($metres)) {
            return '';
        }

        $unit = $this->elevationUnit();

        return $this->number($unit->fromMetres((float) $metres), $decimals).' '.$unit->suffix();
    }

    /** "16,089 km²" or "6,212 sq mi", from an area in square kilometres. */
    public function area(int|float|string|null $km2, int $decimals = 0): string
    {
        if (!is_numeric($km2)) {
            return '';
        }

        $unit = $this->distanceUnit();

        return $this->number($unit->areaFromKm2((float) $km2), $decimals).' '.$unit->areaSuffix();
    }

    /**
     * A count per square kilometre, in the rider's unit of area — the coverage
     * table's density column, which is a number beside a separately-written
     * "/ km²" or "/ sq mi".
     */
    public function density(int|float|string|null $perKm2, int $decimals = 2): string
    {
        if (!is_numeric($perKm2)) {
            return '';
        }

        // Fixed decimals, unlike everywhere else: this one sits in a column,
        // and a table where 1.00 prints as "1" beside 0.87 reads as ragged.
        return $this->number($this->distanceUnit()->densityFromPerKm2((float) $perKm2), $decimals, false);
    }

    /** The area suffix on its own, for the strings that write it separately. */
    public function areaSuffix(): string
    {
        return $this->distanceUnit()->areaSuffix();
    }

    /** The bare converted distance, for form fields and slider bounds. */
    public function distanceValue(int|float|string|null $km, int $decimals = 1): float
    {
        return is_numeric($km) ? round($this->distanceUnit()->fromKm((float) $km), $decimals) : 0.0;
    }

    /** The bare converted height, same reason. */
    public function elevationValue(int|float|string|null $metres, int $decimals = 0): float
    {
        return is_numeric($metres) ? round($this->elevationUnit()->fromMetres((float) $metres), $decimals) : 0.0;
    }

    /**
     * Thousands separated, trailing zeros dropped.
     *
     * "1,240 m" rather than "1240 m", and "12 mi" rather than "12.0 mi": the
     * decimal exists to keep a 4.3 km climb from rounding to 4, not to decorate
     * a round number.
     */
    private function number(float $value, int $decimals, bool $trim = true): string
    {
        $rendered = number_format($value, max(0, $decimals), '.', ',');

        return $trim && str_contains($rendered, '.')
            ? rtrim(rtrim($rendered, '0'), '.')
            : $rendered;
    }
}
