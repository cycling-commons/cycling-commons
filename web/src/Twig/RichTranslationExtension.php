<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `|rich`: sanitise translation markup. YAML is not an XSS trust boundary.
 *
 * @see docs/specs/security-architecture.md §3
 *
 * @api
 */
final class RichTranslationExtension extends AbstractExtension
{
    public function __construct(
        #[Target('app.rich_translations')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('rich', fn (string $html): string => $this->sanitizer->sanitize($html), ['is_safe' => ['html']]),
        ];
    }
}
