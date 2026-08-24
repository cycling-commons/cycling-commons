<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Commons;

/**
 * Wikimedia could not be reached, or answered with something unusable.
 *
 * Our problem, not the file's, so a caller settles the row as `failed` and
 * leaves it retryable rather than `unusable`.
 *
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
final class CommonsUnavailable extends \RuntimeException
{
}
