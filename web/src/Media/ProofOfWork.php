<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A local challenge for the urgent report path
 * (docs/specs/photo-uploads.md §6c).
 *
 * **Why proof-of-work and not a bot service.** The thing that needs pricing is
 * an anonymous request that can take a photo off the map. A hosted CAPTCHA or
 * bot check would price it, but it also means a third-party script on the one
 * page where the site promises to collect as little as possible, a CSP host
 * allowance for that script, and a hard dependency on somebody else's uptime
 * for a rights-exercise route. Proof-of-work costs the reporter a second of
 * their own CPU, tells nobody anything about them, and is ours.
 *
 * **What it is.** The server hands out a signed, expiring challenge. The
 * client searches for a nonce whose SHA-256 digest with that challenge starts
 * with DIFFICULTY zero bits — about a million hashes at 20 bits, a second or
 * two in a browser. The server re-hashes once to check. Asymmetric by
 * construction: expensive to produce, free to verify.
 *
 * **What it is not.** It is not a human test and does not pretend to be. A
 * determined attacker with real hardware still gets through; the point is to
 * make ten thousand reports cost ten thousand seconds of CPU rather than being
 * free, and to do it only while an attack is actually underway — the breaker
 * decides when this is enforced, so an ordinary day pays nothing.
 *
 * Statelessness: the challenge carries its own expiry and HMAC, so no server
 * state is needed to issue one. Only the *spent* ones are remembered, in a
 * cache with the challenge's own lifetime, so a solved challenge cannot be
 * replayed across a flood.
 *
 * @api Issued by MediaReportController, verified on its POST.
 */
final class ProofOfWork
{
    /** Leading zero BITS required. 20 ≈ a million hashes ≈ 1–2s of browser CPU. */
    public const int DIFFICULTY = 20;

    /** A challenge is good for ten minutes — long enough to write a report, short enough to bound reuse. */
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
     * True only for a challenge we issued, that has not expired, has not been
     * spent before, and whose nonce actually solves it. Every failure is the
     * same `false` — the caller must not tell a client which one it was.
     */
    public function verify(string $challenge, string $nonce, \DateTimeImmutable $now): bool
    {
        $parts = explode('.', $challenge);
        if (3 !== \count($parts)) {
            return false;
        }
        [$expiry, $random, $signature] = $parts;

        // hash_equals, not ===: signature comparison is the one place a timing
        // difference would leak the secret a byte at a time.
        if (!hash_equals($this->sign($expiry.'.'.$random), $signature)) {
            return false;
        }
        if (!ctype_digit($expiry) || (int) $expiry < $now->getTimestamp()) {
            return false;
        }
        if (!$this->solves($challenge, $nonce)) {
            return false;
        }

        // Spend it. Done last so an unsolved guess cannot burn somebody else's
        // valid challenge, and keyed by hash so the cache never holds the
        // challenge itself.
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
                // The first 1-bit ends the run of leading zeros. Reaching it
                // before the target means this nonce does not solve the
                // challenge — the count can only go down from here.
                if (0 !== ($value & $mask)) {
                    return false;
                }
                if (++$bits >= self::DIFFICULTY) {
                    return true;
                }
            }
        }

        // A digest of 256 zero bits. Unreachable for any sane difficulty, and
        // honest rather than an exception: it would genuinely be a solution.
        return true;
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', 'media-pow|'.$body, $this->secret);
    }
}
