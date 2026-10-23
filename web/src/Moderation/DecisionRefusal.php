<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

/**
 * A decision refused by one of its checks, before anything changed: out of
 * the curator's areas, already decided, an open OSM question, an undecided
 * Wikipedia credit, unconfirmed links. ModerationService::decide() rolls back
 * on one and leaves the EntityManager open, so the request that asked goes
 * on (a curator's own add stays queued, and its receipt still renders).
 *
 * @api
 */
interface DecisionRefusal extends \Throwable
{
}
