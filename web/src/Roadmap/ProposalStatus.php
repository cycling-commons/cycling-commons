<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap;

/**
 * Where a rider's roadmap proposal has got to.
 *
 * `Pending` is seen by its rider and the desk only. `Open` is public on the
 * Proposals tab, where riders vote for it and follow it. `Promoted` became a
 * roadmap item and handed its votes and follows to it. `Declined` always
 * carries a reason the proposer is told.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
enum ProposalStatus: string
{
    case Pending = 'pending';
    case Open = 'open';
    case Promoted = 'promoted';
    case Declined = 'declined';

    /** @return list<self> desk filter order */
    public static function all(): array
    {
        return [self::Pending, self::Open, self::Promoted, self::Declined];
    }
}
