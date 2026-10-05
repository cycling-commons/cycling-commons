<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Catalog\RegionLabels;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cc_region_label(slug, name)`: a region's display name in the page's
 * language (RegionLabels: region.labels, then English, then the name). A
 * plain string, so it can go into a translation parameter or an attribute
 * without being escaped twice.
 *
 * @api
 */
final class RegionLabelExtension extends AbstractExtension
{
    public function __construct(
        private readonly RegionLabels $labels,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('cc_region_label', $this->label(...))];
    }

    public function label(?string $slug, ?string $name = null): string
    {
        if (null === $slug || '' === $slug) {
            return (string) $name;
        }

        return $this->labels->label($slug, $name);
    }
}
