<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Asset;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\Compiler\AssetCompilerInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Production stylesheets carry no comments.
 *
 * The comments in assets/styles are the documentation of why a rule is the
 * way it is, and they stay in the source. A visitor's browser has no use for
 * them (owner 2026-09-28: "I really hate inline css and even more if it has
 * all these comments on a production site"). With debug off, as in the
 * production `asset-map:compile`, every comment goes; with debug on, as in
 * dev, they stay for reading in the browser's inspector.
 *
 * Kept: the file's SPDX licence line, and a `/*! … *\/` comment, the
 * convention for any other notice that must travel with the file. Text inside
 * quotes is never touched, so `content:"/*"` survives. Runs before the URL
 * compiler, so a `url(…)` quoted in a comment is never resolved.
 *
 * @api
 */
#[AsTaggedItem(priority: 10)]
final readonly class CssCommentStripper implements AssetCompilerInterface
{
    public function __construct(
        #[Autowire('%kernel.debug%')] private bool $debug,
    ) {
    }

    #[\Override]
    public function supports(MappedAsset $asset): bool
    {
        return !$this->debug && str_ends_with($asset->logicalPath, '.css');
    }

    #[\Override]
    public function compile(string $content, MappedAsset $asset, AssetMapperInterface $assetMapper): string
    {
        return self::strip($content);
    }

    /** The stylesheet without its comments, and without the blank lines they leave. */
    public static function strip(string $css): string
    {
        $out = '';
        $length = \strlen($css);
        $quote = null;
        for ($i = 0; $i < $length; ++$i) {
            $c = $css[$i];
            if (null !== $quote) {
                $out .= $c;
                if ('\\' === $c && $i + 1 < $length) {
                    $out .= $css[++$i];
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $c || "'" === $c) {
                $quote = $c;
                $out .= $c;
                continue;
            }
            if ('/' === $c && '*' === ($css[$i + 1] ?? '')) {
                $end = strpos($css, '*/', $i + 2);
                $end = false === $end ? $length : $end + 2;
                $comment = substr($css, $i, $end - $i);
                if ('!' === ($css[$i + 2] ?? '') || str_contains($comment, 'SPDX-License-Identifier:')) {
                    $out .= $comment;
                }
                $i = $end - 1;
                continue;
            }
            $out .= $c;
        }

        // Lines left holding nothing but spaces, then runs of empty lines.
        $out = (string) preg_replace('/^[ \t]+$/m', '', $out);

        return trim((string) preg_replace("/\n{2,}/", "\n", $out))."\n";
    }
}
