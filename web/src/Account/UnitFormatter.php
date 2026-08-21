<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Distances and heights in the reader's units. Inputs are always metric.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
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

    public function distance(int|float|string|null $km, int $decimals = 1): string
    {
        if (!is_numeric($km)) {
            return '';
        }

        $unit = $this->distanceUnit();

        return $this->number($unit->fromKm((float) $km), $decimals).' '.$unit->suffix();
    }

    /** Promotes to km/mi past {@see DistanceUnit::shortLimitMetres()}. */
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

    public function elevation(int|float|string|null $metres, int $decimals = 0): string
    {
        if (!is_numeric($metres)) {
            return '';
        }

        $unit = $this->elevationUnit();

        return $this->number($unit->fromMetres((float) $metres), $decimals).' '.$unit->suffix();
    }

    public function area(int|float|string|null $km2, int $decimals = 0): string
    {
        if (!is_numeric($km2)) {
            return '';
        }

        $unit = $this->distanceUnit();

        return $this->number($unit->areaFromKm2((float) $km2), $decimals).' '.$unit->areaSuffix();
    }

    public function density(int|float|string|null $perKm2, int $decimals = 2): string
    {
        if (!is_numeric($perKm2)) {
            return '';
        }

        // Fixed decimals: this sits in a table column.
        return $this->number($this->distanceUnit()->densityFromPerKm2((float) $perKm2), $decimals, false);
    }

    public function areaSuffix(): string
    {
        return $this->distanceUnit()->areaSuffix();
    }

    public function distanceValue(int|float|string|null $km, int $decimals = 1): float
    {
        return is_numeric($km) ? round($this->distanceUnit()->fromKm((float) $km), $decimals) : 0.0;
    }

    public function elevationValue(int|float|string|null $metres, int $decimals = 0): float
    {
        return is_numeric($metres) ? round($this->elevationUnit()->fromMetres((float) $metres), $decimals) : 0.0;
    }

    private function number(float $value, int $decimals, bool $trim = true): string
    {
        $rendered = number_format($value, max(0, $decimals), '.', ',');

        return $trim && str_contains($rendered, '.')
            ? rtrim(rtrim($rendered, '0'), '.')
            : $rendered;
    }
}
