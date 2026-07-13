<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/**
 * Thrown by RouteModerationService::trashProposal() when the route is not in
 * a trashable state (spec M9: only `submitted` or `rejected` — never an
 * active/served or retired route).
 *
 * @api Consumed by RouteModerateController::trash().
 */
final class TrashBlockedException extends \RuntimeException
{
}
