<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Security\Csp;

/**
 * Request-scoped CSP nonce, shared by Twig and the CSP header.
 *
 * @see docs/specs/security-architecture.md §2.2
 *
 * @api
 */
final class CspNonce
{
    private ?string $value = null;

    public function value(): string
    {
        return $this->value ??= base64_encode(random_bytes(16));
    }

    /** Whether any template asked for the nonce during this request. */
    public function used(): bool
    {
        return null !== $this->value;
    }
}
