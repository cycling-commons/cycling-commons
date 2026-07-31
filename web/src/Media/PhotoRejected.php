<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * A photo the processor refuses, carrying the machine-readable reason the
 * endpoint returns and the wizard translates (docs/specs/photo-uploads.md §3).
 * Honest degradation: every refusal names itself, none is a silent drop.
 *
 * @api Thrown by PhotoProcessor, caught by MediaController.
 */
final class PhotoRejected extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
