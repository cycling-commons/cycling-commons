<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Local PoW for the urgent report path — no third-party bot check.
 *
 * @see docs/specs/photo-uploads.md §6c
 *
 * @api
 */
final class ProofOfWork
{
    /** Leading zero bits. 20 ≈ 1–2s of browser CPU. */
    public const int DIFFICULTY = 20;

    private const int TTL_SECONDS = 600;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire(service: 'cache.media_pow')]
        private readonly CacheItemPoolInterface $spent,
    ) {
    }

    /** `<expiry>.<random>.<signature>` — self-contained, no server state. */
    public function issue(\DateTimeImmutable $now): string
    {
        $expiry = $now->getTimestamp() + self::TTL_SECONDS;
        $random = bin2hex(random_bytes(12));
        $body = $expiry.'.'.$random;

        return $body.'.'.$this->sign($body);
    }

    /**
     * True only for an unexpired, unspent, solved challenge we issued.
     * Every failure is the same `false`.
     */
    public function verify(string $challenge, string $nonce, \DateTimeImmutable $now): bool
    {
        $parts = explode('.', $challenge);
        if (3 !== \count($parts)) {
            return false;
        }
        [$expiry, $random, $signature] = $parts;

        // Timing-safe: === would leak the HMAC a byte at a time.
        if (!hash_equals($this->sign($expiry.'.'.$random), $signature)) {
            return false;
        }
        if (!ctype_digit($expiry) || (int) $expiry < $now->getTimestamp()) {
            return false;
        }
        if (!$this->solves($challenge, $nonce)) {
            return false;
        }

        $item = $this->spent->getItem('pow_'.hash('sha256', $challenge));
        if ($item->isHit()) {
            return false;
        }
        $item->set(true)->expiresAfter(max(1, (int) $expiry - $now->getTimestamp()));
        $this->spent->save($item);

        return true;
    }

    /** Does `sha256(challenge . nonce)` begin with DIFFICULTY zero bits? */
    private function solves(string $challenge, string $nonce): bool
    {
        if ('' === $nonce || \strlen($nonce) > 64) {
            return false;
        }

        $digest = hash('sha256', $challenge.'.'.$nonce, true);
        $bits = 0;
        foreach (str_split($digest) as $byte) {
            $value = \ord($byte);
            for ($mask = 0x80; $mask > 0; $mask >>= 1) {
                if (0 !== ($value & $mask)) {
                    return false;
                }
                if (++$bits >= self::DIFFICULTY) {
                    return true;
                }
            }
        }

        return true;
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', 'media-pow|'.$body, $this->secret);
    }
}
