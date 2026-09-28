<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Catalog\BestOfFilters;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `best_of_path(params)`: a /best filter link in the one normal form
 * (BestOfFilters), so a link never needs the 301 a hand-built one would get.
 *
 * @api
 */
final class BestOfExtension extends AbstractExtension
{
    public function __construct(
        private readonly BestOfFilters $filters,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('best_of_path', fn (array $params): string => $this->urls->generate('best_of', $this->filters->normalize($params))),
        ];
    }
}
