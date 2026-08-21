<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * Processor refusal with the machine-readable reason the endpoint returns.
 *
 * @see docs/specs/photo-uploads.md §3
 *
 * @api
 */
final class PhotoRejected extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
