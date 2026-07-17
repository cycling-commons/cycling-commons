<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/**
 * A moderation write targeted an item outside the acting curator's assigned
 * areas. Controllers map this to a 403.
 *
 * @see docs/specs/moderation-and-contribution.md §9.3
 *
 * @api Thrown by ModerationService/RouteModerationService guards.
 */
final class OutOfScopeException extends \RuntimeException
{
}
