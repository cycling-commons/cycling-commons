<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/**
 * A moderation write targeted an item outside the acting curator's assigned
 * areas (moderator-areas spec 2026-07-14). Controllers map this to a 403.
 *
 * @api Thrown by ModerationService/RouteModerationService guards.
 */
final class OutOfScopeException extends \RuntimeException
{
}
