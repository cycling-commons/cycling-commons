<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `|rich`: render a translation's inline markup (<b>, <a href>, <code>, …)
 * without making the YAML catalogs an XSS trust boundary. The repo is
 * public and contributor-oriented, so a malicious or careless translation
 * PR must not be able to inject script. Replaces every `|trans|raw` with
 * `|trans|rich`; the allowlist lives in config/packages/html_sanitizer.yaml
 * (app.rich_translations).
 *
 * @see docs/specs/security-architecture.md §3
 *
 * @api Auto-registered Twig extension.
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
