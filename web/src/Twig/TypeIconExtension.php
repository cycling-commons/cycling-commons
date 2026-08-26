<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Catalog\ItemType;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cc_type_icons()`: the one category icon set (ItemType::iconSet()) for
 * server-rendered pages. partials/_type_icon.html.twig is its only reader.
 *
 * @api
 */
final class TypeIconExtension extends AbstractExtension
{
    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cc_type_icons', static fn (): array => ItemType::iconSet()),
        ];
    }
}
