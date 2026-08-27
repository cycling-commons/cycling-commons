<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\ProofOfWork;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A solved challenge is worth exactly one submission
 * (docs/specs/contact-and-support.md §3).
 *
 * **Why this is a kernel test and not part of ContactFormTest.** The spent-set
 * pool is `cache.adapter.array` under `when@test`, and Symfony's service
 * resetter clears an ArrayAdapter between requests inside a `WebTestCase`. A
 * replay assertion there would pass trivially on the first request and fail on
 * the second for a reason that has nothing to do with the code under test. One
 * kernel, one cache, one honest answer.
 *
 * The property matters because without it the proof of work is a one-off toll
 * rather than a per-message cost: solve once, replay the same nonce forever.
 */
final class ProofOfWorkSpentTest extends KernelTestCase
{
    private ProofOfWork $pow;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->pow = static::getContainer()->get(ProofOfWork::class);
    }

    /** Do the same work a visitor's browser does. */
    private function solve(string $challenge): string
    {
        for ($nonce = 0;; ++$nonce) {
            $digest = hash('sha256', $challenge.'.'.$nonce, true);
            $bits = 0;
            foreach (str_split($digest) as $byte) {
                $value = \ord($byte);
                for ($mask = 0x80; $mask > 0; $mask >>= 1) {
                    if (0 !== ($value & $mask)) {
                        break 2;
                    }
                    if (++$bits >= ProofOfWork::DIFFICULTY) {
                        return (string) $nonce;
                    }
                }
            }
        }
    }

    public function testASolvedChallengeIsAcceptedOnceAndOnlyOnce(): void
    {
        $now = new \DateTimeImmutable();
        $challenge = $this->pow->issue($now);
        $nonce = $this->solve($challenge);

        self::assertTrue($this->pow->verify($challenge, $nonce, $now));
        self::assertFalse($this->pow->verify($challenge, $nonce, $now), 'a replayed nonce must be refused');
    }

    public function testAnUnsolvedNonceIsRefused(): void
    {
        $now = new \DateTimeImmutable();

        self::assertFalse($this->pow->verify($this->pow->issue($now), '0', $now));
    }

    public function testAChallengeWeNeverIssuedIsRefused(): void
    {
        $now = new \DateTimeImmutable();

        self::assertFalse($this->pow->verify('9999999999.deadbeef.notasignature', '0', $now));
        self::assertFalse($this->pow->verify('', '0', $now));
    }

    public function testAnExpiredChallengeIsRefused(): void
    {
        $issued = new \DateTimeImmutable('-1 hour');
        $challenge = $this->pow->issue($issued);
        $nonce = $this->solve($challenge);

        // Solved correctly, signed by us, and still refused: the ten-minute
        // window is what stops a solved challenge being banked for later.
        self::assertFalse($this->pow->verify($challenge, $nonce, new \DateTimeImmutable()));
    }
}
