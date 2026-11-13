<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Security;

/**
 * The input key for the 2FA secrets at rest: the TOTP seed and the backup code
 * hashes. ENCRYPTION_SECRET, or APP_SECRET when it is not set.
 *
 * APP_SECRET is a signing key an incident runbook may rotate; rotating the key
 * that guards 2FA locks every 2FA user out without a log line (security scan
 * 2026-08-25). Set ENCRYPTION_SECRET to the CURRENT APP_SECRET first, then
 * APP_SECRET is free to rotate. See docs/specs/account-and-auth.md §4.
 */
final class EncryptionSecret
{
    public static function value(): string
    {
        $secret = self::env('ENCRYPTION_SECRET') ?? self::env('APP_SECRET');
        if (null === $secret) {
            throw new \LogicException('ENCRYPTION_SECRET or APP_SECRET must be set to derive the 2FA keys.');
        }

        return $secret;
    }

    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
