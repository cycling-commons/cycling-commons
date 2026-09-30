<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\AnalyticsExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Visitor analytics counts public pages only (owner 2026-09-30): never a
 * signed-in area, and never an address that carries a one-time token.
 */
final class AnalyticsExtensionTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function paths(): iterable
    {
        yield 'home' => ['/', true];
        yield 'map' => ['/map', true];
        yield 'regions, Dutch' => ['/nl/regio-s', true];
        yield 'sign-in' => ['/login', true];
        yield 'sign-up, French' => ['/fr/register', true];
        yield 'api reference' => ['/developers/api', true];
        yield 'dashboard' => ['/account', false];
        yield 'contributions, German' => ['/de/account/contributions', false];
        yield 'moderation' => ['/moderate/bugs/141', false];
        yield 'admin' => ['/admin', false];
        yield 'translate mode' => ['/translate/mine', false];
        yield 'confirm link' => ['/verify?token=abc', false];
        yield 'reset link' => ['/reset-password/reset/abc', false];
        yield 'unsubscribe link' => ['/unsubscribe/abc', false];
        yield '2fa step' => ['/2fa', false];
        yield 'former account address' => ['/settings', false];
    }

    #[DataProvider('paths')]
    public function testOnlyPublicPagesAreCounted(string $path, bool $counted): void
    {
        self::assertSame($counted, AnalyticsExtension::isPublicPath((string) parse_url($path, \PHP_URL_PATH)));
    }
}
