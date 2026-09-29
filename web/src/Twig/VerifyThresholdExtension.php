<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cc_verify_threshold()`: how many riders' confirmations make a place
 * verified (`map.item_verify_threshold`, runtime-editable), so copy that
 * explains verification says the number the site actually uses.
 *
 * @see docs/specs/edit-items/README.md
 *
 * @api
 */
final class VerifyThresholdExtension extends AbstractExtension
{
    public function __construct(
        private readonly SettingsProviderInterface $settings,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cc_verify_threshold', fn (): int => $this->settings->get(SettingsRegistry::MAP_ITEM_VERIFY_THRESHOLD)),
        ];
    }
}
