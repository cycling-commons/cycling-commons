<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Account\UnitFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The Twig half of the rider's unit preference
 * (docs/specs/account-and-auth.md §9).
 *
 * All the thinking lives in {@see UnitFormatter}; this only exposes it to
 * templates, so a distance rendered by a controller, by a template and by
 * JavaScript on the same page cannot end up in three different units.
 *
 * Every value handed to these filters is metric, because that is what the app
 * stores — see UnitFormatter for why that never changes.
 *
 * @api Auto-registered Twig extension.
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
            // A ride-scale distance, given in kilometres.
            new TwigFilter('cc_km', $this->units->distance(...)),
            // A short distance, given in metres ("820 ft from the pin").
            new TwigFilter('cc_m', $this->units->shortDistance(...)),
            // Height climbed or altitude, given in metres.
            new TwigFilter('cc_elev', $this->units->elevation(...)),
            // An area, given in square kilometres.
            new TwigFilter('cc_km2', $this->units->area(...)),
            // A count per square kilometre; the unit is written separately by
            // the string this lands in, via cc_area_suffix().
            new TwigFilter('cc_per_km2', $this->units->density(...)),
        ];
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            // For the base template, which hands the same two preferences to
            // the client so a JS-rendered distance on a page cannot disagree
            // with a server-rendered one beside it.
            new TwigFunction('cc_user_distance_unit', fn (): string => $this->units->distanceUnit()->value),
            new TwigFunction('cc_user_elevation_unit', fn (): string => $this->units->elevationUnit()->value),
            // Bare unit words, for axis captions and input labels where the
            // unit is written once and the numbers stand alone under it.
            new TwigFunction('cc_distance_suffix', fn (): string => $this->units->distanceUnit()->suffix()),
            new TwigFunction('cc_elevation_suffix', fn (): string => $this->units->elevationUnit()->suffix()),
            new TwigFunction('cc_area_suffix', fn (): string => $this->units->areaSuffix()),
            // Converters for the handful of places a rider TYPES a distance:
            // the form shows their unit, and the value is converted back to
            // metric before anything stores it.
            new TwigFunction('cc_km_value', $this->units->distanceValue(...)),
            new TwigFunction('cc_elev_value', $this->units->elevationValue(...)),
        ];
    }
}
