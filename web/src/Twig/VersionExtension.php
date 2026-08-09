<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Service\BuildVersion;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cc_build()` — the release-tag build stamp for the footer, one shape for
 * every template that emits window.CC_VERSION ({@see BuildVersion} for how it
 * is derived and why).
 *
 * @api Auto-registered Twig extension.
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
