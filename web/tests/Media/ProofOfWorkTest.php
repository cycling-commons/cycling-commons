<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Security\ProofOfWork;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The local challenge on the urgent report path
 * (docs/specs/photo-uploads.md §6c).
 */
final class ProofOfWorkTest extends KernelTestCase
{
    private ProofOfWork $pow;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->pow = static::getContainer()->get(ProofOfWork::class);
    }

    /* Brute-forces a stamp the way a browser does — the cheap half is
       verification, not this.

       The bound is a runaway guard, NOT a budget. Finding a 20-bit stamp takes
       about 2^20 ≈ 1.05M hashes on average, but the search is memoryless: the
       chance of going 5M nonces without a hit is e^(-5/1.05) ≈ 0.85% per call,
       and this file solves several, so roughly one full run in twenty failed on
       nothing at all (seen 2026-08-14). A real browser has no such cap — it
       hashes until it lands one — so the failure was the test's alone.

       Raising it costs nothing: the loop returns the instant it finds a stamp,
       so the extra headroom is only ever reached in the tail it exists to
       cover. At 30M the odds of a spurious failure are e^-28.6, about one run
       in 2.6 trillion. */
    private function solve(string $challenge): string
    {
        for ($nonce = 0; $nonce < 30_000_000; ++$nonce) {
            $digest = hash('sha256', $challenge.'.'.$nonce, true);
            $bits = 0;
            foreach (str_split($digest) as $byte) {
                $value = \ord($byte);
                if (0 === $value) {
                    $bits += 8;
                    continue;
                }
                for ($mask = 0x80; $mask > 0; $mask >>= 1) {
                    if (0 !== ($value & $mask)) {
                        break 2;
                    }
                    ++$bits;
                }
                break;
            }
            if ($bits >= ProofOfWork::DIFFICULTY) {
                return (string) $nonce;
            }
        }

        self::fail('no solution found — the difficulty is set far too high');
    }

    public function testASolvedChallengeIsAccepted(): void
    {
        $now = new \DateTimeImmutable();
        $challenge = $this->pow->issue($now);

        self::assertTrue($this->pow->verify($challenge, $this->solve($challenge), $now));
    }

    public function testAnUnsolvedChallengeIsRefused(): void
    {
        $now = new \DateTimeImmutable();
        $challenge = $this->pow->issue($now);

        self::assertFalse($this->pow->verify($challenge, '1', $now));
        self::assertFalse($this->pow->verify($challenge, '', $now));
    }

    /** The whole point of signing it: an attacker cannot mint their own easy challenge. */
    public function testAForgedChallengeIsRefused(): void
    {
        $now = new \DateTimeImmutable();
        $forged = ($now->getTimestamp() + 600).'.deadbeef.'.str_repeat('a', 64);

        self::assertFalse($this->pow->verify($forged, '1', $now));
    }

    public function testAnExpiredChallengeIsRefused(): void
    {
        $now = new \DateTimeImmutable();
        $challenge = $this->pow->issue($now);
        $nonce = $this->solve($challenge);

        self::assertFalse($this->pow->verify($challenge, $nonce, $now->modify('+31 minutes')));
    }

    /**
     * The window is long enough to write in.
     *
     * It was ten minutes, measured against how long a challenge is worth
     * replaying and never against how long a person takes. A useful bug report
     * has steps in it and often a pasted screenshot, and that is regularly more
     * than ten minutes; the challenge died mid-sentence and the reporter was
     * told the spam check had failed (owner, 2026-08-28).
     *
     * Twenty minutes has to hold, so this is not a restatement of the constant.
     */
    public function testSomebodyWritingCarefullyIsStillInsideTheWindow(): void
    {
        $now = new \DateTimeImmutable();
        $challenge = $this->pow->issue($now);
        $nonce = $this->solve($challenge);

        self::assertTrue($this->pow->verify($challenge, $nonce, $now->modify('+20 minutes')));
    }

    /**
     * Expired and wrong are told apart.
     *
     * They say opposite things about the person on the other end, and the bug
     * form treats them differently: a wrong nonce is a refusal, an expired one
     * is somebody who took their time
     * ({@see \App\Controller\BugReportController}).
     */
    public function testExpiryIsDistinguishableFromAWrongAnswer(): void
    {
        $now = new \DateTimeImmutable();
        $challenge = $this->pow->issue($now);

        self::assertFalse($this->pow->isExpired($challenge, $now), 'fresh');
        self::assertTrue($this->pow->isExpired($challenge, $now->modify('+31 minutes')), 'stale');
    }

    /** A forged string is not "expired": it is not ours at all. */
    public function testAForgedChallengeIsNeverCalledExpired(): void
    {
        $now = new \DateTimeImmutable();
        $forged = ($now->getTimestamp() - 600).'.deadbeef.'.str_repeat('a', 64);

        self::assertFalse($this->pow->isExpired($forged, $now));
        self::assertFalse($this->pow->isExpired('nonsense', $now));
    }

    /**
     * Without this, one solve would pay for a whole flood: the challenge is
     * stateless, so nothing but the spent-list stops it being replayed.
     */
    public function testASolvedChallengeCannotBeUsedTwice(): void
    {
        $now = new \DateTimeImmutable();
        $challenge = $this->pow->issue($now);
        $nonce = $this->solve($challenge);

        self::assertTrue($this->pow->verify($challenge, $nonce, $now));
        self::assertFalse($this->pow->verify($challenge, $nonce, $now), 'replay');
    }

    /** A wrong guess must not burn a challenge somebody is still solving. */
    public function testAFailedAttemptDoesNotSpendTheChallenge(): void
    {
        $now = new \DateTimeImmutable();
        $challenge = $this->pow->issue($now);

        self::assertFalse($this->pow->verify($challenge, '1', $now));
        self::assertTrue($this->pow->verify($challenge, $this->solve($challenge), $now));
    }
}
