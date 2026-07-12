<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Security\Csp\CspNonce;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `csp_nonce()` — the per-request nonce every inline <script> must carry now
 * that responses ship a Content-Security-Policy without 'unsafe-inline'
 * (frontend review 2026-07-12 W3). Usage: <script nonce="{{ csp_nonce() }}">.
 *
 * @api Auto-registered Twig extension.
 */
final class CspExtension extends AbstractExtension
{
    public function __construct(private readonly CspNonce $nonce)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', fn (): string => $this->nonce->value()),
        ];
    }
}
