<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/**
 * Thrown by ModerationService::decide() when a submission is not in a
 * decidable state (Pending or NeedsInfo), e.g. a second decision on an
 * already-decided submission.
 *
 * @api Consumed by ModerateController::decide().
 */
final class AlreadyDecidedException extends \RuntimeException
{
}
