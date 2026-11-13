<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\EncryptionSecret;
use PHPUnit\Framework\TestCase;

/**
 * The 2FA seed and the backup code hashes take their key from ENCRYPTION_SECRET,
 * falling back to APP_SECRET, so APP_SECRET can be rotated without a silent 2FA
 * lockout (docs/specs/account-and-auth.md §4).
 */
final class EncryptionSecretTest extends TestCase
{
    private const NAMES = ['APP_SECRET', 'ENCRYPTION_SECRET'];

    /** @var array<string, array{mixed, mixed, string|false}> */
    private array $saved = [];

    #[\Override]
    protected function setUp(): void
    {
        foreach (self::NAMES as $name) {
            $this->saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            self::set($name, null);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$server, $env, $process]) {
            self::set($name, null);
            if (null !== $server) {
                $_SERVER[$name] = $server;
            }
            if (null !== $env) {
                $_ENV[$name] = $env;
            }
            if (false !== $process) {
                putenv($name.'='.$process);
            }
        }
    }

    public function testPrefersEncryptionSecret(): void
    {
        self::set('APP_SECRET', 'app');
        self::set('ENCRYPTION_SECRET', 'enc');

        self::assertSame('enc', EncryptionSecret::value());
    }

    public function testFallsBackToAppSecretWhenUnsetOrEmpty(): void
    {
        self::set('APP_SECRET', 'app');
        self::assertSame('app', EncryptionSecret::value());

        self::set('ENCRYPTION_SECRET', '');
        self::assertSame('app', EncryptionSecret::value());
    }

    public function testThrowsWhenNeitherIsSet(): void
    {
        $this->expectException(\LogicException::class);
        EncryptionSecret::value();
    }

    public function testBackupCodeHashSurvivesAnAppSecretRotation(): void
    {
        self::set('ENCRYPTION_SECRET', 'enc');
        self::set('APP_SECRET', 'old');
        $before = User::hashBackupCode('aaaa-1111');

        self::set('APP_SECRET', 'new');

        self::assertSame($before, User::hashBackupCode('aaaa-1111'));
    }

    public function testCopyingAppSecretKeepsExistingBackupCodesValid(): void
    {
        self::set('APP_SECRET', 'same');
        $stored = User::hashBackupCode('aaaa-1111');

        self::set('ENCRYPTION_SECRET', 'same');
        self::set('APP_SECRET', 'rotated');

        self::assertSame($stored, User::hashBackupCode('aaaa-1111'));
    }

    private static function set(string $name, ?string $value): void
    {
        if (null === $value) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            return;
        }
        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv($name.'='.$value);
    }
}
