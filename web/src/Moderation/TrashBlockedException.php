<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/**
 * Route is not trashable (only submitted or rejected).
 *
 * @see docs/specs/moderation-and-contribution.md §6
 *
 * @api
 */
final class TrashBlockedException extends \RuntimeException
{
}
