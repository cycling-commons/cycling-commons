<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\ProofOfWork;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A solved challenge is worth exactly one submission
 * (docs/specs/contact-and-support.md §3).
 *
 * The spent set is the `pow_spent` table, and the spend is one INSERT on its
 * primary key, so the first request to insert wins on any web host, also when
 * two arrive at the same moment (audit 2026-10-04).
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

    public function testAChallengeAnotherHostSpentIsRefused(): void
    {
        $now = new \DateTimeImmutable();
        $challenge = $this->pow->issue($now);
        $nonce = $this->solve($challenge);
        // The other web host's insert, already in the table.
        static::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO pow_spent (challenge_hash, expires_at) VALUES (?, NOW() + INTERVAL '30 minutes')",
            [hash('sha256', $challenge)],
        );

        self::assertFalse($this->pow->verify($challenge, $nonce, $now));
    }

    public function testRowsPastTheirExpiryAreCleared(): void
    {
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement("INSERT INTO pow_spent (challenge_hash, expires_at) VALUES (?, NOW() - INTERVAL '1 minute')", [str_repeat('a', 64)]);

        $now = new \DateTimeImmutable();
        $challenge = $this->pow->issue($now);
        self::assertTrue($this->pow->verify($challenge, $this->solve($challenge), $now));

        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM pow_spent WHERE challenge_hash = ?', [str_repeat('a', 64)]));
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
