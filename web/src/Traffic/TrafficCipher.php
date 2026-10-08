<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

/**
 * AES-256-GCM sealing of traffic payloads: a fresh 12-byte nonce per seal,
 * stored as nonce . tag . ciphertext. The row a payload belongs to is bound in
 * as associated data, so a blob that was changed, moved to another row, or
 * sealed with another key does not open. The JSON is padded with spaces to a
 * multiple of PAD_STEP bytes, so a blob's size says little about what a
 * waiting line holds.
 *
 * @see docs/specs/traffic-measurements.md §4.2
 *
 * @api
 */
final class TrafficCipher
{
    private const string CIPHER = 'aes-256-gcm';
    private const int NONCE = 12;
    private const int TAG = 16;
    public const int PAD_STEP = 256;

    public function __construct(private readonly TrafficKeys $keys)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param string               $row  what the payload belongs to, e.g. the table and row key
     */
    public function seal(array $data, string $row = ''): string
    {
        $json = json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        $json .= str_repeat(' ', (self::PAD_STEP - \strlen($json) % self::PAD_STEP) % self::PAD_STEP);
        $nonce = random_bytes(self::NONCE);
        $tag = '';
        $ct = openssl_encrypt($json, self::CIPHER, $this->keys->encryptionKey(),
            \OPENSSL_RAW_DATA, $nonce, $tag, $row, self::TAG);
        if (false === $ct) {
            throw new \RuntimeException('Traffic payload could not be encrypted.');
        }

        return $nonce.$tag.$ct;
    }

    /** @return array<string, mixed> */
    public function open(string $blob, string $row = ''): array
    {
        if (\strlen($blob) < self::NONCE + self::TAG) {
            throw new \RuntimeException('Traffic payload is too short.');
        }
        $plain = openssl_decrypt(substr($blob, self::NONCE + self::TAG), self::CIPHER, $this->keys->encryptionKey(),
            \OPENSSL_RAW_DATA, substr($blob, 0, self::NONCE), substr($blob, self::NONCE, self::TAG), $row);
        if (false === $plain) {
            throw new \RuntimeException('Traffic payload does not open with this key.');
        }
        $data = json_decode($plain, true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($data) ? $data : throw new \RuntimeException('Traffic payload is not an object.');
    }
}
