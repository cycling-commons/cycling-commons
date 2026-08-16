<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/**
 * Thrown by ModerationService::withdraw() when the caller is not the rider
 * who filed the submission - withdrawing is the submitter's own exit and
 * nobody else's (a curator who wants it gone has reject and Trash).
 *
 * @api Consumed by ProfileController::withdraw().
 */
final class NotTheSubmitterException extends \RuntimeException
{
}
