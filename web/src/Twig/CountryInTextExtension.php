<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\World\CountryInText;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `country_in_text(code, start)`: a country's name as it reads inside a
 * sentence of the page's language ({@see CountryInText}).
 *
 * @api
 */
final class CountryInTextExtension extends AbstractExtension
{
    public function __construct(private readonly CountryInText $names)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('country_in_text', fn (string $code, bool $start = false): string => $this->names->name($code, null, $start)),
        ];
    }
}
