<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cc_region_label(slug, name)`: a region's display name in the page's
 * language, from `region.<slug>.label` (map-and-search.md §4.5), or the
 * registry name when no label is translated for that slug. A plain string, so
 * it can go into a translation parameter or an attribute without being
 * escaped twice.
 *
 * @api
 */
final class RegionLabelExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('cc_region_label', $this->label(...))];
    }

    public function label(?string $slug, ?string $name): string
    {
        if (null !== $slug && '' !== $slug) {
            $key = 'region.'.$slug.'.label';
            if ($this->translator instanceof TranslatorBagInterface && $this->translator->getCatalogue()->has($key)) {
                return $this->translator->trans($key);
            }
        }

        return (string) $name;
    }
}
