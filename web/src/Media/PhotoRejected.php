<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
