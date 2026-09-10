<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
