<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * The anti-spam layers a public form gets, with no third party in the loop.
 *
 * Cycling Commons runs no Turnstile, no reCAPTCHA and no hCaptcha, on purpose:
 * every one of them makes a rider's browser call a company we do not control
 * before it can send us a sentence, and every one of them ships that visitor's
 * address to that company. The four layers here cost a spammer more than they
 * cost a rider, and cost a rider no privacy at all.
 *
 * 1. **Honeypots.** Two fields no human ever sees. A bot that fills forms by
 *    name fills them; a person cannot. Hidden by CLASS, never by an inline
 *    `style=` attribute. The CSP work in docs/TODO.md is removing those, and a
 *    honeypot that stops hiding when `style-src` tightens is worse than none.
 * 2. **A timer.** The form carries a signed stamp of when it was rendered. Under
 *    {@see MIN_SECONDS} is a script; over {@see MAX_SECONDS} is a stale tab.
 *    Signed, so the value cannot simply be back-dated by hand.
 * 3. **A rate limit**, keyed on a salted hash of the address, never the address.
 * 4. **{@see ProofOfWork}**, solved in the visitor's own tab.
 *
 * Ported from the pattern already proven on the BikeCoders site, with three
 * changes: the stamp is signed rather than a bare integer, the honeypots hide
 * by class, and the minimum dwell drops from nine seconds to four. Nine is
 * comfortably longer than it takes to paste a prepared sentence and hit send,
 * and a real reporter who does exactly that should not be told they are a bot.
 *
 * @see docs/specs/contact-and-support.md §3
 *
 * @api
 */
final class FormGuard
{
    /** Field names. Plausible enough that a form-filling bot wants them. */
    public const string HONEYPOT_A = 'company_url';
    public const string HONEYPOT_B = 'website';
    public const string STAMP = 'form_stamp';

    /** Faster than this and nobody typed it. */
    public const int MIN_SECONDS = 4;

    /** Older than this and the tab has been open since another session. */
    public const int MAX_SECONDS = 7200;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /** `<issued>.<signature>`, for a hidden field on the rendered form. */
    public function stamp(\DateTimeImmutable $now): string
    {
        $issued = (string) $now->getTimestamp();

        return $issued.'.'.$this->sign($issued);
    }

    /**
     * Every rejection reason a public form can have, or null when it passes.
     *
     * Returns a translation key rather than a boolean so the page can say which
     * of the four it was, except for the honeypots, which deliberately share
     * one vague key. Telling a bot author precisely which trap fired is free
     * help.
     */
    public function reject(Request $request, \DateTimeImmutable $now): ?string
    {
        if ('' !== trim((string) $request->request->get(self::HONEYPOT_A, ''))
            || '' !== trim((string) $request->request->get(self::HONEYPOT_B, ''))) {
            return 'support.error.rejected';
        }

        $issued = $this->issuedAt((string) $request->request->get(self::STAMP, ''));
        if (null === $issued) {
            return 'support.error.rejected';
        }

        $elapsed = $now->getTimestamp() - $issued;
        if ($elapsed < self::MIN_SECONDS) {
            return 'support.error.too_fast';
        }
        if ($elapsed > self::MAX_SECONDS) {
            return 'support.error.stale';
        }

        return null;
    }

    /**
     * A rate-limiter key that is not the visitor's address.
     *
     * The privacy page (privacy.html.twig, "Rate-limit counters") promises that
     * counters are kept against a salted one-way hash rather than an address,
     * and says plainly that hashing is not anonymisation. This is the function
     * that keeps the first half of that promise.
     */
    public function key(Request $request): string
    {
        return 'ip-'.substr(hash_hmac('sha256', 'form-guard|'.($request->getClientIp() ?? 'unknown'), $this->secret), 0, 32);
    }

    /**
     * Does the domain of an address resolve at all?
     *
     * Catches `@gmial.com` and the invented domains bulk spam is sent from,
     * without ever contacting the address. Skipped entirely when the resolver
     * is unavailable, because a DNS outage must not close the contact form.
     */
    public function domainResolves(string $email): bool
    {
        $at = strrpos($email, '@');
        if (false === $at) {
            return false;
        }
        $domain = substr($email, $at + 1);
        if ('' === $domain) {
            return false;
        }

        return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
    }

    /** Unix seconds from a stamp we signed, or null for anything else. */
    private function issuedAt(string $stamp): ?int
    {
        $parts = explode('.', $stamp);
        if (2 !== \count($parts)) {
            return null;
        }
        [$issued, $signature] = $parts;

        // Timing-safe, for the same reason ProofOfWork::verify() is.
        if (!ctype_digit($issued) || !hash_equals($this->sign($issued), $signature)) {
            return null;
        }

        return (int) $issued;
    }

    private function sign(string $issued): string
    {
        return hash_hmac('sha256', 'form-stamp|'.$issued, $this->secret);
    }
}
