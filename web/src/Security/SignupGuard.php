<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * The contact form's bot layers ({@see FormGuard}, {@see ProofOfWork}) on the
 * two anonymous forms that mail an address the sender typed: sign-up and a new
 * confirmation link. Bots used sign-up to mail strangers (2026-09-28).
 *
 * Returns `security.guard.*` keys: the contact form's own messages talk about
 * "the address beside the form" and "your message", which are not here.
 *
 * @see docs/specs/account-and-auth.md §2
 *
 * @api
 */
final class SignupGuard
{
    private const array KEYS = [
        'support.error.rejected' => 'security.guard.rejected',
        'support.error.too_fast' => 'security.guard.too_fast',
        'support.error.stale' => 'security.guard.stale',
    ];

    public function __construct(
        private readonly FormGuard $guard,
        private readonly ProofOfWork $proofOfWork,
    ) {
    }

    /** Honeypots and the signed timer: free, so they run before validation. */
    public function refusal(Request $request, \DateTimeImmutable $now): ?string
    {
        $rejection = $this->guard->reject($request, $now);

        return null === $rejection ? null : (self::KEYS[$rejection] ?? 'security.guard.rejected');
    }

    /** The proof of work, after validation so a typo does not spend it. */
    public function proofRefusal(Request $request, \DateTimeImmutable $now): ?string
    {
        $challenge = (string) $request->request->get('pow_challenge', '');
        if ($this->proofOfWork->verify($challenge, (string) $request->request->get('pow_nonce', ''), $now)) {
            return null;
        }

        return $this->proofOfWork->isExpired($challenge, $now) ? 'security.guard.challenge_stale' : 'security.guard.challenge';
    }

    /** After the rate limit: the one check that leaves the process (see ContactController). */
    public function domainResolves(string $email): bool
    {
        return $this->guard->domainResolves($email);
    }

    /**
     * @return array<string, mixed> what templates/security/_guard_fields.html.twig needs
     */
    public function context(\DateTimeImmutable $now): array
    {
        return [
            'form_stamp' => $this->guard->stamp($now),
            'pow_difficulty' => ProofOfWork::DIFFICULTY,
            'honeypot_a' => FormGuard::HONEYPOT_A,
            'honeypot_b' => FormGuard::HONEYPOT_B,
            'stamp_field' => FormGuard::STAMP,
        ];
    }
}
