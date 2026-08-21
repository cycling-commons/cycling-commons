<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Encrypts a string column at rest (AES-256-GCM, APP_SECRET). Used for TOTP secrets.
 *
 * @see docs/specs/account-and-auth.md §4
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

        // Unreadable ciphertext hydrates as null so a rotated key cannot 500 login.
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
