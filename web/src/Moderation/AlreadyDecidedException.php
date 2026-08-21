<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/**
 * Thrown when a submission is not in a decidable state.
 *
 * @api
 */
final class AlreadyDecidedException extends \RuntimeException
{
}
