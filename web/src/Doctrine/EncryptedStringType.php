<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Transparently encrypts a string column at rest with AES-256-GCM.
 *
 * The key is derived from APP_SECRET via HKDF-SHA256, so there is no extra secret
 * to manage; rotating APP_SECRET invalidates stored ciphertext (affected users
 * simply re-enrol). Used for the TOTP 2FA secret so a database leak alone does
 * not expose the authenticator seed. Stored as base64(iv || tag || ciphertext);
 * the column stays a plain VARCHAR(255) (an encrypted seed is ~80 chars).
 *
 * @api
 */
final class EncryptedStringType extends Type
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    #[\Override]
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        // Match a plain string(255) column so swapping a `string` field to this
        // type produces no schema diff (the ORM defaults `string` to 255).
        $column['length'] ??= 255;

        return $platform->getStringTypeDeclarationSQL($column);
    }

    #[\Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt((string) $value, self::CIPHER, self::key(), \OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $ciphertext) {
            throw new \RuntimeException('Failed to encrypt value.');
        }

        return base64_encode($iv.$tag.$ciphertext);
    }

    #[\Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        // Any unreadable stored value (structurally invalid, or key
        // rotated / data corrupted so GCM auth fails) hydrates as NULL rather
        // than throwing. This type is mapped on User::$totpSecret, so a throw
        // here would 500 EVERY hydration of the affected row — the user could
        // never log in and an admin could never open them to disarm 2FA.
        // Returning null makes the seed read as absent, so the documented
        // "affected users simply re-enrol" path (and the mandatory-2FA
        // enforcer) actually works (#14). An affected account is observable:
        // it simply shows 2FA disabled and is prompted to re-enrol.
        $raw = base64_decode((string) $value, true);
        if (false === $raw || \strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            return null;
        }

        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($ciphertext, self::CIPHER, self::key(), \OPENSSL_RAW_DATA, $iv, $tag);

        return false === $plain ? null : $plain;
    }

    private static function key(): string
    {
        $secret = $_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? getenv('APP_SECRET');
        if (!\is_string($secret) || '' === $secret) {
            throw new \LogicException('APP_SECRET must be set to derive the at-rest encryption key.');
        }

        return hash_hkdf('sha256', $secret, 32, 'cc-totp-secret-v1');
    }
}
