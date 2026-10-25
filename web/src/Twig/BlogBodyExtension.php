<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `|blog_headings`: a blog post's paragraph that is one bold line and nothing
 * else is a section title, so it becomes one (`<h2 class="bh">`) and sits
 * close to the text it heads (docs/specs/blog.md §2).
 *
 * `|blog_links`: `[words](/map)` or `[words](https://...)` becomes a link.
 * A path on the site opens in the same tab; any other https address in a new
 * one. Nothing but a site path or https is read, so no scheme a writer could
 * slip in (javascript:, data:, //host) ever reaches an href.
 *
 * Both run on the output of `|bug_markdown`, which has escaped and sanitised
 * the body already, so the quotes and angle brackets a writer types arrive as
 * entities and cannot leave the attribute. The markdown itself keeps its six
 * rules, shared with the bug desk.
 *
 * @api
 */
final class BlogBodyExtension extends AbstractExtension
{
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('blog_headings', self::headings(...), ['is_safe' => ['html'], 'pre_escape' => 'html']),
            new TwigFilter('blog_links', self::links(...), ['is_safe' => ['html'], 'pre_escape' => 'html']),
        ];
    }

    public static function links(string $html): string
    {
        // Code shows what a reader types, so a link written inside it stays text.
        $parts = preg_split('~(<pre>.*?</pre>|<code>.*?</code>)~su', $html, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            return $html;
        }
        foreach ($parts as $i => $part) {
            if (1 === $i % 2) {
                continue;
            }
            $parts[$i] = (string) preg_replace_callback(
                '~\[([^\[\]\n]{1,200})\]\(((?:https://[^\s()<>]+)|(?:/(?!/)[^\s()<>]*))\)~u',
                static function (array $m): string {
                    $external = str_starts_with($m[2], 'https://') && 1 !== preg_match('~^https://(?:www\.)?cyclingcommons\.org(?:[/?#]|$)~', $m[2]);

                    return sprintf('<a href="%s"%s>%s</a>', $m[2], $external ? ' target="_blank" rel="noopener"' : '', $m[1]);
                },
                $part,
            );
        }

        return implode('', $parts);
    }

    public static function headings(string $html): string
    {
        return (string) preg_replace('~<p><strong>([^<]{1,160})</strong></p>~u', '<h2 class="bh">$1</h2>', $html);
    }
}
