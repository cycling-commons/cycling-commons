<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Account\UnitFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig filters for the rider's unit preference.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
 */
final class UnitDisplayExtension extends AbstractExtension
{
    public function __construct(
        private readonly UnitFormatter $units,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('cc_km', $this->units->distance(...)),
            new TwigFilter('cc_m', $this->units->shortDistance(...)),
            new TwigFilter('cc_elev', $this->units->elevation(...)),
            new TwigFilter('cc_km2', $this->units->area(...)),
            new TwigFilter('cc_per_km2', $this->units->density(...)),
        ];
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cc_user_distance_unit', fn (): string => $this->units->distanceUnit()->value),
            new TwigFunction('cc_user_elevation_unit', fn (): string => $this->units->elevationUnit()->value),
            new TwigFunction('cc_distance_suffix', fn (): string => $this->units->distanceUnit()->suffix()),
            new TwigFunction('cc_elevation_suffix', fn (): string => $this->units->elevationUnit()->suffix()),
            new TwigFunction('cc_area_suffix', fn (): string => $this->units->areaSuffix()),
            new TwigFunction('cc_km_value', $this->units->distanceValue(...)),
            new TwigFunction('cc_elev_value', $this->units->elevationValue(...)),
        ];
    }
}
