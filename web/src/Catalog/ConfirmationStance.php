<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * A rider's community stance on a non-votable catalog item (spec: utilities are
 * confirmed, not voted). Drinking water carries a potability judgement
 * (potable / not potable); other point utilities carry a plain existence
 * confirmation. Votable types (climbs, stays, viewpoints, history, routes) use
 * the vote/best-of funnel instead and have no confirmation stance.
 *
 * @api Persisted on ItemConfirmation; tallied in the map drawer.
 */
enum ConfirmationStance: string
{
    case Potable = 'potable';
    case NotPotable = 'not_potable';
    case Exists = 'exists';
    /* A road-surface segment's "no": the stretch is there, but not as the
       entry describes it (owner 2026-08-13). Like NotPotable it is a warning,
       not a vouching — it counts in the public tally but never verifies — and
       it may carry a note telling the curators what differs. */
    case NotAsDescribed = 'not_as_described';
}
