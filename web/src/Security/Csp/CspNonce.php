<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Security\Csp;

/**
 * Request-scoped CSP nonce: one random value per request, shared between the
 * Twig `csp_nonce()` function (stamped on every inline <script>) and the
 * response header the subscriber emits.
 *
 * @see docs/specs/security-architecture.md §2.2
 *
 * @api Auto-registered service, injected into CspExtension + CspSubscriber.
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
