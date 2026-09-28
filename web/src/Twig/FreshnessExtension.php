<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Catalog\ConfirmationFreshness;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The confirmation window, for the map keys: an orange ring after this many
 * months without a confirmation, a red border after twice as many. Read from
 * the setting, so the key's numbers are the map's (ConfirmationFreshness).
 *
 * @api
 */
final class FreshnessExtension extends AbstractExtension
{
    public function __construct(
        private readonly ConfirmationFreshness $freshness,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cc_stale_months', fn (): int => $this->freshness->staleMonths()),
        ];
    }
}
