<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Smoke;

use PHPUnit\Framework\TestCase;

/**
 * No template carries a `<style>` block (page-caching.md §3.2): CSS lives in
 * the stylesheets under assets/styles, linked from the page, so a cached page
 * carries no per-response nonce and the CSP needs no inline style source.
 */
final class NoStyleBlocksTest extends TestCase
{
    public function testNoTemplateHasAStyleBlock(): void
    {
        $root = \dirname(__DIR__, 2).'/templates';
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'twig' !== $file->getExtension()) {
                continue;
            }
            if (1 === preg_match('/<style\b/i', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }
        sort($offenders);

        self::assertSame([], $offenders, 'move these rules to a stylesheet under assets/styles/page/');
    }
}
