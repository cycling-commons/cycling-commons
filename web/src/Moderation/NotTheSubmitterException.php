<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
