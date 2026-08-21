<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Security\Csp\CspNonce;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `csp_nonce()` for inline scripts.
 *
 * @see docs/specs/security-architecture.md §2.2
 *
 * @api
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
