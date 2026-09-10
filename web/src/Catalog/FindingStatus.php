<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Where a catalog finding stands.
 *
 * `Dismissed` is the load-bearing one. A scan that cannot be told "no"
 * re-proposes the same rows every week, and a desk that shows the same rejected
 * work every week is a desk people stop reading — at which point the real
 * findings are invisible too. Ligne KW is the worked example: many bunkers
 * along one line, correctly named the same, correctly close together, and
 * correctly NOT duplicates.
 *
 * @see docs/specs/catalog-data-model.md §5c
 *
 * @api
 */
enum FindingStatus: string
{
    /** Waiting for a curator. */
    case Open = 'open';

    /** A curator agreed and the action was applied. */
    case Accepted = 'accepted';

    /** A curator said no. The scan must never raise this finding again. */
    case Dismissed = 'dismissed';

    /**
     * Gone on its own: the rows changed and the finding no longer holds.
     *
     * Distinct from `Dismissed` on purpose. Dismissed records a human judgement
     * worth keeping; this records that nobody had to make one, so it must not
     * be read later as "a curator decided these were different".
     */
    case Resolved = 'resolved';

    /** Statuses that still want a curator's attention. */
    public function isOpen(): bool
    {
        return self::Open === $this;
    }
}
