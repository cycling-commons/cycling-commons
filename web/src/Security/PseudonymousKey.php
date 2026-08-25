<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Security;

/**
 * One construction for every "identify this caller without storing who they are"
 * key in the app.
 *
 * The value being pseudonymised is almost always an IP address, which is
 * personal data under the GDPR. Hashing it keyed by APP_SECRET means the
 * limiter store and the takedown table hold something that groups a caller's
 * own requests together and nothing else. This is pseudonymisation, NOT
 * anonymisation: the output still relates to a person, it is still personal
 * data, and the privacy notice says so.
 *
 * **HMAC, not `hash($secret.'|'.$value)`.** The old hand-rolled form was
 * duplicated in four places and was length-extension shaped: knowing one output
 * would let an attacker derive further valid outputs without the secret. Not
 * exploitable here (the digests are never published), but a keyed hash has a
 * correct primitive and there is no reason to keep using the wrong one in four
 * copies (security scan 2026-08-25).
 *
 * `$purpose` keeps the namespaces apart, so the same address at the ride-check
 * endpoint and at the sign-up form produces two unrelated keys and one budget
 * can never drain the other.
 *
 * Changing this changes every stored pseudonym. Only
 * `media_upload.takedown_reporter_hash` is persisted, nothing compares stored
 * values to each other or to a freshly computed one, and limiter keys are
 * cache entries that expire on their own, so the 2026-08-25 switch needed no
 * migration. A future change that has to preserve continuity will.
 *
 * @see docs/specs/security-architecture.md §7
 *
 * @api
 */
final class PseudonymousKey
{
    /** 64 lowercase hex characters, which is what `takedown_reporter_hash` is sized for. */
    public static function of(string $purpose, string $value, string $secret): string
    {
        return hash_hmac('sha256', $purpose.'|'.$value, $secret);
    }

    /** The same digest behind an `anon-` tag, for limiter keys that share a store with `user-<id>`. */
    public static function limiter(string $purpose, string $value, string $secret): string
    {
        return 'anon-'.self::of($purpose, $value, $secret);
    }
}
