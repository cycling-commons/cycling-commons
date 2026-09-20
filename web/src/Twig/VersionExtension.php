<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Service\BuildVersion;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cc_build()` — the footer stamp and its source offer ({@see BuildVersion}).
 *
 * The `url` the footer links comes from the service, not from here, so that the
 * footer and `/humans.txt` cannot disagree about which code is running.
 *
 * @api
 */
final class VersionExtension extends AbstractExtension
{
    public function __construct(
        private readonly BuildVersion $version,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cc_build', fn (): array => $this->version->stamp()),
        ];
    }
}
