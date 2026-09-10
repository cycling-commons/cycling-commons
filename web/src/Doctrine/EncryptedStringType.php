<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Encrypts a string column at rest (AES-256-GCM). Used for TOTP secrets.
 *
 * **The key comes from ENCRYPTION_SECRET, falling back to APP_SECRET.** Those
 * two used to be the same value with no way to separate them, which made
 * rotating APP_SECRET a silent 2FA outage: every stored TOTP secret becomes
 * undecryptable, `convertToPHPValue()` hydrates it as null by design, and the
 * next elevated login is simply told the code is wrong, with nothing anywhere
 * saying why (security scan 2026-08-25). APP_SECRET is a signing key that a
 * runbook may reasonably tell you to rotate after an incident; this is a
 * data-encryption key that can only be rotated by re-encrypting every row.
 * They should never have shared a variable.
 *
 * The fallback keeps every existing deployment working untouched: set
 * ENCRYPTION_SECRET to the CURRENT value of APP_SECRET, and only then is
 * APP_SECRET free to rotate. `app:security:encryption-audit` reports how many
 * rows the current key can actually read, which is the check to run before and
 * after touching either variable.
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

    /**
     * True when the stored value is ciphertext this key cannot read.
     *
     * Distinguishes "wrong key" from "empty column", which
     * `convertToPHPValue()` deliberately flattens into the same null so that a
     * rotated key cannot 500 anybody's login. The audit command needs the
     * difference; nothing on the request path does.
     */
    public static function isUnreadable(?string $stored): bool
    {
        if (null === $stored || '' === $stored) {
            return false;   // nothing stored is not the same as unreadable
        }

        $raw = base64_decode($stored, true);
        if (false === $raw || \strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            return true;
        }

        return false === openssl_decrypt(
            substr($raw, self::IV_LEN + self::TAG_LEN),
            self::CIPHER,
            self::key(),
            \OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_LEN),
            substr($raw, self::IV_LEN, self::TAG_LEN),
        );
    }

    /**
     * ENCRYPTION_SECRET, or APP_SECRET when it is not set.
     *
     * The HKDF info string stays `cc-totp-secret-v1` whichever variable
     * supplied the input: changing it would be a second, invisible key
     * rotation on top of the one being made, and every existing row would stop
     * decrypting for a reason nobody would think to look for.
     */
    private static function key(): string
    {
        $secret = self::env('ENCRYPTION_SECRET') ?? self::env('APP_SECRET');
        if (null === $secret) {
            throw new \LogicException('ENCRYPTION_SECRET or APP_SECRET must be set to derive the at-rest encryption key.');
        }

        return hash_hkdf('sha256', $secret, 32, 'cc-totp-secret-v1');
    }

    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
