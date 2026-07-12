<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Frontend review 2026-07-12 W61: |rich renders the inline markup the
 * translation catalogs legitimately use, and neutralizes everything a
 * hostile translation PR could smuggle in.
 */
final class RichTranslationExtensionTest extends KernelTestCase
{
    private function render(string $value): string
    {
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');

        return $twig->createTemplate('{{ value|rich }}')->render(['value' => $value]);
    }

    public function testKeepsTheCatalogsInlineMarkup(): void
    {
        self::assertSame('<b>bold</b> and <code>code</code>', $this->render('<b>bold</b> and <code>code</code>'));
        self::assertSame('<span class="dot g"></span>', $this->render('<span class="dot g"></span>'));
        self::assertStringContainsString('href="/privacy"', $this->render('<a href="/privacy">p</a>'));
        self::assertStringContainsString('href="mailto:info&#64;cyclingcommons.org"', $this->render('<a href="mailto:info@cyclingcommons.org">m</a>'));
    }

    public function testStripsScriptAndEventHandlers(): void
    {
        self::assertSame('', $this->render('<script>alert(1)</script>'));
        self::assertSame('<b>x</b>', $this->render('<b onmouseover="alert(1)">x</b>'));
        self::assertStringNotContainsString('onerror', $this->render('<img src=x onerror=alert(1)>'));
        self::assertStringNotContainsString('javascript:', $this->render('<a href="javascript:alert(1)">x</a>'));
    }
}
