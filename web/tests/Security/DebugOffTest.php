<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Debug mode prints stack traces, file paths and SQL on every error page, and
 * the shared .env turns it on for dev. Each deployed environment's committed
 * file must turn it off again, so a missing server-side override cannot boot
 * production with debug on. Read from the committed files, never a `.local`
 * override.
 */
final class DebugOffTest extends TestCase
{
    private static function value(string $file): ?string
    {
        $matched = preg_match('/^APP_DEBUG=(.*)$/m', (string) file_get_contents(\dirname(__DIR__, 2).'/'.$file), $m);

        return 1 === $matched ? trim($m[1]) : null;
    }

    public function testEveryDeployedEnvironmentTurnsDebugOff(): void
    {
        self::assertSame('1', self::value('.env'), 'dev inherits debug from .env');
        self::assertSame('0', self::value('.env.prod'));
        self::assertSame('0', self::value('.env.staging'));
    }
}
