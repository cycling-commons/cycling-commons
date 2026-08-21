<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Service\BuildVersion;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cc_build()` — footer stamp ({@see BuildVersion}).
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
