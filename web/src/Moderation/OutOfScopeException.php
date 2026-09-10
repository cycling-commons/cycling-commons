<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

/**
 * Write targeted an item outside the curator's assigned areas (HTTP 403).
 *
 * @see docs/specs/moderation-and-contribution.md §9.3
 *
 * @api
 */
final class OutOfScopeException extends \RuntimeException
{
}
