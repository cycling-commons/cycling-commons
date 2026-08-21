<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/**
 * Withdraw is the submitter's own exit, nobody else's.
 *
 * @api
 */
final class NotTheSubmitterException extends \RuntimeException
{
}
