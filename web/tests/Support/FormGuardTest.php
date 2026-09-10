<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\FormGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The anti-spam layers that stand in for a third-party captcha
 * (docs/specs/contact-and-support.md §3).
 *
 * The point of these is that Cycling Commons never sends a visitor's browser
 * to Cloudflare or Google before it will accept a sentence. That only holds if
 * the local layers actually work, so each one is pinned here, especially the
 * signed stamp, which is the one an attacker would otherwise simply back-date.
 */
final class FormGuardTest extends TestCase
{
    private const string SECRET = 'test-secret-for-the-form-guard';

    private FormGuard $guard;

    #[\Override]
    protected function setUp(): void
    {
        $this->guard = new FormGuard(self::SECRET);
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(array $fields, string $ip = '203.0.113.7'): Request
    {
        return Request::create('/contact', 'POST', $fields, server: ['REMOTE_ADDR' => $ip]);
    }

    private function goodFields(\DateTimeImmutable $issued): array
    {
        return [
            FormGuard::STAMP => $this->guard->stamp($issued),
            FormGuard::HONEYPOT_A => '',
            FormGuard::HONEYPOT_B => '',
        ];
    }

    public function testAHumanPassesEveryLayer(): void
    {
        $issued = new \DateTimeImmutable('2026-08-27 10:00:00');
        $sent = $issued->modify('+30 seconds');

        self::assertNull($this->guard->reject($this->post($this->goodFields($issued)), $sent));
    }

    public function testEitherHoneypotRejects(): void
    {
        $issued = new \DateTimeImmutable('2026-08-27 10:00:00');
        $sent = $issued->modify('+30 seconds');

        foreach ([FormGuard::HONEYPOT_A, FormGuard::HONEYPOT_B] as $field) {
            $fields = $this->goodFields($issued);
            $fields[$field] = 'http://spam.example';

            self::assertSame('support.error.rejected', $this->guard->reject($this->post($fields), $sent), $field);
        }
    }

    /**
     * Both honeypots share ONE vague message on purpose. Telling a bot author
     * precisely which trap fired is free help.
     */
    public function testHoneypotAndMissingStampGiveTheSameVagueReason(): void
    {
        $now = new \DateTimeImmutable('2026-08-27 10:00:00');

        $honeypot = $this->goodFields($now->modify('-30 seconds'));
        $honeypot[FormGuard::HONEYPOT_A] = 'x';

        self::assertSame('support.error.rejected', $this->guard->reject($this->post($honeypot), $now));
        self::assertSame('support.error.rejected', $this->guard->reject($this->post([]), $now));
    }

    public function testTooFastIsRejected(): void
    {
        $issued = new \DateTimeImmutable('2026-08-27 10:00:00');
        $sent = $issued->modify('+'.(FormGuard::MIN_SECONDS - 1).' seconds');

        self::assertSame('support.error.too_fast', $this->guard->reject($this->post($this->goodFields($issued)), $sent));
    }

    /**
     * Exactly at the floor is a pass, not a fail. Somebody pasting a prepared
     * sentence is a real person and must not be told otherwise.
     */
    public function testExactlyTheMinimumPasses(): void
    {
        $issued = new \DateTimeImmutable('2026-08-27 10:00:00');
        $sent = $issued->modify('+'.FormGuard::MIN_SECONDS.' seconds');

        self::assertNull($this->guard->reject($this->post($this->goodFields($issued)), $sent));
    }

    public function testAStaleTabIsRejected(): void
    {
        $issued = new \DateTimeImmutable('2026-08-27 10:00:00');
        $sent = $issued->modify('+'.(FormGuard::MAX_SECONDS + 1).' seconds');

        self::assertSame('support.error.stale', $this->guard->reject($this->post($this->goodFields($issued)), $sent));
    }

    /**
     * The whole reason the stamp is signed rather than a bare integer: an
     * unsigned one could be back-dated by hand to walk straight past the timer.
     */
    public function testAHandWrittenStampIsRejected(): void
    {
        $now = new \DateTimeImmutable('2026-08-27 10:00:00');
        $fields = $this->goodFields($now);
        $fields[FormGuard::STAMP] = (string) $now->modify('-60 seconds')->getTimestamp();

        self::assertSame('support.error.rejected', $this->guard->reject($this->post($fields), $now));
    }

    public function testAStampSignedWithAnotherSecretIsRejected(): void
    {
        $now = new \DateTimeImmutable('2026-08-27 10:00:00');
        $fields = $this->goodFields($now->modify('-30 seconds'));
        $fields[FormGuard::STAMP] = (new FormGuard('a-different-secret'))->stamp($now->modify('-30 seconds'));

        self::assertSame('support.error.rejected', $this->guard->reject($this->post($fields), $now));
    }

    public function testATamperedTimestampInvalidatesItsSignature(): void
    {
        $now = new \DateTimeImmutable('2026-08-27 10:00:00');
        $stamp = $this->guard->stamp($now->modify('-1 second'));
        [, $signature] = explode('.', $stamp);

        $fields = $this->goodFields($now);
        // Keep our signature, move the clock back past the floor.
        $fields[FormGuard::STAMP] = $now->modify('-600 seconds')->getTimestamp().'.'.$signature;

        self::assertSame('support.error.rejected', $this->guard->reject($this->post($fields), $now));
    }

    /**
     * The privacy page promises counters are kept against a salted one-way hash
     * rather than an address. This is the half of that promise the code owns.
     */
    public function testTheRateLimitKeyIsNotTheAddress(): void
    {
        $key = $this->guard->key($this->post([], '198.51.100.42'));

        self::assertStringStartsWith('ip-', $key);
        self::assertStringNotContainsString('198.51.100.42', $key);
        self::assertSame($key, $this->guard->key($this->post([], '198.51.100.42')));
        self::assertNotSame($key, $this->guard->key($this->post([], '198.51.100.43')));
    }

    public function testTheRateLimitKeyIsSaltedWithTheAppSecret(): void
    {
        $mine = $this->guard->key($this->post([], '198.51.100.42'));
        $theirs = (new FormGuard('a-different-secret'))->key($this->post([], '198.51.100.42'));

        self::assertNotSame($mine, $theirs);
    }

    public function testAnUnknownDomainIsCaught(): void
    {
        // .invalid is reserved by RFC 2606 and can never resolve.
        self::assertFalse($this->guard->domainResolves('someone@definitely-not-real.invalid'));
        self::assertFalse($this->guard->domainResolves('no-at-sign'));
        self::assertFalse($this->guard->domainResolves('trailing@'));
    }
}
