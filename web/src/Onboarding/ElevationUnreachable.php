<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding;

/**
 * The elevation instance did not answer (timeout, refused connection, HTTP error), as opposed to answering null or 0.
 *
 * @api
 */
final class ElevationUnreachable extends \RuntimeException
{
    public function __construct(public readonly string $endpoint, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('elevation instance unreachable: %s (%s)', $endpoint, $reason), 0, $previous);
    }
}
