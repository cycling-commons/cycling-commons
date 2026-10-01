<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Api\V1\CategoryTable;
use App\Catalog\BasemapIcons;
use App\Catalog\ItemType;
use App\Catalog\KindIcons;
use App\Catalog\SurfaceVocabulary;
use App\Contribution\PlaceText;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cc_type_icons()`: the one category icon set (ItemType::iconSet()) for
 * server-rendered pages. partials/_type_icon.html.twig is its only reader.
 *
 * `cc_text_icons()`: the town and region icons of a Text submission's row
 * (PlaceText::ICONS), read by partials/_type_icon.html.twig beside the set.
 *
 * `cc_kind_icons()`: the one kind glyph set (KindIcons::set()), read by
 * partials/_kind_icon.html.twig and by the two legends that list every kind.
 *
 * `cc_surface_colours()`: the surface line palette (SurfaceVocabulary::LINE_COLOUR).
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
            new TwigFunction('cc_kind_icons', static fn (): array => KindIcons::set()),
            new TwigFunction('cc_text_icons', static fn (): array => PlaceText::ICONS),
            new TwigFunction('cc_basemap_icons', static fn (): array => BasemapIcons::set()),
            new TwigFunction('cc_surface_colours', static fn (): array => SurfaceVocabulary::LINE_COLOUR),
            // Letter => fill colour, from the public API's category table: the
            // one server-side copy of catalog.js's colours, for both map keys.
            new TwigFunction('cc_category_colours', static fn (): array => array_column(CategoryTable::CATEGORIES, 'color', 'letter')),
        ];
    }
}
