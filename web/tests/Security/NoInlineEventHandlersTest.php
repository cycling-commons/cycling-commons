<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * No template may carry an inline event handler, because none of them work.
 *
 * The CSP is `script-src 'self' 'nonce-…'`
 * (docs/specs/security-architecture.md §2). A nonce authorises a `<script>`
 * BLOCK; it does not authorise an `on*=` attribute — only `'unsafe-inline'` or
 * `'unsafe-hashes'` would, and neither is worth having. So the browser refuses
 * to run the handler, logs one console line, and does nothing else.
 *
 * That failure mode is why this test exists rather than a code-review habit.
 * A dead `onchange=` looks exactly like a control nobody wired up: no error
 * page, no exception, no failing request. It cost the Regions desk its country
 * filter and the pager its page-length control, and both shipped that way
 * because everything about them LOOKED right (owner-reported 2026-08-09).
 *
 * The fix is always the same shape: a `data-` attribute in the markup and the
 * behaviour in a file served from 'self' — `assets/js/form-autosubmit.js` for
 * submit-on-change, the page's own module for anything else.
 */
final class NoInlineEventHandlersTest extends TestCase
{
    private const string TEMPLATES = __DIR__.'/../../templates';

    public function testNoTemplateUsesAnInlineEventHandler(): void
    {
        $offenders = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATES)),
            '/\.twig$/',
        );

        foreach ($files as $file) {
            $source = (string) file_get_contents($file->getPathname());
            // Twig comments are not markup — a `{# onclick="…" #}` explaining
            // why something ISN'T an inline handler must not fail this.
            $markup = (string) preg_replace('/\{#.*?#\}/s', '', $source);

            foreach (explode("\n", $markup) as $n => $line) {
                if (1 === preg_match('/\son[a-z]+\s*=\s*["\']/i', $line, $m)) {
                    $offenders[] = sprintf(
                        '%s:%d — %s',
                        str_replace(self::TEMPLATES.'/', '', $file->getPathname()),
                        $n + 1,
                        trim($m[0]),
                    );
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", array_merge(
            ['Inline event handlers are blocked by the CSP and fail SILENTLY:'],
            $offenders,
            ['', 'Move the behaviour into a script served from \'self\' and trigger it',
                'from a data- attribute. assets/js/form-autosubmit.js already covers',
                'submit-on-change: give the control `data-autosubmit`.'],
        )));
    }
}
