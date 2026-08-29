<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Support\BugMarkdown;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `|bug_markdown`: render the small markdown a bug report may use.
 *
 * Marked `is_safe` because {@see BugMarkdown} escapes before it renders and
 * sanitises after. Never point this filter at anything else: it is safe for a
 * bug body precisely because that is the input it was written against.
 *
 * @see docs/specs/contact-and-support.md §15
 *
 * @api
 */
final class BugMarkdownExtension extends AbstractExtension
{
    public function __construct(private readonly BugMarkdown $markdown)
    {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter(
                'bug_markdown',
                fn (?string $text): string => $this->markdown->render($text),
                ['is_safe' => ['html']],
            ),
            // NOT is_safe: this one returns plain text and Twig must escape it
            // like any other string.
            new TwigFilter(
                'bug_excerpt',
                fn (?string $text, int $length = 160): string => $this->markdown->plain($text, $length),
            ),
        ];
    }
}
