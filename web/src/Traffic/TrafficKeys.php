<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The secrets behind traffic storage, all derived from TRAFFIC_SECRET with
 * HKDF-SHA256: one AES-256-GCM key for the waiting room's payloads and two
 * HMAC keys (block, seen). A waiting line is found by its block without its
 * road or time ever being stored readable.
 *
 * TRAFFIC_SECRET is its own variable, never APP_SECRET or ENCRYPTION_SECRET,
 * and there is no fallback: losing it makes every waiting line unreadable,
 * and a guessed default would make the encryption decorative. Staging and
 * production refuse the committed development and test values.
 *
 * @see docs/specs/traffic-measurements.md §4.2
 *
 * @api
 */
final class TrafficKeys
{
    /** The development stack's key (developers/docker/compose.yaml). Public on purpose; dev only. */
    public const string DEV_SECRET = 'ubyLKqQZEaQ8ROhLzsl8QHRUKy+DA0yKjLSpm+Gw7Hs=';
    /** The test environment's key (web/.env.test). Public on purpose; tests only. */
    public const string TEST_SECRET = 'nuBwuANqdfd0r2fBj9Cs+698Zv9KUtJSd6PQH0mhmUo=';

    /** @var array<string, string> purpose => derived key */
    private array $derived = [];

    public function __construct(
        #[Autowire('%env(default::TRAFFIC_SECRET)%')]
        private readonly ?string $secret,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function encryptionKey(): string
    {
        return $this->derive('enc');
    }

    /**
     * The waiting room's name for a line's block: one road, direction, part of
     * the day and day type, across quarters (traffic-measurements.md §4.3). It
     * says how many lines wait per block, and nothing about which road or time.
     *
     * @param array{way: int, dir: string, band: int, dayType: string} $line
     */
    public function blockKey(array $line): string
    {
        return hash_hmac('sha256', implode('|', [$line['way'], $line['dir'], $line['band'], $line['dayType']]),
            $this->derive('block'), true);
    }

    /** The stored form of a block code the browser sent (64 hex characters). */
    public function seenCode(string $clientHex): string
    {
        return hash_hmac('sha256', strtolower($clientHex), $this->derive('seen'), true);
    }

    private function derive(string $purpose): string
    {
        if (isset($this->derived[$purpose])) {
            return $this->derived[$purpose];
        }
        $secret = (string) $this->secret;
        if ('' === $secret) {
            throw new \LogicException('TRAFFIC_SECRET is not set; traffic data cannot be stored or read.');
        }
        if (\in_array($this->environment, ['prod', 'staging'], true) && \in_array($secret, [self::DEV_SECRET, self::TEST_SECRET], true)) {
            throw new \LogicException('TRAFFIC_SECRET is a committed development or test value; set a real one.');
        }
        $ikm = base64_decode($secret, true);
        if (false === $ikm || \strlen($ikm) < 32) {
            throw new \LogicException('TRAFFIC_SECRET must be at least 32 random bytes, base64-encoded.');
        }

        return $this->derived[$purpose] = hash_hkdf('sha256', $ikm, 32, 'cc-traffic-'.$purpose);
    }
}
